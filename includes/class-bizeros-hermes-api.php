<?php
/**
 * Centralized Hermes webhook client.
 *
 * @package BizerOS
 */

namespace BizerOS\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends signed webhook events from WordPress to Hermes.
 *
 * BizerOS no longer treats Hermes as a chat-completions/message API endpoint.
 * Runtime requests are delivered as JSON webhook events to the configured Hermes
 * webhook URL, signed with HMAC-SHA256 over the exact JSON request body.
 *
 * Default host-derived endpoint:
 * https://hermes-agent-olqc.{TRAEFIK_HOST}:8644/webhooks/wordpress
 */
class BizerOS_Hermes_API {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Maximum allowed outgoing prompt length.
	 *
	 * @var int
	 */
	const MAX_MESSAGE_LENGTH = 8000;

	/**
	 * Maximum response message length returned to callers.
	 *
	 * @var int
	 */
	const MAX_RESPONSE_LENGTH = 20000;

	/**
	 * Maximum admin-safe HTTP response excerpt length.
	 *
	 * @var int
	 */
	const MAX_ERROR_EXCERPT_LENGTH = 600;

	/**
	 * Default webhook port.
	 *
	 * @var int
	 */
	const DEFAULT_WEBHOOK_PORT = 8644;

	/**
	 * Default webhook route.
	 *
	 * @var string
	 */
	const DEFAULT_WEBHOOK_ROUTE = 'wordpress';

	/**
	 * Webhook route prefix.
	 *
	 * @var string
	 */
	const WEBHOOK_ROUTE_PREFIX = '/webhooks/';

	/**
	 * HMAC signature algorithm.
	 *
	 * @var string
	 */
	const SIGNATURE_ALGORITHM = 'sha256';

	/**
	 * Hermes webhook signature header.
	 *
	 * @var string
	 */
	const SIGNATURE_HEADER = 'X-Webhook-Signature';

	/**
	 * Default acknowledgement message when Hermes does not return an immediate reply.
	 *
	 * @var string
	 */
	const DEFAULT_ACK_MESSAGE = 'Your message was sent to Hermes.';

	/**
	 * Legacy constants retained for callers that introspect the old API client.
	 *
	 * @var string
	 */
	const DEFAULT_API_PATH = '';

	/**
	 * Legacy auth scheme label retained for compatibility only.
	 *
	 * @var string
	 */
	const DEFAULT_AUTH_SCHEME = 'webhook_hmac_sha256';

	/**
	 * Legacy auth header label retained for compatibility only.
	 *
	 * @var string
	 */
	const DEFAULT_AUTH_HEADER = self::SIGNATURE_HEADER;

	/**
	 * HTTP request timeout.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Explicit webhook URL override.
	 *
	 * Null means read from saved settings at request time.
	 *
	 * @var string|null
	 */
	private $webhook_url;

	/**
	 * Explicit webhook route override.
	 *
	 * Null means read from saved settings at request time.
	 *
	 * @var string|null
	 */
	private $webhook_route;

	/**
	 * Last endpoint URL used or resolved.
	 *
	 * @var string
	 */
	private $last_endpoint_url = '';

	/**
	 * Last webhook route used or resolved.
	 *
	 * @var string
	 */
	private $last_webhook_route = '';

	/**
	 * Last webhook event type sent.
	 *
	 * @var string
	 */
	private $last_event_type = '';

	/**
	 * Last webhook action sent.
	 *
	 * @var string
	 */
	private $last_action = '';

	/**
	 * Last remote HTTP status code.
	 *
	 * @var int
	 */
	private $last_status_code = 0;

	/**
	 * Last delivery success state.
	 *
	 * @var bool
	 */
	private $last_delivery_success = false;

	/**
	 * Safe excerpt of the latest HTTP response body.
	 *
	 * @var string
	 */
	private $last_response_body_excerpt = '';

	/**
	 * Last safe delivery error.
	 *
	 * @var string
	 */
	private $last_delivery_error = '';

	/**
	 * Last safe endpoint/configuration recommendation.
	 *
	 * @var string
	 */
	private $last_endpoint_recommendation = '';

	/**
	 * Constructor.
	 *
	 * @param array $args Optional arguments.
	 */
	public function __construct( array $args = array() ) {
		$timeout = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : absint( apply_filters( 'bizeros_hermes_timeout', self::DEFAULT_TIMEOUT ) );

		if ( $timeout < 5 ) {
			$timeout = 5;
		}

		if ( $timeout > 120 ) {
			$timeout = 120;
		}

		$this->timeout       = $timeout;
		$this->webhook_url   = array_key_exists( 'webhook_url', $args ) ? (string) $args['webhook_url'] : null;
		$this->webhook_route = array_key_exists( 'webhook_route', $args ) ? (string) $args['webhook_route'] : null;

		/*
		 * Backward compatibility: older integrations may instantiate this class
		 * with an api_path argument. In webhook mode this is interpreted as a route
		 * only when webhook_route is not explicitly provided.
		 */
		if ( null === $this->webhook_route && array_key_exists( 'api_path', $args ) ) {
			$this->webhook_route = (string) $args['api_path'];
		}
	}

	/**
	 * Send a chat prompt to Hermes as a signed webhook event.
	 *
	 * @param string $message    User prompt.
	 * @param string $session_id Optional conversation/session identifier.
	 * @return array
	 */
	public function send_chat_prompt( $message, $session_id = '' ) {
		return $this->send_chat_message( $message, $session_id );
	}

	/**
	 * Send a chat message to Hermes as a signed webhook event.
	 *
	 * @param string $message    User message.
	 * @param string $session_id Optional conversation/session identifier.
	 * @return array
	 */
	public function send_chat_message( $message, $session_id = '' ) {
		$message = $this->prepare_message( $message );

		$this->reset_request_diagnostics();

		if ( '' === $message ) {
			return $this->build_failure_response(
				__( 'Please enter a message before sending.', 'bizeros' ),
				'bizeros_empty_message',
				400,
				array(
					'diagnostics' => $this->get_safe_diagnostics(),
				)
			);
		}

		if ( $this->string_length( $message ) > self::MAX_MESSAGE_LENGTH ) {
			return $this->build_failure_response(
				sprintf(
					/* translators: %d: Maximum message length. */
					__( 'Your message is too long. Please keep it under %d characters.', 'bizeros' ),
					self::MAX_MESSAGE_LENGTH
				),
				'bizeros_message_too_long',
				400,
				array(
					'diagnostics' => $this->get_safe_diagnostics(),
				)
			);
		}

		$payload = $this->build_webhook_event_payload(
			'chat_message',
			'bizeros.chat.message',
			$message,
			$session_id
		);

		return $this->deliver_webhook_event( $payload );
	}

	/**
	 * Alias for integrations that call send_message().
	 *
	 * @param string $message    User message.
	 * @param string $session_id Optional conversation/session identifier.
	 * @return array
	 */
	public function send_message( $message, $session_id = '' ) {
		return $this->send_chat_message( $message, $session_id );
	}

	/**
	 * Alias for integrations that call send_prompt().
	 *
	 * @param string $message    User message.
	 * @param string $session_id Optional conversation/session identifier.
	 * @return array
	 */
	public function send_prompt( $message, $session_id = '' ) {
		return $this->send_chat_message( $message, $session_id );
	}

	/**
	 * Alias for integrations that call chat().
	 *
	 * @param string $message    User message.
	 * @param string $session_id Optional conversation/session identifier.
	 * @return array
	 */
	public function chat( $message, $session_id = '' ) {
		return $this->send_chat_message( $message, $session_id );
	}

	/**
	 * Alias for integrations that call request().
	 *
	 * @param string $message    User message.
	 * @param string $session_id Optional conversation/session identifier.
	 * @return array
	 */
	public function request( $message, $session_id = '' ) {
		return $this->send_chat_message( $message, $session_id );
	}

	/**
	 * Send an arbitrary signed webhook event to Hermes.
	 *
	 * @param array $payload Webhook event payload.
	 * @return array
	 */
	public function send_webhook_event( array $payload ) {
		$this->reset_request_diagnostics();

		return $this->deliver_webhook_event( $payload );
	}

	/**
	 * Get the generated public Hermes webhook base URL.
	 *
	 * @return string|\WP_Error
	 */
	public function get_public_base_url() {
		return $this->get_base_url();
	}

	/**
	 * Build the endpoint URL used for Hermes webhook requests.
	 *
	 * @param string|null $route Optional route. Defaults to configured route.
	 * @return string|\WP_Error
	 */
	public function get_endpoint_url( $route = null ) {
		$configured_url = $this->get_configured_webhook_url();

		if ( '' !== $configured_url ) {
			$route = $this->get_configured_webhook_route( $route );
			$url   = $this->ensure_webhook_url_has_path( $configured_url, $route );

			if ( \is_wp_error( $url ) ) {
				$this->last_endpoint_url  = '';
				$this->last_webhook_route = $route;

				return $url;
			}

			$this->last_endpoint_url  = $url;
			$this->last_webhook_route = $route;

			/**
			 * Filters the final Hermes webhook endpoint URL.
			 *
			 * @param string             $url        Endpoint URL.
			 * @param string             $route      Webhook route.
			 * @param BizerOS_Hermes_API $api_client API client instance.
			 */
			return apply_filters( 'bizeros_hermes_webhook_endpoint_url', $url, $route, $this );
		}

		$base_url = $this->get_base_url();

		if ( \is_wp_error( $base_url ) ) {
			$this->last_endpoint_url  = '';
			$this->last_webhook_route = '';

			return $base_url;
		}

		$route = $this->get_configured_webhook_route( $route );
		$url   = untrailingslashit( $base_url ) . $this->get_webhook_path( $route );
		$url   = esc_url_raw( $url );

		$this->last_endpoint_url  = $url;
		$this->last_webhook_route = $route;

		/**
		 * Filters the final Hermes webhook endpoint URL.
		 *
		 * @param string             $url        Endpoint URL.
		 * @param string             $route      Webhook route.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		return apply_filters( 'bizeros_hermes_webhook_endpoint_url', $url, $route, $this );
	}

	/**
	 * Get safe redacted diagnostics for administrators.
	 *
	 * This method never returns the webhook shared secret or generated signature.
	 *
	 * @return array
	 */
	public function get_safe_diagnostics() {
		$route    = $this->get_configured_webhook_route();
		$endpoint = $this->get_endpoint_url( $route );
		$secret   = $this->get_webhook_secret();

		$secret_present     = '' !== $secret;
		$secret_fingerprint = $secret_present ? substr( hash( 'sha256', $secret ), 0, 12 ) : '';

		$diagnostics = array(
			'configured'                  => ! \is_wp_error( $endpoint ) && $secret_present,
			'endpoint_url'                => \is_wp_error( $endpoint ) ? '' : (string) $endpoint,
			'webhook_url'                 => \is_wp_error( $endpoint ) ? '' : (string) $endpoint,
			'endpoint_error'              => \is_wp_error( $endpoint ) ? sanitize_text_field( $endpoint->get_error_message() ) : '',
			'webhook_route'               => $route,
			'route'                       => $route,
			'webhook_path'                => $this->get_webhook_path( $route ),
			'webhook_port'                => $this->get_webhook_port_for_display( \is_wp_error( $endpoint ) ? '' : (string) $endpoint ),
			'signature_header'            => $this->get_signature_header_name(),
			'signature_header_name'       => $this->get_signature_header_name(),
			'signature_algorithm'         => self::SIGNATURE_ALGORITHM,
			'auth_scheme'                 => 'webhook_hmac_sha256',
			'auth_header'                 => $this->get_signature_header_name(),
			'auth_header_name'            => $this->get_signature_header_name(),
			'auth_value_mode'             => 'HMAC-SHA256 hex digest of exact JSON body',
			'webhook_secret_saved'        => $secret_present,
			'webhook_secret_present'      => $secret_present,
			'secret_saved'                => $secret_present,
			'secret_present'              => $secret_present,
			'secret_fingerprint'          => $secret_fingerprint,
			'webhook_secret_fingerprint'  => $secret_fingerprint,
			'timeout'                     => absint( $this->timeout ),
			'delivery_mode'               => 'signed_webhook',
			'payload_mode'                => 'webhook_event',
			'default_webhook_config_hint' => 'platforms.webhook.enabled=true; port=8644; route=wordpress; signature_header=X-Webhook-Signature',
		);

		if ( '' !== $this->last_endpoint_url ) {
			$diagnostics['last_endpoint_url'] = $this->last_endpoint_url;
		}

		if ( '' !== $this->last_event_type ) {
			$diagnostics['last_event_type'] = $this->last_event_type;
		}

		if ( '' !== $this->last_action ) {
			$diagnostics['last_action'] = $this->last_action;
		}

		if ( $this->last_status_code ) {
			$diagnostics['last_delivery_status_code'] = absint( $this->last_status_code );
			$diagnostics['remote_status_code']        = absint( $this->last_status_code );
		}

		if ( '' !== $this->last_response_body_excerpt ) {
			$diagnostics['http_response_body_excerpt'] = $this->last_response_body_excerpt;
			$diagnostics['http_error_body_excerpt']    = $this->last_response_body_excerpt;
		}

		if ( '' !== $this->last_delivery_error ) {
			$diagnostics['last_delivery_error'] = $this->last_delivery_error;
		}

		if ( '' !== $this->last_endpoint_recommendation ) {
			$diagnostics['recommendation'] = $this->last_endpoint_recommendation;
		}

		$diagnostics['last_delivery_success'] = (bool) $this->last_delivery_success;

		/**
		 * Filters safe Hermes webhook diagnostics.
		 *
		 * Do not add secrets, generated signatures, or full shared secret values to
		 * this array. It may be shown in wp-admin to users with manage_options.
		 *
		 * @param array              $diagnostics Safe diagnostics.
		 * @param BizerOS_Hermes_API $api_client  API client instance.
		 */
		$diagnostics = apply_filters( 'bizeros_hermes_safe_diagnostics', $diagnostics, $this );

		if ( ! is_array( $diagnostics ) ) {
			$diagnostics = array();
		}

		$diagnostics['signature_header']       = $this->get_signature_header_name();
		$diagnostics['signature_header_name']  = $this->get_signature_header_name();
		$diagnostics['signature_algorithm']    = self::SIGNATURE_ALGORITHM;
		$diagnostics['auth_scheme']            = 'webhook_hmac_sha256';
		$diagnostics['auth_header']            = $this->get_signature_header_name();
		$diagnostics['auth_header_name']       = $this->get_signature_header_name();
		$diagnostics['auth_value_mode']        = 'HMAC-SHA256 hex digest of exact JSON body';
		$diagnostics['webhook_secret_saved']   = $secret_present;
		$diagnostics['webhook_secret_present'] = $secret_present;
		$diagnostics['secret_saved']           = $secret_present;
		$diagnostics['secret_present']         = $secret_present;

		if ( $secret_present ) {
			$diagnostics['secret_fingerprint']         = $secret_fingerprint;
			$diagnostics['webhook_secret_fingerprint'] = $secret_fingerprint;
		}

		return $this->sanitize_diagnostics_array( $diagnostics );
	}

	/**
	 * Alias for callers that request diagnostics.
	 *
	 * @return array
	 */
	public function get_diagnostics() {
		return $this->get_safe_diagnostics();
	}

	/**
	 * Get safe redacted authentication/signature diagnostics.
	 *
	 * @return array
	 */
	public function get_auth_diagnostics() {
		$secret = $this->get_webhook_secret();

		return $this->sanitize_diagnostics_array(
			array(
				'auth_scheme'                   => 'webhook_hmac_sha256',
				'auth_header'                   => $this->get_signature_header_name(),
				'auth_header_name'              => $this->get_signature_header_name(),
				'auth_value_mode'               => 'HMAC-SHA256 hex digest of exact JSON body',
				'signature_header'              => $this->get_signature_header_name(),
				'signature_header_name'         => $this->get_signature_header_name(),
				'signature_algorithm'           => self::SIGNATURE_ALGORITHM,
				'webhook_secret_saved'          => '' !== $secret,
				'webhook_secret_present'        => '' !== $secret,
				'secret_saved'                  => '' !== $secret,
				'secret_present'                => '' !== $secret,
				'secret_fingerprint'            => '' !== $secret ? substr( hash( 'sha256', $secret ), 0, 12 ) : '',
				'webhook_secret_fingerprint'    => '' !== $secret ? substr( hash( 'sha256', $secret ), 0, 12 ) : '',
				'legacy_basic_auth_deprecated'  => true,
				'runtime_authentication_method' => 'signed_webhook_hmac_sha256',
			)
		);
	}

	/**
	 * Run an admin/server-side Hermes webhook connection test.
	 *
	 * The returned diagnostics are redacted and never include the shared secret
	 * or generated signature.
	 *
	 * @param string $message Optional test prompt.
	 * @return array
	 */
	public function test_connection( $message = '' ) {
		$message = $this->prepare_message( $message );

		if ( '' === $message ) {
			$message = __( 'BizerOS webhook connection test. Reply with OK if immediate replies are supported.', 'bizeros' );
		}

		$this->reset_request_diagnostics();

		$payload = $this->build_webhook_event_payload(
			'connection_test',
			'bizeros.connection_test',
			$message,
			'bizeros-admin-test',
			array(
				'is_connection_test' => true,
				'requested_by'       => 'wp-admin',
			)
		);

		$result = $this->deliver_webhook_event( $payload );

		return array(
			'success'     => ! empty( $result['success'] ),
			'message'     => isset( $result['message'] ) ? $this->sanitize_response_message( (string) $result['message'] ) : '',
			'error'       => isset( $result['error'] ) ? sanitize_text_field( (string) $result['error'] ) : '',
			'code'        => isset( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : '',
			'status_code' => isset( $result['status_code'] ) ? absint( $result['status_code'] ) : 0,
			'diagnostics' => $this->get_safe_diagnostics(),
		);
	}

	/**
	 * Alias for admin integrations that call test_hermes_connection().
	 *
	 * @param string $message Optional test prompt.
	 * @return array
	 */
	public function test_hermes_connection( $message = '' ) {
		return $this->test_connection( $message );
	}

	/**
	 * Normalize a Hermes HTTP response or pre-normalized value.
	 *
	 * @param mixed $response Response from wp_remote_post or another caller.
	 * @return array
	 */
	public function normalize_response( $response ) {
		if ( \is_wp_error( $response ) ) {
			$this->last_delivery_success = false;
			$this->last_status_code      = 0;
			$this->last_delivery_error   = sanitize_text_field( $response->get_error_message() );

			return $this->build_failure_response(
				__( 'Hermes webhook could not be reached. Please try again later.', 'bizeros' ),
				$response->get_error_code() ? $response->get_error_code() : 'bizeros_hermes_webhook_unreachable',
				0
			);
		}

		if ( is_array( $response ) && $this->is_wp_http_response( $response ) ) {
			$normalized = $this->normalize_http_response( $response );
		} else {
			$normalized = $this->normalize_non_http_response( $response );
		}

		/**
		 * Filters the normalized Hermes webhook response.
		 *
		 * @param array              $normalized Normalized response.
		 * @param mixed              $response   Original response.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		return apply_filters( 'bizeros_hermes_normalized_response', $normalized, $response, $this );
	}

	/**
	 * Deliver a webhook event to Hermes.
	 *
	 * @param array $payload Webhook payload.
	 * @return array
	 */
	private function deliver_webhook_event( array $payload ) {
		$endpoint = $this->get_endpoint_url();

		if ( \is_wp_error( $endpoint ) ) {
			$this->last_delivery_success        = false;
			$this->last_delivery_error          = sanitize_text_field( $endpoint->get_error_message() );
			$this->last_endpoint_recommendation = __( 'Configure a full Hermes Webhook URL or enter TRAEFIK_HOST so BizerOS can build the default :8644 /webhooks/wordpress endpoint.', 'bizeros' );

			return $this->build_failure_response(
				$endpoint->get_error_message(),
				$endpoint->get_error_code(),
				400,
				array(
					'diagnostics' => $this->get_safe_diagnostics(),
				)
			);
		}

		$secret         = $this->get_webhook_secret();
		$require_secret = (bool) apply_filters( 'bizeros_hermes_webhook_require_secret', true, $this );

		if ( $require_secret && '' === $secret ) {
			$this->last_delivery_success        = false;
			$this->last_delivery_error          = __( 'Hermes webhook shared secret is not configured.', 'bizeros' );
			$this->last_endpoint_recommendation = __( 'Enter the same webhook shared secret configured in Hermes config.yaml. BizerOS signs each JSON body with HMAC-SHA256 and sends it in X-Webhook-Signature.', 'bizeros' );

			return $this->build_failure_response(
				__( 'Hermes webhook shared secret is not configured. Please enter the same secret configured in Hermes.', 'bizeros' ),
				'bizeros_missing_hermes_webhook_secret',
				400,
				array(
					'diagnostics' => $this->get_safe_diagnostics(),
				)
			);
		}

		$payload = $this->sanitize_webhook_payload( $payload );

		if ( empty( $payload['event_type'] ) ) {
			$payload['event_type'] = 'chat_message';
		}

		if ( empty( $payload['action'] ) ) {
			$payload['action'] = 'bizeros.chat.message';
		}

		$this->last_event_type = sanitize_key( (string) $payload['event_type'] );
		$this->last_action     = sanitize_key( (string) $payload['action'] );

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			$this->last_delivery_success = false;
			$this->last_delivery_error   = __( 'The webhook event could not be encoded as JSON.', 'bizeros' );

			return $this->build_failure_response(
				__( 'The webhook event could not be prepared for Hermes.', 'bizeros' ),
				'bizeros_webhook_json_encode_failed',
				500,
				array(
					'diagnostics' => $this->get_safe_diagnostics(),
				)
			);
		}

		$args = array(
			'timeout'     => $this->timeout,
			'redirection' => 3,
			'blocking'    => true,
			'headers'     => array(
				'Accept'                      => 'application/json',
				'Content-Type'                => 'application/json; charset=utf-8',
				'User-Agent'                  => 'BizerOS/' . $this->get_plugin_version() . '; ' . home_url( '/' ),
				'X-BizerOS-Event-Type'        => sanitize_key( (string) $payload['event_type'] ),
				'X-BizerOS-Webhook-Action'    => sanitize_key( (string) $payload['action'] ),
				'X-Webhook-Signature-Alg'     => self::SIGNATURE_ALGORITHM,
				'X-Webhook-Signature-Version' => 'v1',
			),
			'body'        => $body,
			'data_format' => 'body',
			'sslverify'   => true,
		);

		if ( '' !== $secret ) {
			$args['headers'][ $this->get_signature_header_name() ] = $this->build_signature_header_value( $body, $secret, $payload, $endpoint );
		}

		/**
		 * Filters the wp_remote_post arguments used for Hermes webhook requests.
		 *
		 * The HMAC signature header, when a secret is configured, has already been
		 * added before this filter runs. Do not expose the secret or signature to
		 * front-end code.
		 *
		 * @param array              $args       Request arguments.
		 * @param string             $endpoint   Hermes webhook endpoint URL.
		 * @param array              $payload    Webhook payload.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		$args = apply_filters( 'bizeros_hermes_webhook_request_args', $args, $endpoint, $payload, $this );

		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$response = wp_remote_post( $endpoint, $args );
		$result   = $this->normalize_response( $response );

		$result['diagnostics'] = $this->get_safe_diagnostics();

		return $result;
	}

	/**
	 * Build the outgoing webhook event payload.
	 *
	 * @param string $event_type Event type.
	 * @param string $action     Action name.
	 * @param string $message    Prepared message.
	 * @param string $session_id Optional session ID.
	 * @param array  $metadata   Additional safe metadata.
	 * @return array
	 */
	private function build_webhook_event_payload( $event_type, $action, $message, $session_id = '', array $metadata = array() ) {
		$event_type = sanitize_key( (string) $event_type );
		$action     = sanitize_key( (string) $action );
		$message    = $this->prepare_message( $message );
		$session_id = $this->prepare_session_id( $session_id );

		if ( '' === $event_type ) {
			$event_type = 'chat_message';
		}

		if ( '' === $action ) {
			$action = 'bizeros.chat.message';
		}

		$default_metadata = array(
			'source'             => 'wordpress',
			'platform'           => 'wordpress',
			'plugin'             => 'bizeros',
			'plugin_version'     => $this->get_plugin_version(),
			'site_url'           => home_url( '/' ),
			'admin_url'          => admin_url(),
			'agent_name'         => $this->get_agent_name(),
			'webhook_route'      => $this->get_configured_webhook_route(),
			'signature_header'   => $this->get_signature_header_name(),
			'signature_alg'      => self::SIGNATURE_ALGORITHM,
			'created_at'         => gmdate( 'c' ),
			'timestamp'          => time(),
			'request_id'         => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'bizeros_', true ),
			'immediate_response' => true,
		);

		if ( is_user_logged_in() ) {
			$default_metadata['wordpress_user_id'] = absint( get_current_user_id() );
		}

		$metadata = array_merge( $default_metadata, $metadata );
		$metadata = $this->sanitize_metadata_array( $metadata );

		$payload = array(
			'event_type' => $event_type,
			'message'    => $message,
			'action'     => $action,
			'session_id' => $session_id,
			'metadata'   => $metadata,
		);

		/**
		 * Filters the Hermes webhook event payload.
		 *
		 * Do not add the webhook shared secret or generated signature to this array.
		 * The exact JSON encoding of this payload is what BizerOS signs.
		 *
		 * @param array              $payload    Webhook payload.
		 * @param string             $message    User message.
		 * @param string             $session_id Sanitized session ID.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		$payload = apply_filters( 'bizeros_hermes_webhook_payload', $payload, $message, $session_id, $this );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Build the public Hermes webhook base URL from the saved TRAEFIK_HOST setting.
	 *
	 * @return string|\WP_Error
	 */
	private function get_base_url() {
		$host = $this->get_traefik_host();

		if ( '' === $host ) {
			return new \WP_Error(
				'bizeros_missing_traefik_host',
				__( 'Hermes webhook is not configured. Enter a full webhook URL or configure TRAEFIK_HOST.', 'bizeros' )
			);
		}

		$subdomain = defined( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN' ) ? BIZEROS_HERMES_PUBLIC_SUBDOMAIN : 'hermes-agent-olqc';
		$subdomain = sanitize_title_with_dashes( (string) apply_filters( 'bizeros_hermes_public_subdomain', $subdomain ) );

		if ( '' === $subdomain ) {
			$subdomain = 'hermes-agent-olqc';
		}

		$base_url = 'https://' . $subdomain . '.' . $host . ':' . absint( $this->get_default_webhook_port() );

		/**
		 * Filters the Hermes webhook base URL.
		 *
		 * @param string             $base_url   Public base URL.
		 * @param string             $host       Sanitized TRAEFIK_HOST.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		$base_url = (string) apply_filters( 'bizeros_hermes_webhook_base_url', $base_url, $host, $this );
		$base_url = esc_url_raw( trim( $base_url ) );

		if ( '' === $base_url || ! $this->is_http_url( $base_url ) ) {
			return new \WP_Error(
				'bizeros_invalid_hermes_webhook_base_url',
				__( 'Hermes webhook is not configured correctly. Please check the webhook URL.', 'bizeros' )
			);
		}

		return untrailingslashit( $base_url );
	}

	/**
	 * Get the saved TRAEFIK_HOST value.
	 *
	 * @return string
	 */
	private function get_traefik_host() {
		$option_name = defined( 'BIZEROS_OPTION_TRAEFIK_HOST' ) ? BIZEROS_OPTION_TRAEFIK_HOST : 'bizeros_traefik_host';
		$host        = get_option( $option_name, '' );

		return $this->sanitize_traefik_host( $host );
	}

	/**
	 * Sanitize a TRAEFIK_HOST value.
	 *
	 * @param mixed $host Raw host value.
	 * @return string
	 */
	private function sanitize_traefik_host( $host ) {
		$host = is_scalar( $host ) ? (string) $host : '';
		$host = wp_unslash( $host );
		$host = wp_strip_all_tags( $host );
		$host = trim( $host );
		$host = preg_replace( '/\s+/', '', $host );
		$host = preg_replace( '#^[a-z][a-z0-9+\-.]*://#i', '', $host );
		$host = ltrim( $host, '/' );

		$parts = preg_split( '/[\/?#]/', $host );
		$host  = isset( $parts[0] ) ? $parts[0] : '';

		$host = strtolower( $host );
		$host = preg_replace( '/[^a-z0-9.-]/', '', $host );
		$host = preg_replace( '/\.{2,}/', '.', $host );
		$host = trim( $host, ".-\t\n\r\0\x0B/" );

		return $host;
	}

	/**
	 * Get the configured full webhook URL.
	 *
	 * @return string
	 */
	private function get_configured_webhook_url() {
		if ( null !== $this->webhook_url ) {
			$configured_url = (string) $this->webhook_url;
		} else {
			$option_name    = defined( 'BIZEROS_OPTION_HERMES_WEBHOOK_URL' ) ? BIZEROS_OPTION_HERMES_WEBHOOK_URL : 'bizeros_hermes_webhook_url';
			$configured_url = (string) get_option( $option_name, '' );
		}

		$configured_url = $this->sanitize_webhook_url( $configured_url );

		/**
		 * Filters the configured Hermes webhook URL.
		 *
		 * Return an empty string to use the host-derived default:
		 * https://hermes-agent-olqc.{TRAEFIK_HOST}:8644/webhooks/{route}
		 *
		 * @param string             $configured_url Configured webhook URL.
		 * @param BizerOS_Hermes_API $api_client     API client instance.
		 */
		$configured_url = (string) apply_filters( 'bizeros_hermes_webhook_url', $configured_url, $this );

		return $this->sanitize_webhook_url( $configured_url );
	}

	/**
	 * Sanitize a webhook URL.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	private function sanitize_webhook_url( $url ) {
		$url = is_scalar( $url ) ? (string) $url : '';
		$url = wp_unslash( $url );
		$url = wp_strip_all_tags( $url );
		$url = wp_check_invalid_utf8( $url );
		$url = str_replace( "\0", '', $url );
		$url = preg_replace( '/[\x00-\x1F\x7F]/', '', $url );
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( '' === $url || ! $this->is_http_url( $url ) ) {
			return '';
		}

		if ( $this->string_length( $url ) > 500 ) {
			$url = $this->substring( $url, 0, 500 );
		}

		return $url;
	}

	/**
	 * Ensure a configured webhook URL has a path. If only a base URL is saved,
	 * append /webhooks/{route}.
	 *
	 * @param string $url   Webhook URL or base URL.
	 * @param string $route Webhook route.
	 * @return string|\WP_Error
	 */
	private function ensure_webhook_url_has_path( $url, $route ) {
		$url   = $this->sanitize_webhook_url( $url );
		$route = $this->get_configured_webhook_route( $route );

		if ( '' === $url ) {
			return new \WP_Error(
				'bizeros_invalid_hermes_webhook_url',
				__( 'Hermes webhook URL is invalid. Use a full http or https URL.', 'bizeros' )
			);
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		if ( '' === trim( $path, '/' ) ) {
			$url = untrailingslashit( $url ) . $this->get_webhook_path( $route );
		}

		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Determine whether a URL is http(s).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_http_url( $url ) {
		$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );

		return '' !== $host && in_array( strtolower( $scheme ), array( 'http', 'https' ), true );
	}

	/**
	 * Get the configured webhook route.
	 *
	 * @param string|null $route Explicit route override.
	 * @return string
	 */
	private function get_configured_webhook_route( $route = null ) {
		if ( null !== $route ) {
			$configured_route = (string) $route;
		} elseif ( null !== $this->webhook_route ) {
			$configured_route = (string) $this->webhook_route;
		} else {
			$option_name      = defined( 'BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE' ) ? BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE : 'bizeros_hermes_webhook_route';
			$configured_route = (string) get_option( $option_name, $this->get_default_webhook_route() );
		}

		$configured_route = $this->sanitize_webhook_route( $configured_route );

		/**
		 * Filters the Hermes webhook route appended to /webhooks/.
		 *
		 * @param string             $configured_route Sanitized route.
		 * @param BizerOS_Hermes_API $api_client       API client instance.
		 */
		$configured_route = (string) apply_filters( 'bizeros_hermes_webhook_route', $configured_route, $this );

		return $this->sanitize_webhook_route( $configured_route );
	}

	/**
	 * Sanitize a webhook route.
	 *
	 * @param mixed $route Raw route.
	 * @return string
	 */
	private function sanitize_webhook_route( $route ) {
		$route = is_scalar( $route ) ? (string) $route : '';
		$route = wp_unslash( $route );
		$route = wp_strip_all_tags( $route );
		$route = wp_check_invalid_utf8( $route );
		$route = str_replace( "\0", '', $route );
		$route = preg_replace( '/[\x00-\x1F\x7F]/', '', $route );
		$route = preg_replace( '/\s+/', '', trim( $route ) );

		if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $route ) ) {
			$route = (string) wp_parse_url( $route, PHP_URL_PATH );
		}

		$route = ltrim( $route, '/' );
		$route = preg_replace( '#^webhooks/#i', '', $route );
		$route = preg_replace( '#/+#', '/', $route );
		$route = preg_replace( '/[^A-Za-z0-9\-._~\/]/', '', $route );
		$route = trim( $route, "/ \t\n\r\0\x0B" );

		if ( '' === $route ) {
			$route = $this->get_default_webhook_route();
		}

		if ( $this->string_length( $route ) > 120 ) {
			$route = $this->substring( $route, 0, 120 );
		}

		return $route;
	}

	/**
	 * Get the webhook path for a route.
	 *
	 * @param string|null $route Optional route.
	 * @return string
	 */
	private function get_webhook_path( $route = null ) {
		return self::WEBHOOK_ROUTE_PREFIX . $this->get_configured_webhook_route( $route );
	}

	/**
	 * Get default webhook route.
	 *
	 * @return string
	 */
	private function get_default_webhook_route() {
		return defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE' ) ? (string) BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE : self::DEFAULT_WEBHOOK_ROUTE;
	}

	/**
	 * Get default webhook port.
	 *
	 * @return int
	 */
	private function get_default_webhook_port() {
		return defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ? absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) : self::DEFAULT_WEBHOOK_PORT;
	}

	/**
	 * Get configured webhook secret.
	 *
	 * @return string
	 */
	private function get_webhook_secret() {
		$option_name = defined( 'BIZEROS_OPTION_HERMES_WEBHOOK_SECRET' ) ? BIZEROS_OPTION_HERMES_WEBHOOK_SECRET : 'bizeros_hermes_webhook_secret';
		$secret      = get_option( $option_name, '' );

		/**
		 * Filters the Hermes webhook shared secret.
		 *
		 * This value must remain server-side only. Do not expose it to JavaScript.
		 *
		 * @param string             $secret     Sanitized shared secret.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		$secret = apply_filters( 'bizeros_hermes_webhook_secret', $this->sanitize_secret( $secret ), $this );

		return $this->sanitize_secret( $secret );
	}

	/**
	 * Sanitize a shared secret for HMAC use.
	 *
	 * @param mixed $secret Raw secret.
	 * @return string
	 */
	private function sanitize_secret( $secret ) {
		$secret = is_scalar( $secret ) ? (string) $secret : '';
		$secret = wp_unslash( $secret );
		$secret = wp_check_invalid_utf8( $secret );
		$secret = str_replace( "\0", '', $secret );
		$secret = preg_replace( '/[\x00-\x1F\x7F]/', '', $secret );
		$secret = wp_strip_all_tags( $secret );
		$secret = trim( $secret );

		if ( $this->string_length( $secret ) > 2000 ) {
			$secret = $this->substring( $secret, 0, 2000 );
		}

		return $secret;
	}

	/**
	 * Get the webhook signature header name.
	 *
	 * @return string
	 */
	private function get_signature_header_name() {
		$header = defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER' ) ? BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER : self::SIGNATURE_HEADER;
		$header = (string) apply_filters( 'bizeros_hermes_webhook_signature_header', $header, $this );
		$header = trim( $header );

		if ( '' === $header || ! preg_match( '/^[A-Za-z0-9-]+$/', $header ) ) {
			return self::SIGNATURE_HEADER;
		}

		return $header;
	}

	/**
	 * Build HMAC signature header value for the exact JSON body.
	 *
	 * @param string $body     Exact JSON body.
	 * @param string $secret   Shared secret.
	 * @param array  $payload  Event payload.
	 * @param string $endpoint Endpoint URL.
	 * @return string
	 */
	private function build_signature_header_value( $body, $secret, array $payload, $endpoint ) {
		$signature = hash_hmac( self::SIGNATURE_ALGORITHM, (string) $body, (string) $secret );

		/**
		 * Filters the signature header value.
		 *
		 * By default BizerOS sends the lowercase hex HMAC-SHA256 digest. If a Hermes
		 * deployment expects a prefix such as "sha256=", trusted server-side code may
		 * add it here.
		 *
		 * @param string             $signature  Hex HMAC digest.
		 * @param string             $body       Exact JSON request body.
		 * @param array              $payload    Webhook payload.
		 * @param string             $endpoint   Webhook endpoint.
		 * @param BizerOS_Hermes_API $api_client API client instance.
		 */
		$signature = apply_filters( 'bizeros_hermes_webhook_signature_value', $signature, $body, $payload, $endpoint, $this );

		return is_scalar( $signature ) ? trim( (string) $signature ) : '';
	}

	/**
	 * Sanitize an outgoing webhook payload without adding secrets.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	private function sanitize_webhook_payload( array $payload ) {
		$sanitized = array();

		foreach ( $payload as $key => $value ) {
			$key = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';

			if ( '' === $key ) {
				continue;
			}

			if ( in_array( $key, array( 'secret', 'webhook_secret', 'signature', 'token', 'authorization', 'password', 'api_key', 'apikey' ), true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_metadata_array( $value );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				if ( 'message' === $key ) {
					$sanitized[ $key ] = $this->prepare_message( (string) $value );
				} elseif ( 'session_id' === $key ) {
					$sanitized[ $key ] = $this->prepare_session_id( (string) $value );
				} elseif ( 'event_type' === $key || 'action' === $key ) {
					$sanitized[ $key ] = sanitize_key( (string) $value );
				} else {
					$sanitized[ $key ] = sanitize_text_field( (string) $value );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize metadata recursively.
	 *
	 * @param array $metadata Metadata.
	 * @return array
	 */
	private function sanitize_metadata_array( array $metadata ) {
		$sanitized = array();

		foreach ( $metadata as $key => $value ) {
			$key_string = is_scalar( $key ) ? (string) $key : '';
			$key_safe   = sanitize_key( $key_string );

			if ( '' === $key_safe || $this->is_secret_like_key( $key_string ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$sanitized[ $key_safe ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key_safe ] = $value;
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key_safe ] = $this->sanitize_metadata_array( $value );
			} elseif ( is_scalar( $value ) ) {
				$string_value = (string) $value;

				if ( false !== strpos( strtolower( $key_string ), 'url' ) ) {
					$sanitized[ $key_safe ] = esc_url_raw( $string_value );
				} else {
					$sanitized[ $key_safe ] = sanitize_text_field( $string_value );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Prepare and sanitize an outgoing message.
	 *
	 * @param mixed $message Raw message.
	 * @return string
	 */
	private function prepare_message( $message ) {
		$message = is_scalar( $message ) ? (string) $message : '';
		$message = wp_unslash( $message );
		$message = wp_check_invalid_utf8( $message );
		$message = str_replace( "\0", '', $message );
		$message = wp_strip_all_tags( $message );
		$message = trim( $message );

		return $message;
	}

	/**
	 * Prepare a session identifier.
	 *
	 * @param mixed $session_id Raw session ID.
	 * @return string
	 */
	private function prepare_session_id( $session_id ) {
		$session_id = is_scalar( $session_id ) ? (string) $session_id : '';
		$session_id = wp_unslash( $session_id );
		$session_id = sanitize_text_field( $session_id );
		$session_id = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $session_id );
		$session_id = trim( $session_id );

		if ( $this->string_length( $session_id ) > 120 ) {
			$session_id = $this->substring( $session_id, 0, 120 );
		}

		return $session_id;
	}

	/**
	 * Normalize a WordPress HTTP API response.
	 *
	 * @param array $response HTTP response.
	 * @return array
	 */
	private function normalize_http_response( array $response ) {
		$status_code = absint( wp_remote_retrieve_response_code( $response ) );
		$body        = (string) wp_remote_retrieve_body( $response );
		$excerpt     = $this->get_safe_response_body_excerpt( $body );

		$this->last_status_code           = $status_code;
		$this->last_response_body_excerpt = $excerpt;

		if ( 401 === $status_code || 403 === $status_code ) {
			$this->last_delivery_success        = false;
			$this->last_delivery_error          = __( 'Hermes rejected the signed webhook request. Check the shared secret and signature header configuration.', 'bizeros' );
			$this->last_endpoint_recommendation = __( 'Confirm Hermes config.yaml uses the same shared secret and validates the X-Webhook-Signature HMAC-SHA256 value over the exact JSON body.', 'bizeros' );

			return $this->build_failure_response(
				__( 'Hermes rejected the signed webhook request. Please check the webhook shared secret and X-Webhook-Signature configuration.', 'bizeros' ),
				'bizeros_hermes_webhook_unauthorized',
				$status_code,
				array(
					'http_response_body_excerpt' => $excerpt,
					'http_error_body_excerpt'    => $excerpt,
					'remote_status_code'         => $status_code,
				)
			);
		}

		if ( 404 === $status_code ) {
			$this->last_delivery_success        = false;
			$this->last_delivery_error          = __( 'Hermes did not find the configured webhook route.', 'bizeros' );
			$this->last_endpoint_recommendation = __( 'Confirm Hermes webhook platform is enabled on port 8644 and the route is configured as wordpress, producing /webhooks/wordpress.', 'bizeros' );

			return $this->build_failure_response(
				__( 'Hermes did not find the configured webhook route. Check the webhook URL, port 8644, and route in Hermes config.yaml.', 'bizeros' ),
				'bizeros_hermes_webhook_not_found',
				$status_code,
				array(
					'http_response_body_excerpt' => $excerpt,
					'http_error_body_excerpt'    => $excerpt,
					'remote_status_code'         => $status_code,
				)
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->last_delivery_success = false;
			$this->last_delivery_error   = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Hermes webhook returned HTTP status %d.', 'bizeros' ),
				$status_code
			);

			return $this->build_failure_response(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Hermes webhook returned an error. HTTP status: %d.', 'bizeros' ),
					$status_code
				),
				'bizeros_hermes_webhook_http_error',
				$status_code,
				array(
					'http_response_body_excerpt' => $excerpt,
					'http_error_body_excerpt'    => $excerpt,
					'remote_status_code'         => $status_code,
				)
			);
		}

		$this->last_delivery_success = true;
		$this->last_delivery_error   = '';

		if ( '' === trim( $body ) ) {
			return $this->build_success_response(
				__( self::DEFAULT_ACK_MESSAGE, 'bizeros' ),
				$this->get_public_success_status_code( $status_code ),
				array(
					'acknowledged'              => true,
					'delivered'                 => true,
					'remote_status_code'        => $status_code,
					'http_response_body_excerpt' => $excerpt,
				)
			);
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$message = $this->sanitize_response_message( $body );

			return $this->build_success_response(
				'' !== $message ? $message : __( self::DEFAULT_ACK_MESSAGE, 'bizeros' ),
				$this->get_public_success_status_code( $status_code ),
				array(
					'acknowledged'              => true,
					'delivered'                 => true,
					'remote_status_code'        => $status_code,
					'http_response_body_excerpt' => $excerpt,
				)
			);
		}

		if ( is_array( $decoded ) ) {
			if ( array_key_exists( 'success', $decoded ) && false === (bool) $decoded['success'] ) {
				$this->last_delivery_success = false;
				$this->last_delivery_error   = $this->extract_error_message( $decoded );

				return $this->build_failure_response(
					$this->extract_error_message( $decoded ),
					'bizeros_hermes_webhook_unsuccessful',
					$status_code,
					array(
						'http_response_body_excerpt' => $excerpt,
						'http_error_body_excerpt'    => $excerpt,
						'remote_status_code'         => $status_code,
					)
				);
			}

			$message = $this->extract_response_message( $decoded );

			if ( '' === $message ) {
				$message = __( self::DEFAULT_ACK_MESSAGE, 'bizeros' );
			}

			return $this->build_success_response(
				$message,
				$this->get_public_success_status_code( $status_code ),
				array(
					'acknowledged'              => true,
					'delivered'                 => true,
					'remote_status_code'        => $status_code,
					'http_response_body_excerpt' => $excerpt,
				)
			);
		}

		if ( is_scalar( $decoded ) ) {
			$message = $this->sanitize_response_message( (string) $decoded );

			return $this->build_success_response(
				'' !== $message ? $message : __( self::DEFAULT_ACK_MESSAGE, 'bizeros' ),
				$this->get_public_success_status_code( $status_code ),
				array(
					'acknowledged'              => true,
					'delivered'                 => true,
					'remote_status_code'        => $status_code,
					'http_response_body_excerpt' => $excerpt,
				)
			);
		}

		return $this->build_success_response(
			__( self::DEFAULT_ACK_MESSAGE, 'bizeros' ),
			$this->get_public_success_status_code( $status_code ),
			array(
				'acknowledged'              => true,
				'delivered'                 => true,
				'remote_status_code'        => $status_code,
				'http_response_body_excerpt' => $excerpt,
			)
		);
	}

	/**
	 * Normalize a non-HTTP response value.
	 *
	 * @param mixed $response Response value.
	 * @return array
	 */
	private function normalize_non_http_response( $response ) {
		if ( true === $response ) {
			$this->last_delivery_success = true;
			return $this->build_success_response( __( self::DEFAULT_ACK_MESSAGE, 'bizeros' ), 200, array( 'acknowledged' => true, 'delivered' => true ) );
		}

		if ( is_string( $response ) ) {
			if ( '' === trim( $response ) ) {
				$this->last_delivery_success = false;
				$this->last_delivery_error   = __( 'Hermes returned an empty response.', 'bizeros' );

				return $this->build_failure_response(
					__( 'Hermes returned an empty response.', 'bizeros' ),
					'bizeros_empty_hermes_response',
					0
				);
			}

			$this->last_delivery_success = true;
			return $this->build_success_response( $this->sanitize_response_message( $response ), 200, array( 'acknowledged' => true, 'delivered' => true ) );
		}

		if ( is_array( $response ) ) {
			$status_code = isset( $response['status_code'] ) ? absint( $response['status_code'] ) : 0;

			if ( array_key_exists( 'success', $response ) && false === (bool) $response['success'] ) {
				$this->last_delivery_success = false;
				$this->last_delivery_error   = $this->extract_error_message( $response );

				return $this->build_failure_response(
					$this->extract_error_message( $response ),
					isset( $response['code'] ) ? sanitize_key( (string) $response['code'] ) : 'bizeros_hermes_webhook_unsuccessful',
					$status_code
				);
			}

			$message = $this->extract_response_message( $response );

			if ( '' !== $message || ! empty( $response['success'] ) ) {
				$this->last_delivery_success = true;
				return $this->build_success_response(
					'' !== $message ? $message : __( self::DEFAULT_ACK_MESSAGE, 'bizeros' ),
					$status_code ? $this->get_public_success_status_code( $status_code ) : 200,
					array(
						'acknowledged'       => true,
						'delivered'          => true,
						'remote_status_code' => $status_code,
					)
				);
			}
		}

		$this->last_delivery_success = false;
		$this->last_delivery_error   = __( 'Hermes returned an unexpected response.', 'bizeros' );

		return $this->build_failure_response(
			__( 'Hermes returned an unexpected response.', 'bizeros' ),
			'bizeros_unexpected_hermes_response',
			0
		);
	}

	/**
	 * Determine whether an array looks like a WordPress HTTP API response.
	 *
	 * @param array $response HTTP response array.
	 * @return bool
	 */
	private function is_wp_http_response( array $response ) {
		return array_key_exists( 'headers', $response )
			&& array_key_exists( 'body', $response )
			&& array_key_exists( 'response', $response );
	}

	/**
	 * Extract response text from common Hermes/webhook response shapes.
	 *
	 * @param mixed $data Decoded response data.
	 * @return string
	 */
	private function extract_response_message( $data ) {
		if ( is_scalar( $data ) ) {
			return $this->sanitize_response_message( (string) $data );
		}

		if ( ! is_array( $data ) ) {
			return '';
		}

		$candidate_keys = array(
			'message',
			'response',
			'reply',
			'answer',
			'content',
			'text',
			'output_text',
			'result',
			'ack',
			'acknowledgement',
			'acknowledgment',
		);

		foreach ( $candidate_keys as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$message = $this->extract_scalar_message( $data[ $key ] );

				if ( '' !== $message ) {
					return $message;
				}
			}
		}

		if ( isset( $data['data'] ) ) {
			$message = $this->extract_response_message( $data['data'] );

			if ( '' !== $message ) {
				return $message;
			}
		}

		if ( isset( $data['event'] ) ) {
			$message = $this->extract_response_message( $data['event'] );

			if ( '' !== $message ) {
				return $message;
			}
		}

		if ( isset( $data['choices'] ) && is_array( $data['choices'] ) ) {
			foreach ( $data['choices'] as $choice ) {
				$message = $this->extract_response_message( $choice );

				if ( '' !== $message ) {
					return $message;
				}
			}
		}

		if ( isset( $data['output'] ) && is_array( $data['output'] ) ) {
			foreach ( $data['output'] as $output_item ) {
				$message = $this->extract_response_message( $output_item );

				if ( '' !== $message ) {
					return $message;
				}
			}
		}

		if ( isset( $data['messages'] ) && is_array( $data['messages'] ) ) {
			$messages = array_reverse( $data['messages'] );

			foreach ( $messages as $message_item ) {
				$message = $this->extract_response_message( $message_item );

				if ( '' !== $message ) {
					return $message;
				}
			}
		}

		return '';
	}

	/**
	 * Extract a scalar message from a mixed value.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function extract_scalar_message( $value ) {
		if ( is_scalar( $value ) ) {
			return $this->sanitize_response_message( (string) $value );
		}

		if ( is_array( $value ) ) {
			return $this->extract_response_message( $value );
		}

		return '';
	}

	/**
	 * Extract an error message from decoded response data.
	 *
	 * @param mixed $data Response data.
	 * @return string
	 */
	private function extract_error_message( $data ) {
		$fallback = __( 'Hermes webhook returned an unsuccessful response. Please try again later.', 'bizeros' );

		if ( is_scalar( $data ) ) {
			$error = sanitize_text_field( (string) $data );
			return '' !== $error ? $error : $fallback;
		}

		if ( ! is_array( $data ) ) {
			return $fallback;
		}

		$error_keys = array(
			'error',
			'errors',
			'detail',
			'details',
			'message',
		);

		foreach ( $error_keys as $key ) {
			if ( empty( $data[ $key ] ) ) {
				continue;
			}

			$error = $this->extract_scalar_message( $data[ $key ] );

			if ( '' !== $error ) {
				return sanitize_text_field( $error );
			}
		}

		return $fallback;
	}

	/**
	 * Sanitize text returned from Hermes.
	 *
	 * @param string $message Raw response message.
	 * @return string
	 */
	private function sanitize_response_message( $message ) {
		$message = wp_check_invalid_utf8( (string) $message );
		$message = str_replace( "\0", '', $message );
		$message = wp_strip_all_tags( $message );
		$message = trim( $message );

		if ( $this->string_length( $message ) > self::MAX_RESPONSE_LENGTH ) {
			$message = $this->substring( $message, 0, self::MAX_RESPONSE_LENGTH );
		}

		return $message;
	}

	/**
	 * Get an admin-safe excerpt from an HTTP response body.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	private function get_safe_response_body_excerpt( $body ) {
		$secret = $this->get_webhook_secret();
		$body   = (string) $body;

		if ( '' !== $secret ) {
			$body = str_replace( $secret, '[redacted]', $body );
		}

		$body = wp_check_invalid_utf8( $body );
		$body = str_replace( "\0", '', $body );
		$body = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body );
		$body = wp_strip_all_tags( $body );
		$body = preg_replace( '/\s+/', ' ', $body );
		$body = trim( $body );

		if ( '' === $body ) {
			return '';
		}

		if ( $this->string_length( $body ) > self::MAX_ERROR_EXCERPT_LENGTH ) {
			$body = $this->substring( $body, 0, self::MAX_ERROR_EXCERPT_LENGTH ) . '…';
		}

		return sanitize_text_field( $body );
	}

	/**
	 * Build a normalized success response.
	 *
	 * @param string $message     Response message.
	 * @param int    $status_code Public HTTP status code.
	 * @param array  $extra       Extra safe response data.
	 * @return array
	 */
	private function build_success_response( $message, $status_code = 200, array $extra = array() ) {
		return array_merge(
			array(
				'success'     => true,
				'message'     => $this->sanitize_response_message( $message ),
				'error'       => '',
				'code'        => '',
				'status_code' => absint( $status_code ) ? absint( $status_code ) : 200,
			),
			$extra
		);
	}

	/**
	 * Build a normalized failure response.
	 *
	 * @param string $error       Error message.
	 * @param string $code        Error code.
	 * @param int    $status_code HTTP status code.
	 * @param array  $extra       Extra safe response data.
	 * @return array
	 */
	private function build_failure_response( $error, $code = 'bizeros_hermes_webhook_error', $status_code = 0, array $extra = array() ) {
		$error = sanitize_text_field( (string) $error );

		if ( '' === $error ) {
			$error = __( 'Hermes webhook could not process the request. Please try again later.', 'bizeros' );
		}

		return array_merge(
			array(
				'success'     => false,
				'message'     => '',
				'error'       => $error,
				'code'        => sanitize_key( (string) $code ),
				'status_code' => absint( $status_code ),
			),
			$extra
		);
	}

	/**
	 * Convert a remote success status into a safe public WordPress AJAX status.
	 *
	 * Avoid returning 204 to wp_send_json callers because it can suppress the JSON
	 * body in browsers/proxies.
	 *
	 * @param int $remote_status Remote HTTP status.
	 * @return int
	 */
	private function get_public_success_status_code( $remote_status ) {
		$remote_status = absint( $remote_status );

		if ( $remote_status >= 200 && $remote_status < 300 && 204 !== $remote_status ) {
			return $remote_status;
		}

		return 200;
	}

	/**
	 * Reset request diagnostics.
	 *
	 * @return void
	 */
	private function reset_request_diagnostics() {
		$this->last_endpoint_url             = '';
		$this->last_webhook_route            = '';
		$this->last_event_type               = '';
		$this->last_action                   = '';
		$this->last_status_code              = 0;
		$this->last_delivery_success         = false;
		$this->last_response_body_excerpt    = '';
		$this->last_delivery_error           = '';
		$this->last_endpoint_recommendation  = '';
	}

	/**
	 * Sanitize a diagnostics array and ensure secrets/signatures are not returned.
	 *
	 * @param array $diagnostics Diagnostics.
	 * @return array
	 */
	private function sanitize_diagnostics_array( array $diagnostics ) {
		$sanitized = array();

		foreach ( $diagnostics as $key => $value ) {
			$key_string = is_scalar( $key ) ? (string) $key : '';
			$key_safe   = sanitize_key( $key_string );

			if ( '' === $key_safe || $this->is_blocked_diagnostic_key( $key_string ) ) {
				continue;
			}

			$key_lower = strtolower( $key_string );

			if ( is_bool( $value ) ) {
				$sanitized[ $key_safe ] = $value;
			} elseif ( is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key_safe ] = $value;
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key_safe ] = $this->sanitize_diagnostics_array( $value );
			} elseif ( is_scalar( $value ) ) {
				$string_value = (string) $value;

				if ( false !== strpos( $key_lower, 'url' ) ) {
					$sanitized[ $key_safe ] = esc_url_raw( $string_value );
				} elseif ( false !== strpos( $key_lower, 'excerpt' ) ) {
					$sanitized[ $key_safe ] = $this->get_safe_response_body_excerpt( $string_value );
				} else {
					$sanitized[ $key_safe ] = sanitize_text_field( $string_value );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Determine whether a diagnostic key must be blocked.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function is_blocked_diagnostic_key( $key ) {
		$lower = strtolower( (string) $key );

		if ( '' === $lower ) {
			return true;
		}

		$allowed = array(
			'secret_saved',
			'secret_present',
			'secret_fingerprint',
			'webhook_secret_saved',
			'webhook_secret_present',
			'webhook_secret_fingerprint',
			'signature_header',
			'signature_header_name',
			'signature_algorithm',
			'signature_alg',
		);

		if ( in_array( $lower, $allowed, true ) ) {
			return false;
		}

		if ( false !== strpos( $lower, 'secret' ) ) {
			return true;
		}

		if ( false !== strpos( $lower, 'signature' ) ) {
			return true;
		}

		if ( false !== strpos( $lower, 'token' ) || false !== strpos( $lower, 'password' ) ) {
			return true;
		}

		if ( in_array( $lower, array( 'authorization', 'api_key', 'apikey', 'auth_token' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether a metadata key looks secret-like.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private function is_secret_like_key( $key ) {
		$lower = strtolower( (string) $key );

		if ( '' === $lower ) {
			return false;
		}

		if ( false !== strpos( $lower, 'secret' ) || false !== strpos( $lower, 'token' ) || false !== strpos( $lower, 'password' ) ) {
			return true;
		}

		if ( false !== strpos( $lower, 'signature' ) ) {
			return true;
		}

		return in_array( $lower, array( 'authorization', 'api_key', 'apikey' ), true );
	}

	/**
	 * Get a display port from an endpoint URL.
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return int
	 */
	private function get_webhook_port_for_display( $endpoint ) {
		$port = '' !== $endpoint ? absint( wp_parse_url( $endpoint, PHP_URL_PORT ) ) : 0;

		if ( $port ) {
			return $port;
		}

		return $this->get_default_webhook_port();
	}

	/**
	 * Get the configured BizerOS agent display name.
	 *
	 * @return string
	 */
	private function get_agent_name() {
		$option_name = defined( 'BIZEROS_OPTION_AGENT_NAME' ) ? BIZEROS_OPTION_AGENT_NAME : 'bizeros_agent_name';
		$default     = defined( 'BIZEROS_DEFAULT_AGENT_NAME' ) ? BIZEROS_DEFAULT_AGENT_NAME : 'Miles';
		$agent_name  = get_option( $option_name, $default );

		$agent_name = sanitize_text_field( wp_unslash( (string) $agent_name ) );
		$agent_name = trim( $agent_name );

		if ( '' === $agent_name ) {
			$agent_name = $default;
		}

		if ( $this->string_length( $agent_name ) > 80 ) {
			$agent_name = $this->substring( $agent_name, 0, 80 );
		}

		return $agent_name;
	}

	/**
	 * Get the plugin version for the request user agent.
	 *
	 * @return string
	 */
	private function get_plugin_version() {
		return defined( 'BIZEROS_VERSION' ) ? (string) BIZEROS_VERSION : '1.0.0';
	}

	/**
	 * Get a string length with multibyte support.
	 *
	 * @param string $value String value.
	 * @return int
	 */
	private function string_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value );
		}

		return strlen( $value );
	}

	/**
	 * Get a substring with multibyte support.
	 *
	 * @param string $value  String value.
	 * @param int    $start  Start position.
	 * @param int    $length Length.
	 * @return string
	 */
	private function substring( $value, $start, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, $start, $length );
		}

		return substr( $value, $start, $length );
	}
}

if ( ! class_exists( __NAMESPACE__ . '\\Hermes_API', false ) ) {
	class_alias( __NAMESPACE__ . '\\BizerOS_Hermes_API', __NAMESPACE__ . '\\Hermes_API' );
}