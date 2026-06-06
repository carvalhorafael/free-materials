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
	public const MATERIALS_PAGE_PATH          = 'materiais-gratuitos';
	public const META_BOX_ID                  = 'free-materials-capture';
	public const META_BOX_NONCE_ACTION        = 'free_materials_save_capture_settings';
	public const META_BOX_NONCE_NAME          = 'free_materials_capture_nonce';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_content_types' ) );
		add_action( 'init', array( $this, 'register_meta' ), 11 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_box' ) );
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
				'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
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
	}

	public function register_meta_box(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Material capture', 'free-materials' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'default'
		);
	}

	public function render_meta_box( WP_Post $post ): void {
		$cta_label          = get_post_meta( $post->ID, self::CTA_LABEL_META_KEY, true );
		$brevo_list_id      = get_post_meta( $post->ID, self::BREVO_LIST_ID_META_KEY, true );
		$brevo_delivery_url = get_post_meta( $post->ID, self::BREVO_DELIVERY_URL_META_KEY, true );

		wp_nonce_field( self::META_BOX_NONCE_ACTION, self::META_BOX_NONCE_NAME );
		?>
		<p>
			<label for="free-materials-cta-label"><?php esc_html_e( 'Button text', 'free-materials' ); ?></label>
			<input
				class="widefat"
				id="free-materials-cta-label"
				name="free_materials_cta_label"
				type="text"
				value="<?php echo esc_attr( $cta_label ); ?>"
				placeholder="<?php esc_attr_e( 'Download free material', 'free-materials' ); ?>"
			>
		</p>
		<p>
			<label for="free-materials-brevo-list-id"><?php esc_html_e( 'Brevo list ID', 'free-materials' ); ?></label>
			<input
				class="widefat"
				id="free-materials-brevo-list-id"
				name="free_materials_brevo_list_id"
				type="text"
				value="<?php echo esc_attr( $brevo_list_id ); ?>"
			>
		</p>
		<p>
			<label for="free-materials-brevo-delivery-url"><?php esc_html_e( 'Delivery redirect URL', 'free-materials' ); ?></label>
			<input
				class="widefat"
				id="free-materials-brevo-delivery-url"
				name="free_materials_brevo_delivery_url"
				type="url"
				value="<?php echo esc_attr( $brevo_delivery_url ); ?>"
				placeholder="https://"
			>
		</p>
		<?php
	}

	public function save_meta_box( int $post_id ): void {
		$nonce = isset( $_POST[ self::META_BOX_NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_BOX_NONCE_NAME ] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, self::META_BOX_NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$values = array(
			self::CTA_LABEL_META_KEY          => isset( $_POST['free_materials_cta_label'] ) ? sanitize_text_field( wp_unslash( $_POST['free_materials_cta_label'] ) ) : '',
			self::BREVO_LIST_ID_META_KEY      => isset( $_POST['free_materials_brevo_list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['free_materials_brevo_list_id'] ) ) : '',
			self::BREVO_DELIVERY_URL_META_KEY => isset( $_POST['free_materials_brevo_delivery_url'] ) ? esc_url_raw( wp_unslash( $_POST['free_materials_brevo_delivery_url'] ) ) : '',
		);

		foreach ( $values as $meta_key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
				continue;
			}

			update_post_meta( $post_id, $meta_key, $value );
		}
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
