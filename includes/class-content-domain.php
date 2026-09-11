<?php
/**
 * Free Materials content domain.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_Content_Domain {
	public const POST_TYPE                    = 'material_gratuito';
	public const TAXONOMY                     = 'material_categoria';
	public const CTA_LABEL_META_KEY           = '_executive_signal_material_capture_label';
	public const BREVO_LIST_ID_META_KEY       = '_brevo_leads_capture_list_id';
	public const BREVO_DELIVERY_URL_META_KEY  = '_brevo_leads_capture_delivery_url';
	public const CAPTURE_DESTINATION_META_KEY = self::BREVO_LIST_ID_META_KEY;
	public const DELIVERY_URL_META_KEY        = self::BREVO_DELIVERY_URL_META_KEY;
	public const MATERIALS_PAGE_PATH          = 'materiais-gratuitos';

	/*
	 * Descriptive metadata about the material itself. These keys are owned by
	 * this plugin, so they use its own prefix instead of the legacy keys kept
	 * above for portability of existing content.
	 */
	public const FORMAT_META_KEY     = '_free_materials_format';
	public const PAGES_META_KEY      = '_free_materials_pages';
	public const FILE_SIZE_META_KEY  = '_free_materials_file_size';
	public const LEVEL_META_KEY      = '_free_materials_level';
	public const HIGHLIGHTS_META_KEY = '_free_materials_highlights';
	public const FEATURED_META_KEY   = '_free_materials_featured';
	public const DOWNLOADS_META_KEY  = '_free_materials_downloads';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_content_types' ) );
		add_action( 'init', array( $this, 'register_meta' ), 11 );
	}

	public function register_content_types(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'has_archive'        => false,
				'hierarchical'       => false,
				'labels'             => $this->post_type_labels(),
				'menu_icon'          => 'dashicons-download',
				'public'             => true,
				'publicly_queryable' => true,
				'query_var'          => true,
				'rewrite'            => array(
					'slug'       => self::MATERIALS_PAGE_PATH,
					'with_front' => false,
				),
				'show_in_rest'       => true,
				/*
				 * 'custom-fields' is what makes WordPress add the `meta` field
				 * to the REST response. Without it the keys registered below
				 * are unreachable over REST even with show_in_rest set on each
				 * one. Every key this plugin registers is protected, so the
				 * editor's Custom Fields panel does not expose them.
				 */
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ),
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'hierarchical'      => true,
				'labels'            => $this->taxonomy_labels(),
				'public'            => true,
				'query_var'         => true,
				'rewrite'           => array(
					'slug'       => self::MATERIALS_PAGE_PATH . '/categoria',
					'with_front' => false,
				),
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'show_ui'           => true,
			)
		);

		add_rewrite_rule(
			'^' . self::MATERIALS_PAGE_PATH . '/categoria/([^/]+)/?$',
			'index.php?' . self::TAXONOMY . '=$matches[1]',
			'top'
		);
	}

	public function register_meta(): void {
		register_post_meta(
			self::POST_TYPE,
			self::CTA_LABEL_META_KEY,
			array(
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::BREVO_LIST_ID_META_KEY,
			array(
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::BREVO_DELIVERY_URL_META_KEY,
			array(
				'auth_callback'     => static function () {
					return current_user_can( 'edit_posts' );
				},
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		$this->register_material_details_meta();
	}

	/**
	 * Register the metadata that describes the material itself.
	 *
	 * Consumers use it to tell a visitor what they are about to download
	 * before asking for their contact details.
	 */
	public function register_material_details_meta(): void {
		$can_edit = static function () {
			return current_user_can( 'edit_posts' );
		};

		register_post_meta(
			self::POST_TYPE,
			self::FORMAT_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'sanitize_callback' => array( $this, 'sanitize_format' ),
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::PAGES_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'integer',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::FILE_SIZE_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::LEVEL_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::HIGHLIGHTS_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_highlights' ),
				'show_in_rest'      => array(
					'schema' => array(
						'items' => array( 'type' => 'string' ),
						'type'  => 'array',
					),
				),
				'single'            => true,
				'type'              => 'array',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::DOWNLOADS_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'integer',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::FEATURED_META_KEY,
			array(
				'auth_callback'     => $can_edit,
				'default'           => false,
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'boolean',
			)
		);
	}

	/**
	 * The formats a material can be delivered in.
	 *
	 * Filterable so a site can add its own without patching the plugin.
	 *
	 * @return array<string, string> Slug keyed labels.
	 */
	public static function formats(): array {
		$formats = array(
			'pdf'       => __( 'PDF', 'free-materials' ),
			'planner'   => __( 'Planner', 'free-materials' ),
			'checklist' => __( 'Checklist', 'free-materials' ),
			'planilha'  => __( 'Spreadsheet', 'free-materials' ),
			'simulado'  => __( 'Mock exam', 'free-materials' ),
			'videoaula' => __( 'Video lesson', 'free-materials' ),
		);

		/**
		 * Filters the list of free material formats.
		 *
		 * @param array<string, string> $formats Slug keyed labels.
		 */
		return (array) apply_filters( 'free_materials_formats', $formats );
	}

	/**
	 * Keep the stored format inside the known list.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_format( $value ): string {
		$slug = sanitize_key( is_scalar( $value ) ? (string) $value : '' );

		return array_key_exists( $slug, self::formats() ) ? $slug : '';
	}

	/**
	 * Normalise the "what is inside" list into clean, non-empty strings.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public function sanitize_highlights( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$highlights = array();

		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$clean = sanitize_text_field( (string) $item );

			if ( '' !== $clean ) {
				$highlights[] = $clean;
			}
		}

		return array_values( array_slice( $highlights, 0, 12 ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function post_type_labels(): array {
		return array(
			'name'                  => _x( 'Free materials', 'Post type general name', 'free-materials' ),
			'singular_name'         => _x( 'Free material', 'Post type singular name', 'free-materials' ),
			'menu_name'             => _x( 'Free materials', 'Admin menu text', 'free-materials' ),
			'name_admin_bar'        => _x( 'Free material', 'Add new on toolbar', 'free-materials' ),
			'add_new'               => __( 'Add new', 'free-materials' ),
			'add_new_item'          => __( 'Add free material', 'free-materials' ),
			'all_items'             => __( 'All materials', 'free-materials' ),
			'archives'              => __( 'Free materials', 'free-materials' ),
			'edit_item'             => __( 'Edit free material', 'free-materials' ),
			'featured_image'        => __( 'Material image', 'free-materials' ),
			'filter_items_list'     => __( 'Filter materials', 'free-materials' ),
			'items_list'            => __( 'Materials list', 'free-materials' ),
			'items_list_navigation' => __( 'Materials list navigation', 'free-materials' ),
			'new_item'              => __( 'New free material', 'free-materials' ),
			'not_found'             => __( 'No materials found.', 'free-materials' ),
			'not_found_in_trash'    => __( 'No materials found in Trash.', 'free-materials' ),
			'remove_featured_image' => __( 'Remove material image', 'free-materials' ),
			'search_items'          => __( 'Search materials', 'free-materials' ),
			'set_featured_image'    => __( 'Set material image', 'free-materials' ),
			'uploaded_to_this_item' => __( 'Uploaded to this material', 'free-materials' ),
			'use_featured_image'    => __( 'Use as material image', 'free-materials' ),
			'view_item'             => __( 'View free material', 'free-materials' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function taxonomy_labels(): array {
		return array(
			'name'              => _x( 'Material categories', 'taxonomy general name', 'free-materials' ),
			'singular_name'     => _x( 'Material category', 'taxonomy singular name', 'free-materials' ),
			'add_new_item'      => __( 'Add material category', 'free-materials' ),
			'all_items'         => __( 'All categories', 'free-materials' ),
			'back_to_items'     => __( 'Back to categories', 'free-materials' ),
			'edit_item'         => __( 'Edit category', 'free-materials' ),
			'menu_name'         => __( 'Categories', 'free-materials' ),
			'new_item_name'     => __( 'New category name', 'free-materials' ),
			'not_found'         => __( 'No categories found.', 'free-materials' ),
			'parent_item'       => __( 'Parent category', 'free-materials' ),
			'parent_item_colon' => __( 'Parent category:', 'free-materials' ),
			'search_items'      => __( 'Search categories', 'free-materials' ),
			'update_item'       => __( 'Update category', 'free-materials' ),
			'view_item'         => __( 'View category', 'free-materials' ),
		);
	}
}
