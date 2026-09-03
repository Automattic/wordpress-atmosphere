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
	 * Reset the option between tests.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_shortlink' );
		\delete_option( 'atmosphere_identity' );
		\delete_option( 'atmosphere_connection' );
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
	 * The choice is put as a comparison between two visible addresses.
	 *
	 * Naming only the one being offered leaves "short link" as something
	 * the reader has to picture, and the thing it replaces is exactly
	 * what makes the trade legible.
	 */
	public function test_compares_both_addresses() {
		$html = $this->render();

		$this->assertStringContainsString( '?p=123', $html, 'What WordPress offers today.' );
		$this->assertStringContainsString( '/post/3mn3kzvtns72d', $html, 'What this offers instead.' );
	}

	/**
	 * Where the address comes from sits behind a disclosure, so the
	 * checkbox and the sentence answering it come first.
	 *
	 * The class is load-bearing: `assets/css/admin.css` styles the summary
	 * as a link with a pointer cursor, matching how the ActivityPub plugin
	 * presents the same control.
	 */
	public function test_explanation_sits_behind_a_styled_disclosure() {
		$html = $this->render();

		$this->assertStringContainsString( '<details class="atmosphere-details">', $html );
		$this->assertStringContainsString( '<summary>', $html );

		$this->assertLessThan(
			\strpos( $html, '<details' ),
			\strpos( $html, 'atmosphere_shortlink' ),
			'The checkbox must come before the disclosure.'
		);
	}

	/**
	 * The example inside names the site's own handle, so the reader sees
	 * their address rather than an abstract one.
	 */
	public function test_example_uses_the_connected_handle() {
		\update_option(
			'atmosphere_identity',
			array(
				'did'    => 'did:plc:test123',
				'handle' => 'alice.example.com',
			)
		);

		$this->assertStringContainsString( 'profile/alice.example.com/post/', $this->render() );
	}

	/**
	 * A site pointed at another appview is not told about one it does not
	 * use, because the example is built through `appview_url()`.
	 */
	public function test_example_follows_the_appview_filter() {
		\add_filter( 'atmosphere_appview_host', static fn() => 'deer.social' );

		$html = $this->render();

		$this->assertStringContainsString( 'deer.social/profile/', $html );
		$this->assertStringNotContainsString( 'bsky.app', $html );
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
