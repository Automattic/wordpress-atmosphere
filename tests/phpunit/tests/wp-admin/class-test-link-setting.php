<?php
/**
 * Tests for the record-id link settings field.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\WP_Admin\Settings_Fields;

/**
 * Link field tests.
 */
class Test_Link_Setting extends \WP_UnitTestCase {

	/**
	 * Reset identity between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_shortlink' );
		\remove_all_filters( 'atmosphere_appview_host' );

		parent::tear_down();
	}

	/**
	 * Capture the rendered field.
	 *
	 * @return string
	 */
	private function render(): string {
		\ob_start();
		Settings_Fields::render_shortlink_field();

		return (string) \ob_get_clean();
	}

	/**
	 * The example uses the site's own handle, so the reader recognises it
	 * as their address rather than an abstract one.
	 */
	public function test_example_uses_the_connected_handle() {
		\update_option(
			'atmosphere_identity',
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.example.com',
			)
		);

		$this->assertStringContainsString( 'bsky.app/profile/alice.example.com/post/', $this->render() );
	}

	/**
	 * Before the first connection there is no truthful handle to show, so
	 * the example falls back to a placeholder rather than inventing one.
	 */
	public function test_example_falls_back_before_connecting() {
		$html = $this->render();

		$this->assertStringContainsString( 'yourname.bsky.social', $html );
		$this->assertStringContainsString( '/post/', $html );
	}

	/**
	 * A site pointed at a different appview is not told about one it does
	 * not use, because the example is built through `appview_url()`.
	 */
	public function test_example_follows_the_appview_filter() {
		\add_filter( 'atmosphere_appview_host', static fn() => 'deer.social' );

		$html = $this->render();

		$this->assertStringContainsString( 'deer.social/profile/', $html );
		$this->assertStringNotContainsString( 'bsky.app', $html );
	}

	/**
	 * The choice is shown as a comparison: what WordPress offers today
	 * against what ticking the box would offer instead. Without the
	 * former, "short link" is an abstraction the reader has to supply.
	 */
	public function test_shows_what_wordpress_offers_today() {
		$html = $this->render();

		$this->assertStringContainsString( '?p=123', $html );
		$this->assertStringContainsString( '/post/', $html );
	}

	/**
	 * The opt-in reflects the stored option.
	 */
	public function test_checkbox_reflects_the_option() {
		$this->assertStringNotContainsString( 'checked', $this->render() );

		\update_option( 'atmosphere_shortlink', '1' );

		$this->assertStringContainsString( 'checked', $this->render() );
	}
}
