<?php
/**
 * Tests for the record-id link settings field.
 *
 * @package Atmosphere
 * @group atmosphere
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\Handle;
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
		\remove_all_filters( Handle::FILTER_ENABLED );

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
	 * Store an identity with the given handle.
	 *
	 * @param string $handle Bluesky handle.
	 */
	private function connect_as( string $handle ): void {
		$row = array(
			'did'          => 'did:plc:test123',
			'handle'       => $handle,
			'access_token' => 'token',
		);

		\update_option( 'atmosphere_identity', $row );
		\update_option( 'atmosphere_connection', $row );
	}

	/**
	 * When the handle is the site's own domain, the pattern is at its
	 * neatest and the copy says so, instead of walking the reader through
	 * a substitution they do not have to make.
	 */
	public function test_domain_handle_gets_its_own_explanation() {
		$host = (string) \wp_parse_url( \home_url(), \PHP_URL_HOST );
		$this->connect_as( $host );

		$html = $this->render();

		$this->assertStringContainsString( 'Because your Bluesky handle is your domain', $html );
		$this->assertStringNotContainsString( 'would line up more neatly', $html );
	}

	/**
	 * Otherwise the domain handle is recommended, because it is what makes
	 * the two addresses line up.
	 */
	public function test_other_handles_get_the_recommendation() {
		$this->connect_as( 'someone.bsky.social' );

		$html = $this->render();

		$this->assertStringContainsString( 'would line up more neatly', $html );
		$this->assertStringContainsString( 'Domain handle', $html, 'The tip must name the setting it points at.' );
	}

	/**
	 * The recommendation is suppressed where the swap is not on offer, so
	 * it cannot point at a row that is not on the page.
	 */
	public function test_recommendation_is_suppressed_when_unavailable() {
		$this->connect_as( 'someone.bsky.social' );
		\add_filter( Handle::FILTER_ENABLED, '__return_false' );

		$this->assertStringNotContainsString( 'would line up more neatly', $this->render() );
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
