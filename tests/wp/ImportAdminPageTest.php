<?php
/**
 * Import admin screen integration tests.
 *
 * @package Free_Materials
 */

final class ImportAdminPageTest extends WP_UnitTestCase {
	private Free_Materials_Import_Admin_Page $page;

	/** @var string[] */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();

		// add_submenu_page writes into globals that outlive a test, so a menu
		// built by an earlier one would answer for this one.
		$GLOBALS['menu']    = array();
		$GLOBALS['submenu'] = array();

		$parser     = new Free_Materials_CSV_Parser();
		$this->page = new Free_Materials_Import_Admin_Page(
			$parser,
			new Free_Materials_Importer( $parser, new Free_Materials_Content_Domain() )
		);
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

	public function test_the_screen_hangs_under_the_materials_menu(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );

		$this->page->register_hooks();
		do_action( 'admin_menu' );

		global $submenu;

		$parent = 'edit.php?post_type=' . free_materials_post_type();

		$this->assertArrayHasKey( $parent, $submenu );
		$this->assertContains(
			Free_Materials_Import_Admin_Page::PAGE_SLUG,
			wp_list_pluck( $submenu[ $parent ], 2 )
		);
	}

	/**
	 * Importing creates and publishes content, so it stays with the capability
	 * that already governs that.
	 */
	public function test_an_editor_does_not_reach_the_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'dashboard' );

		$this->page->register_hooks();
		do_action( 'admin_menu' );

		global $submenu;

		$slugs = wp_list_pluck( $submenu['edit.php?post_type=' . free_materials_post_type()] ?? array(), 2 );

		$this->assertNotContains( Free_Materials_Import_Admin_Page::PAGE_SLUG, $slugs );
	}

	public function test_the_upload_envelope_is_checked_before_anything_is_read(): void {
		$this->assertSame( 'upload_error', $this->page->validate_csv_file( array( 'error' => UPLOAD_ERR_NO_FILE ) ) );

		$this->assertSame(
			'empty_file',
			$this->page->validate_csv_file( array( 'error' => UPLOAD_ERR_OK, 'size' => 0 ) )
		);

		$this->assertSame(
			'file_too_large',
			$this->page->validate_csv_file(
				array(
					'error' => UPLOAD_ERR_OK,
					'size'  => Free_Materials_Import_Admin_Page::MAX_FILE_SIZE + 1,
				)
			)
		);

		$this->assertSame(
			'invalid_file_type',
			$this->page->validate_csv_file(
				array(
					'error' => UPLOAD_ERR_OK,
					'name'  => 'materiais.php',
					'size'  => 100,
				)
			)
		);
	}

	public function test_a_sheet_with_other_columns_is_refused(): void {
		$path = $this->write_file( "coluna_a,coluna_b\n1,2\n" );

		$this->assertSame(
			'invalid_headers',
			$this->page->validate_csv_file( $this->file( $path ) )
		);
	}

	public function test_the_template_headers_are_accepted(): void {
		$path = $this->write_file( "\xEF\xBB\xBF" . implode( ',', Free_Materials_CSV_Parser::headers() ) . "\n" );

		$this->assertSame( 'file_ready', $this->page->validate_csv_file( $this->file( $path ) ) );
	}

	/**
	 * The cleanup runs from a scheduled event carrying a path, so it must
	 * refuse to delete anything it did not create.
	 */
	public function test_cleanup_only_touches_its_own_temporary_files(): void {
		$outsider = $this->write_file( 'não é do importador' );

		$this->page->cleanup_temp_file( $outsider );

		$this->assertFileExists( $outsider );

		$own = trailingslashit( get_temp_dir() ) . Free_Materials_Import_Admin_Page::TEMP_PREFIX . 'test.csv';
		file_put_contents( $own, 'id_externo' );
		$this->temp_files[] = $own;

		$this->page->cleanup_temp_file( $own );

		$this->assertFileDoesNotExist( $own );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function file( string $path ): array {
		return array(
			'error'    => UPLOAD_ERR_OK,
			'name'     => 'materiais.csv',
			'size'     => (int) filesize( $path ),
			'tmp_name' => $path,
		);
	}

	private function write_file( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'fm-admin-test-' );
		file_put_contents( $path, $contents );
		$this->temp_files[] = $path;

		return $path;
	}
}
