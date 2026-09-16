<?php
/**
 * Importer integration tests.
 *
 * @package Free_Materials
 */

final class ImporterTest extends WP_UnitTestCase {
	private Free_Materials_CSV_Parser $parser;

	private Free_Materials_Content_Domain $content_domain;

	/** @var string[] */
	private array $temp_files = array();

	/** @var array<int,array{url:string,post_id:int}> */
	private array $media_calls = array();

	public function set_up(): void {
		parent::set_up();

		$this->parser         = new Free_Materials_CSV_Parser();
		$this->content_domain = new Free_Materials_Content_Domain();
		$this->media_calls    = array();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		foreach ( $this->temp_files as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}

		$this->temp_files = array();

		parent::tear_down();
	}

	public function test_a_row_becomes_a_draft_material_with_its_whole_sheet(): void {
		$report = $this->import(
			array(
				$this->row(
					array(
						'id_externo'      => 'mat-1',
						'titulo'          => 'Checklist de revisão',
						'resumo'          => 'Uma lista prática.',
						'conteudo'        => '<p>Como usar.</p>',
						'categorias'      => 'Redação|Simulados',
						'formato'         => 'checklist',
						'paginas'         => '12',
						'tamanho_arquivo' => '1,8 MB',
						'nivel'           => 'Intermediário',
						'destaques'       => 'Rotina semanal|Onde você perdeu pontos',
						'destaque'        => 'sim',
						'downloads'       => '1847',
						'url_entrega'     => 'https://exemplo.com/a.pdf',
						'rotulo_cta'      => 'Baixar checklist',
					)
				),
			)
		);

		$this->assertSame( 1, $report['summary']['created'] );

		$post_id = $report['rows'][0]['post_id'];
		$post    = get_post( $post_id );

		$this->assertSame( free_materials_post_type(), $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Checklist de revisão', $post->post_title );
		$this->assertSame( 'Uma lista prática.', $post->post_excerpt );
		$this->assertStringContainsString( 'Como usar.', $post->post_content );

		$this->assertSame( 'checklist', get_post_meta( $post_id, free_materials_format_meta_key(), true ) );
		$this->assertSame( 12, (int) get_post_meta( $post_id, free_materials_pages_meta_key(), true ) );
		$this->assertSame( '1,8 MB', get_post_meta( $post_id, free_materials_file_size_meta_key(), true ) );
		$this->assertSame( 'Intermediário', get_post_meta( $post_id, free_materials_level_meta_key(), true ) );
		$this->assertSame( 1847, (int) get_post_meta( $post_id, free_materials_downloads_meta_key(), true ) );
		$this->assertSame( '1', get_post_meta( $post_id, free_materials_featured_meta_key(), true ) );
		$this->assertSame( 'https://exemplo.com/a.pdf', get_post_meta( $post_id, free_materials_delivery_url_meta_key(), true ) );
		$this->assertSame( 'Baixar checklist', get_post_meta( $post_id, free_materials_cta_label_meta_key(), true ) );

		$this->assertSame(
			array( 'Rotina semanal', 'Onde você perdeu pontos' ),
			get_post_meta( $post_id, free_materials_highlights_meta_key(), true )
		);

		$terms = wp_get_object_terms( $post_id, free_materials_taxonomy(), array( 'fields' => 'names' ) );
		sort( $terms );
		$this->assertSame( array( 'Redação', 'Simulados' ), $terms );
	}

	/**
	 * Running the same sheet twice is the normal way a migration goes, and it
	 * must not leave two of everything behind.
	 */
	public function test_reimporting_the_same_sheet_skips_instead_of_duplicating(): void {
		$rows = array( $this->row( array( 'id_externo' => 'mat-1' ) ) );

		$first  = $this->import( $rows );
		$second = $this->import( $rows );

		$this->assertSame( 1, $first['summary']['created'] );
		$this->assertSame( 0, $second['summary']['created'] );
		$this->assertSame( 1, $second['summary']['skipped'] );
		$this->assertSame( $first['rows'][0]['post_id'], $second['rows'][0]['post_id'] );

		$this->assertCount(
			1,
			get_posts(
				array(
					'fields'         => 'ids',
					'post_status'    => 'any',
					'post_type'      => free_materials_post_type(),
					'posts_per_page' => -1,
				)
			)
		);
	}

	public function test_update_mode_rewrites_the_existing_material(): void {
		$first = $this->import( array( $this->row( array( 'id_externo' => 'mat-1', 'titulo' => 'Antes' ) ) ) );

		$second = $this->import(
			array( $this->row( array( 'id_externo' => 'mat-1', 'titulo' => 'Depois', 'nivel' => 'Avançado' ) ) ),
			array( 'existing_mode' => 'update' )
		);

		$post_id = $first['rows'][0]['post_id'];

		$this->assertSame( 1, $second['summary']['updated'] );
		$this->assertSame( $post_id, $second['rows'][0]['post_id'] );
		$this->assertSame( 'Depois', get_post( $post_id )->post_title );
		$this->assertSame( 'Avançado', get_post_meta( $post_id, free_materials_level_meta_key(), true ) );
	}

	/**
	 * The external ID is how a row finds its material again, so casing in the
	 * sheet must not create a second one.
	 */
	public function test_the_external_id_matches_regardless_of_casing(): void {
		$this->import( array( $this->row( array( 'id_externo' => 'MAT-1' ) ) ) );
		$second = $this->import( array( $this->row( array( 'id_externo' => 'mat-1' ) ) ) );

		$this->assertSame( 1, $second['summary']['skipped'] );
	}

	public function test_an_invalid_row_is_reported_and_writes_nothing(): void {
		$report = $this->import(
			array(
				$this->row( array( 'id_externo' => '', 'titulo' => 'Sem identificador' ) ),
				$this->row( array( 'id_externo' => 'mat-2' ) ),
			)
		);

		$this->assertSame( 1, $report['summary']['invalid'] );
		$this->assertSame( 1, $report['summary']['created'] );
		$this->assertSame( 'invalid', $report['rows'][0]['status'] );
		$this->assertSame( 0, $report['rows'][0]['post_id'] );

		$this->assertCount(
			1,
			get_posts(
				array(
					'fields'         => 'ids',
					'post_status'    => 'any',
					'post_type'      => free_materials_post_type(),
					'posts_per_page' => -1,
				)
			)
		);
	}

	public function test_publish_mode_holds_back_a_material_with_nowhere_to_deliver(): void {
		$report = $this->import(
			array(
				$this->row( array( 'id_externo' => 'mat-1', 'url_entrega' => 'https://exemplo.com/a.pdf' ) ),
				$this->row( array( 'id_externo' => 'mat-2', 'url_entrega' => '' ) ),
			),
			array( 'publication_mode' => 'publish' )
		);

		$this->assertSame( 'publish', get_post_status( $report['rows'][0]['post_id'] ) );
		$this->assertSame( 'draft', get_post_status( $report['rows'][1]['post_id'] ) );
		$this->assertStringContainsString( 'url_entrega', $report['rows'][1]['message'] );
		$this->assertSame( 1, $report['summary']['published'] );
		$this->assertSame( 1, $report['summary']['drafts'] );
	}

	public function test_respect_csv_mode_follows_the_sheet(): void {
		$report = $this->import(
			array(
				$this->row( array( 'id_externo' => 'mat-1', 'status_publicacao' => 'pending' ) ),
				$this->row( array( 'id_externo' => 'mat-2', 'status_publicacao' => '' ) ),
			),
			array( 'publication_mode' => 'respect_csv' )
		);

		$this->assertSame( 'pending', get_post_status( $report['rows'][0]['post_id'] ) );
		$this->assertSame( 'draft', get_post_status( $report['rows'][1]['post_id'] ) );
	}

	public function test_the_default_mode_keeps_everything_as_a_draft(): void {
		$report = $this->import(
			array( $this->row( array( 'id_externo' => 'mat-1', 'status_publicacao' => 'publish' ) ) )
		);

		$this->assertSame( 'draft', get_post_status( $report['rows'][0]['post_id'] ) );
	}

	public function test_the_cover_is_sideloaded_and_becomes_the_featured_image(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		$report = $this->import(
			array( $this->row( array( 'id_externo' => 'mat-1', 'imagem_url' => 'https://exemplo.com/capa.jpg' ) ) ),
			array(),
			static fn(): int => $attachment_id
		);

		$post_id = $report['rows'][0]['post_id'];

		$this->assertSame( 'imported', $report['rows'][0]['media_status'] );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $post_id ) );
		$this->assertSame( 'https://exemplo.com/capa.jpg', get_post_meta( $post_id, Free_Materials_Importer::IMAGE_URL_META_KEY, true ) );
	}

	/**
	 * A cover that cannot be fetched is worth reporting, but the material and
	 * its whole sheet are still worth keeping.
	 */
	public function test_a_failed_cover_does_not_lose_the_material(): void {
		$report = $this->import(
			array( $this->row( array( 'id_externo' => 'mat-1', 'imagem_url' => 'https://exemplo.com/capa.jpg' ) ) ),
			array(),
			static fn(): WP_Error => new WP_Error( 'http_404', 'Não encontrado' )
		);

		$this->assertSame( 1, $report['summary']['created'] );
		$this->assertSame( 'failed', $report['rows'][0]['media_status'] );
		$this->assertStringContainsString( 'Não encontrado', $report['rows'][0]['message'] );
		$this->assertGreaterThan( 0, $report['rows'][0]['post_id'] );
	}

	/**
	 * Updating a sheet should not re-download a cover that is already there.
	 */
	public function test_an_unchanged_cover_is_not_downloaded_again_on_update(): void {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$rows          = array( $this->row( array( 'id_externo' => 'mat-1', 'imagem_url' => 'https://exemplo.com/capa.jpg' ) ) );
		$downloads     = 0;
		$callback      = function () use ( $attachment_id, &$downloads ): int {
			++$downloads;

			return $attachment_id;
		};

		$this->import( $rows, array(), $callback );
		$second = $this->import( $rows, array( 'existing_mode' => 'update' ), $callback );

		$this->assertSame( 1, $downloads );
		$this->assertSame( 'imported', $second['rows'][0]['media_status'] );
	}

	public function test_every_material_carries_where_it_came_from(): void {
		$report  = $this->import( array( $this->row( array( 'id_externo' => 'MAT-1' ) ) ), array(), null, 'lote-julho.csv' );
		$post_id = $report['rows'][0]['post_id'];

		$this->assertSame( 'mat-1', get_post_meta( $post_id, Free_Materials_Importer::EXTERNAL_ID_META_KEY, true ) );
		$this->assertSame( $report['batch_id'], get_post_meta( $post_id, Free_Materials_Importer::BATCH_ID_META_KEY, true ) );
		$this->assertSame( 'lote-julho.csv', get_post_meta( $post_id, Free_Materials_Importer::SOURCE_META_KEY, true ) );
		$this->assertNotEmpty( get_post_meta( $post_id, Free_Materials_Importer::FINGERPRINT_META_KEY, true ) );
	}

	/**
	 * The tracking meta is internal bookkeeping, not editorial content.
	 */
	public function test_tracking_meta_stays_out_of_rest(): void {
		( new Free_Materials_Importer( $this->parser, $this->content_domain ) )->register_meta();

		$registered = get_registered_meta_keys( 'post', free_materials_post_type() );

		foreach ( Free_Materials_Importer::tracking_meta_keys() as $meta_key ) {
			$this->assertArrayHasKey( $meta_key, $registered );
			$this->assertFalse( $registered[ $meta_key ]['show_in_rest'] );
		}
	}

	public function test_a_file_error_stops_the_whole_import(): void {
		$path   = $this->write_file( "id_externo,titulo\nmat-1,Título\n" );
		$result = ( new Free_Materials_Importer( $this->parser, $this->content_domain ) )->import( $path, 'materiais.csv' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * @param array<int,array<string,string>> $rows    CSV rows.
	 * @param array<string,string>            $options Import options.
	 * @return array<string,mixed>
	 */
	private function import( array $rows, array $options = array(), ?callable $media_callback = null, string $source = 'materiais.csv' ): array {
		$importer = new Free_Materials_Importer(
			$this->parser,
			$this->content_domain,
			$media_callback ?? static fn(): WP_Error => new WP_Error( 'no_media', 'Mídia desativada no teste.' )
		);

		$report = $importer->import( $this->write_csv( $rows ), $source, $options );

		$this->assertNotWPError( $report );

		return $report;
	}

	/**
	 * @param array<string,string> $overrides Column overrides.
	 * @return array<string,string>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array_fill_keys( Free_Materials_CSV_Parser::headers(), '' ),
			array(
				'id_externo'  => 'mat-1',
				'titulo'      => 'Checklist de revisão',
				'resumo'      => 'Uma lista prática.',
				'categorias'  => 'Redação',
				'url_entrega' => 'https://exemplo.com/a.pdf',
			),
			$overrides
		);
	}

	/**
	 * @param array<int,array<string,string>> $rows CSV rows.
	 */
	private function write_csv( array $rows ): string {
		$lines = array( implode( ',', Free_Materials_CSV_Parser::headers() ) );

		foreach ( $rows as $row ) {
			$ordered = array();
			foreach ( Free_Materials_CSV_Parser::headers() as $header ) {
				$ordered[] = '"' . str_replace( '"', '""', $row[ $header ] ?? '' ) . '"';
			}

			$lines[] = implode( ',', $ordered );
		}

		return $this->write_file( implode( "\n", $lines ) . "\n" );
	}

	private function write_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'fm-importer-test-' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}
}
