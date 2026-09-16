<?php
/**
 * CSV parsing and validation rules.
 *
 * @package Free_Materials
 */

final class CSVParserTest extends WP_UnitTestCase {
	private Free_Materials_CSV_Parser $parser;

	/** @var string[] */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();

		$this->parser = new Free_Materials_CSV_Parser();
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

	/**
	 * A sheet saved by Excel in Brazil arrives with a BOM and semicolons.
	 */
	public function test_header_inspection_survives_excel(): void {
		$path = $this->write_file( "\xEF\xBB\xBF" . implode( ';', Free_Materials_CSV_Parser::headers() ) . "\n" );

		$inspection = $this->parser->inspect_header( $path );

		$this->assertIsArray( $inspection );
		$this->assertSame( ';', $inspection['delimiter'] );
		$this->assertSame( 'id_externo', $inspection['headers'][0] );
		$this->assertSame( Free_Materials_CSV_Parser::headers(), $inspection['headers'] );
	}

	public function test_required_columns_are_reported_per_row(): void {
		$path = $this->write_csv(
			array(
				$this->row( array( 'id_externo' => '', 'titulo' => 'Sem identificador' ) ),
				$this->row( array( 'id_externo' => 'mat-2', 'titulo' => '' ) ),
			)
		);

		$rows = $this->parser->parse_rows( $path )['rows'];

		$this->assertStringContainsString( 'id_externo', implode( ' ', $rows[0]['errors'] ) );
		$this->assertStringContainsString( 'titulo', implode( ' ', $rows[1]['errors'] ) );
	}

	public function test_repeated_external_id_points_at_the_first_line(): void {
		$path = $this->write_csv(
			array(
				$this->row( array( 'id_externo' => 'mat-1' ) ),
				$this->row( array( 'id_externo' => 'MAT-1' ) ),
			)
		);

		$rows = $this->parser->parse_rows( $path )['rows'];

		$this->assertSame( array(), $rows[0]['errors'] );
		$this->assertStringContainsString( 'linha 2', implode( ' ', $rows[1]['errors'] ) );
	}

	public function test_format_must_exist_in_the_plugin_list(): void {
		$path = $this->write_csv(
			array(
				$this->row( array( 'id_externo' => 'mat-1', 'formato' => 'checklist' ) ),
				$this->row( array( 'id_externo' => 'mat-2', 'formato' => 'ebook' ) ),
			)
		);

		$rows = $this->parser->parse_rows( $path )['rows'];

		$this->assertSame( array(), $rows[0]['errors'] );
		$this->assertStringContainsString( 'formato', implode( ' ', $rows[1]['errors'] ) );
	}

	/**
	 * Publishing without a delivery URL means collecting leads and handing
	 * them nothing, so the parser refuses it up front.
	 */
	public function test_publishing_requires_a_delivery_url(): void {
		$path = $this->write_csv(
			array(
				$this->row( array( 'id_externo' => 'mat-1', 'status_publicacao' => 'publish', 'url_entrega' => '' ) ),
				$this->row( array( 'id_externo' => 'mat-2', 'status_publicacao' => 'publish', 'url_entrega' => 'https://exemplo.com/a.pdf' ) ),
			)
		);

		$rows = $this->parser->parse_rows( $path )['rows'];

		$this->assertStringContainsString( 'url_entrega', implode( ' ', $rows[0]['errors'] ) );
		$this->assertSame( array(), $rows[1]['errors'] );
	}

	public function test_numbers_and_urls_are_validated(): void {
		$path = $this->write_csv(
			array(
				$this->row( array( 'id_externo' => 'mat-1', 'paginas' => '12,5' ) ),
				$this->row( array( 'id_externo' => 'mat-2', 'downloads' => '-3' ) ),
				$this->row( array( 'id_externo' => 'mat-3', 'imagem_url' => 'nao-e-url' ) ),
			)
		);

		$rows = $this->parser->parse_rows( $path )['rows'];

		$this->assertStringContainsString( 'paginas', implode( ' ', $rows[0]['errors'] ) );
		$this->assertStringContainsString( 'downloads', implode( ' ', $rows[1]['errors'] ) );
		$this->assertStringContainsString( 'imagem_url', implode( ' ', $rows[2]['errors'] ) );
	}

	/**
	 * A missing cover or category is worth saying out loud, but it must not
	 * hold the row back.
	 */
	public function test_missing_optional_content_warns_without_blocking(): void {
		$path = $this->write_csv(
			array(
				$this->row(
					array(
						'id_externo' => 'mat-1',
						'imagem_url' => '',
						'categorias' => '',
						'resumo'     => '',
					)
				),
			)
		);

		$row = $this->parser->parse_rows( $path )['rows'][0];

		$this->assertSame( array(), $row['errors'] );
		$this->assertCount( 4, $row['warnings'] );
	}

	public function test_blank_lines_are_not_rows(): void {
		$path = $this->write_csv( array( $this->row( array( 'id_externo' => 'mat-1' ) ) ), true );

		$parsed = $this->parser->parse_rows( $path );

		$this->assertCount( 1, $parsed['rows'] );
	}

	public function test_a_short_line_is_rejected_instead_of_misaligned(): void {
		$path = $this->write_file(
			implode( ',', Free_Materials_CSV_Parser::headers() ) . "\n" . "mat-1,Um título\n"
		);

		$row = $this->parser->parse_rows( $path )['rows'][0];

		$this->assertNotEmpty( $row['errors'] );
		$this->assertSame( array(), $row['data'] );
	}

	public function test_analysis_counts_and_caps_the_preview(): void {
		$rows = array();
		for ( $index = 1; $index <= Free_Materials_CSV_Parser::PREVIEW_ROW_LIMIT + 5; $index++ ) {
			$rows[] = $this->row( array( 'id_externo' => 'mat-' . $index ) );
		}

		$rows[] = $this->row( array( 'id_externo' => '', 'titulo' => '' ) );

		$analysis = $this->parser->analyze( $this->write_csv( $rows ) );

		$this->assertSame( Free_Materials_CSV_Parser::PREVIEW_ROW_LIMIT + 6, $analysis['summary']['total'] );
		$this->assertSame( 1, $analysis['summary']['invalid'] );
		$this->assertCount( Free_Materials_CSV_Parser::PREVIEW_ROW_LIMIT, $analysis['rows'] );
	}

	public function test_split_list_cleans_the_separated_cells(): void {
		$this->assertSame(
			array( 'Redação', 'Simulados' ),
			Free_Materials_CSV_Parser::split_list( ' Redação | Simulados |' )
		);
		$this->assertSame( array(), Free_Materials_CSV_Parser::split_list( '  ' ) );
	}

	public function test_too_many_highlights_are_rejected(): void {
		$highlights = array();
		for ( $index = 0; $index <= Free_Materials_CSV_Parser::MAX_HIGHLIGHTS; $index++ ) {
			$highlights[] = 'Item ' . $index;
		}

		$path = $this->write_csv(
			array( $this->row( array( 'id_externo' => 'mat-1', 'destaques' => implode( '|', $highlights ) ) ) )
		);

		$row = $this->parser->parse_rows( $path )['rows'][0];

		$this->assertStringContainsString( 'destaques', implode( ' ', $row['errors'] ) );
	}

	/**
	 * @param array<string,string> $overrides Column overrides.
	 * @return array<string,string>
	 */
	private function row( array $overrides = array() ): array {
		$defaults = array_fill_keys( Free_Materials_CSV_Parser::headers(), '' );

		return array_merge(
			$defaults,
			array(
				'id_externo' => 'mat-1',
				'titulo'     => 'Checklist de revisão',
				'resumo'     => 'Uma lista prática.',
				'categorias' => 'Redação',
				'imagem_url' => 'https://exemplo.com/capa.jpg',
			),
			$overrides
		);
	}

	/**
	 * @param array<int,array<string,string>> $rows CSV rows.
	 */
	private function write_csv( array $rows, bool $with_blank_lines = false ): string {
		$lines = array( implode( ',', Free_Materials_CSV_Parser::headers() ) );

		foreach ( $rows as $row ) {
			if ( $with_blank_lines ) {
				$lines[] = str_repeat( ',', count( Free_Materials_CSV_Parser::headers() ) - 1 );
			}

			$ordered = array();
			foreach ( Free_Materials_CSV_Parser::headers() as $header ) {
				$ordered[] = '"' . str_replace( '"', '""', $row[ $header ] ?? '' ) . '"';
			}

			$lines[] = implode( ',', $ordered );
		}

		return $this->write_file( implode( "\n", $lines ) . "\n" );
	}

	private function write_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'fm-csv-test-' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}
}
