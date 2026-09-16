<?php
/**
 * Creates free material posts from validated CSV rows.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_Importer {
	public const EXTERNAL_ID_META_KEY  = '_free_materials_import_external_id';
	public const FINGERPRINT_META_KEY  = '_free_materials_import_fingerprint';
	public const BATCH_ID_META_KEY     = '_free_materials_import_batch_id';
	public const SOURCE_META_KEY       = '_free_materials_import_source';
	public const IMAGE_URL_META_KEY    = '_free_materials_import_image_url';
	public const MAX_IMAGE_FILE_SIZE   = 10485760;

	private Free_Materials_CSV_Parser $csv_parser;

	private Free_Materials_Content_Domain $content_domain;

	/** @var callable|null */
	private $media_import_callback;

	public function __construct( Free_Materials_CSV_Parser $csv_parser, Free_Materials_Content_Domain $content_domain, ?callable $media_import_callback = null ) {
		$this->csv_parser            = $csv_parser;
		$this->content_domain        = $content_domain;
		$this->media_import_callback = $media_import_callback;
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ), 12 );
	}

	public function register_meta(): void {
		foreach ( self::tracking_meta_keys() as $meta_key ) {
			register_post_meta(
				Free_Materials_Content_Domain::POST_TYPE,
				$meta_key,
				array(
					'auth_callback'     => static fn( bool $allowed, string $key, int $post_id ): bool => current_user_can( 'edit_post', $post_id ),
					'sanitize_callback' => 'sanitize_text_field',
					'show_in_rest'      => false,
					'single'            => true,
					'type'              => 'string',
				)
			);
		}
	}

	/**
	 * Meta the importer writes to trace where a material came from.
	 *
	 * @return string[]
	 */
	public static function tracking_meta_keys(): array {
		return array(
			self::EXTERNAL_ID_META_KEY,
			self::FINGERPRINT_META_KEY,
			self::BATCH_ID_META_KEY,
			self::SOURCE_META_KEY,
			self::IMAGE_URL_META_KEY,
		);
	}

	/**
	 * @param array<string,string> $options Import options.
	 * @return array<string,mixed>|WP_Error
	 */
	public function import( string $path, string $source_name, array $options = array() ): array|WP_Error {
		$parsed = $this->csv_parser->parse_rows( $path );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( $parsed['file_errors'] ) {
			return new WP_Error( 'invalid_import_file', implode( ' ', $parsed['file_errors'] ) );
		}

		$publication_mode = in_array( $options['publication_mode'] ?? '', array( 'publish', 'respect_csv' ), true )
			? $options['publication_mode']
			: 'draft';
		$existing_mode    = 'update' === ( $options['existing_mode'] ?? '' ) ? 'update' : 'skip';

		$batch_id    = wp_generate_uuid4();
		$report_rows = array();
		$counters    = array(
			'created'        => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'invalid'        => 0,
			'media_imported' => 0,
			'media_failed'   => 0,
			'media_missing'  => 0,
			'published'      => 0,
			'drafts'         => 0,
		);

		foreach ( $parsed['rows'] as $row ) {
			if ( ! empty( $row['errors'] ) ) {
				++$counters['invalid'];
				$report_rows[] = $this->report_row( $row, 'invalid', 0, implode( ' ', $row['errors'] ), 'not_processed', 0, '' );
				continue;
			}

			$data        = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array();
			$external_id = mb_strtolower( sanitize_text_field( $data['id_externo'] ?? '' ) );
			$existing_id = $this->find_existing_post_id( $external_id );

			if ( $existing_id && 'update' !== $existing_mode ) {
				++$counters['skipped'];
				$report_rows[] = $this->report_row(
					$row,
					'skipped',
					$existing_id,
					__( 'Já existe um material com este id_externo.', 'free-materials' ),
					'not_processed',
					0,
					(string) get_post_status( $existing_id )
				);
				continue;
			}

			$post_id = $existing_id ? $this->update_post( $existing_id, $data ) : $this->insert_post( $data );
			if ( is_wp_error( $post_id ) ) {
				++$counters['failed'];
				$report_rows[] = $this->report_row( $row, 'failed', 0, $post_id->get_error_message(), 'not_processed', 0, '' );
				continue;
			}

			$this->save_metadata( $post_id, $data, $external_id, $batch_id, $source_name );
			$term_error   = $this->assign_categories( $post_id, $data['categorias'] ?? '' );
			$media_result = $this->import_featured_image( $post_id, $data, (bool) $existing_id );
			$message      = is_wp_error( $term_error ) ? $term_error->get_error_message() : '';

			if ( 'imported' === $media_result['status'] ) {
				++$counters['media_imported'];
			} elseif ( 'failed' === $media_result['status'] ) {
				++$counters['media_failed'];
				$message = $this->append_message( $message, $media_result['message'] );
			} else {
				++$counters['media_missing'];
			}

			$status_result = $this->finalize_post_status( $post_id, $data, $publication_mode );
			$message       = $this->append_message( $message, $status_result['message'] );

			if ( 'publish' === $status_result['status'] ) {
				++$counters['published'];
			} else {
				++$counters['drafts'];
			}

			++$counters[ $existing_id ? 'updated' : 'created' ];
			$report_rows[] = $this->report_row(
				$row,
				$existing_id ? 'updated' : 'created',
				$post_id,
				$message,
				$media_result['status'],
				$media_result['attachment_id'],
				$status_result['status']
			);
		}

		return array(
			'batch_id' => $batch_id,
			'summary'  => array_merge( array( 'total' => count( $parsed['rows'] ) ), $counters ),
			'rows'     => $report_rows,
		);
	}

	/**
	 * @param array<string,string> $data Validated CSV row.
	 * @return int|WP_Error
	 */
	private function insert_post( array $data ): int|WP_Error {
		return wp_insert_post( wp_slash( $this->post_fields( $data ) ), true );
	}

	/**
	 * Only the fields the sheet owns, so an edit made in the editor to
	 * anything the sheet does not carry survives a reimport.
	 *
	 * @param array<string,string> $data Validated CSV row.
	 * @return int|WP_Error
	 */
	private function update_post( int $post_id, array $data ): int|WP_Error {
		return wp_update_post( wp_slash( array_merge( array( 'ID' => $post_id ), $this->post_fields( $data ) ) ), true );
	}

	/**
	 * @param array<string,string> $data Validated CSV row.
	 * @return array<string,string>
	 */
	private function post_fields( array $data ): array {
		return array(
			'post_type'    => Free_Materials_Content_Domain::POST_TYPE,
			'post_status'  => 'draft',
			'post_title'   => sanitize_text_field( $data['titulo'] ?? '' ),
			'post_content' => wp_kses_post( $data['conteudo'] ?? '' ),
			'post_excerpt' => sanitize_textarea_field( $data['resumo'] ?? '' ),
		);
	}

	/**
	 * @param array<string,string> $data Validated CSV row.
	 * @return array{status:string,message:string}
	 */
	private function finalize_post_status( int $post_id, array $data, string $publication_mode ): array {
		$desired_status = 'draft';
		$message        = '';

		if ( 'respect_csv' === $publication_mode ) {
			$desired_status = in_array( $data['status_publicacao'] ?? '', array( 'draft', 'pending', 'publish' ), true )
				? $data['status_publicacao']
				: 'draft';
		} elseif ( 'publish' === $publication_mode ) {
			$desired_status = 'publish';
		}

		// A published material whose form has nowhere to send the file collects
		// leads and delivers nothing.
		if ( 'publish' === $desired_status && '' === ( $data['url_entrega'] ?? '' ) ) {
			$desired_status = 'draft';
			$message        = __( 'Mantido como rascunho porque a url_entrega está vazia.', 'free-materials' );
		}

		if ( 'draft' !== $desired_status ) {
			$updated_post_id = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $desired_status,
				),
				true
			);

			if ( is_wp_error( $updated_post_id ) ) {
				return array(
					'status'  => 'draft',
					'message' => $this->append_message(
						$message,
						sprintf(
							/* translators: %s: post publication error. */
							__( 'Não foi possível atualizar o status: %s', 'free-materials' ),
							$updated_post_id->get_error_message()
						)
					),
				);
			}
		}

		return array(
			'status'  => $desired_status,
			'message' => $message,
		);
	}

	/**
	 * @param array<string,string> $data Validated CSV row.
	 */
	private function save_metadata( int $post_id, array $data, string $external_id, string $batch_id, string $source_name ): void {
		$text_meta = array(
			Free_Materials_Content_Domain::FILE_SIZE_META_KEY           => $data['tamanho_arquivo'] ?? '',
			Free_Materials_Content_Domain::LEVEL_META_KEY               => $data['nivel'] ?? '',
			Free_Materials_Content_Domain::CTA_LABEL_META_KEY           => $data['rotulo_cta'] ?? '',
			Free_Materials_Content_Domain::CAPTURE_DESTINATION_META_KEY => $data['lista_destino'] ?? '',
		);

		foreach ( $text_meta as $meta_key => $value ) {
			$value = sanitize_text_field( $value );
			if ( '' === $value ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, $value );
		}

		$format = $this->content_domain->sanitize_format( $data['formato'] ?? '' );
		if ( '' !== $format ) {
			update_post_meta( $post_id, Free_Materials_Content_Domain::FORMAT_META_KEY, $format );
		}

		foreach ( array(
			Free_Materials_Content_Domain::PAGES_META_KEY     => $data['paginas'] ?? '',
			Free_Materials_Content_Domain::DOWNLOADS_META_KEY => $data['downloads'] ?? '',
		) as $meta_key => $value ) {
			if ( '' === $value ) {
				continue;
			}

			update_post_meta( $post_id, $meta_key, absint( $value ) );
		}

		$delivery_url = esc_url_raw( $data['url_entrega'] ?? '' );
		if ( '' !== $delivery_url ) {
			update_post_meta( $post_id, Free_Materials_Content_Domain::DELIVERY_URL_META_KEY, $delivery_url );
		}

		$highlights = $this->content_domain->sanitize_highlights( Free_Materials_CSV_Parser::split_list( $data['destaques'] ?? '' ) );
		if ( $highlights ) {
			update_post_meta( $post_id, Free_Materials_Content_Domain::HIGHLIGHTS_META_KEY, $highlights );
		}

		if ( $this->boolean_value( $data['destaque'] ?? '' ) ) {
			update_post_meta( $post_id, Free_Materials_Content_Domain::FEATURED_META_KEY, '1' );
		}

		update_post_meta( $post_id, self::EXTERNAL_ID_META_KEY, $external_id );
		update_post_meta( $post_id, self::FINGERPRINT_META_KEY, $this->fingerprint( $data ) );
		update_post_meta( $post_id, self::BATCH_ID_META_KEY, $batch_id );
		update_post_meta( $post_id, self::SOURCE_META_KEY, sanitize_file_name( $source_name ) );
	}

	/**
	 * @param array<string,string> $data Validated CSV row.
	 * @return array{status:string,attachment_id:int,message:string}
	 */
	private function import_featured_image( int $post_id, array $data, bool $is_update ): array {
		$image_url = esc_url_raw( $data['imagem_url'] ?? '' );
		if ( '' === $image_url ) {
			return array(
				'status'        => 'missing',
				'attachment_id' => 0,
				'message'       => '',
			);
		}

		// Reimporting the same sheet should not download the same cover again.
		if ( $is_update
			&& has_post_thumbnail( $post_id )
			&& $image_url === (string) get_post_meta( $post_id, self::IMAGE_URL_META_KEY, true ) ) {
			return array(
				'status'        => 'imported',
				'attachment_id' => (int) get_post_thumbnail_id( $post_id ),
				'message'       => '',
			);
		}

		$result = $this->media_import_callback
			? call_user_func( $this->media_import_callback, $image_url, $post_id, $data['titulo'] ?? '' )
			: $this->sideload_image( $image_url, $post_id, $data['titulo'] ?? '' );

		if ( is_wp_error( $result ) ) {
			return array(
				'status'        => 'failed',
				'attachment_id' => 0,
				'message'       => sprintf(
					/* translators: %s: media import error. */
					__( 'A capa não pôde ser importada: %s', 'free-materials' ),
					$result->get_error_message()
				),
			);
		}

		$attachment_id = (int) $result;
		if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) || ! set_post_thumbnail( $post_id, $attachment_id ) ) {
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
			}

			return array(
				'status'        => 'failed',
				'attachment_id' => 0,
				'message'       => __( 'A capa baixada não pôde ser definida como imagem destacada.', 'free-materials' ),
			);
		}

		update_post_meta( $post_id, self::IMAGE_URL_META_KEY, $image_url );

		return array(
			'status'        => 'imported',
			'attachment_id' => $attachment_id,
			'message'       => '',
		);
	}

	/**
	 * @return int|WP_Error
	 */
	private function sideload_image( string $image_url, int $post_id, string $description ): int|WP_Error {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$temp_file = wp_tempnam( 'free-materials-image-' . $post_id );
		if ( ! $temp_file ) {
			return new WP_Error( 'image_temp_file', __( 'Não foi possível criar o arquivo temporário.', 'free-materials' ) );
		}

		// wp_safe_remote_get blocks private hosts, so a sheet cannot make the
		// site fetch from its own network.
		$response = wp_safe_remote_get(
			$image_url,
			array(
				'filename'            => $temp_file,
				'limit_response_size' => self::MAX_IMAGE_FILE_SIZE,
				'redirection'         => 3,
				'stream'              => true,
				'timeout'             => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $temp_file );

			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			wp_delete_file( $temp_file );

			return new WP_Error(
				'image_http_status',
				sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'A URL respondeu com o código HTTP %d.', 'free-materials' ),
					$status_code
				)
			);
		}

		if ( filesize( $temp_file ) >= self::MAX_IMAGE_FILE_SIZE ) {
			wp_delete_file( $temp_file );

			return new WP_Error( 'image_too_large', __( 'A capa excede o limite de 10 MB.', 'free-materials' ) );
		}

		$file_name = $this->image_file_name( $image_url, (string) wp_remote_retrieve_header( $response, 'content-type' ), $post_id );
		$file_type = wp_check_filetype_and_ext( $temp_file, $file_name );
		if ( ! isset( $file_type['type'] ) || ! is_string( $file_type['type'] ) || ! str_starts_with( $file_type['type'], 'image/' ) ) {
			wp_delete_file( $temp_file );

			return new WP_Error( 'invalid_image_type', __( 'O arquivo remoto não é uma imagem compatível.', 'free-materials' ) );
		}

		if ( ! empty( $file_type['proper_filename'] ) && is_string( $file_type['proper_filename'] ) ) {
			$file_name = $file_type['proper_filename'];
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $file_name,
				'tmp_name' => $temp_file,
			),
			$post_id,
			sanitize_text_field( $description )
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp_file );

			return $attachment_id;
		}

		update_post_meta( (int) $attachment_id, '_source_url', $image_url );

		return (int) $attachment_id;
	}

	private function image_file_name( string $image_url, string $content_type, int $post_id ): string {
		$path      = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$file_name = sanitize_file_name( wp_basename( $path ) );
		$extension = strtolower( (string) pathinfo( $file_name, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'avif', 'gif', 'heic', 'heif', 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
			return $file_name;
		}

		$extensions = array(
			'image/avif' => 'avif',
			'image/gif'  => 'gif',
			'image/heic' => 'heic',
			'image/heif' => 'heif',
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
		);

		return 'material-' . $post_id . '.' . ( $extensions[ strtolower( $content_type ) ] ?? 'jpg' );
	}

	private function append_message( string $current, string $addition ): string {
		if ( '' === $addition ) {
			return $current;
		}

		return '' === $current ? $addition : $current . ' ' . $addition;
	}

	/**
	 * @return WP_Error|array<int,int|string>
	 */
	private function assign_categories( int $post_id, string $categories ): WP_Error|array {
		$terms = array_map( 'sanitize_text_field', Free_Materials_CSV_Parser::split_list( $categories ) );
		if ( ! $terms ) {
			return array();
		}

		return wp_set_object_terms( $post_id, $terms, Free_Materials_Content_Domain::TAXONOMY, false );
	}

	private function find_existing_post_id( string $external_id ): int {
		if ( '' === $external_id ) {
			return 0;
		}

		$post_ids = get_posts(
			array(
				'fields'         => 'ids',
				'meta_key'       => self::EXTERNAL_ID_META_KEY,
				'meta_value'     => $external_id,
				'post_status'    => 'any',
				'post_type'      => Free_Materials_Content_Domain::POST_TYPE,
				'posts_per_page' => 1,
			)
		);

		return $post_ids ? (int) $post_ids[0] : 0;
	}

	/**
	 * @param array<string,string> $data CSV row.
	 */
	private function fingerprint( array $data ): string {
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	private function boolean_value( string $value ): bool {
		return in_array( mb_strtolower( $value ), array( '1', 'sim', 'yes', 'true' ), true );
	}

	/**
	 * @param array<string,mixed> $row Parsed row.
	 * @return array<string,mixed>
	 */
	private function report_row( array $row, string $status, int $post_id, string $message, string $media_status, int $attachment_id, string $post_status ): array {
		$data = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : array();

		return array(
			'line'          => (int) ( $row['line'] ?? 0 ),
			'external_id'   => (string) ( $data['id_externo'] ?? '' ),
			'title'         => (string) ( $data['titulo'] ?? '' ),
			'status'        => $status,
			'post_id'       => $post_id,
			'message'       => $message,
			'media_status'  => $media_status,
			'attachment_id' => $attachment_id,
			'post_status'   => $post_status,
		);
	}
}
