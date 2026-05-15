<?php
/**
 * BizerOS public-facing shortcode and assets.
 *
 * @package BizerOS
 */

namespace BizerOS\Public_Facing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the front-end shortcode, enqueues public assets, localizes settings,
 * and renders the BizerOS chat interface markup.
 */
class BizerOS_Public {

	/**
	 * Shortcode tag.
	 *
	 * @var string
	 */
	const SHORTCODE = 'bizeros_chat';

	/**
	 * Secondary shortcode alias.
	 *
	 * @var string
	 */
	const SHORTCODE_ALIAS = 'bizeros';

	/**
	 * Public style handle.
	 *
	 * @var string
	 */
	const STYLE_HANDLE = 'bizeros-public';

	/**
	 * Public script handle.
	 *
	 * @var string
	 */
	const SCRIPT_HANDLE = 'bizeros-chat';

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private $hooks_registered = false;

	/**
	 * Whether assets have already been registered.
	 *
	 * @var bool
	 */
	private $assets_registered = false;

	/**
	 * Whether script settings have already been localized.
	 *
	 * @var bool
	 */
	private $settings_localized = false;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		if ( $this->hooks_registered ) {
			return;
		}

		$this->hooks_registered = true;

		\add_action( 'init', array( $this, 'register_shortcodes' ) );
		\add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		\add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets_for_current_page' ), 20 );
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
	 * Register BizerOS shortcodes.
	 *
	 * @return void
	 */
	public function register_shortcodes() {
		\add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		\add_shortcode( self::SHORTCODE_ALIAS, array( $this, 'render_shortcode' ) );
	}

	/**
	 * Register front-end CSS and JavaScript assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		if ( $this->assets_registered ) {
			return;
		}

		$this->assets_registered = true;

		\wp_register_style(
			self::STYLE_HANDLE,
			$this->get_asset_url( 'css/bizeros-public.css' ),
			array(),
			$this->get_asset_version( 'css/bizeros-public.css' )
		);

		\wp_register_script(
			self::SCRIPT_HANDLE,
			$this->get_asset_url( 'js/bizeros-chat.js' ),
			array(),
			$this->get_asset_version( 'js/bizeros-chat.js' ),
			true
		);

		if ( \function_exists( 'wp_set_script_translations' ) ) {
			\wp_set_script_translations( self::SCRIPT_HANDLE, 'bizeros' );
		}
	}

	/**
	 * Enqueue public assets when the current singular page contains the shortcode.
	 *
	 * This helps styles load in the document head for normal post/page content,
	 * while render_shortcode() still enqueues assets as a fallback for dynamic use.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets_for_current_page() {
		if ( \is_admin() || \wp_doing_ajax() || ! \is_singular() ) {
			return;
		}

		$post = \get_post();

		if ( ! $post instanceof \WP_Post || empty( $post->post_content ) ) {
			return;
		}

		if ( \has_shortcode( $post->post_content, self::SHORTCODE ) || \has_shortcode( $post->post_content, self::SHORTCODE_ALIAS ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * Enqueue and localize front-end assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$this->register_assets();
		$this->localize_script_settings();

		\wp_enqueue_style( self::STYLE_HANDLE );
		\wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * Localize BizerOS settings for the front-end JavaScript controller.
	 *
	 * @return void
	 */
	private function localize_script_settings() {
		if ( $this->settings_localized ) {
			return;
		}

		$this->settings_localized = true;

		$agent_name = $this->get_agent_name();

		$settings = array(
			'ajaxUrl'          => \admin_url( 'admin-ajax.php' ),
			'action'           => $this->get_ajax_action(),
			'nonce'            => \wp_create_nonce( $this->get_nonce_action() ),
			'nonceAction'      => $this->get_nonce_action(),
			'agentName'        => $agent_name,
			'maxMessageLength' => $this->get_max_message_length(),
			'promptCards'      => $this->get_prompt_cards(),
			'selectors'        => array(
				'root'         => '[data-bizeros-chat]',
				'form'         => '[data-bizeros-form]',
				'input'        => '[data-bizeros-input]',
				'sendButton'   => '[data-bizeros-send]',
				'promptButton' => '[data-bizeros-prompt]',
				'conversation' => '[data-bizeros-conversation]',
				'messages'     => '[data-bizeros-messages]',
				'status'       => '[data-bizeros-status]',
			),
			'i18n'             => array(
				'agentLabel'       => $agent_name,
				'userLabel'        => \__( 'You', 'bizeros' ),
				'sendLabel'        => \__( 'Send message', 'bizeros' ),
				'sending'          => \__( 'Sending...', 'bizeros' ),
				'thinking'         => \sprintf(
					/* translators: %s: Agent name. */
					\__( '%s is thinking...', 'bizeros' ),
					$agent_name
				),
				'emptyMessage'     => \__( 'Please enter a message before sending.', 'bizeros' ),
				'genericError'     => \__( 'BizerOS could not send your message. Please try again.', 'bizeros' ),
				'connectionError'  => \__( 'Hermes could not be reached. Please try again later.', 'bizeros' ),
				'messageTooLong'   => \sprintf(
					/* translators: %d: Maximum message length. */
					\__( 'Your message is too long. Please keep it under %d characters.', 'bizeros' ),
					$this->get_max_message_length()
				),
				'conversationOpen' => \__( 'Chat conversation opened.', 'bizeros' ),
			),
		);

		/**
		 * Filters public JavaScript settings localized for the BizerOS chat.
		 *
		 * @param array          $settings Public JavaScript settings.
		 * @param BizerOS_Public $public   Public component instance.
		 */
		$settings = \apply_filters( 'bizeros_public_script_settings', $settings, $this );

		\wp_localize_script(
			self::SCRIPT_HANDLE,
			'bizerosChatSettings',
			$settings
		);
	}

	/**
	 * Render the BizerOS chat shortcode.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts = array() ) {
		$this->enqueue_assets();

		$atts = \shortcode_atts(
			array(
				'title'      => \__( 'BizerOS', 'bizeros' ),
				'subtitle'   => \__( 'Run your business from one place.', 'bizeros' ),
				'agent_name' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$title      = \sanitize_text_field( (string) $atts['title'] );
		$subtitle   = \sanitize_text_field( (string) $atts['subtitle'] );
		$agent_name = \sanitize_text_field( (string) $atts['agent_name'] );

		if ( '' === \trim( $agent_name ) ) {
			$agent_name = $this->get_agent_name();
		}

		if ( '' === \trim( $title ) ) {
			$title = \__( 'BizerOS', 'bizeros' );
		}

		if ( '' === \trim( $subtitle ) ) {
			$subtitle = \__( 'Run your business from one place.', 'bizeros' );
		}

		$instance_id        = \wp_unique_id( 'bizeros-chat-' );
		$input_id           = $instance_id . '-input';
		$conversation_id    = $instance_id . '-conversation';
		$prompt_cards       = $this->get_prompt_cards();
		$agent_initial      = $this->get_agent_initial( $agent_name );
		$max_message_length = $this->get_max_message_length();

		\ob_start();
		?>
		<div
			id="<?php echo \esc_attr( $instance_id ); ?>"
			class="bizeros-chat"
			data-bizeros-chat
			data-agent-name="<?php echo \esc_attr( $agent_name ); ?>"
		>
			<div class="bizeros-chat__brand">
				<div class="bizeros-chat__brand-mark" aria-hidden="true">B</div>
				<h2 class="bizeros-chat__title"><?php echo \esc_html( $title ); ?></h2>
				<p class="bizeros-chat__subtitle"><?php echo \esc_html( $subtitle ); ?></p>
			</div>

			<div class="bizeros-chat__prompt-panel">
				<form class="bizeros-chat__form" data-bizeros-form aria-controls="<?php echo \esc_attr( $conversation_id ); ?>">
					<div class="bizeros-chat__form-top">
						<div class="bizeros-chat__agent-chip">
							<span class="bizeros-chat__agent-avatar" aria-hidden="true"><?php echo \esc_html( $agent_initial ); ?></span>
							<span class="bizeros-chat__agent-name"><?php echo \esc_html( $agent_name ); ?></span>
						</div>

						<div class="bizeros-chat__utility-icons" aria-hidden="true">
							<span class="bizeros-chat__utility-icon">✦</span>
							<span class="bizeros-chat__utility-icon">◌</span>
							<span class="bizeros-chat__utility-icon">＋</span>
						</div>
					</div>

					<label class="screen-reader-text" for="<?php echo \esc_attr( $input_id ); ?>">
						<?php
						echo \esc_html(
							\sprintf(
								/* translators: %s: Agent name. */
								\__( 'Message %s', 'bizeros' ),
								$agent_name
							)
						);
						?>
					</label>

					<div class="bizeros-chat__input-row">
						<textarea
							id="<?php echo \esc_attr( $input_id ); ?>"
							class="bizeros-chat__input"
							data-bizeros-input
							rows="1"
							maxlength="<?php echo \esc_attr( (string) $max_message_length ); ?>"
							placeholder="<?php echo \esc_attr__( 'What do you want to work on in your business today?', 'bizeros' ); ?>"
							aria-label="<?php echo \esc_attr__( 'What do you want to work on in your business today?', 'bizeros' ); ?>"
						></textarea>

						<button
							type="submit"
							class="bizeros-chat__send"
							data-bizeros-send
							aria-label="<?php echo \esc_attr__( 'Send message', 'bizeros' ); ?>"
						>
							<span aria-hidden="true">↑</span>
						</button>
					</div>
				</form>
			</div>

			<section
				id="<?php echo \esc_attr( $conversation_id ); ?>"
				class="bizeros-chat__conversation"
				data-bizeros-conversation
				aria-label="<?php echo \esc_attr__( 'BizerOS chat conversation', 'bizeros' ); ?>"
				hidden
			>
				<div
					class="bizeros-chat__messages"
					data-bizeros-messages
					role="log"
					aria-live="polite"
					aria-relevant="additions"
				></div>
				<div class="bizeros-chat__status screen-reader-text" data-bizeros-status aria-live="polite"></div>
			</section>

			<div class="bizeros-chat__suggestions">
				<h3 class="bizeros-chat__suggestions-title">
					<?php
					echo \esc_html(
						\sprintf(
							/* translators: %s: Agent name. */
							\__( 'Try asking %s', 'bizeros' ),
							$agent_name
						)
					);
					?>
				</h3>

				<div class="bizeros-chat__prompt-cards" role="list">
					<?php foreach ( $prompt_cards as $card ) : ?>
						<?php
						$card_icon = isset( $card['icon'] ) ? (string) $card['icon'] : '✦';
						$card_text = isset( $card['text'] ) ? (string) $card['text'] : '';

						if ( '' === \trim( $card_text ) ) {
							continue;
						}
						?>
						<div class="bizeros-chat__prompt-card-wrap" role="listitem">
							<button
								type="button"
								class="bizeros-chat__prompt-card"
								data-bizeros-prompt
								data-prompt="<?php echo \esc_attr( $card_text ); ?>"
							>
								<span class="bizeros-chat__prompt-card-icon" aria-hidden="true"><?php echo \esc_html( $card_icon ); ?></span>
								<span class="bizeros-chat__prompt-card-text"><?php echo \esc_html( $card_text ); ?></span>
							</button>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php

		return (string) \ob_get_clean();
	}

	/**
	 * Get the configured BizerOS agent display name.
	 *
	 * @return string
	 */
	private function get_agent_name() {
		$option_name = \defined( 'BIZEROS_OPTION_AGENT_NAME' ) ? BIZEROS_OPTION_AGENT_NAME : 'bizeros_agent_name';
		$default     = \defined( 'BIZEROS_DEFAULT_AGENT_NAME' ) ? BIZEROS_DEFAULT_AGENT_NAME : 'Miles';
		$agent_name  = \get_option( $option_name, $default );

		$agent_name = \sanitize_text_field( \wp_unslash( (string) $agent_name ) );
		$agent_name = \trim( $agent_name );

		if ( '' === $agent_name ) {
			$agent_name = $default;
		}

		if ( \function_exists( 'mb_substr' ) ) {
			$agent_name = \mb_substr( $agent_name, 0, 80 );
		} else {
			$agent_name = \substr( $agent_name, 0, 80 );
		}

		/**
		 * Filters the public BizerOS agent display name.
		 *
		 * @param string $agent_name Agent display name.
		 */
		$agent_name = (string) \apply_filters( 'bizeros_public_agent_name', $agent_name );

		$agent_name = \sanitize_text_field( $agent_name );
		$agent_name = \trim( $agent_name );

		return '' !== $agent_name ? $agent_name : $default;
	}

	/**
	 * Get the first letter of the agent name for the avatar circle.
	 *
	 * @param string $agent_name Agent display name.
	 * @return string
	 */
	private function get_agent_initial( $agent_name ) {
		$agent_name = \trim( \wp_strip_all_tags( (string) $agent_name ) );

		if ( '' === $agent_name ) {
			return 'M';
		}

		if ( \function_exists( 'mb_substr' ) && \function_exists( 'mb_strtoupper' ) ) {
			return \mb_strtoupper( \mb_substr( $agent_name, 0, 1 ) );
		}

		return \strtoupper( \substr( $agent_name, 0, 1 ) );
	}

	/**
	 * Get the default prompt cards shown below the input panel.
	 *
	 * @return array
	 */
	private function get_prompt_cards() {
		$cards = array(
			array(
				'icon' => '▣',
				'text' => \__( 'Create a 90 day growth plan', 'bizeros' ),
			),
			array(
				'icon' => '↗',
				'text' => \__( 'Analyze my revenue and profit', 'bizeros' ),
			),
			array(
				'icon' => '◎',
				'text' => \__( 'Build a customer acquisition system', 'bizeros' ),
			),
			array(
				'icon' => '✦',
				'text' => \__( 'Turn this idea into a project', 'bizeros' ),
			),
		);

		/**
		 * Filters the public BizerOS prompt cards.
		 *
		 * Each card should contain:
		 * array(
		 *   'icon' => '✦',
		 *   'text' => 'Prompt text',
		 * )
		 *
		 * @param array          $cards  Prompt card definitions.
		 * @param BizerOS_Public $public Public component instance.
		 */
		$cards = \apply_filters( 'bizeros_public_prompt_cards', $cards, $this );

		if ( ! is_array( $cards ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}

			$text = isset( $card['text'] ) ? \sanitize_text_field( (string) $card['text'] ) : '';
			$icon = isset( $card['icon'] ) ? \sanitize_text_field( (string) $card['icon'] ) : '✦';

			if ( '' === \trim( $text ) ) {
				continue;
			}

			$normalized[] = array(
				'icon' => '' !== \trim( $icon ) ? $icon : '✦',
				'text' => $text,
			);
		}

		return $normalized;
	}

	/**
	 * Get the configured AJAX action.
	 *
	 * @return string
	 */
	private function get_ajax_action() {
		$action = \defined( 'BIZEROS_AJAX_ACTION' ) ? BIZEROS_AJAX_ACTION : 'bizeros_send_message';

		return \sanitize_key( (string) $action );
	}

	/**
	 * Get the configured nonce action.
	 *
	 * @return string
	 */
	private function get_nonce_action() {
		return \defined( 'BIZEROS_NONCE_ACTION' ) ? (string) BIZEROS_NONCE_ACTION : 'bizeros_chat_nonce';
	}

	/**
	 * Get the maximum public message length.
	 *
	 * @return int
	 */
	private function get_max_message_length() {
		$constant = 'BizerOS\\Includes\\BizerOS_Hermes_API::MAX_MESSAGE_LENGTH';

		if ( \defined( $constant ) ) {
			$max_length = \absint( \constant( $constant ) );
		} else {
			$max_length = 8000;
		}

		$max_length = \absint( \apply_filters( 'bizeros_public_max_message_length', $max_length ) );

		return $max_length ? $max_length : 8000;
	}

	/**
	 * Get an asset URL.
	 *
	 * @param string $relative_path Relative path inside assets directory.
	 * @return string
	 */
	private function get_asset_url( $relative_path ) {
		$relative_path = \ltrim( (string) $relative_path, '/' );

		if ( \defined( 'BIZEROS_ASSETS_URL' ) ) {
			return BIZEROS_ASSETS_URL . $relative_path;
		}

		if ( \defined( 'BIZEROS_PLUGIN_URL' ) ) {
			return BIZEROS_PLUGIN_URL . 'assets/' . $relative_path;
		}

		return \plugins_url( 'assets/' . $relative_path, \dirname( __DIR__ ) . '/bizeros.php' );
	}

	/**
	 * Get an asset version.
	 *
	 * @param string $relative_path Relative path inside assets directory.
	 * @return string
	 */
	private function get_asset_version( $relative_path ) {
		$relative_path = \ltrim( (string) $relative_path, '/' );
		$version       = \defined( 'BIZEROS_VERSION' ) ? (string) BIZEROS_VERSION : '1.0.0';

		$asset_file = '';

		if ( \defined( 'BIZEROS_ASSETS_DIR' ) ) {
			$asset_file = BIZEROS_ASSETS_DIR . $relative_path;
		} elseif ( \defined( 'BIZEROS_PLUGIN_DIR' ) ) {
			$asset_file = BIZEROS_PLUGIN_DIR . 'assets/' . $relative_path;
		}

		if ( '' !== $asset_file && \is_readable( $asset_file ) ) {
			$filemtime = \filemtime( $asset_file );

			if ( $filemtime ) {
				return $version . '.' . $filemtime;
			}
		}

		return $version;
	}
}

if ( ! \class_exists( 'BizerOS\\BizerOS_Public', false ) ) {
	\class_alias( __NAMESPACE__ . '\\BizerOS_Public', 'BizerOS\\BizerOS_Public' );
}

if ( ! \class_exists( 'BizerOS_Public', false ) ) {
	\class_alias( __NAMESPACE__ . '\\BizerOS_Public', 'BizerOS_Public' );
}