<?php
/**
 * CSV parsing and validation for free material imports.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_CSV_Parser {
	public const MAX_ROWS          = 500;
	public const PREVIEW_ROW_LIMIT = 50;
	public const MAX_HIGHLIGHTS    = 12;
	public const LIST_SEPARATOR    = '|';

	/**
	 * Canonical column order of the import template.
	 *
	 * @return string[]
	 */
	public static function headers(): array {
		return array(
			'id_externo',
			'titulo',
			'resumo',
			'conteudo',
			'categorias',
			'formato',
			'paginas',
			'tamanho_arquivo',
			'nivel',
			'destaques',
			'destaque',
			'downloads',
			'url_entrega',
			'lista_destino',
			'rotulo_cta',
			'imagem_url',
			'status_publicacao',
		);
	}

	/**
	 * Read the first line to learn the delimiter and the column names.
	 *
	 * @return array{headers:string[],delimiter:string}|WP_Error
	 */
	public function inspect_header( string $path ): array|WP_Error {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'unreadable_file', __( 'O arquivo não pôde ser lido pelo WordPress.', 'free-materials' ) );
		}

		$first_line = fgets( $handle );
		fclose( $handle );

		if ( false === $first_line || str_contains( $first_line, "\0" ) ) {
			return new WP_Error( 'invalid_file', __( 'O arquivo não contém um cabeçalho CSV válido.', 'free-materials' ) );
		}

		// Excel writes a BOM on the first cell, which would break the match.
		$first_line = preg_replace( '/^\xEF\xBB\xBF/', '', $first_line ) ?? $first_line;

		// Brazilian Excel defaults to the semicolon.
		$delimiter = substr_count( $first_line, ';' ) > substr_count( $first_line, ',' ) ? ';' : ',';
		$headers   = array_map( 'trim', str_getcsv( $first_line, $delimiter, '"', '' ) );

		return array(
			'headers'   => $headers,
			'delimiter' => $delimiter,
		);
	}

	/**
	 * Summarise a file for the preview screen, without touching the database.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function analyze( string $path ): array|WP_Error {
		$parsed = $this->parse_rows( $path );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$valid    = 0;
		$warnings = 0;
		$invalid  = 0;

		foreach ( $parsed['rows'] as $row ) {
			if ( ! empty( $row['errors'] ) ) {
				++$invalid;
			} else {
				++$valid;
			}

			if ( ! empty( $row['warnings'] ) ) {
				++$warnings;
			}
		}

		return array(
			'summary'       => array(
				'total'        => count( $parsed['rows'] ),
				'valid'        => $valid,
				'with_warning' => $warnings,
				'invalid'      => $invalid,
			),
			'rows'          => array_slice( $parsed['rows'], 0, self::PREVIEW_ROW_LIMIT ),
			'file_errors'   => $parsed['file_errors'],
			'preview_limit' => self::PREVIEW_ROW_LIMIT,
			'truncated'     => $parsed['truncated'],
		);
	}

	/**
	 * @return array{rows:array<int,array<string,mixed>>,file_errors:string[],truncated:bool}|WP_Error
	 */
	public function parse_rows( string $path ): array|WP_Error {
		$inspection = $this->inspect_header( $path );
		if ( is_wp_error( $inspection ) ) {
			return $inspection;
		}

		if ( ! self::has_canonical_headers( $inspection['headers'] ) ) {
			return new WP_Error(
				'invalid_headers',
				__( 'As colunas não correspondem ao modelo do plugin. Baixe o modelo e preserve os cabeçalhos.', 'free-materials' )
			);
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'unreadable_file', __( 'O arquivo não pôde ser lido pelo WordPress.', 'free-materials' ) );
		}

		fgetcsv( $handle, 0, $inspection['delimiter'], '"', '' );

		$line_number  = 1;
		$rows         = array();
		$external_ids = array();
		$file_errors  = array();
		$truncated    = false;

		while ( false !== ( $values = fgetcsv( $handle, 0, $inspection['delimiter'], '"', '' ) ) ) {
			++$line_number;

			if ( $this->is_empty_row( $values ) ) {
				continue;
			}

			if ( count( $rows ) >= self::MAX_ROWS ) {
				$truncated     = true;
				$file_errors[] = sprintf(
					/* translators: %d: maximum number of CSV rows. */
					__( 'O arquivo excede o limite de %d materiais por importação.', 'free-materials' ),
					self::MAX_ROWS
				);
				break;
			}

			$rows[] = $this->analyze_row( $inspection['headers'], $values, $line_number, $external_ids );
		}

		fclose( $handle );

		if ( ! $rows ) {
			$file_errors[] = __( 'A planilha não contém nenhum material.', 'free-materials' );
		}

		return array(
			'rows'        => $rows,
			'file_errors' => $file_errors,
			'truncated'   => $truncated,
		);
	}

	/**
	 * Whether a file carries exactly the template's columns, in any order.
	 *
	 * @param string[] $headers Uploaded CSV headers.
	 */
	public static function has_canonical_headers( array $headers ): bool {
		$canonical_headers = self::headers();

		if ( count( $headers ) !== count( array_unique( $headers ) ) || count( $headers ) !== count( $canonical_headers ) ) {
			return false;
		}

		sort( $headers );
		sort( $canonical_headers );

		return $canonical_headers === $headers;
	}

	/**
	 * Split a pipe separated cell into clean values.
	 *
	 * @return string[]
	 */
	public static function split_list( string $value ): array {
		$parts = preg_split( '/\s*\\' . self::LIST_SEPARATOR . '\s*/', $value );

		return array_values( array_filter( array_map( 'trim', $parts ?: array() ), static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * @param string[]          $headers      CSV headers.
	 * @param array<int,string> $values       CSV row values.
	 * @param array<string,int> $external_ids Previously seen external IDs.
	 * @return array<string,mixed>
	 */
	private function analyze_row( array $headers, array $values, int $line_number, array &$external_ids ): array {
		$errors   = array();
		$warnings = array();

		if ( count( $headers ) !== count( $values ) ) {
			return array(
				'line'     => $line_number,
				'data'     => array(),
				'errors'   => array( __( 'A quantidade de valores não corresponde à quantidade de colunas.', 'free-materials' ) ),
				'warnings' => array(),
			);
		}

		$data = array_combine( $headers, array_map( array( $this, 'normalize_value' ), $values ) );
		if ( false === $data ) {
			$data = array();
		}

		foreach ( array( 'id_externo', 'titulo' ) as $required_field ) {
			if ( '' === ( $data[ $required_field ] ?? '' ) ) {
				$errors[] = sprintf(
					/* translators: %s: required CSV column name. */
					__( 'O campo %s é obrigatório.', 'free-materials' ),
					$required_field
				);
			}
		}

		$external_id = mb_strtolower( $data['id_externo'] ?? '' );
		if ( '' !== $external_id ) {
			if ( isset( $external_ids[ $external_id ] ) ) {
				$errors[] = sprintf(
					/* translators: %d: line number where the duplicate ID first appeared. */
					__( 'O id_externo está repetido; ele apareceu primeiro na linha %d.', 'free-materials' ),
					$external_ids[ $external_id ]
				);
			} else {
				$external_ids[ $external_id ] = $line_number;
			}
		}

		$format = mb_strtolower( $data['formato'] ?? '' );
		if ( '' !== $format && ! array_key_exists( $format, Free_Materials_Content_Domain::formats() ) ) {
			$errors[] = sprintf(
				/* translators: %s: accepted format slugs. */
				__( 'O campo formato possui um valor inválido. Valores aceitos: %s.', 'free-materials' ),
				implode( ', ', array_keys( Free_Materials_Content_Domain::formats() ) )
			);
		}

		$this->validate_choice( $data, 'status_publicacao', array( '', 'draft', 'pending', 'publish' ), $errors );
		$this->validate_choice( $data, 'destaque', array( '', '0', '1', 'nao', 'não', 'no', 'false', 'sim', 'yes', 'true' ), $errors );

		$this->validate_positive_integer( $data, 'paginas', $errors );
		$this->validate_positive_integer( $data, 'downloads', $errors );

		foreach ( array( 'url_entrega', 'imagem_url' ) as $url_field ) {
			$url = $data[ $url_field ] ?? '';
			if ( '' !== $url && ! wp_http_validate_url( $url ) ) {
				$errors[] = sprintf(
					/* translators: %s: CSV URL column name. */
					__( 'O campo %s deve conter uma URL pública válida.', 'free-materials' ),
					$url_field
				);
			}
		}

		if ( count( self::split_list( $data['destaques'] ?? '' ) ) > self::MAX_HIGHLIGHTS ) {
			$errors[] = sprintf(
				/* translators: %d: maximum number of highlights. */
				__( 'O campo destaques aceita no máximo %d itens.', 'free-materials' ),
				self::MAX_HIGHLIGHTS
			);
		}

		if ( '' === ( $data['imagem_url'] ?? '' ) ) {
			$warnings[] = __( 'Nenhuma capa foi informada.', 'free-materials' );
		}

		if ( '' === ( $data['categorias'] ?? '' ) ) {
			$warnings[] = __( 'Sem categoria, o material não aparece em nenhum filtro do catálogo.', 'free-materials' );
		}

		if ( '' === ( $data['resumo'] ?? '' ) ) {
			$warnings[] = __( 'Sem resumo, a página do material fica sem a promessa abaixo do título.', 'free-materials' );
		}

		if ( '' === ( $data['url_entrega'] ?? '' ) ) {
			$warnings[] = __( 'Sem url_entrega, quem preencher o formulário não recebe o arquivo.', 'free-materials' );
		}

		if ( 'publish' === ( $data['status_publicacao'] ?? '' ) && '' === ( $data['url_entrega'] ?? '' ) ) {
			$errors[] = __( 'Para publicar, a url_entrega é obrigatória.', 'free-materials' );
		}

		return array(
			'line'     => $line_number,
			'data'     => $data,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * @param array<string,string> $data    Row data.
	 * @param string[]             $allowed Allowed values.
	 * @param string[]             $errors  Row errors.
	 */
	private function validate_choice( array $data, string $field, array $allowed, array &$errors ): void {
		if ( in_array( mb_strtolower( $data[ $field ] ?? '' ), $allowed, true ) ) {
			return;
		}

		$errors[] = sprintf(
			/* translators: 1: CSV column name, 2: allowed values. */
			__( 'O campo %1$s possui um valor inválido. Valores aceitos: %2$s.', 'free-materials' ),
			$field,
			implode( ', ', array_filter( $allowed ) )
		);
	}

	/**
	 * @param array<string,string> $data   Row data.
	 * @param string[]             $errors Row errors.
	 */
	private function validate_positive_integer( array $data, string $field, array &$errors ): void {
		$value = $data[ $field ] ?? '';
		if ( '' === $value ) {
			return;
		}

		if ( ! ctype_digit( $value ) || (int) $value <= 0 ) {
			$errors[] = sprintf(
				/* translators: %s: CSV column name. */
				__( 'O campo %s deve conter um número inteiro maior que zero.', 'free-materials' ),
				$field
			);
		}
	}

	/**
	 * @param array<int,string|null> $values CSV row.
	 */
	private function is_empty_row( array $values ): bool {
		foreach ( $values as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	private function normalize_value( mixed $value ): string {
		return trim( (string) $value );
	}
}
