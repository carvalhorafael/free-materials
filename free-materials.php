<?php
/**
 * Plugin Name: Free Materials
 * Description: Registers the reusable Free Materials content domain for WordPress sites.
 * Version: 0.2.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Rafael Carvalho
 * Plugin URI: https://github.com/carvalhorafael/free-materials
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/carvalhorafael/free-materials
 * Text Domain: free-materials
 * Domain Path: /languages
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FREE_MATERIALS_VERSION', '0.2.0' );
define( 'FREE_MATERIALS_FILE', __FILE__ );
define( 'FREE_MATERIALS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FREE_MATERIALS_BASENAME', plugin_basename( __FILE__ ) );

require_once FREE_MATERIALS_DIR . 'includes/class-content-domain.php';
require_once FREE_MATERIALS_DIR . 'includes/class-material-details.php';
require_once FREE_MATERIALS_DIR . 'includes/class-github-updater.php';
require_once FREE_MATERIALS_DIR . 'includes/class-csv-parser.php';
require_once FREE_MATERIALS_DIR . 'includes/class-importer.php';
require_once FREE_MATERIALS_DIR . 'includes/class-import-admin-page.php';
require_once FREE_MATERIALS_DIR . 'includes/class-plugin.php';

/**
 * Returns the plugin singleton.
 */
function free_materials(): Free_Materials_Plugin {
	return Free_Materials_Plugin::instance();
}

/**
 * Returns the canonical free material post type.
 */
function free_materials_post_type(): string {
	return Free_Materials_Content_Domain::POST_TYPE;
}

/**
 * Returns the canonical free material taxonomy.
 */
function free_materials_taxonomy(): string {
	return Free_Materials_Content_Domain::TAXONOMY;
}

/**
 * Returns the canonical free material CTA label meta key.
 */
function free_materials_cta_label_meta_key(): string {
	return Free_Materials_Content_Domain::CTA_LABEL_META_KEY;
}

/**
 * Returns the canonical capture destination meta key.
 */
function free_materials_capture_destination_meta_key(): string {
	return Free_Materials_Content_Domain::CAPTURE_DESTINATION_META_KEY;
}

/**
 * Returns the canonical delivery URL meta key.
 */
function free_materials_delivery_url_meta_key(): string {
	return Free_Materials_Content_Domain::DELIVERY_URL_META_KEY;
}

/**
 * Returns the legacy Brevo list ID meta key.
 */
function free_materials_brevo_list_id_meta_key(): string {
	return Free_Materials_Content_Domain::BREVO_LIST_ID_META_KEY;
}

/**
 * Returns the legacy Brevo delivery URL meta key.
 */
function free_materials_brevo_delivery_url_meta_key(): string {
	return Free_Materials_Content_Domain::BREVO_DELIVERY_URL_META_KEY;
}

/**
 * Returns the meta key for the material format.
 */
function free_materials_format_meta_key(): string {
	return Free_Materials_Content_Domain::FORMAT_META_KEY;
}

/**
 * Returns the meta key for the material page or item count.
 */
function free_materials_pages_meta_key(): string {
	return Free_Materials_Content_Domain::PAGES_META_KEY;
}

/**
 * Returns the meta key for the material file size.
 */
function free_materials_file_size_meta_key(): string {
	return Free_Materials_Content_Domain::FILE_SIZE_META_KEY;
}

/**
 * Returns the meta key for who the material is for.
 */
function free_materials_level_meta_key(): string {
	return Free_Materials_Content_Domain::LEVEL_META_KEY;
}

/**
 * Returns the meta key for the "what is inside" list.
 */
function free_materials_highlights_meta_key(): string {
	return Free_Materials_Content_Domain::HIGHLIGHTS_META_KEY;
}

/**
 * Returns the meta key for the download count shown as social proof.
 */
function free_materials_downloads_meta_key(): string {
	return Free_Materials_Content_Domain::DOWNLOADS_META_KEY;
}

/**
 * Returns the meta key for the catalog feature flag.
 */
function free_materials_featured_meta_key(): string {
	return Free_Materials_Content_Domain::FEATURED_META_KEY;
}

/**
 * Returns the known material formats as slug keyed labels.
 *
 * @return array<string, string>
 */
function free_materials_formats(): array {
	return Free_Materials_Content_Domain::formats();
}

/**
 * Returns the label for a material format slug, or an empty string.
 */
function free_materials_format_label( string $slug ): string {
	$formats = Free_Materials_Content_Domain::formats();

	return $formats[ $slug ] ?? '';
}

register_activation_hook( __FILE__, array( 'Free_Materials_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Free_Materials_Plugin', 'deactivate' ) );

free_materials()->boot();
