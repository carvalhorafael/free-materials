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
		$this->assertSame( free_materials_brevo_list_id_meta_key(), '_brevo_leads_capture_list_id' );
		$this->assertSame( free_materials_brevo_delivery_url_meta_key(), '_brevo_leads_capture_delivery_url' );
		$this->assertTrue( $registered_meta['_executive_signal_material_capture_label']['show_in_rest'] );
		$this->assertTrue( $registered_meta['_brevo_leads_capture_list_id']['show_in_rest'] );
		$this->assertTrue( $registered_meta['_brevo_leads_capture_delivery_url']['show_in_rest'] );
	}

	public function test_meta_box_renders_capture_fields(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Operating guide',
				'post_status' => 'publish',
				'post_type'   => free_materials_post_type(),
			)
		);

		update_post_meta( $post_id, free_materials_cta_label_meta_key(), 'Receive guide' );
		update_post_meta( $post_id, free_materials_brevo_list_id_meta_key(), '42' );
		update_post_meta( $post_id, free_materials_brevo_delivery_url_meta_key(), 'https://example.com/delivery' );

		ob_start();
		free_materials()->content_domain()->render_meta_box( get_post( $post_id ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'name="free_materials_cta_label"', $output );
		$this->assertStringContainsString( 'name="free_materials_brevo_list_id"', $output );
		$this->assertStringContainsString( 'name="free_materials_brevo_delivery_url"', $output );
		$this->assertStringContainsString( 'value="Receive guide"', $output );
		$this->assertStringContainsString( 'value="42"', $output );
		$this->assertStringContainsString( 'value="https://example.com/delivery"', $output );
	}

	public function test_save_meta_box_updates_and_deletes_values(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Capture guide',
				'post_status' => 'publish',
				'post_type'   => free_materials_post_type(),
			)
		);

		$_POST[ Free_Materials_Content_Domain::META_BOX_NONCE_NAME ] = wp_create_nonce( Free_Materials_Content_Domain::META_BOX_NONCE_ACTION );
		$_POST['free_materials_cta_label']                          = 'Download now';
		$_POST['free_materials_brevo_list_id']                      = '77';
		$_POST['free_materials_brevo_delivery_url']                 = 'https://example.com/asset';

		free_materials()->content_domain()->save_meta_box( $post_id );

		$this->assertSame( 'Download now', get_post_meta( $post_id, free_materials_cta_label_meta_key(), true ) );
		$this->assertSame( '77', get_post_meta( $post_id, free_materials_brevo_list_id_meta_key(), true ) );
		$this->assertSame( 'https://example.com/asset', get_post_meta( $post_id, free_materials_brevo_delivery_url_meta_key(), true ) );

		$_POST['free_materials_cta_label']          = '';
		$_POST['free_materials_brevo_list_id']      = '';
		$_POST['free_materials_brevo_delivery_url'] = '';

		free_materials()->content_domain()->save_meta_box( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, free_materials_cta_label_meta_key(), true ) );
		$this->assertSame( '', get_post_meta( $post_id, free_materials_brevo_list_id_meta_key(), true ) );
		$this->assertSame( '', get_post_meta( $post_id, free_materials_brevo_delivery_url_meta_key(), true ) );
	}
}
