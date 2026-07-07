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
		$this->assertSame( free_materials_capture_destination_meta_key(), '_brevo_leads_capture_list_id' );
		$this->assertSame( free_materials_delivery_url_meta_key(), '_brevo_leads_capture_delivery_url' );
		$this->assertSame( free_materials_brevo_list_id_meta_key(), '_brevo_leads_capture_list_id' );
		$this->assertSame( free_materials_brevo_delivery_url_meta_key(), '_brevo_leads_capture_delivery_url' );
		$this->assertTrue( $registered_meta['_executive_signal_material_capture_label']['show_in_rest'] );
		$this->assertTrue( $registered_meta['_brevo_leads_capture_list_id']['show_in_rest'] );
		$this->assertTrue( $registered_meta['_brevo_leads_capture_delivery_url']['show_in_rest'] );
	}

	public function test_plugin_does_not_register_capture_admin_meta_box(): void {
		$content_domain = free_materials()->content_domain();

		$this->assertFalse( has_action( 'add_meta_boxes', array( $content_domain, 'register_meta_box' ) ) );
		$this->assertFalse( has_action( 'save_post_' . free_materials_post_type(), array( $content_domain, 'save_meta_box' ) ) );
	}
}
