<?php
/**
 * Content domain integration tests.
 *
 * @package Free_Materials
 */

final class ContentDomainTest extends WP_UnitTestCase {
	public function test_post_type_is_registered_with_portable_contract(): void {
		$post_type = get_post_type_object( free_materials_post_type() );

		$this->assertNotNull( $post_type );
		$this->assertSame( 'material_gratuito', free_materials_post_type() );
		$this->assertTrue( $post_type->public );
		$this->assertFalse( $post_type->has_archive );
		$this->assertTrue( $post_type->show_in_rest );
		$this->assertSame( 'materiais-gratuitos', $post_type->rewrite['slug'] );
		$this->assertTrue( post_type_supports( free_materials_post_type(), 'title' ) );
		$this->assertTrue( post_type_supports( free_materials_post_type(), 'editor' ) );
		$this->assertTrue( post_type_supports( free_materials_post_type(), 'thumbnail' ) );
		$this->assertTrue( post_type_supports( free_materials_post_type(), 'excerpt' ) );
		// Required for the registered meta to reach the REST response at all.
		$this->assertTrue( post_type_supports( free_materials_post_type(), 'custom-fields' ) );
	}

	public function test_material_details_metadata_round_trips_through_rest(): void {
		// The WordPress test suite unregisters meta keys between tests, so the
		// registration has to be replayed before exercising REST.
		free_materials()->content_domain()->register_meta();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => free_materials_post_type(),
			)
		);

		update_post_meta( $post_id, free_materials_format_meta_key(), 'pdf' );
		update_post_meta( $post_id, free_materials_pages_meta_key(), 14 );
		update_post_meta( $post_id, free_materials_highlights_meta_key(), array( 'Um', 'Dois' ) );
		update_post_meta( $post_id, free_materials_featured_meta_key(), true );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/' . free_materials_post_type() . '/' . $post_id );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'meta', $data );
		$this->assertSame( 'pdf', $data['meta'][ free_materials_format_meta_key() ] );
		$this->assertSame( 14, $data['meta'][ free_materials_pages_meta_key() ] );
		$this->assertSame( array( 'Um', 'Dois' ), $data['meta'][ free_materials_highlights_meta_key() ] );
		$this->assertTrue( $data['meta'][ free_materials_featured_meta_key() ] );
	}

	public function test_taxonomy_is_registered_with_portable_contract(): void {
		$taxonomy = get_taxonomy( free_materials_taxonomy() );

		$this->assertNotFalse( $taxonomy );
		$this->assertSame( 'material_categoria', free_materials_taxonomy() );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertTrue( $taxonomy->show_in_rest );
		$this->assertContains( free_materials_post_type(), $taxonomy->object_type );
		$this->assertSame( 'materiais-gratuitos/categoria', $taxonomy->rewrite['slug'] );
	}

	public function test_metadata_is_registered_with_original_keys(): void {
		free_materials()->content_domain()->register_meta();

		$registered_meta = get_registered_meta_keys( 'post', free_materials_post_type() );

		$this->assertArrayHasKey( '_executive_signal_material_capture_label', $registered_meta );
		$this->assertArrayHasKey( '_brevo_leads_capture_list_id', $registered_meta );
		$this->assertArrayHasKey( '_brevo_leads_capture_delivery_url', $registered_meta );
		$this->assertSame( free_materials_cta_label_meta_key(), '_executive_signal_material_capture_label' );
		$this->assertSame( free_materials_capture_destination_meta_key(), '_brevo_leads_capture_list_id' );
		$this->assertSame( free_materials_delivery_url_meta_key(), '_brevo_leads_capture_delivery_url' );
		$this->assertSame( free_materials_brevo_list_id_meta_key(), '_brevo_leads_capture_list_id' );
		$this->assertSame( free_materials_brevo_delivery_url_meta_key(), '_brevo_leads_capture_delivery_url' );
		$this->assertTrue( $registered_meta['_executive_signal_material_capture_label']['show_in_rest'] );
		$this->assertTrue( $registered_meta['_brevo_leads_capture_list_id']['show_in_rest'] );
		$this->assertTrue( $registered_meta['_brevo_leads_capture_delivery_url']['show_in_rest'] );
	}

	public function test_material_details_metadata_is_registered(): void {
		free_materials()->content_domain()->register_meta();

		$registered_meta = get_registered_meta_keys( 'post', free_materials_post_type() );

		$this->assertArrayHasKey( '_free_materials_format', $registered_meta );
		$this->assertArrayHasKey( '_free_materials_pages', $registered_meta );
		$this->assertArrayHasKey( '_free_materials_file_size', $registered_meta );
		$this->assertArrayHasKey( '_free_materials_level', $registered_meta );
		$this->assertArrayHasKey( '_free_materials_highlights', $registered_meta );
		$this->assertArrayHasKey( '_free_materials_featured', $registered_meta );
		$this->assertArrayHasKey( '_free_materials_downloads', $registered_meta );

		// The plugin owns these, so they carry its own prefix instead of the
		// legacy keys kept for portability.
		$this->assertSame( '_free_materials_format', free_materials_format_meta_key() );
		$this->assertSame( '_free_materials_pages', free_materials_pages_meta_key() );
		$this->assertSame( '_free_materials_file_size', free_materials_file_size_meta_key() );
		$this->assertSame( '_free_materials_level', free_materials_level_meta_key() );
		$this->assertSame( '_free_materials_highlights', free_materials_highlights_meta_key() );
		$this->assertSame( '_free_materials_featured', free_materials_featured_meta_key() );
		$this->assertSame( '_free_materials_downloads', free_materials_downloads_meta_key() );

		$this->assertTrue( $registered_meta['_free_materials_format']['show_in_rest'] );
		$this->assertSame( 'integer', $registered_meta['_free_materials_pages']['type'] );
		$this->assertSame( 'boolean', $registered_meta['_free_materials_featured']['type'] );
		$this->assertSame( 'array', $registered_meta['_free_materials_highlights']['type'] );
		$this->assertSame( 'integer', $registered_meta['_free_materials_downloads']['type'] );
	}

	public function test_format_is_constrained_to_the_known_list(): void {
		$domain = free_materials()->content_domain();

		$this->assertSame( 'pdf', $domain->sanitize_format( 'pdf' ) );
		$this->assertSame( 'planner', $domain->sanitize_format( 'PLANNER' ) );
		$this->assertSame( '', $domain->sanitize_format( 'executavel' ) );
		$this->assertSame( '', $domain->sanitize_format( array( 'pdf' ) ) );
		$this->assertArrayHasKey( 'pdf', free_materials_formats() );
		$this->assertSame( '', free_materials_format_label( 'inexistente' ) );
	}

	public function test_formats_can_be_extended_by_a_site(): void {
		$add = static function ( array $formats ): array {
			$formats['audiobook'] = 'Audiobook';

			return $formats;
		};

		add_filter( 'free_materials_formats', $add );

		$this->assertSame( 'audiobook', free_materials()->content_domain()->sanitize_format( 'audiobook' ) );
		$this->assertSame( 'Audiobook', free_materials_format_label( 'audiobook' ) );

		remove_filter( 'free_materials_formats', $add );

		$this->assertSame( '', free_materials()->content_domain()->sanitize_format( 'audiobook' ) );
	}

	public function test_highlights_are_normalised_into_clean_lines(): void {
		$domain = free_materials()->content_domain();

		$this->assertSame(
			array( 'Estrutura da redação', 'Repertório pronto' ),
			$domain->sanitize_highlights( "Estrutura da redação

  Repertório pronto  
" )
		);
		$this->assertSame(
			array( 'Um', 'Dois' ),
			$domain->sanitize_highlights( array( 'Um', '', '  ', 'Dois', array( 'ignorado' ) ) )
		);
		$this->assertSame( array(), $domain->sanitize_highlights( null ) );
		$this->assertCount( 12, $domain->sanitize_highlights( array_fill( 0, 20, 'item' ) ) );
		// sanitize_text_field() drops the script tag together with its contents,
		// so the line collapses to nothing and is discarded.
		$this->assertSame( array(), $domain->sanitize_highlights( '<script>alert(1)</script>' ) );
		$this->assertSame( array( 'Negrito' ), $domain->sanitize_highlights( '<strong>Negrito</strong>' ) );
	}

	public function test_material_details_meta_box_is_registered_for_the_post_type(): void {
		$details = free_materials()->material_details();

		$this->assertSame( 10, has_action( 'add_meta_boxes', array( $details, 'register_meta_box' ) ) );
		$this->assertSame(
			10,
			has_action( 'save_post_' . free_materials_post_type(), array( $details, 'save' ) )
		);
	}

	public function test_plugin_does_not_register_capture_admin_meta_box(): void {
		$content_domain = free_materials()->content_domain();

		$this->assertFalse( has_action( 'add_meta_boxes', array( $content_domain, 'register_meta_box' ) ) );
		$this->assertFalse( has_action( 'save_post_' . free_materials_post_type(), array( $content_domain, 'save_meta_box' ) ) );
	}
}
