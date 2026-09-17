<?php
/**
 * Legacy client-metadata REST controller.
 *
 * @package Atmosphere
 */

namespace Atmosphere\Rest;

\defined( 'ABSPATH' ) || exit;

use Atmosphere\OAuth\Client;

/**
 * Serves the public-client metadata document of connections created before
 * confidential client authentication was introduced.
 *
 * Its URL is the `client_id` stored on those sessions, so it must keep
 * answering, unchanged, until every one of them has been reconnected.
 *
 * @since unreleased
 */
class Legacy_Client_Metadata_Controller extends Client_Metadata_Controller {

	/**
	 * Version of the client metadata document this controller serves.
	 *
	 * @var string
	 *
	 * @since unreleased
	 */
	public const VERSION = 'v1';

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = 'atmosphere/' . self::VERSION;

	/**
	 * Fields that identify the public client.
	 *
	 * @return array
	 *
	 * @since unreleased
	 */
	protected function pinned_fields(): array {
		return array(
			'client_id'                  => Client::legacy_client_id(),
			'token_endpoint_auth_method' => 'none',
		);
	}
}
