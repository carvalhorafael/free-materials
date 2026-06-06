<?php
/**
 * GitHub Releases update checker.
 *
 * @package Free_Materials
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Free_Materials_GitHub_Updater {
	private const API_URL = 'https://api.github.com/repos/carvalhorafael/free-materials/releases/latest';

	private const REPOSITORY_URL = 'https://github.com/carvalhorafael/free-materials';

	private const CACHE_KEY = 'free_materials_github_release';

	private string $plugin_basename;

	private string $current_version;

	/**
	 * @var callable|null
	 */
	private $http_client;

	/**
	 * @param callable|null $http_client Optional transport for tests.
	 */
	public function __construct( string $plugin_file, string $current_version, ?callable $http_client = null ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->current_version = $current_version;
		$this->http_client     = $http_client;
	}

	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'filter_update_plugins' ) );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
	}

	/**
	 * @param mixed $transient Update transient.
	 *
	 * @return mixed
	 */
	public function filter_update_plugins( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		if ( ! array_key_exists( $this->plugin_basename, $transient->checked ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( null === $release || ! version_compare( $release['version'], $this->current_version, '>' ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $this->plugin_basename ] = (object) array(
			'id'          => self::REPOSITORY_URL,
			'slug'        => $this->plugin_slug(),
			'plugin'      => $this->plugin_basename,
			'new_version' => $release['version'],
			'url'         => $release['html_url'],
			'package'     => $release['package_url'],
		);

		return $transient;
	}

	/**
	 * @param mixed $result Existing API result.
	 * @param mixed $args Plugin API args.
	 *
	 * @return mixed
	 */
	public function filter_plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! is_object( $args ) || ( $args->slug ?? '' ) !== $this->plugin_slug() ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => 'Free Materials',
			'slug'          => $this->plugin_slug(),
			'version'       => $release['version'],
			'author'        => 'Rafael Carvalho',
			'homepage'      => self::REPOSITORY_URL,
			'download_link' => $release['package_url'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => 'Registers the reusable Free Materials content domain for WordPress sites.',
				'changelog'   => $release['body'],
			),
		);
	}

	/**
	 * @return array<string, string>|null
	 */
	public function latest_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = $this->request_latest_release();
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$release = $this->normalize_release( $decoded );
		if ( null !== $release ) {
			set_site_transient( self::CACHE_KEY, $release, 6 * HOUR_IN_SECONDS );
		}

		return $release;
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	private function request_latest_release() {
		$args = array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'free-materials/' . $this->current_version,
			),
		);

		if ( null !== $this->http_client ) {
			return call_user_func( $this->http_client, self::API_URL, $args );
		}

		return wp_remote_get( self::API_URL, $args );
	}

	/**
	 * @param array<string, mixed> $release GitHub release payload.
	 *
	 * @return array<string, string>|null
	 */
	private function normalize_release( array $release ): ?array {
		if ( ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
			return null;
		}

		$tag_name = isset( $release['tag_name'] ) ? sanitize_text_field( (string) $release['tag_name'] ) : '';
		$version  = ltrim( $tag_name, 'vV' );
		if ( '' === $version ) {
			return null;
		}

		$package_url = $this->find_package_asset_url( $release['assets'] ?? array(), $version );
		if ( '' === $package_url ) {
			return null;
		}

		return array(
			'version'      => $version,
			'tag_name'     => $tag_name,
			'package_url'  => esc_url_raw( $package_url ),
			'html_url'     => esc_url_raw( (string) ( $release['html_url'] ?? self::REPOSITORY_URL ) ),
			'published_at' => sanitize_text_field( (string) ( $release['published_at'] ?? '' ) ),
			'body'         => wp_kses_post( (string) ( $release['body'] ?? '' ) ),
		);
	}

	/**
	 * @param mixed $assets Release assets.
	 */
	private function find_package_asset_url( $assets, string $version ): string {
		if ( ! is_array( $assets ) ) {
			return '';
		}

		$expected_name = sprintf( 'free-materials-%s.zip', $version );
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || (string) ( $asset['name'] ?? '' ) !== $expected_name ) {
				continue;
			}

			return isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
		}

		return '';
	}

	private function plugin_slug(): string {
		return basename( $this->plugin_basename, '.php' );
	}
}
