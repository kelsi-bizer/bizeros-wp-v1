<?php
/**
 * Plugin Name: BizerOS
 * Description: Adds a branded AI business operating system chat interface connected to Hermes through signed webhook events.
 * Version: 1.0.0
 * Author: WP-Bizerbuilder
 * Text Domain: bizeros
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package BizerOS
 */

namespace BizerOS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BIZEROS_VERSION' ) ) {
	define( 'BIZEROS_VERSION', '1.0.0' );
}

if ( ! defined( 'BIZEROS_PLUGIN_FILE' ) ) {
	define( 'BIZEROS_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'BIZEROS_PLUGIN_DIR' ) ) {
	define( 'BIZEROS_PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'BIZEROS_PLUGIN_URL' ) ) {
	define( 'BIZEROS_PLUGIN_URL', \plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'BIZEROS_PLUGIN_BASENAME' ) ) {
	define( 'BIZEROS_PLUGIN_BASENAME', \plugin_basename( __FILE__ ) );
}

if ( ! defined( 'BIZEROS_ASSETS_DIR' ) ) {
	define( 'BIZEROS_ASSETS_DIR', BIZEROS_PLUGIN_DIR . 'assets/' );
}

if ( ! defined( 'BIZEROS_ASSETS_URL' ) ) {
	define( 'BIZEROS_ASSETS_URL', BIZEROS_PLUGIN_URL . 'assets/' );
}

if ( ! defined( 'BIZEROS_OPTION_TRAEFIK_HOST' ) ) {
	define( 'BIZEROS_OPTION_TRAEFIK_HOST', 'bizeros_traefik_host' );
}

if ( ! defined( 'BIZEROS_OPTION_AGENT_NAME' ) ) {
	define( 'BIZEROS_OPTION_AGENT_NAME', 'bizeros_agent_name' );
}

/*
 * Webhook-based Hermes integration settings.
 *
 * BizerOS now sends server-side webhook events to Hermes and signs the exact
 * JSON body with HMAC-SHA256. The shared secret is stored only in WordPress and
 * is never localized to public JavaScript.
 */
if ( ! defined( 'BIZEROS_OPTION_HERMES_WEBHOOK_URL' ) ) {
	define( 'BIZEROS_OPTION_HERMES_WEBHOOK_URL', 'bizeros_hermes_webhook_url' );
}

if ( ! defined( 'BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE' ) ) {
	define( 'BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE', 'bizeros_hermes_webhook_route' );
}

if ( ! defined( 'BIZEROS_OPTION_HERMES_WEBHOOK_SECRET' ) ) {
	define( 'BIZEROS_OPTION_HERMES_WEBHOOK_SECRET', 'bizeros_hermes_webhook_secret' );
}

if ( ! defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT' ) ) {
	define( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT', 8644 );
}

if ( ! defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE' ) ) {
	define( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE', 'wordpress' );
}

if ( ! defined( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PATH' ) ) {
	define( 'BIZEROS_DEFAULT_HERMES_WEBHOOK_PATH', '/webhooks/wordpress' );
}

if ( ! defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER' ) ) {
	define( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_HEADER', 'X-Webhook-Signature' );
}

if ( ! defined( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_ALGORITHM' ) ) {
	define( 'BIZEROS_HERMES_WEBHOOK_SIGNATURE_ALGORITHM', 'sha256' );
}

/*
 * Legacy Hermes API/Auth settings.
 *
 * These constants remain defined for backward compatibility and migration only.
 * Runtime Hermes requests should use the webhook URL/route/secret settings above
 * and HMAC-SHA256 signatures instead of Authorization: Basic.
 */
if ( ! defined( 'BIZEROS_OPTION_HERMES_API_PATH' ) ) {
	define( 'BIZEROS_OPTION_HERMES_API_PATH', 'bizeros_hermes_api_path' );
}

if ( ! defined( 'BIZEROS_OPTION_HERMES_AUTH_TOKEN' ) ) {
	define( 'BIZEROS_OPTION_HERMES_AUTH_TOKEN', 'bizeros_hermes_auth_token' );
}

if ( ! defined( 'BIZEROS_OPTION_HERMES_AUTH_SCHEME' ) ) {
	define( 'BIZEROS_OPTION_HERMES_AUTH_SCHEME', 'bizeros_hermes_auth_scheme' );
}

if ( ! defined( 'BIZEROS_OPTION_HERMES_AUTH_HEADER' ) ) {
	define( 'BIZEROS_OPTION_HERMES_AUTH_HEADER', 'bizeros_hermes_auth_header' );
}

if ( ! defined( 'BIZEROS_DEFAULT_HERMES_AUTH_SCHEME' ) ) {
	define( 'BIZEROS_DEFAULT_HERMES_AUTH_SCHEME', 'basic' );
}

if ( ! defined( 'BIZEROS_DEFAULT_HERMES_AUTH_HEADER' ) ) {
	define( 'BIZEROS_DEFAULT_HERMES_AUTH_HEADER', 'Authorization' );
}

if ( ! defined( 'BIZEROS_OPTION_CHAT_PAGE_ID' ) ) {
	define( 'BIZEROS_OPTION_CHAT_PAGE_ID', 'bizeros_chat_page_id' );
}

if ( ! defined( 'BIZEROS_OPTION_VERSION' ) ) {
	define( 'BIZEROS_OPTION_VERSION', 'bizeros_version' );
}

if ( ! defined( 'BIZEROS_DEFAULT_AGENT_NAME' ) ) {
	define( 'BIZEROS_DEFAULT_AGENT_NAME', 'Miles' );
}

if ( ! defined( 'BIZEROS_AJAX_ACTION' ) ) {
	define( 'BIZEROS_AJAX_ACTION', 'bizeros_send_message' );
}

if ( ! defined( 'BIZEROS_NONCE_ACTION' ) ) {
	define( 'BIZEROS_NONCE_ACTION', 'bizeros_chat_nonce' );
}

if ( ! defined( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN' ) ) {
	define( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN', 'hermes-agent-olqc' );
}

if ( ! defined( 'BIZEROS_CHAT_PAGE_TITLE' ) ) {
	define( 'BIZEROS_CHAT_PAGE_TITLE', 'BizerOS' );
}

if ( ! defined( 'BIZEROS_CHAT_PAGE_SLUG' ) ) {
	define( 'BIZEROS_CHAT_PAGE_SLUG', 'bizeros' );
}

if ( ! defined( 'BIZEROS_CHAT_PAGE_META_KEY' ) ) {
	define( 'BIZEROS_CHAT_PAGE_META_KEY', '_bizeros_chat_page' );
}

/**
 * Main BizerOS plugin bootstrap.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Loaded component instances.
	 *
	 * @var array
	 */
	private $components = array();

	/**
	 * Missing dependency messages.
	 *
	 * @var array
	 */
	private $missing_dependencies = array();

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->run();
		}

		return self::$instance;
	}

	/**
	 * Prevent direct construction outside singleton.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 */
	public function __wakeup() {
		_doing_it_wrong( __METHOD__, \esc_html__( 'BizerOS cannot be unserialized.', 'bizeros' ), '1.0.0' );
	}

	/**
	 * Activation callback. Creates default options and ensures the chat UI page exists.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === \get_option( BIZEROS_OPTION_TRAEFIK_HOST, false ) ) {
			\add_option( BIZEROS_OPTION_TRAEFIK_HOST, '' );
		}

		$agent_name = \get_option( BIZEROS_OPTION_AGENT_NAME, false );

		if ( false === $agent_name ) {
			\add_option( BIZEROS_OPTION_AGENT_NAME, BIZEROS_DEFAULT_AGENT_NAME );
		} elseif ( '' === \trim( \wp_strip_all_tags( (string) $agent_name ) ) ) {
			\update_option( BIZEROS_OPTION_AGENT_NAME, BIZEROS_DEFAULT_AGENT_NAME );
		}

		self::initialize_webhook_options( true );
		self::initialize_legacy_options_for_backward_compatibility();

		self::ensure_chat_page_exists();

		\update_option( BIZEROS_OPTION_VERSION, BIZEROS_VERSION );
	}

	/**
	 * Initialize plugin.
	 *
	 * @return void
	 */
	private function run() {
		$this->load_textdomain();
		$this->load_dependencies();

		self::initialize_webhook_options( true );
		self::initialize_legacy_options_for_backward_compatibility();

		$this->init_components();

		\add_filter( 'plugin_action_links_' . BIZEROS_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );

		if ( \is_admin() ) {
			\add_action( 'admin_init', array( $this, 'maybe_ensure_chat_page_exists' ) );
			\add_action( 'admin_menu', array( $this, 'register_chat_menu_link' ), 5 );
		}

		if ( ! empty( $this->missing_dependencies ) ) {
			\add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
		}
	}

	/**
	 * Initialize webhook options and migrate a legacy auth token to the webhook
	 * secret option when the new secret has not been saved yet.
	 *
	 * @param bool $migrate_legacy_secret Whether to copy a legacy token into the webhook secret.
	 * @return void
	 */
	private static function initialize_webhook_options( $migrate_legacy_secret = true ) {
		if ( false === \get_option( BIZEROS_OPTION_HERMES_WEBHOOK_URL, false ) ) {
			\add_option( BIZEROS_OPTION_HERMES_WEBHOOK_URL, '', '', 'no' );
		}

		$route = \get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, false );

		if ( false === $route ) {
			\add_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE, '', 'no' );
		} elseif ( '' === \trim( \wp_strip_all_tags( (string) $route ) ) ) {
			\update_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE );
		}

		$secret = \get_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, false );

		if ( false === $secret ) {
			$migrated_secret = '';

			if ( $migrate_legacy_secret ) {
				$migrated_secret = self::get_legacy_webhook_secret_candidate();
			}

			\add_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, $migrated_secret, '', 'no' );
			return;
		}

		if ( $migrate_legacy_secret && '' === \trim( (string) $secret ) ) {
			$migrated_secret = self::get_legacy_webhook_secret_candidate();

			if ( '' !== $migrated_secret ) {
				\update_option( BIZEROS_OPTION_HERMES_WEBHOOK_SECRET, $migrated_secret );
			}
		}
	}

	/**
	 * Initialize old Hermes API/Auth options only so older code paths do not
	 * encounter missing option values. These settings are deprecated and are not
	 * the active Hermes authentication mechanism.
	 *
	 * @return void
	 */
	private static function initialize_legacy_options_for_backward_compatibility() {
		if ( false === \get_option( BIZEROS_OPTION_HERMES_API_PATH, false ) ) {
			\add_option( BIZEROS_OPTION_HERMES_API_PATH, '', '', 'no' );
		}

		if ( false === \get_option( BIZEROS_OPTION_HERMES_AUTH_TOKEN, false ) ) {
			\add_option( BIZEROS_OPTION_HERMES_AUTH_TOKEN, '', '', 'no' );
		}

		if ( false === \get_option( BIZEROS_OPTION_HERMES_AUTH_SCHEME, false ) ) {
			\add_option( BIZEROS_OPTION_HERMES_AUTH_SCHEME, BIZEROS_DEFAULT_HERMES_AUTH_SCHEME, '', 'no' );
		}

		if ( false === \get_option( BIZEROS_OPTION_HERMES_AUTH_HEADER, false ) ) {
			\add_option( BIZEROS_OPTION_HERMES_AUTH_HEADER, BIZEROS_DEFAULT_HERMES_AUTH_HEADER, '', 'no' );
		}
	}

	/**
	 * Get a sanitized webhook secret candidate from the legacy Basic Auth token.
	 *
	 * @return string
	 */
	private static function get_legacy_webhook_secret_candidate() {
		$legacy_token = \get_option( BIZEROS_OPTION_HERMES_AUTH_TOKEN, '' );
		$legacy_token = self::normalize_secret_value( $legacy_token );

		/**
		 * Filters whether BizerOS should migrate a saved legacy Hermes auth token
		 * into the new webhook shared secret option.
		 *
		 * The migrated value is still stored server-side only. Return false to
		 * force administrators to enter the webhook secret manually.
		 *
		 * @param bool   $should_migrate Whether to migrate.
		 * @param string $legacy_token   Sanitized legacy token candidate.
		 */
		$should_migrate = (bool) \apply_filters( 'bizeros_migrate_legacy_auth_token_to_webhook_secret', true, $legacy_token );

		if ( ! $should_migrate || '' === $legacy_token ) {
			return '';
		}

		return $legacy_token;
	}

	/**
	 * Normalize a secret/token value without exposing it.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function normalize_secret_value( $value ) {
		$value = \is_scalar( $value ) ? (string) $value : '';
		$value = \wp_unslash( $value );
		$value = \wp_check_invalid_utf8( $value );
		$value = \str_replace( "\0", '', $value );
		$value = \preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		$value = \wp_strip_all_tags( $value );
		$value = \trim( $value, " \t\n\r\0\x0B\"'" );

		for ( $i = 0; $i < 8; $i++ ) {
			$previous = $value;

			if ( \preg_match( '/^authorization\s*:\s*(.+)$/i', $value, $matches ) ) {
				$value = \trim( $matches[1], " \t\n\r\0\x0B\"'" );
			}

			if ( \preg_match( '/^(basic|bearer)\s+(.+)$/i', $value, $matches ) ) {
				$value = \trim( $matches[2], " \t\n\r\0\x0B\"'" );
			}

			if ( $previous === $value ) {
				break;
			}
		}

		if ( \function_exists( 'mb_substr' ) ) {
			$value = \mb_substr( $value, 0, 2000 );
		} else {
			$value = \substr( $value, 0, 2000 );
		}

		return $value;
	}

	/**
	 * Build the default host-derived Hermes webhook URL.
	 *
	 * @param string $host Optional TRAEFIK_HOST value. Reads the saved option when blank.
	 * @return string
	 */
	public static function get_default_hermes_webhook_url( $host = '' ) {
		$host = '' !== \trim( (string) $host ) ? $host : \get_option( BIZEROS_OPTION_TRAEFIK_HOST, '' );
		$host = self::sanitize_traefik_host_value( $host );

		if ( '' === $host ) {
			return '';
		}

		$subdomain = \defined( 'BIZEROS_HERMES_PUBLIC_SUBDOMAIN' ) ? BIZEROS_HERMES_PUBLIC_SUBDOMAIN : 'hermes-agent-olqc';
		$subdomain = \sanitize_title_with_dashes( (string) \apply_filters( 'bizeros_hermes_public_subdomain', $subdomain ) );

		if ( '' === $subdomain ) {
			$subdomain = 'hermes-agent-olqc';
		}

		$url = 'https://' . $subdomain . '.' . $host . ':' . absint( BIZEROS_DEFAULT_HERMES_WEBHOOK_PORT ) . self::get_hermes_webhook_path();

		return \esc_url_raw( $url );
	}

	/**
	 * Get the normalized Hermes webhook route.
	 *
	 * @param string|null $route Optional explicit route.
	 * @return string
	 */
	public static function get_hermes_webhook_route( $route = null ) {
		if ( null === $route ) {
			$route = \get_option( BIZEROS_OPTION_HERMES_WEBHOOK_ROUTE, BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE );
		}

		$route = \is_scalar( $route ) ? (string) $route : '';
		$route = \wp_unslash( $route );
		$route = \wp_strip_all_tags( $route );
		$route = \wp_check_invalid_utf8( $route );
		$route = \str_replace( "\0", '', $route );
		$route = \preg_replace( '/[\x00-\x1F\x7F]/', '', $route );
		$route = \preg_replace( '/\s+/', '', \trim( $route ) );
		$route = \preg_replace( '#^[a-z][a-z0-9+\-.]*://#i', '', $route );
		$route = \ltrim( $route, '/' );
		$route = \preg_replace( '#^webhooks/#i', '', $route );
		$route = \preg_replace( '#/+#', '/', $route );
		$route = \preg_replace( '/[^A-Za-z0-9\-._~\/]/', '', $route );
		$route = \trim( $route, "/ \t\n\r\0\x0B" );

		if ( '' === $route ) {
			$route = BIZEROS_DEFAULT_HERMES_WEBHOOK_ROUTE;
		}

		if ( \function_exists( 'mb_substr' ) ) {
			$route = \mb_substr( $route, 0, 120 );
		} else {
			$route = \substr( $route, 0, 120 );
		}

		return $route;
	}

	/**
	 * Get the Hermes webhook path for a route.
	 *
	 * @param string|null $route Optional route.
	 * @return string
	 */
	public static function get_hermes_webhook_path( $route = null ) {
		return '/webhooks/' . self::get_hermes_webhook_route( $route );
	}

	/**
	 * Sanitize a TRAEFIK_HOST value.
	 *
	 * @param mixed $host Raw host.
	 * @return string
	 */
	private static function sanitize_traefik_host_value( $host ) {
		$host = \is_scalar( $host ) ? (string) $host : '';
		$host = \wp_unslash( $host );
		$host = \wp_strip_all_tags( $host );
		$host = \trim( $host );
		$host = \preg_replace( '/\s+/', '', $host );
		$host = \preg_replace( '#^[a-z][a-z0-9+\-.]*://#i', '', $host );
		$host = \ltrim( $host, '/' );

		$parts = \preg_split( '/[\/?#]/', $host );
		$host  = isset( $parts[0] ) ? $parts[0] : '';

		$host = \strtolower( $host );
		$host = \preg_replace( '/[^a-z0-9.-]/', '', $host );
		$host = \preg_replace( '/\.{2,}/', '.', $host );
		$host = \trim( $host, ".-\t\n\r\0\x0B/" );

		return $host;
	}

	/**
	 * Load plugin translations.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		\load_plugin_textdomain(
			'bizeros',
			false,
			\dirname( BIZEROS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Load required plugin files.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$files = array(
			'includes/class-bizeros-hermes-api.php',
			'includes/class-bizeros-ajax.php',
			'public/class-bizeros-public.php',
		);

		if ( \is_admin() ) {
			$files[] = 'admin/class-bizeros-admin.php';
		}

		foreach ( $files as $relative_file ) {
			$file = BIZEROS_PLUGIN_DIR . $relative_file;

			if ( \is_readable( $file ) ) {
				require_once $file;
				continue;
			}

			$this->missing_dependencies[] = $relative_file;
		}
	}

	/**
	 * Initialize admin, public, AJAX, and API components.
	 *
	 * @return void
	 */
	private function init_components() {
		$hermes_api = $this->create_component(
			array(
				'\\BizerOS\\Includes\\BizerOS_Hermes_API',
				'\\BizerOS\\Includes\\Hermes_API',
				'\\BizerOS\\Hermes_API',
				'\\BizerOS_Hermes_API',
			)
		);

		if ( ! $hermes_api ) {
			$this->missing_dependencies[] = 'BizerOS_Hermes_API class';
		}

		if ( \is_admin() && $hermes_api ) {
			$admin = $this->create_component(
				array(
					'\\BizerOS\\Admin\\BizerOS_Admin',
					'\\BizerOS\\Admin\\Admin',
					'\\BizerOS\\BizerOS_Admin',
					'\\BizerOS_Admin',
				),
				array( $hermes_api )
			);

			if ( $admin ) {
				$this->register_component( $admin );
			} else {
				$this->missing_dependencies[] = 'BizerOS_Admin class';
			}
		}

		if ( $hermes_api ) {
			$ajax = $this->create_component(
				array(
					'\\BizerOS\\Includes\\BizerOS_Ajax',
					'\\BizerOS\\Includes\\Ajax',
					'\\BizerOS\\Ajax',
					'\\BizerOS_Ajax',
				),
				array( $hermes_api )
			);

			if ( $ajax ) {
				$this->register_component( $ajax );
			} else {
				$this->missing_dependencies[] = 'BizerOS_Ajax class';
			}
		}

		$public = $this->create_component(
			array(
				'\\BizerOS\\Public_Facing\\BizerOS_Public',
				'\\BizerOS\\Public\\BizerOS_Public',
				'\\BizerOS\\Frontend\\BizerOS_Public',
				'\\BizerOS\\BizerOS_Public',
				'\\BizerOS_Public',
			)
		);

		if ( $public ) {
			$this->register_component( $public );
		} else {
			$this->missing_dependencies[] = 'BizerOS_Public class';
		}
	}

	/**
	 * Create the first available component class from a list.
	 *
	 * @param array $class_names Possible class names.
	 * @param array $args Constructor arguments.
	 * @return object|null
	 */
	private function create_component( array $class_names, array $args = array() ) {
		foreach ( $class_names as $class_name ) {
			if ( \class_exists( $class_name ) ) {
				$component = new $class_name( ...$args );
				$this->components[] = $component;

				return $component;
			}
		}

		return null;
	}

	/**
	 * Register a component's hooks.
	 *
	 * @param object $component Component instance.
	 * @return void
	 */
	private function register_component( $component ) {
		foreach ( array( 'init', 'register_hooks', 'run' ) as $method ) {
			if ( \method_exists( $component, $method ) ) {
				$component->{$method}();
				return;
			}
		}
	}

	/**
	 * Ensure the chat page exists for administrators after plugin load.
	 *
	 * @return void
	 */
	public function maybe_ensure_chat_page_exists() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		self::ensure_chat_page_exists();
	}

	/**
	 * Register a top-level admin menu item labeled BizerOS that opens the chat UI.
	 *
	 * @return void
	 */
	public function register_chat_menu_link() {
		\add_menu_page(
			\esc_html__( 'BizerOS', 'bizeros' ),
			\esc_html__( 'BizerOS', 'bizeros' ),
			'read',
			'bizeros-chat-ui',
			array( $this, 'render_chat_menu_redirect' ),
			'dashicons-format-chat',
			58
		);

		\add_submenu_page(
			'bizeros-chat-ui',
			\esc_html__( 'BizerOS Chat UI', 'bizeros' ),
			\esc_html__( 'Chat UI', 'bizeros' ),
			'read',
			'bizeros-chat-ui',
			array( $this, 'render_chat_menu_redirect' )
		);

		\add_submenu_page(
			'bizeros-chat-ui',
			\esc_html__( 'BizerOS Settings', 'bizeros' ),
			\esc_html__( 'Settings', 'bizeros' ),
			'manage_options',
			'bizeros-settings',
			array( $this, 'render_settings_menu_redirect' )
		);
	}

	/**
	 * Redirect the top-level BizerOS admin page to the front-end chat UI.
	 *
	 * @return void
	 */
	public function render_chat_menu_redirect() {
		$url = self::get_chat_page_url();

		if ( '' === $url ) {
			$url = \admin_url( 'options-general.php?page=bizeros' );
		}

		if ( ! headers_sent() ) {
			\wp_safe_redirect( $url );
			exit;
		}

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'BizerOS', 'bizeros' ) . '</h1>';
		echo '<p>' . \esc_html__( 'Opening the BizerOS chat UI...', 'bizeros' ) . '</p>';
		echo '<p><a class="button button-primary" href="' . \esc_url( $url ) . '">' . \esc_html__( 'Open BizerOS', 'bizeros' ) . '</a></p>';
		echo '<script>window.location.href=' . \wp_json_encode( $url ) . ';</script>';
		echo '</div>';
	}

	/**
	 * Redirect the top-level settings submenu to the existing Settings > BizerOS page.
	 *
	 * @return void
	 */
	public function render_settings_menu_redirect() {
		$url = \admin_url( 'options-general.php?page=bizeros' );

		if ( ! headers_sent() ) {
			\wp_safe_redirect( $url );
			exit;
		}

		echo '<div class="wrap">';
		echo '<h1>' . \esc_html__( 'BizerOS Settings', 'bizeros' ) . '</h1>';
		echo '<p><a class="button button-primary" href="' . \esc_url( $url ) . '">' . \esc_html__( 'Open Settings', 'bizeros' ) . '</a></p>';
		echo '<script>window.location.href=' . \wp_json_encode( $url ) . ';</script>';
		echo '</div>';
	}

	/**
	 * Add settings and chat UI shortcuts on the plugins screen.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$chat_url = self::get_chat_page_url();

		if ( '' !== $chat_url ) {
			$chat_link = \sprintf(
				'<a href="%1$s">%2$s</a>',
				\esc_url( $chat_url ),
				\esc_html__( 'Chat UI', 'bizeros' )
			);

			\array_unshift( $links, $chat_link );
		}

		$settings_link = \sprintf(
			'<a href="%1$s">%2$s</a>',
			\esc_url( \admin_url( 'options-general.php?page=bizeros' ) ),
			\esc_html__( 'Settings', 'bizeros' )
		);

		\array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Render an admin notice when required files or classes are unavailable.
	 *
	 * @return void
	 */
	public function render_dependency_notice() {
		if ( ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$dependencies = \implode( ', ', \array_map( 'esc_html', \array_unique( $this->missing_dependencies ) ) );

		echo '<div class="notice notice-error"><p>';
		echo \esc_html__( 'BizerOS could not load all required plugin components. Please reinstall the plugin or confirm these files/classes exist:', 'bizeros' );
		echo ' <code>' . \wp_kses_post( $dependencies ) . '</code>';
		echo '</p></div>';
	}

	/**
	 * Get the public BizerOS chat page URL.
	 *
	 * @return string
	 */
	public static function get_chat_page_url() {
		if ( \is_admin() && \current_user_can( 'manage_options' ) ) {
			$page_id = self::ensure_chat_page_exists();
		} else {
			$page_id = self::get_saved_chat_page_id();

			if ( ! self::is_usable_chat_page_id( $page_id ) ) {
				$page_id = self::find_existing_chat_page_id();

				if ( $page_id ) {
					self::store_chat_page_id( $page_id );
				}
			}
		}

		if ( ! $page_id ) {
			return '';
		}

		$url = \get_permalink( $page_id );

		return $url ? (string) $url : '';
	}

	/**
	 * Ensure there is a published front-end page containing the BizerOS chat shortcode.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	private static function ensure_chat_page_exists() {
		$page_id = self::get_saved_chat_page_id();

		if ( self::is_usable_chat_page_id( $page_id ) ) {
			self::ensure_page_has_shortcode( $page_id );
			self::store_chat_page_id( $page_id );

			return $page_id;
		}

		$page_id = self::find_existing_chat_page_id();

		if ( $page_id ) {
			self::store_chat_page_id( $page_id );

			return $page_id;
		}

		$page_id = self::create_chat_page();

		if ( $page_id ) {
			self::store_chat_page_id( $page_id );
		}

		return $page_id;
	}

	/**
	 * Get the saved chat page ID option.
	 *
	 * @return int
	 */
	private static function get_saved_chat_page_id() {
		return \absint( \get_option( BIZEROS_OPTION_CHAT_PAGE_ID, 0 ) );
	}

	/**
	 * Store the chat page ID option and mark the page as managed by BizerOS.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private static function store_chat_page_id( $page_id ) {
		$page_id = \absint( $page_id );

		if ( ! $page_id ) {
			return;
		}

		if ( false === \get_option( BIZEROS_OPTION_CHAT_PAGE_ID, false ) ) {
			\add_option( BIZEROS_OPTION_CHAT_PAGE_ID, $page_id, '', 'no' );
		} else {
			\update_option( BIZEROS_OPTION_CHAT_PAGE_ID, $page_id );
		}

		\update_post_meta( $page_id, BIZEROS_CHAT_PAGE_META_KEY, '1' );
	}

	/**
	 * Determine whether a page ID points to a usable, published page.
	 *
	 * @param int $page_id Page ID.
	 * @return bool
	 */
	private static function is_usable_chat_page_id( $page_id ) {
		$page_id = \absint( $page_id );

		if ( ! $page_id ) {
			return false;
		}

		$post = \get_post( $page_id );

		return $post instanceof \WP_Post
			&& 'page' === $post->post_type
			&& 'publish' === $post->post_status;
	}

	/**
	 * Find an existing published page that already contains the BizerOS chat shortcode.
	 *
	 * @return int Page ID, or 0 when not found.
	 */
	private static function find_existing_chat_page_id() {
		$page_id = self::find_existing_chat_page_id_by_meta();

		if ( $page_id ) {
			self::ensure_page_has_shortcode( $page_id );
			return $page_id;
		}

		$page_id = self::find_existing_chat_page_id_by_shortcode();

		if ( $page_id ) {
			return $page_id;
		}

		return 0;
	}

	/**
	 * Find a previously marked BizerOS chat page.
	 *
	 * @return int
	 */
	private static function find_existing_chat_page_id_by_meta() {
		$pages = \get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
				'meta_query'             => array(
					array(
						'key'   => BIZEROS_CHAT_PAGE_META_KEY,
						'value' => '1',
					),
				),
			)
		);

		if ( empty( $pages ) ) {
			return 0;
		}

		return \absint( $pages[0] );
	}

	/**
	 * Find a published page containing the BizerOS shortcode.
	 *
	 * @return int
	 */
	private static function find_existing_chat_page_id_by_shortcode() {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return 0;
		}

		$shortcode_like       = '%' . $wpdb->esc_like( '[bizeros_chat' ) . '%';
		$shortcode_alias_like = '%' . $wpdb->esc_like( '[bizeros' ) . '%';

		$page_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID
				FROM {$wpdb->posts}
				WHERE post_type = %s
					AND post_status = %s
					AND (post_content LIKE %s OR post_content LIKE %s)
				ORDER BY
					CASE
						WHEN post_name = %s THEN 0
						WHEN post_title = %s THEN 1
						ELSE 2
					END ASC,
					ID ASC
				LIMIT 1",
				'page',
				'publish',
				$shortcode_like,
				$shortcode_alias_like,
				BIZEROS_CHAT_PAGE_SLUG,
				BIZEROS_CHAT_PAGE_TITLE
			)
		);

		return \absint( $page_id );
	}

	/**
	 * Create the front-end BizerOS chat page.
	 *
	 * @return int Page ID, or 0 on failure.
	 */
	private static function create_chat_page() {
		$page_id = \wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => BIZEROS_CHAT_PAGE_TITLE,
				'post_name'      => BIZEROS_CHAT_PAGE_SLUG,
				'post_content'   => "[bizeros_chat]\n",
				'post_excerpt'   => '',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( \is_wp_error( $page_id ) || ! $page_id ) {
			return 0;
		}

		$page_id = \absint( $page_id );

		\update_post_meta( $page_id, BIZEROS_CHAT_PAGE_META_KEY, '1' );

		return $page_id;
	}

	/**
	 * Ensure a stored/managed page still contains the chat shortcode.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private static function ensure_page_has_shortcode( $page_id ) {
		$page_id = \absint( $page_id );

		if ( ! $page_id ) {
			return;
		}

		$post = \get_post( $page_id );

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return;
		}

		if ( self::post_content_has_chat_shortcode( $post->post_content ) ) {
			return;
		}

		$content = \rtrim( (string) $post->post_content );

		if ( '' !== $content ) {
			$content .= "\n\n";
		}

		$content .= "[bizeros_chat]\n";

		\wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => $content,
			)
		);
	}

	/**
	 * Determine whether content contains a BizerOS chat shortcode.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	private static function post_content_has_chat_shortcode( $content ) {
		$content = (string) $content;

		if ( '' === $content ) {
			return false;
		}

		return \has_shortcode( $content, 'bizeros_chat' ) || \has_shortcode( $content, 'bizeros' );
	}
}

\register_activation_hook( __FILE__, array( __NAMESPACE__ . '\\Plugin', 'activate' ) );
\add_action( 'plugins_loaded', array( __NAMESPACE__ . '\\Plugin', 'instance' ) );