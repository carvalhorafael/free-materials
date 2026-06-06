<?php
/**
 * Main plugin bootstrap.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_Plugin {
	private static ?Free_Materials_Plugin $instance = null;

	private bool $booted = false;

	private Free_Materials_Content_Domain $content_domain;

	private Free_Materials_GitHub_Updater $github_updater;

	private function __construct() {
		$this->content_domain = new Free_Materials_Content_Domain();
		$this->github_updater = new Free_Materials_GitHub_Updater( FREE_MATERIALS_FILE, FREE_MATERIALS_VERSION );
	}

	public static function instance(): Free_Materials_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->content_domain->register_hooks();
		$this->github_updater->register_hooks();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'free-materials',
			false,
			dirname( FREE_MATERIALS_BASENAME ) . '/languages'
		);
	}

	public function content_domain(): Free_Materials_Content_Domain {
		return $this->content_domain;
	}

	public function github_updater(): Free_Materials_GitHub_Updater {
		return $this->github_updater;
	}

	public static function activate(): void {
		$domain = new Free_Materials_Content_Domain();
		$domain->register_content_types();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
