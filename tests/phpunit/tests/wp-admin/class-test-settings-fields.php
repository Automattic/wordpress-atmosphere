<?php
/**
 * Tests for settings page field rendering.
 *
 * @package Atmosphere
 * @group atmosphere
 * @group wp-admin
 */

namespace Atmosphere\Tests\WP_Admin;

use Atmosphere\WP_Admin\Settings_Fields;

/**
 * Settings field rendering tests.
 */
class Test_Settings_Fields extends \WP_UnitTestCase {

	/**
	 * Clean up connection state after each test.
	 */
	public function tear_down(): void {
		\delete_option( 'atmosphere_connection' );
		\delete_option( 'atmosphere_identity' );

		parent::tear_down();
	}

	/**
	 * Render the connected section and return the buffered HTML.
	 *
	 * @return string Rendered HTML.
	 */
	private function render_connected_section(): string {
		\ob_start();
		Settings_Fields::render_connected_section();
		return (string) \ob_get_clean();
	}

	/**
	 * Connected users can open their PDS account-management page.
	 */
	public function test_connected_section_renders_pds_account_management_link(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.example.com',
				'did'          => 'did:plc:alice',
				'pds_endpoint' => 'https://pds.example.com/',
				'access_token' => 'token',
			)
		);

		$html = $this->render_connected_section();

		$this->assertStringContainsString( 'Manage AT Protocol account', $html );
		$this->assertStringContainsString( 'href="https://pds.example.com/account"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}

	/**
	 * No account-management link is rendered when no PDS endpoint is stored.
	 */
	public function test_connected_section_hides_account_management_link_without_pds_endpoint(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.example.com',
				'did'          => 'did:plc:alice',
				'access_token' => 'token',
			)
		);

		$html = $this->render_connected_section();

		$this->assertStringNotContainsString( 'Manage AT Protocol account', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}

	/**
	 * The identity option is the canonical source for account details.
	 */
	public function test_connected_section_uses_identity_pds_endpoint_for_account_management_link(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'access_token' => 'token',
			)
		);
		\update_option(
			'atmosphere_identity',
			array(
				'handle'       => 'alice.example.com',
				'did'          => 'did:plc:alice',
				'pds_endpoint' => 'https://pds.identity.example',
			)
		);

		$html = $this->render_connected_section();

		$this->assertStringContainsString( 'alice.example.com', $html );
		$this->assertStringContainsString( 'did:plc:alice', $html );
		$this->assertStringContainsString( 'https://pds.identity.example', $html );
		$this->assertStringContainsString( 'href="https://pds.identity.example/account"', $html );
	}

	/**
	 * Hand-edited unsafe PDS endpoints must not become admin links.
	 */
	public function test_connected_section_hides_account_management_link_for_unsafe_pds_endpoint(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.example.com',
				'did'          => 'did:plc:alice',
				'pds_endpoint' => 'http://pds.example.com',
				'access_token' => 'token',
			)
		);

		$html = $this->render_connected_section();

		$this->assertStringNotContainsString( 'Manage AT Protocol account', $html );
		$this->assertStringNotContainsString( 'href="http://pds.example.com/account"', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}

	/**
	 * PDS endpoints with userinfo must not become admin links.
	 */
	public function test_connected_section_hides_account_management_link_for_pds_endpoint_with_userinfo(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.example.com',
				'did'          => 'did:plc:alice',
				'pds_endpoint' => 'https://pds.example.com@evil.example',
				'access_token' => 'token',
			)
		);

		$html = $this->render_connected_section();

		$this->assertStringNotContainsString( 'Manage AT Protocol account', $html );
		$this->assertStringNotContainsString( 'href="https://evil.example/account"', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}

	/**
	 * Valid IPv6 literal PDS hosts can still render account-management links.
	 */
	public function test_connected_section_renders_account_management_link_for_ipv6_pds_endpoint(): void {
		\update_option(
			'atmosphere_connection',
			array(
				'handle'       => 'alice.example.com',
				'did'          => 'did:plc:alice',
				'pds_endpoint' => 'https://[2001:db8::1]:8443',
				'access_token' => 'token',
			)
		);

		$html = $this->render_connected_section();

		$this->assertStringContainsString( 'Manage AT Protocol account', $html );
		$this->assertStringContainsString( 'href="https://[2001:db8::1]:8443/account"', $html );
		$this->assertStringContainsString( 'Disconnect', $html );
	}
}
