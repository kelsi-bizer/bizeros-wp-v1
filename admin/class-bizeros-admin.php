<?php
/**
 * BizerOS admin settings.
 *
 * @package BizerOS
 */

namespace BizerOS\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the BizerOS settings page, fields, sanitizers, and admin save behavior.
 */
class BizerOS_Admin {

	/**
	 * Hermes webhook client instance.
	 *
	 * @var object|null
	 */
	private $hermes_api;

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'bizeros';

	/**
	 * Settings option group.
	 *
	 * @var string
	 */
	private $option_group = 'bizeros_settings';

	/**
	 * Settings section ID.
	 *
	 * @var string
	 */
	private $section_id = 'bizeros_main_section';

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Top-level chat UI menu slug.
	 *
	 * @var string
	 */
	private $chat_menu_slug = 'bizeros-chat-ui';

	/**
	 * Top-level settings submenu redirect slug.
	 *
	 * @var string
	 */
	private $settings_submenu_slug = 'bizeros-settings';

	/**
	 * Admin AJAX action used for testing Hermes webhook delivery.
	 *
	 * @var string
	 */
	private $test_connection_action = 'bizeros_test_hermes_connection';

	/**
	 * Nonce action used for the admin Hermes webhook connection test.
	 *
	 * @var string
	 */
	private $test_connection_nonce_action = 'bizeros_test_hermes_connection';

	/**
	 * Admin JavaScript handle.
	 *
	 * @var string
	 */
	private $admin_script_handle = 'bizeros-admin-settings';

	/**
	 * Constructor.
	 *
	 * @param object|null $hermes_api Hermes webhook client.
	 */
	public function __construct( $hermes_api = null ) {
		$this->hermes_api = $hermes_api;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_' . $this->test_connection_action, array( $this, 'handle_test_connection_ajax' ) );
		add_action( 'update_option_' . BIZEROS_OPTION_AGENT_NAME, array( $this, 'handle_agent_name_updated' ), 10, 3 );

		add_filter( 'option_page_capability_' . $this->option_group, array( $this, 'get_settings_capability' ) );
	}

	/**
	 * Alias for bootstraps that call init().
	 *
	 * @return void
	 */
	public function init() {
		$this->register_hooks();
	}

	/**
	 * Alias for bootstraps that call run().
	 *
	 * @return void
	 */
	public function run() {
		$this->register_hooks();
	}

	/**
	 * Get the capability required to update BizerOS settings.
	 *
	 * @return string
	 */
	public function get_settings_capability() {
		return 'manage_options';
	}

	/**
	 * Register the BizerOS settings page under Settings and a chat UI admin shortcut.
	 *
	 * @return void
	 */
	public function register_settings_page() {
		$this->register_chat_admin_menu_link();

		$this->page_hook = add_options_page(
			esc_html__( 'BizerOS Settings', 'bizeros' ),
			esc_html__( 'BizerOS', 'bizeros' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register a top-level WordPress admin link labeled BizerOS that opens the front-end chat UI.
	 *
	 * @return void
	 */
	private function register_chat_admin_menu_link() {
		if ( $this->is_admin_menu_slug_registered( $this->chat_menu_slug ) ) {
			return;
		}

		add_menu_page(
			esc_html__( 'BizerOS', 'bizeros' ),
			esc_html__( 'BizerOS', 'bizeros' ),
			'read',
			$this->chat_menu_slug,
			array( $this, 'render_chat_menu_redirect' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			$this->chat_menu_slug,
			esc_html__( 'BizerOS Chat UI', 'bizeros' ),
			esc_html__( 'Chat UI', 'bizeros' ),
			'read',
			$this->chat_menu_slug,
			array( $this, 'render_chat_menu_redirect' )
		);

		add_submenu_page(
			$this->chat_menu_slug,
			esc_html__( 'BizerOS Settings', 'bizeros' ),
			esc_html__( 'Settings', 'bizeros' ),
			'manage_options',
			$this->settings_submenu_slug,
			array( $this, 'render_settings_menu_redirect' )
		);
	}

	/**
	 * Determine whether an admin menu slug is already registered.
	 *
	 * @param string $slug Menu slug.
	 * @return bool
	 */
	private function is_admin_menu_slug_registered( $slug ) {
		global $menu;

		if ( empty( $menu ) || ! is_array( $menu ) ) {
			return false;
		}

		foreach ( $menu as $menu_item ) {
			if ( isset( $menu_item[2] ) && $slug === $menu_item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redirect the top-level BizerOS admin page to the front-end chat UI.
	 *
	 * @return void
	 */
	public function render_chat_menu_redirect() {
		$url = $this->get_chat_page_url();

		if ( '' === $url ) {
			$url = admin_url( 'options-general.php?page=' . $this->page_slug );
		}

		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
			exit;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BizerOS', 'bizeros' ) . '</h1>';
		echo '<p>' . esc_html__( 'Opening the BizerOS chat UI...', 'bizeros' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open BizerOS', 'bizeros' ) . '</a></p>';
		echo '<script>window.location.href=' . wp_json_encode( $url ) . ';</script>';
		echo '</div>';
	}

	/**
	 * Redirect the top-level settings submenu to the existing Settings > BizerOS page.
	 *
	 * @return void
	 */
	public function render_settings_menu_redirect() {
		$url = admin_url( 'options-general.php?page=' . $this->page_slug );

		if ( ! headers_sent() ) {
			wp_safe_redirect( $url );
			exit;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'BizerOS Settings', 'bizeros' ) . '</h1>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open Settings', 'bizeros' ) . '</a></p>';
		echo '<script>window.location.href=' . wp_json_encode( $url ) . ';</script>';
		echo '</div>';
	}

	/**
	 * Register settings, sections, and fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			$this->option_group,
			BIZEROS_OPTION_TRAEFIK_HOST,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_traefik_host' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			$this->option_group,
			BIZEROS_OPTION_HERMES_WEBHOOK_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_hermes_webhook_url' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			$this->option_group,
			BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_hermes_webhook_route' ),
				'default'           => BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			$this->option_group,
			BIZEROS_OPTION_HERMES_WEBHOOK_SECRET,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_hermes_webhook_secret' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			$this->option_group,
			BIZEROS_OPTION_AGENT_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_agent_name' ),
				'default'           => BIZEROS_DEFAULT_AGENT_NAME,
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			$this->section_id,
			esc_html__( 'Hermes Webhook Connection', 'bizeros' ),
			array( $this, 'render_main_section' ),
			$this->page_slug
		);

		add_settings_field(
			BIZEROS_OPTION_TRAEFIK_HOST,
			esc_html__( 'TRAEFIK_HOST', 'bizeros' ),
			array( $this, 'render_traefik_host_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			BIZEROS_OPTION_HERMES_WEBHOOK_URL,
			esc_html__( 'Hermes Webhook URL', 'bizeros' ),
			array( $this, 'render_hermes_webhook_url_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE,
			esc_html__( 'Hermes Webhook Route', 'bizeros' ),
			array( $this, 'render_hermes_webhook_route_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			'bizeros_hermes_webhook_endpoint_preview',
			esc_html__( 'Webhook Endpoint Preview', 'bizeros' ),
			array( $this, 'render_hermes_webhook_preview_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			BIZEROS_OPTION_HERMES_WEBHOOK_SECRET,
			esc_html__( 'Webhook Shared Secret', 'bizeros' ),
			array( $this, 'render_hermes_webhook_secret_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			'bizeros_hermes_connection_test',
			esc_html__( 'Connection Test', 'bizeros' ),
			array( $this, 'render_connection_test_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			'bizeros_hermes_webhook_setup',
			esc_html__( 'Hermes config.yaml Setup', 'bizeros' ),
			array( $this, 'render_webhook_setup_field' ),
			$this->page_slug,
			$this->section_id
		);

		add_settings_field(
			BIZEROS_OPTION_AGENT_NAME,
			esc_html__( 'Agent Name', 'bizeros' ),
			array( $this, 'render_agent_name_field' ),
			$this->page_slug,
			$this->section_id
		);
	}

	/**
	 * Enqueue admin assets for the BizerOS settings page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( $this->page_hook !== $hook_suffix ) {
			return;
		}

		if ( defined( 'BIZEROS_ASSETS_URL' ) ) {
			wp_enqueue_style(
				'bizeros-admin',
				BIZEROS_ASSETS_URL . 'css/bizeros-admin.css',
				array(),
				$this->get_asset_version( 'css/bizeros-admin.css' )
			);

			wp_enqueue_script(
				$this->admin_script_handle,
				BIZEROS_ASSETS_URL . 'js/bizeros-admin.js',
				array(),
				$this->get_asset_version( 'js/bizeros-admin.js' ),
				true
			);

			wp_localize_script(
				$this->admin_script_handle,
				'bizerosAdminSettings',
				array(
					'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
					'testAction'            => $this->test_connection_action,
					'nonce'                 => wp_create_nonce( $this->test_connection_nonce_action ),
					'hermesSubdomain'       => defined( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN' ) ? BIZEROS_HERMES_PUBLIC_SUBDOMAIN : 'hermes-agent-olqc',
					'webhookPort'           => defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ? absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) : 8644,
					'webhookPathPrefix'     => '/webhooks/',
					'signatureHeader'       => defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER' ) ? BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER : 'X-Webhook-Signature',
					'signatureAlgorithm'    => defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_ALGORITHM' ) ? BIZEROS_HERMES_WEBHOOK_SIGNATURE_ALGORITHM : 'sha256',
					'optionNames'           => array(
						'traefikHost'   => BIZEROS_OPTION_TRAEFIK_HOST,
						'webhookUrl'    => BIZEROS_OPTION_HERMES_WEBHOOK_URL,
						'webhookRoute'  => BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE,
						'webhookSecret' => BIZEROS_OPTION_HERMES_WEBHOOK_SECRET,
						'apiPath'       => defined( 'BIZEROS_OPTION_HERMES_API_PATH' ) ? BIZEROS_OPTION_HERMES_API_PATH : 'bizeros_hermes_api_path',
					),
					'selectors'             => array(
						'traefikHost'     => '#' . BIZEROS_OPTION_TRAEFIK_HOST,
						'webhookUrl'      => '#' . BIZEROS_OPTION_HERMES_WEBHOOK_URL,
						'webhookRoute'    => '#' . BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE,
						'apiPath'         => '#' . BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE,
						'endpointPreview' => '#bizeros_hermes_webhook_endpoint_preview',
						'testButton'      => '[data-bizeros-test-button]',
						'testResult'      => '[data-bizeros-test-result]',
						'testSpinner'     => '[data-bizeros-test-spinner]',
					),
					'i18n'                  => array(
						'testRunning'       => __( 'Sending a signed test webhook event to Hermes...', 'bizeros' ),
						'testSuccess'       => __( 'Hermes webhook delivery succeeded.', 'bizeros' ),
						'testFailed'        => __( 'Hermes webhook delivery failed.', 'bizeros' ),
						'testUnauthorized'  => __( 'Hermes rejected the webhook signature. Confirm the saved shared secret matches Hermes config.yaml.', 'bizeros' ),
						'networkError'      => __( 'The webhook connection test could not be completed. Please try again.', 'bizeros' ),
						'endpointNeedsHost' => __( 'Enter a TRAEFIK_HOST value or a full Hermes Webhook URL to preview the webhook endpoint.', 'bizeros' ),
					),
				)
			);
		}
	}

	/**
	 * Get an admin asset version.
	 *
	 * @param string $relative_path Relative path inside assets directory.
	 * @return string
	 */
	private function get_asset_version( $relative_path ) {
		$version = defined( 'BIZEROS_VERSION' ) ? (string) BIZEROS_VERSION : '1.0.0';
		$file    = '';

		if ( defined( 'BIZEROS_ASSETS_DIR' ) ) {
			$file = BIZEROS_ASSETS_DIR . ltrim( (string) $relative_path, '/' );
		}

		if ( '' !== $file && is_readable( $file ) ) {
			$filemtime = filemtime( $file );

			if ( $filemtime ) {
				return $version . '.' . $filemtime;
			}
		}

		return $version;
	}

	/**
	 * Handle the admin-only Hermes webhook connection test AJAX request.
	 *
	 * @return void
	 */
	public function handle_test_connection_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'error'   => __( 'You do not have permission to test BizerOS settings.', 'bizeros' ),
					'code'    => 'bizeros_permission_denied',
				),
				403
			);
		}

		if ( ! check_ajax_referer( $this->test_connection_nonce_action, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'success' => false,
					'error'   => __( 'The connection test nonce is invalid. Refresh the settings page and try again.', 'bizeros' ),
					'code'    => 'bizeros_invalid_nonce',
				),
				403
			);
		}

		$message = isset( $_POST['message'] ) && is_scalar( $_POST['message'] )
			? sanitize_text_field( wp_unslash( $_POST['message'] ) )
			: '';

		$result = $this->run_hermes_connection_test( $message );
		$status = ! empty( $result['status_code'] ) ? absint( $result['status_code'] ) : 200;

		if ( empty( $result['success'] ) ) {
			$http_status = $status >= 400 && $status <= 599 ? $status : 200;
			wp_send_json_error( $result, $http_status );
		}

		wp_send_json_success( $result, 200 );
	}

	/**
	 * Run the connection test using the centralized Hermes webhook client.
	 *
	 * @param string $message Optional test message.
	 * @return array
	 */
	private function run_hermes_connection_test( $message = '' ) {
		$diagnostics = $this->get_safe_hermes_diagnostics();

		if ( ! is_object( $this->hermes_api ) ) {
			return array(
				'success'     => false,
				'message'     => '',
				'error'       => __( 'The Hermes webhook client is not available.', 'bizeros' ),
				'code'        => 'bizeros_missing_hermes_api_client',
				'status_code' => 0,
				'diagnostics' => $diagnostics,
			);
		}

		$message = trim( (string) $message );

		if ( '' === $message ) {
			$message = __( 'BizerOS webhook connection test. Reply with OK if immediate replies are supported.', 'bizeros' );
		}

		foreach ( array( 'test_connection', 'test_hermes_connection' ) as $method_name ) {
			if ( ! method_exists( $this->hermes_api, $method_name ) ) {
				continue;
			}

			try {
				$response = $this->hermes_api->{$method_name}( $message );
				return $this->normalize_admin_test_result( $response, $this->get_safe_hermes_diagnostics() );
			} catch ( \Throwable $exception ) {
				return array(
					'success'     => false,
					'message'     => '',
					'error'       => sanitize_text_field( $exception->getMessage() ),
					'code'        => 'bizeros_hermes_webhook_test_exception',
					'status_code' => 500,
					'diagnostics' => $diagnostics,
				);
			}
		}

		if ( method_exists( $this->hermes_api, 'send_webhook_event' ) ) {
			try {
				$response = $this->hermes_api->send_webhook_event(
					array(
						'event_type' => 'connection_test',
						'action'     => 'bizeros.connection_test',
						'message'    => $message,
						'session_id' => 'bizeros-admin-test',
						'metadata'   => array(
							'source'             => 'wordpress',
							'plugin'             => 'bizeros',
							'is_connection_test' => true,
						),
					)
				);

				return $this->normalize_admin_test_result( $response, $this->get_safe_hermes_diagnostics() );
			} catch ( \Throwable $exception ) {
				return array(
					'success'     => false,
					'message'     => '',
					'error'       => sanitize_text_field( $exception->getMessage() ),
					'code'        => 'bizeros_hermes_webhook_test_exception',
					'status_code' => 500,
					'diagnostics' => $diagnostics,
				);
			}
		}

		return array(
			'success'     => false,
			'message'     => '',
			'error'       => __( 'No compatible Hermes webhook test method was found.', 'bizeros' ),
			'code'        => 'bizeros_missing_hermes_webhook_test_method',
			'status_code' => 500,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Normalize a Hermes webhook connection test result.
	 *
	 * @param mixed $response Raw test response.
	 * @param array $diagnostics Safe diagnostics.
	 * @return array
	 */
	private function normalize_admin_test_result( $response, array $diagnostics = array() ) {
		$diagnostics = $this->sanitize_diagnostics_array( $diagnostics );

		if ( is_wp_error( $response ) ) {
			return array(
				'success'     => false,
				'message'     => '',
				'error'       => sanitize_text_field( $response->get_error_message() ),
				'code'        => sanitize_key( $response->get_error_code() ? $response->get_error_code() : 'bizeros_hermes_webhook_test_error' ),
				'status_code' => 0,
				'diagnostics' => $diagnostics,
			);
		}

		if ( is_array( $response ) ) {
			$response_diagnostics = array();

			if ( isset( $response['diagnostics'] ) && is_array( $response['diagnostics'] ) ) {
				$response_diagnostics = $this->sanitize_diagnostics_array( $response['diagnostics'] );
			}

			$diagnostics = ! empty( $response_diagnostics ) ? $response_diagnostics : $diagnostics;
			$success     = ! empty( $response['success'] );
			$status_code = isset( $response['status_code'] ) ? absint( $response['status_code'] ) : 0;
			$code        = isset( $response['code'] ) && is_scalar( $response['code'] ) ? sanitize_key( (string) $response['code'] ) : '';
			$message     = isset( $response['message'] ) && is_scalar( $response['message'] ) ? sanitize_text_field( (string) $response['message'] ) : '';
			$error       = isset( $response['error'] ) && is_scalar( $response['error'] ) ? sanitize_text_field( (string) $response['error'] ) : '';

			if ( $success && '' === $message ) {
				$message = __( 'Your message was sent to Hermes.', 'bizeros' );
			}

			if ( ! $success && '' === $error ) {
				$error = __( 'Hermes webhook returned an unsuccessful response.', 'bizeros' );
			}

			if ( ! $success && ! empty( $diagnostics['recommendation'] ) && is_scalar( $diagnostics['recommendation'] ) ) {
				$recommendation = sanitize_text_field( (string) $diagnostics['recommendation'] );

				if ( '' !== $recommendation && false === strpos( $error, $recommendation ) ) {
					$error = trim( $error . ' ' . $recommendation );
				}
			}

			return array(
				'success'     => $success,
				'message'     => $message,
				'error'       => $error,
				'code'        => $code,
				'status_code' => $status_code,
				'diagnostics' => $diagnostics,
			);
		}

		if ( true === $response ) {
			return array(
				'success'     => true,
				'message'     => __( 'Your message was sent to Hermes.', 'bizeros' ),
				'error'       => '',
				'code'        => '',
				'status_code' => 200,
				'diagnostics' => $diagnostics,
			);
		}

		if ( is_string( $response ) && '' !== trim( $response ) ) {
			return array(
				'success'     => true,
				'message'     => sanitize_text_field( $response ),
				'error'       => '',
				'code'        => '',
				'status_code' => 200,
				'diagnostics' => $diagnostics,
			);
		}

		return array(
			'success'     => false,
			'message'     => '',
			'error'       => __( 'Hermes webhook returned an empty or unexpected response.', 'bizeros' ),
			'code'        => 'bizeros_unexpected_hermes_webhook_response',
			'status_code' => 0,
			'diagnostics' => $diagnostics,
		);
	}

	/**
	 * Get safe redacted Hermes webhook diagnostics from the client or settings.
	 *
	 * @return array
	 */
	private function get_safe_hermes_diagnostics() {
		$diagnostics = array();

		if ( is_object( $this->hermes_api ) ) {
			foreach ( array( 'get_safe_diagnostics', 'get_diagnostics', 'get_auth_diagnostics' ) as $method_name ) {
				if ( ! method_exists( $this->hermes_api, $method_name ) ) {
					continue;
				}

				try {
					$value = $this->hermes_api->{$method_name}();

					if ( is_array( $value ) ) {
						$diagnostics = array_merge( $diagnostics, $value );
						break;
					}
				} catch ( \Throwable $exception ) {
					$diagnostics['diagnostics_error'] = sanitize_text_field( $exception->getMessage() );
				}
			}
		}

		if ( empty( $diagnostics['endpoint_url'] ) ) {
			$diagnostics['endpoint_url'] = $this->get_hermes_webhook_url();
		}

		if ( empty( $diagnostics['webhook_url'] ) ) {
			$diagnostics['webhook_url'] = $this->get_hermes_webhook_url();
		}

		$route = $this->prepare_hermes_webhook_route( get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE ) );

		if ( empty( $diagnostics['webhook_route'] ) ) {
			$diagnostics['webhook_route'] = $route;
		}

		if ( empty( $diagnostics['webhook_path'] ) ) {
			$diagnostics['webhook_path'] = '/webhooks/' . $route;
		}

		if ( empty( $diagnostics['webhook_port'] ) ) {
			$diagnostics['webhook_port'] = defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ? absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) : 8644;
		}

		$secret = (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, '' );

		$diagnostics['signature_header']       = defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER' ) ? BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER : 'X-Webhook-Signature';
		$diagnostics['signature_header_name']  = $diagnostics['signature_header'];
		$diagnostics['signature_algorithm']    = defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_ALGORITHM' ) ? BIZEROS_HERMES_WEBHOOK_SIGNATURE_ALGORITHM : 'sha256';
		$diagnostics['auth_scheme']            = 'webhook_hmac_sha256';
		$diagnostics['auth_header']            = $diagnostics['signature_header'];
		$diagnostics['auth_header_name']       = $diagnostics['signature_header'];
		$diagnostics['auth_value_mode']        = 'HMAC-SHA256 hex digest of exact JSON body';
		$diagnostics['webhook_secret_saved']   = '' !== trim( $secret );
		$diagnostics['webhook_secret_present'] = '' !== trim( $secret );
		$diagnostics['secret_saved']           = '' !== trim( $secret );
		$diagnostics['secret_present']         = '' !== trim( $secret );

		if ( '' !== trim( $secret ) ) {
			$diagnostics['secret_fingerprint']         = substr( hash( 'sha256', $this->normalize_secret_value( $secret ) ), 0, 12 );
			$diagnostics['webhook_secret_fingerprint'] = $diagnostics['secret_fingerprint'];
		}

		return $this->sanitize_diagnostics_array( $diagnostics );
	}

	/**
	 * Sanitize a diagnostics array and ensure secrets are not returned.
	 *
	 * @param array $diagnostics Diagnostics.
	 * @return array
	 */
	private function sanitize_diagnostics_array( array $diagnostics ) {
		$blocked_keys = array(
			'token',
			'auth_token',
			'authorization',
			'password',
			'secret',
			'webhook_secret',
			'shared_secret',
			'signature',
			'api_key',
			'apikey',
		);

		$sanitized = array();

		foreach ( $diagnostics as $key => $value ) {
			$key_string = is_scalar( $key ) ? (string) $key : '';
			$key_safe   = sanitize_key( $key_string );

			if ( '' === $key_safe ) {
				continue;
			}

			$key_lower = strtolower( $key_string );

			foreach ( $blocked_keys as $blocked_key ) {
				if ( $blocked_key === $key_lower ) {
					continue 2;
				}
			}

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
				} else {
					$sanitized[ $key_safe ] = sanitize_text_field( $string_value );
				}
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize the TRAEFIK_HOST setting.
	 *
	 * @param mixed $value Raw setting value.
	 * @return string
	 */
	public function sanitize_traefik_host( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) get_option( BIZEROS_OPTION_TRAEFIK_HOST, '' );
		}

		return $this->prepare_traefik_host( $value );
	}

	/**
	 * Sanitize the full Hermes webhook URL setting.
	 *
	 * @param mixed $value Raw setting value.
	 * @return string
	 */
	public function sanitize_hermes_webhook_url( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_URL, '' );
		}

		return $this->prepare_hermes_webhook_url( $value );
	}

	/**
	 * Sanitize the Hermes webhook route setting.
	 *
	 * @param mixed $value Raw setting value.
	 * @return string
	 */
	public function sanitize_hermes_webhook_route( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE );
		}

		return $this->prepare_hermes_webhook_route( $value );
	}

	/**
	 * Sanitize the Hermes webhook shared secret setting.
	 *
	 * @param mixed $value Raw setting value.
	 * @return string
	 */
	public function sanitize_hermes_webhook_secret( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, '' );
		}

		$clear_secret = isset( $_POST['bizeros_hermes_webhook_secret_clear'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['bizeros_hermes_webhook_secret_clear'] ) );

		if ( $clear_secret ) {
			return '';
		}

		$value = $this->normalize_secret_value( $value );

		if ( '' === $value ) {
			return (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, '' );
		}

		return $value;
	}

	/**
	 * Sanitize the agent name setting.
	 *
	 * @param mixed $value Raw setting value.
	 * @return string
	 */
	public function sanitize_agent_name( $value ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) get_option( BIZEROS_OPTION_AGENT_NAME, BIZEROS_DEFAULT_AGENT_NAME );
		}

		$value = is_scalar( $value ) ? (string) $value : '';
		$value = sanitize_text_field( wp_unslash( $value ) );
		$value = trim( $value );

		if ( '' === $value ) {
			$value = BIZEROS_DEFAULT_AGENT_NAME;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 80 );
		} else {
			$value = substr( $value, 0, 80 );
		}

		if ( $this->is_settings_save_request() ) {
			$old_value = (string) get_option( BIZEROS_OPTION_AGENT_NAME, BIZEROS_DEFAULT_AGENT_NAME );

			if ( $old_value === $value ) {
				$this->set_admin_notice(
					'success',
					__( 'BizerOS settings saved. The agent name did not change, so no Hermes webhook rename event was sent.', 'bizeros' )
				);
			}
		}

		return $value;
	}

	/**
	 * Handle agent name changes by notifying Hermes.
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param mixed  $value     New option value.
	 * @param string $option    Option name.
	 * @return void
	 */
	public function handle_agent_name_updated( $old_value, $value, $option ) {
		unset( $option );

		$old_value = trim( (string) $old_value );
		$value     = trim( (string) $value );

		if ( '' === $value ) {
			$value = BIZEROS_DEFAULT_AGENT_NAME;
		}

		if ( $old_value === $value ) {
			return;
		}

		$message = sprintf(
			'Your name is now %s',
			$value
		);

		$result = $this->send_hermes_agent_name_update( $message );

		if ( ! $this->is_settings_save_request() ) {
			return;
		}

		if ( $result['success'] ) {
			$this->set_admin_notice(
				'success',
				sprintf(
					/* translators: %s: Agent name. */
					__( 'BizerOS settings saved. Hermes was notified by signed webhook that the agent name is now %s.', 'bizeros' ),
					$value
				)
			);

			return;
		}

		$this->set_admin_notice(
			'warning',
			sprintf(
				/* translators: 1: Agent name, 2: Error message. */
				__( 'BizerOS settings saved and the agent name is now %1$s, but the Hermes webhook rename event could not be delivered. %2$s', 'bizeros' ),
				$value,
				$result['error']
			)
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage BizerOS settings.', 'bizeros' ),
				esc_html__( 'Permission denied', 'bizeros' ),
				array( 'response' => 403 )
			);
		}

		$chat_url = $this->get_chat_page_url();

		?>
		<div class="wrap bizeros-admin-wrap">
			<h1><?php echo esc_html__( 'BizerOS Settings', 'bizeros' ); ?></h1>

			<?php if ( '' !== $chat_url ) : ?>
				<p>
					<a class="button button-secondary" href="<?php echo esc_url( $chat_url ); ?>">
						<?php echo esc_html__( 'Open BizerOS Chat UI', 'bizeros' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php settings_errors(); ?>
			<?php $this->render_stored_admin_notice(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
				<?php
				settings_fields( $this->option_group );
				do_settings_sections( $this->page_slug );
				submit_button( esc_html__( 'Save Settings', 'bizeros' ) );
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the main settings section description.
	 *
	 * @return void
	 */
	public function render_main_section() {
		echo '<p>';
		echo esc_html__( 'Configure how WordPress sends signed webhook events to Hermes. User messages are proxied server-side by WordPress, encoded as JSON webhook events, signed with HMAC-SHA256, and delivered to Hermes.', 'bizeros' );
		echo '</p>';
		echo '<p class="description">';
		echo esc_html__( 'Webhook secrets are stored only on the server and are never localized to the public chat JavaScript. Runtime requests use the X-Webhook-Signature header, not Authorization: Basic. Older Basic Auth settings are retained only for backward compatibility and migration.', 'bizeros' );
		echo '</p>';
	}

	/**
	 * Render the TRAEFIK_HOST field.
	 *
	 * @return void
	 */
	public function render_traefik_host_field() {
		$value = (string) get_option( BIZEROS_OPTION_TRAEFIK_HOST, '' );

		?>
		<input
			type="text"
			id="<?php echo esc_attr( BIZEROS_OPTION_TRAEFIK_HOST ); ?>"
			name="<?php echo esc_attr( BIZEROS_OPTION_TRAEFIK_HOST ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text code"
			placeholder="<?php echo esc_attr__( 'example.com', 'bizeros' ); ?>"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: URL pattern. */
				esc_html__( 'Optional when using a full Webhook URL. If no full URL is saved, BizerOS builds the default endpoint as %s.', 'bizeros' ),
				'<code>' . esc_html( 'https://' . BIZEROS_HERMES_PUBLIC_SUBDOMAIN . '.{TRAEFIK_HOST}:' . absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) . '/webhooks/{route}' ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the full Hermes webhook URL field.
	 *
	 * @return void
	 */
	public function render_hermes_webhook_url_field() {
		$value = (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_URL, '' );

		?>
		<input
			type="url"
			id="<?php echo esc_attr( BIZEROS_OPTION_HERMES_WEBHOOK_URL ); ?>"
			name="<?php echo esc_attr( BIZEROS_OPTION_HERMES_WEBHOOK_URL ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text code"
			placeholder="<?php echo esc_attr( 'https://hermes-agent-olqc.example.com:8644/webhooks/wordpress' ); ?>"
			autocomplete="off"
		/>
		<p class="description">
			<?php echo esc_html__( 'Optional override. Enter the complete Hermes webhook endpoint URL when it differs from the TRAEFIK_HOST-derived preview. If you enter only a base URL with no path, BizerOS appends /webhooks/{route}.', 'bizeros' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the Hermes webhook route field.
	 *
	 * @return void
	 */
	public function render_hermes_webhook_route_field() {
		$value = (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE );

		if ( '' === trim( $value ) ) {
			$value = BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE;
		}

		?>
		<input
			type="text"
			id="<?php echo esc_attr( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE ); ?>"
			name="<?php echo esc_attr( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text code"
			placeholder="<?php echo esc_attr( BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE ); ?>"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Webhook path. */
				esc_html__( 'Hermes webhook route name. The default route %s produces the path /webhooks/wordpress and should match platforms.webhook.route in Hermes config.yaml.', 'bizeros' ),
				'<code>' . esc_html( BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render the generated Hermes webhook URL preview field.
	 *
	 * @return void
	 */
	public function render_hermes_webhook_preview_field() {
		$url = $this->get_hermes_webhook_url();

		?>
		<input
			type="text"
			id="bizeros_hermes_webhook_endpoint_preview"
			data-bizeros-endpoint-preview
			data-bizeros-webhook-preview
			data-bizeros-hermes-subdomain="<?php echo esc_attr( defined( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN' ) ? BIZEROS_HERMES_PUBLIC_SUBDOMAIN : 'hermes-agent-olqc' ); ?>"
			data-bizeros-webhook-port="<?php echo esc_attr( (string) ( defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ? absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) : 8644 ) ); ?>"
			data-bizeros-webhook-prefix="/webhooks/"
			readonly="readonly"
			value="<?php echo esc_attr( $url ); ?>"
			class="regular-text code"
			placeholder="<?php echo esc_attr__( 'Enter TRAEFIK_HOST or a full Webhook URL to preview the endpoint', 'bizeros' ); ?>"
			onfocus="this.select();"
		/>
		<p class="description" data-bizeros-endpoint-preview-description>
			<?php
			if ( '' === $url ) {
				echo esc_html__( 'Enter a TRAEFIK_HOST value or a full Hermes Webhook URL to preview the endpoint. The default webhook listener uses port 8644 and /webhooks/wordpress.', 'bizeros' );
			} else {
				echo esc_html__( 'This read-only preview shows where WordPress will POST signed Hermes webhook events. If a full Webhook URL is saved, that URL takes priority over the host-derived default.', 'bizeros' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Render the Hermes webhook shared secret field.
	 *
	 * @return void
	 */
	public function render_hermes_webhook_secret_field() {
		$secret     = (string) get_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, '' );
		$has_secret = '' !== trim( $secret );

		?>
		<input
			type="password"
			id="<?php echo esc_attr( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET ); ?>"
			name="<?php echo esc_attr( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET ); ?>"
			value=""
			class="regular-text"
			placeholder="<?php echo $has_secret ? esc_attr__( 'Saved shared secret is hidden. Enter a new secret to replace it.', 'bizeros' ) : esc_attr__( 'Paste Hermes webhook shared secret', 'bizeros' ); ?>"
			autocomplete="new-password"
		/>
		<p class="description">
			<?php
			if ( $has_secret ) {
				echo esc_html__( 'A Hermes webhook shared secret is saved. For security it is not displayed. Leave this field blank to keep the saved secret, or enter a new one to replace it. BizerOS signs the exact JSON body with HMAC-SHA256 and sends the hex digest in X-Webhook-Signature.', 'bizeros' );
			} else {
				echo esc_html__( 'Required by default. Enter the same shared secret configured in Hermes config.yaml. BizerOS uses it only server-side to create HMAC-SHA256 signatures.', 'bizeros' );
			}
			?>
		</p>
		<?php if ( $has_secret ) : ?>
			<label class="description">
				<input type="checkbox" name="bizeros_hermes_webhook_secret_clear" value="1" />
				<?php echo esc_html__( 'Clear the saved Hermes webhook shared secret', 'bizeros' ); ?>
			</label>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the admin-only Hermes webhook connection test field.
	 *
	 * @return void
	 */
	public function render_connection_test_field() {
		$diagnostics = $this->get_safe_hermes_diagnostics();

		?>
		<div class="bizeros-admin-test" data-bizeros-connection-test>
			<button
				type="button"
				class="button button-secondary"
				data-bizeros-test-button
			>
				<?php echo esc_html__( 'Test Hermes Webhook', 'bizeros' ); ?>
			</button>
			<span class="spinner" data-bizeros-test-spinner aria-hidden="true" hidden></span>

			<div
				class="bizeros-admin-test__result"
				data-bizeros-test-result
				aria-live="polite"
				hidden
			></div>

			<p class="description">
				<?php echo esc_html__( 'Sends a server-side signed test webhook event using the saved URL/route and shared secret. The result reports delivery status, HTTP status, endpoint, route, signature metadata, and redacted response excerpts without revealing the shared secret or generated signature.', 'bizeros' ); ?>
			</p>

			<?php if ( ! empty( $diagnostics ) ) : ?>
				<details class="bizeros-admin-test__details">
					<summary><?php echo esc_html__( 'Current saved diagnostics', 'bizeros' ); ?></summary>
					<ul class="bizeros-admin-diagnostics">
						<?php foreach ( $this->get_display_diagnostics( $diagnostics ) as $label => $value ) : ?>
							<li>
								<strong><?php echo esc_html( $label ); ?>:</strong>
								<code><?php echo esc_html( $value ); ?></code>
							</li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render Hermes config.yaml setup guidance.
	 *
	 * @return void
	 */
	public function render_webhook_setup_field() {
		$route  = $this->prepare_hermes_webhook_route( get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE ) );
		$header = defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER' ) ? BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER : 'X-Webhook-Signature';
		$port   = defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ? absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) : 8644;

		?>
		<div class="bizeros-admin-webhook-guidance">
			<p class="description">
				<?php echo esc_html__( 'Configure Hermes webhook support with values equivalent to the example below. Use the same shared secret in WordPress and Hermes.', 'bizeros' ); ?>
			</p>
			<pre class="bizeros-admin-code-block"><code><?php echo esc_html( "platforms:\n  webhook:\n    enabled: true\n    port: " . $port . "\n    route: " . $route . "\n    secret: \"YOUR_SHARED_SECRET\"\n    signature_header: \"" . $header . "\"" ); ?></code></pre>
			<p class="description">
				<?php
				printf(
					/* translators: 1: Header name, 2: Algorithm. */
					esc_html__( 'BizerOS sends Content-Type: application/json and %1$s containing the %2$s HMAC hex digest of the exact JSON body.', 'bizeros' ),
					'<code>' . esc_html( $header ) . '</code>',
					'<code>HMAC-SHA256</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Get a compact diagnostics list for display.
	 *
	 * @param array $diagnostics Safe diagnostics.
	 * @return array
	 */
	private function get_display_diagnostics( array $diagnostics ) {
		$diagnostics = $this->sanitize_diagnostics_array( $diagnostics );

		$items = array(
			__( 'Endpoint', 'bizeros' )                => isset( $diagnostics['endpoint_url'] ) ? (string) $diagnostics['endpoint_url'] : '',
			__( 'Webhook route', 'bizeros' )           => isset( $diagnostics['webhook_route'] ) ? (string) $diagnostics['webhook_route'] : ( isset( $diagnostics['route'] ) ? (string) $diagnostics['route'] : '' ),
			__( 'Webhook path', 'bizeros' )            => isset( $diagnostics['webhook_path'] ) ? (string) $diagnostics['webhook_path'] : '',
			__( 'Webhook port', 'bizeros' )            => isset( $diagnostics['webhook_port'] ) ? (string) $diagnostics['webhook_port'] : '',
			__( 'Delivery mode', 'bizeros' )           => isset( $diagnostics['delivery_mode'] ) ? (string) $diagnostics['delivery_mode'] : '',
			__( 'Payload mode', 'bizeros' )            => isset( $diagnostics['payload_mode'] ) ? (string) $diagnostics['payload_mode'] : '',
			__( 'Signature header', 'bizeros' )        => isset( $diagnostics['signature_header_name'] ) ? (string) $diagnostics['signature_header_name'] : ( isset( $diagnostics['signature_header'] ) ? (string) $diagnostics['signature_header'] : '' ),
			__( 'Signature algorithm', 'bizeros' )     => isset( $diagnostics['signature_algorithm'] ) ? (string) $diagnostics['signature_algorithm'] : '',
			__( 'Signature value mode', 'bizeros' )    => isset( $diagnostics['auth_value_mode'] ) ? (string) $diagnostics['auth_value_mode'] : '',
			__( 'Shared secret saved', 'bizeros' )     => ! empty( $diagnostics['webhook_secret_saved'] ) || ! empty( $diagnostics['secret_saved'] ) ? __( 'Yes', 'bizeros' ) : __( 'No', 'bizeros' ),
			__( 'Secret fingerprint', 'bizeros' )      => isset( $diagnostics['secret_fingerprint'] ) ? (string) $diagnostics['secret_fingerprint'] : ( isset( $diagnostics['webhook_secret_fingerprint'] ) ? (string) $diagnostics['webhook_secret_fingerprint'] : '' ),
			__( 'Last event type', 'bizeros' )         => isset( $diagnostics['last_event_type'] ) ? (string) $diagnostics['last_event_type'] : '',
			__( 'Last action', 'bizeros' )             => isset( $diagnostics['last_action'] ) ? (string) $diagnostics['last_action'] : '',
			__( 'Last delivery status', 'bizeros' )    => isset( $diagnostics['last_delivery_status_code'] ) ? (string) $diagnostics['last_delivery_status_code'] : ( isset( $diagnostics['remote_status_code'] ) ? (string) $diagnostics['remote_status_code'] : '' ),
			__( 'Last delivery success', 'bizeros' )   => isset( $diagnostics['last_delivery_success'] ) ? ( ! empty( $diagnostics['last_delivery_success'] ) ? __( 'Yes', 'bizeros' ) : __( 'No', 'bizeros' ) ) : '',
			__( 'Recommendation', 'bizeros' )          => isset( $diagnostics['recommendation'] ) ? (string) $diagnostics['recommendation'] : '',
			__( 'HTTP response excerpt', 'bizeros' )   => isset( $diagnostics['http_response_body_excerpt'] ) ? (string) $diagnostics['http_response_body_excerpt'] : ( isset( $diagnostics['http_error_body_excerpt'] ) ? (string) $diagnostics['http_error_body_excerpt'] : '' ),
			__( 'Endpoint error', 'bizeros' )          => isset( $diagnostics['endpoint_error'] ) ? (string) $diagnostics['endpoint_error'] : '',
			__( 'Diagnostics error', 'bizeros' )       => isset( $diagnostics['diagnostics_error'] ) ? (string) $diagnostics['diagnostics_error'] : '',
		);

		foreach ( $items as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				$items[ $label ] = '—';
			}
		}

		return $items;
	}

	/**
	 * Render the agent name field.
	 *
	 * @return void
	 */
	public function render_agent_name_field() {
		$value = (string) get_option( BIZEROS_OPTION_AGENT_NAME, BIZEROS_DEFAULT_AGENT_NAME );

		if ( '' === trim( $value ) ) {
			$value = BIZEROS_DEFAULT_AGENT_NAME;
		}

		?>
		<input
			type="text"
			id="<?php echo esc_attr( BIZEROS_OPTION_AGENT_NAME ); ?>"
			name="<?php echo esc_attr( BIZEROS_OPTION_AGENT_NAME ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr( BIZEROS_DEFAULT_AGENT_NAME ); ?>"
			maxlength="80"
			autocomplete="off"
		/>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Rename prompt pattern. */
				esc_html__( 'When this value changes, BizerOS sends Hermes a signed webhook message similar to %s with the saved name.', 'bizeros' ),
				'<code>' . esc_html__( 'Your name is now BLANK', 'bizeros' ) . '</code>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Build the effective Hermes webhook endpoint URL preview.
	 *
	 * @return string
	 */
	private function get_hermes_webhook_url() {
		$configured_url = $this->prepare_hermes_webhook_url( get_option( BIZEROS_OPTION_HERMES_WEBHOOK_URL, '' ) );
		$route          = $this->prepare_hermes_webhook_route( get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE ) );

		if ( '' !== $configured_url ) {
			$path = (string) wp_parse_url( $configured_url, PHP_URL_PATH );

			if ( '' === trim( $path, '/' ) ) {
				$configured_url = untrailingslashit( $configured_url ) . '/webhooks/' . $route;
			}

			return esc_url_raw( $configured_url );
		}

		$host = $this->prepare_traefik_host( get_option( BIZEROS_OPTION_TRAEFIK_HOST, '' ) );

		if ( '' === $host ) {
			return '';
		}

		$subdomain = defined( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN' ) ? BIZEROS_HERMES_PUBLIC_SUBDOMAIN : 'hermes-agent-olqc';
		$subdomain = sanitize_title_with_dashes( (string) apply_filters( 'bizeros_hermes_public_subdomain', $subdomain ) );

		if ( '' === $subdomain ) {
			$subdomain = 'hermes-agent-olqc';
		}

		$port = defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ? absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) : 8644;

		return esc_url_raw( 'https://' . $subdomain . '.' . $host . ':' . $port . '/webhooks/' . $route );
	}

	/**
	 * Prepare a TRAEFIK_HOST value without capability checks.
	 *
	 * @param mixed $value Raw host.
	 * @return string
	 */
	private function prepare_traefik_host( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_unslash( $value );
		$value = wp_strip_all_tags( $value );
		$value = trim( $value );
		$value = preg_replace( '/\s+/', '', $value );
		$value = preg_replace( '#^[a-z][a-z0-9+\-.]*://#i', '', $value );
		$value = ltrim( $value, '/' );

		$parts = preg_split( '/[\/?#]/', $value );
		$value = isset( $parts[0] ) ? $parts[0] : '';

		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9.-]/', '', $value );
		$value = preg_replace( '/\.{2,}/', '.', $value );
		$value = trim( $value, ".-\t\n\r\0\x0B/" );

		return $value;
	}

	/**
	 * Prepare a full webhook URL without capability checks.
	 *
	 * @param mixed $value Raw URL.
	 * @return string
	 */
	private function prepare_hermes_webhook_url( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_unslash( $value );
		$value = wp_strip_all_tags( $value );
		$value = wp_check_invalid_utf8( $value );
		$value = str_replace( "\0", '', $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$value = esc_url_raw( $value, array( 'http', 'https' ) );

		if ( '' === $value ) {
			return '';
		}

		$scheme = (string) wp_parse_url( $value, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $value, PHP_URL_HOST );

		if ( '' === $host || ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 500 );
		} else {
			$value = substr( $value, 0, 500 );
		}

		return $value;
	}

	/**
	 * Prepare a webhook route without capability checks.
	 *
	 * @param mixed $value Raw route.
	 * @return string
	 */
	private function prepare_hermes_webhook_route( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_unslash( $value );
		$value = wp_strip_all_tags( $value );
		$value = wp_check_invalid_utf8( $value );
		$value = str_replace( "\0", '', $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		$value = preg_replace( '/\s+/', '', trim( $value ) );

		if ( preg_match( '#^[a-z][a-z0-9+\-.]*://#i', $value ) ) {
			$value = (string) wp_parse_url( $value, PHP_URL_PATH );
		}

		$value = ltrim( $value, '/' );
		$value = preg_replace( '#^webhooks/#i', '', $value );
		$value = preg_replace( '#/+#', '/', $value );
		$value = preg_replace( '/[^A-Za-z0-9\-._~\/]/', '', $value );
		$value = trim( $value, "/ \t\n\r\0\x0B" );

		if ( '' === $value ) {
			$value = BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 120 );
		} else {
			$value = substr( $value, 0, 120 );
		}

		return $value;
	}

	/**
	 * Normalize a webhook secret value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function normalize_secret_value( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = wp_unslash( $value );
		$value = wp_check_invalid_utf8( $value );
		$value = str_replace( "\0", '', $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		$value = wp_strip_all_tags( $value );
		$value = trim( $value, " \t\n\r\0\x0B\"'" );

		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 2000 );
		} else {
			$value = substr( $value, 0, 2000 );
		}

		return $value;
	}

	/**
	 * Get the generated front-end BizerOS chat page URL.
	 *
	 * @return string
	 */
	private function get_chat_page_url() {
		if ( class_exists( '\BizerOS\Plugin' ) && method_exists( '\BizerOS\Plugin', 'get_chat_page_url' ) ) {
			$url = \BizerOS\Plugin::get_chat_page_url();

			if ( '' !== $url ) {
				return (string) $url;
			}
		}

		if ( ! defined( 'BIZEROS_OPTION_CHAT_PAGE_ID' ) ) {
			return '';
		}

		$page_id = absint( get_option( BIZEROS_OPTION_CHAT_PAGE_ID, 0 ) );

		if ( ! $page_id ) {
			return '';
		}

		$post = get_post( $page_id );

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? (string) $url : '';
	}

	/**
	 * Send the rename prompt through the centralized Hermes webhook client.
	 *
	 * @param string $message Prompt message.
	 * @return array{success:bool,error:string}
	 */
	private function send_hermes_agent_name_update( $message ) {
		if ( '' === $this->get_hermes_webhook_url() ) {
			return array(
				'success' => false,
				'error'   => __( 'Hermes Webhook URL or TRAEFIK_HOST is not configured.', 'bizeros' ),
			);
		}

		if ( ! is_object( $this->hermes_api ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The Hermes webhook client is not available.', 'bizeros' ),
			);
		}

		if ( method_exists( $this->hermes_api, 'send_webhook_event' ) ) {
			try {
				$response = $this->hermes_api->send_webhook_event(
					array(
						'event_type' => 'agent_name_updated',
						'action'     => 'bizeros.agent.rename',
						'message'    => $message,
						'session_id' => 'bizeros-admin-agent-name',
						'metadata'   => array(
							'source' => 'wordpress',
							'plugin' => 'bizeros',
						),
					)
				);

				return $this->normalize_hermes_result( $response );
			} catch ( \Throwable $exception ) {
				return array(
					'success' => false,
					'error'   => sanitize_text_field( $exception->getMessage() ),
				);
			}
		}

		$method_names = array(
			'send_chat_prompt',
			'send_chat_message',
			'send_message',
			'send_prompt',
			'chat',
			'request',
		);

		foreach ( $method_names as $method_name ) {
			if ( ! method_exists( $this->hermes_api, $method_name ) ) {
				continue;
			}

			try {
				$reflection = new \ReflectionMethod( $this->hermes_api, $method_name );

				if ( $reflection->getNumberOfRequiredParameters() > 1 ) {
					continue;
				}

				$response = $this->hermes_api->{$method_name}( $message );

				return $this->normalize_hermes_result( $response );
			} catch ( \Throwable $exception ) {
				return array(
					'success' => false,
					'error'   => sanitize_text_field( $exception->getMessage() ),
				);
			}
		}

		return array(
			'success' => false,
			'error'   => __( 'No compatible Hermes webhook send method was found.', 'bizeros' ),
		);
	}

	/**
	 * Normalize the Hermes webhook client result.
	 *
	 * @param mixed $response Raw client response.
	 * @return array{success:bool,error:string}
	 */
	private function normalize_hermes_result( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => sanitize_text_field( $response->get_error_message() ),
			);
		}

		if ( true === $response ) {
			return array(
				'success' => true,
				'error'   => '',
			);
		}

		if ( is_array( $response ) ) {
			$success = false;

			if ( array_key_exists( 'success', $response ) ) {
				$success = (bool) $response['success'];
			} elseif ( ! empty( $response['message'] ) || ! empty( $response['data'] ) ) {
				$success = true;
			}

			if ( $success ) {
				return array(
					'success' => true,
					'error'   => '',
				);
			}

			$error = '';

			if ( ! empty( $response['error'] ) && is_scalar( $response['error'] ) ) {
				$error = (string) $response['error'];
			} elseif ( ! empty( $response['message'] ) && is_scalar( $response['message'] ) ) {
				$error = (string) $response['message'];
			}

			if ( '' === $error && ! empty( $response['diagnostics']['recommendation'] ) && is_scalar( $response['diagnostics']['recommendation'] ) ) {
				$error = (string) $response['diagnostics']['recommendation'];
			}

			return array(
				'success' => false,
				'error'   => '' !== $error ? sanitize_text_field( $error ) : __( 'Hermes webhook returned an unsuccessful response.', 'bizeros' ),
			);
		}

		if ( is_string( $response ) && '' !== trim( $response ) ) {
			return array(
				'success' => true,
				'error'   => '',
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Hermes webhook returned an empty response.', 'bizeros' ),
		);
	}

	/**
	 * Determine whether the current request is saving this settings group.
	 *
	 * @return bool
	 */
	private function is_settings_save_request() {
		if ( ! is_admin() || 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return false;
		}

		$option_page = isset( $_POST['option_page'] ) ? sanitize_key( wp_unslash( $_POST['option_page'] ) ) : '';

		return $this->option_group === $option_page;
	}

	/**
	 * Store an admin notice for display after the options.php redirect.
	 *
	 * @param string $type    Notice type.
	 * @param string $message Notice message.
	 * @return void
	 */
	private function set_admin_notice( $type, $message ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$type = in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ? $type : 'info';

		set_transient(
			$this->get_notice_transient_key(),
			array(
				'type'    => $type,
				'message' => wp_kses_post( $message ),
			),
			60
		);
	}

	/**
	 * Render and clear the stored admin notice.
	 *
	 * @return void
	 */
	private function render_stored_admin_notice() {
		$notice = get_transient( $this->get_notice_transient_key() );

		if ( empty( $notice ) || ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $this->get_notice_transient_key() );

		$type = isset( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'info';

		if ( 'success' === $type ) {
			$type = 'updated';
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			wp_kses_post( $notice['message'] )
		);
	}

	/**
	 * Get the current user's admin notice transient key.
	 *
	 * @return string
	 */
	private function get_notice_transient_key() {
		$user_id = get_current_user_id();

		return 'bizeros_admin_notice_' . absint( $user_id );
	}
}