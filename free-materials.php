<?php
/**
 * Plugin Name: Free Materials
 * Description: Registers the reusable Free Materials content domain for WordPress sites.
 * Version: 0.1.0
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

define( 'FREE_MATERIALS_VERSION', '0.1.0' );
define( 'FREE_MATERIALS_FILE', __FILE__ );
define( 'FREE_MATERIALS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FREE_MATERIALS_BASENAME', plugin_basename( __FILE__ ) );

require_once FREE_MATERIALS_DIR . 'includes/class-content-domain.php';
require_once FREE_MATERIALS_DIR . 'includes/class-github-updater.php';
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
 * Returns the canonical Brevo list ID meta key.
 */
function free_materials_brevo_list_id_meta_key(): string {
	return Free_Materials_Content_Domain::BREVO_LIST_ID_META_KEY;
}

/**
 * Returns the canonical Brevo delivery URL meta key.
 */
function free_materials_brevo_delivery_url_meta_key(): string {
	return Free_Materials_Content_Domain::BREVO_DELIVERY_URL_META_KEY;
}

register_activation_hook( __FILE__, array( 'Free_Materials_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Free_Materials_Plugin', 'deactivate' ) );

free_materials()->boot();
