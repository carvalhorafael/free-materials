<?php
/**
 * Plugin bootstrap integration tests.
 *
 * @package Free_Materials
 */

final class PluginBootstrapTest extends WP_UnitTestCase {
	public function test_singleton_exposes_services(): void {
		$this->assertInstanceOf( Free_Materials_Plugin::class, free_materials() );
		$this->assertInstanceOf( Free_Materials_Content_Domain::class, free_materials()->content_domain() );
		$this->assertInstanceOf( Free_Materials_GitHub_Updater::class, free_materials()->github_updater() );
	}
}
