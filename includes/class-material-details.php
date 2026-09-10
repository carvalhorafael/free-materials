<?php
/**
 * Editor panel for the descriptive material metadata.
 *
 * This is content-domain UI, not capture UI: it describes the material an
 * editor is publishing, so it belongs to this plugin. Lead capture settings
 * stay in the integration plugin.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_Material_Details {
	private const NONCE_ACTION = 'free_materials_save_material_details';
	private const NONCE_NAME   = 'free_materials_material_details_nonce';

	public function register_hooks(): void {
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'save_post_' . Free_Materials_Content_Domain::POST_TYPE, array( $this, 'save' ), 10, 2 );
	}

	public function register_meta_box(): void {
		add_meta_box(
			'free-materials-details',
			__( 'Material details', 'free-materials' ),
			array( $this, 'render' ),
			Free_Materials_Content_Domain::POST_TYPE,
			// The block editor collapses every meta box into the bottom
			// drawer, where 'normal' gets the full width the topic list needs.
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Current post.
	 */
	public function render( $post ): void {
		$post_id    = (int) $post->ID;
		$format     = (string) get_post_meta( $post_id, Free_Materials_Content_Domain::FORMAT_META_KEY, true );
		$pages      = (int) get_post_meta( $post_id, Free_Materials_Content_Domain::PAGES_META_KEY, true );
		$file_size  = (string) get_post_meta( $post_id, Free_Materials_Content_Domain::FILE_SIZE_META_KEY, true );
		$level      = (string) get_post_meta( $post_id, Free_Materials_Content_Domain::LEVEL_META_KEY, true );
		$featured   = (bool) get_post_meta( $post_id, Free_Materials_Content_Domain::FEATURED_META_KEY, true );
		$highlights = get_post_meta( $post_id, Free_Materials_Content_Domain::HIGHLIGHTS_META_KEY, true );
		$highlights = is_array( $highlights ) ? implode( "\n", $highlights ) : '';

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<style>
			.free-materials-details { display: grid; gap: 0 24px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
			.free-materials-details p { margin-top: 0; }
			.free-materials-details__wide { grid-column: 1 / -1; }
		</style>
		<div class="free-materials-details">
		<p>
			<label for="free-materials-format"><strong><?php echo esc_html__( 'Format', 'free-materials' ); ?></strong></label>
			<select id="free-materials-format" name="free_materials_format" class="widefat">
				<option value=""><?php echo esc_html__( '— Not set —', 'free-materials' ); ?></option>
				<?php foreach ( Free_Materials_Content_Domain::formats() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $format, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="free-materials-pages"><strong><?php echo esc_html__( 'Pages or items', 'free-materials' ); ?></strong></label>
			<input type="number" min="0" step="1" id="free-materials-pages" name="free_materials_pages" class="widefat" value="<?php echo esc_attr( $pages > 0 ? (string) $pages : '' ); ?>" />
		</p>

		<p>
			<label for="free-materials-file-size"><strong><?php echo esc_html__( 'File size', 'free-materials' ); ?></strong></label>
			<input type="text" id="free-materials-file-size" name="free_materials_file_size" class="widefat" value="<?php echo esc_attr( $file_size ); ?>" placeholder="<?php echo esc_attr__( '2,4 MB', 'free-materials' ); ?>" />
		</p>

		<p>
			<label for="free-materials-level"><strong><?php echo esc_html__( 'Who it is for', 'free-materials' ); ?></strong></label>
			<input type="text" id="free-materials-level" name="free_materials_level" class="widefat" value="<?php echo esc_attr( $level ); ?>" />
		</p>

		<p class="free-materials-details__wide">
			<label for="free-materials-highlights"><strong><?php echo esc_html__( 'What is inside', 'free-materials' ); ?></strong></label>
			<textarea id="free-materials-highlights" name="free_materials_highlights" class="widefat" rows="6" aria-describedby="free-materials-highlights-help"><?php echo esc_textarea( $highlights ); ?></textarea>
			<span id="free-materials-highlights-help" class="description">
				<?php echo esc_html__( 'One topic per line, up to 12.', 'free-materials' ); ?>
			</span>
		</p>

		<p class="free-materials-details__wide">
			<label for="free-materials-featured">
				<input type="checkbox" id="free-materials-featured" name="free_materials_featured" value="1" <?php checked( $featured ); ?> />
				<strong><?php echo esc_html__( 'Feature in the catalog', 'free-materials' ); ?></strong>
			</label>
		</p>
		</div>
		<?php
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post being saved.
	 */
	public function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$domain = new Free_Materials_Content_Domain();

		$format = isset( $_POST['free_materials_format'] ) ? wp_unslash( $_POST['free_materials_format'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_format().
		$this->update_or_delete( $post_id, Free_Materials_Content_Domain::FORMAT_META_KEY, $domain->sanitize_format( $format ) );

		$pages = isset( $_POST['free_materials_pages'] ) ? absint( wp_unslash( $_POST['free_materials_pages'] ) ) : 0;
		$this->update_or_delete( $post_id, Free_Materials_Content_Domain::PAGES_META_KEY, $pages > 0 ? $pages : '' );

		$file_size = isset( $_POST['free_materials_file_size'] ) ? sanitize_text_field( wp_unslash( $_POST['free_materials_file_size'] ) ) : '';
		$this->update_or_delete( $post_id, Free_Materials_Content_Domain::FILE_SIZE_META_KEY, $file_size );

		$level = isset( $_POST['free_materials_level'] ) ? sanitize_text_field( wp_unslash( $_POST['free_materials_level'] ) ) : '';
		$this->update_or_delete( $post_id, Free_Materials_Content_Domain::LEVEL_META_KEY, $level );

		$highlights = isset( $_POST['free_materials_highlights'] ) ? wp_unslash( $_POST['free_materials_highlights'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_highlights().
		$this->update_or_delete( $post_id, Free_Materials_Content_Domain::HIGHLIGHTS_META_KEY, $domain->sanitize_highlights( $highlights ) );

		$featured = ! empty( $_POST['free_materials_featured'] );
		$this->update_or_delete( $post_id, Free_Materials_Content_Domain::FEATURED_META_KEY, $featured ? true : '' );
	}

	/**
	 * Store a value, or drop the row when the editor cleared the field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Sanitized value.
	 */
	private function update_or_delete( int $post_id, string $key, $value ): void {
		if ( '' === $value || array() === $value ) {
			delete_post_meta( $post_id, $key );

			return;
		}

		update_post_meta( $post_id, $key, $value );
	}
}
