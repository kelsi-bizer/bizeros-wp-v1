<?php
/**
 * Secure front-end AJAX handlers for BizerOS chat messages.
 *
 * @package BizerOS
 */

namespace BizerOS\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines secure WordPress AJAX handlers for front-end chat messages and
 * returns normalized JSON responses.
 *
 * User messages are accepted by WordPress, nonce/rate-limit checked, and then
 * proxied server-side to Hermes through the centralized signed webhook client.
 */
class BizerOS_Ajax {

	/**
	 * Fallback maximum message length.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_MESSAGE_LENGTH = 8000;

	/**
	 * Maximum session identifier length.
	 *
	 * @var int
	 */
	const MAX_SESSION_ID_LENGTH = 120;

	/**
	 * Default transient rate-limit window in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_RATE_LIMIT_WINDOW = 300;

	/**
	 * Default maximum requests per rate-limit window.
	 *
	 * @var int
	 */
	const DEFAULT_RATE_LIMIT_MAX_REQUESTS = 30;

	/**
	 * Default acknowledgement shown when Hermes accepts webhook delivery but
	 * does not return an immediate reply payload.
	 *
	 * @var string
	 */
	const DEFAULT_WEBHOOK_ACK_MESSAGE = 'Your message was sent to Hermes.';

	/**
	 * Hermes webhook/API client instance.
	 *
	 * @var object|null
	 */
	private $hermes_api;

	/**
	 * Constructor.
	 *
	 * @param object|null $hermes_api Hermes webhook/API client.
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
		$action = $this->get_ajax_action();

		\add_action( 'wp_ajax_' . $action, array( $this, 'handle_send_message' ) );
		\add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'handle_send_message' ) );
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
	 * Handle front-end chat message requests.
	 *
	 * @return void
	 */
	public function handle_send_message() {
		if ( 'POST' !== $this->get_request_method() ) {
			$this->send_error_response(
				__( 'Invalid request method.', 'bizeros' ),
				'bizeros_invalid_request_method',
				405
			);
		}

		if ( ! $this->verify_nonce() ) {
			$this->send_error_response(
				__( 'Your chat session has expired. Please refresh the page and try again.', 'bizeros' ),
				'bizeros_invalid_nonce',
				403
			);
		}

		$message    = $this->prepare_message( $this->get_post_string( 'message' ) );
		$session_id = $this->prepare_session_id( $this->get_session_id_from_request() );

		if ( '' === $message ) {
			$this->send_error_response(
				__( 'Please enter a message before sending.', 'bizeros' ),
				'bizeros_empty_message',
				400
			);
		}

		$max_message_length = $this->get_max_message_length();

		if ( $this->string_length( $message ) > $max_message_length ) {
			$this->send_error_response(
				sprintf(
					/* translators: %d: Maximum message length. */
					__( 'Your message is too long. Please keep it under %d characters.', 'bizeros' ),
					$max_message_length
				),
				'bizeros_message_too_long',
				400
			);
		}

		if ( ! $this->is_rate_limit_allowed( $session_id ) ) {
			$this->send_error_response(
				__( 'You are sending messages too quickly. Please wait a moment and try again.', 'bizeros' ),
				'bizeros_rate_limited',
				429
			);
		}

		if ( ! is_object( $this->hermes_api ) ) {
			$this->send_error_response(
				__( 'The Hermes webhook client is not available. Please contact the site administrator.', 'bizeros' ),
				'bizeros_missing_hermes_api_client',
				500
			);
		}

		$result     = $this->send_to_hermes( $message, $session_id );
		$normalized = $this->normalize_hermes_result( $result );

		if ( ! empty( $normalized['success'] ) ) {
			$this->send_success_response(
				$normalized['message'],
				array(
					'session_id' => $session_id,
				),
				$normalized['status_code']
			);
		}

		$status_code = $this->get_public_error_status_code( $normalized['status_code'], $normalized['code'] );

		$this->send_error_response(
			$normalized['error'],
			$normalized['code'],
			$status_code
		);
	}

	/**
	 * Send a message through the centralized Hermes client.
	 *
	 * In webhook mode, these methods deliver a signed JSON webhook event and may
	 * return either an immediate Hermes reply or an acknowledgement that the event
	 * was delivered.
	 *
	 * @param string $message    Prepared message.
	 * @param string $session_id Prepared session identifier.
	 * @return mixed
	 */
	private function send_to_hermes( $message, $session_id = '' ) {
		$method_names = array(
			'send_chat_message',
			'send_chat_prompt',
			'send_message',
			'send_prompt',
			'chat',
			'request',
		);

		foreach ( $method_names as $method_name ) {
			if ( ! \method_exists( $this->hermes_api, $method_name ) ) {
				continue;
			}

			try {
				$reflection = new \ReflectionMethod( $this->hermes_api, $method_name );

				if ( $reflection->getNumberOfRequiredParameters() > 2 ) {
					continue;
				}

				if ( $reflection->getNumberOfParameters() >= 2 ) {
					return $this->hermes_api->{$method_name}( $message, $session_id );
				}

				return $this->hermes_api->{$method_name}( $message );
			} catch ( \Throwable $exception ) {
				return array(
					'success'     => false,
					'message'     => '',
					'error'       => __( 'Hermes could not process the webhook request. Please try again later.', 'bizeros' ),
					'code'        => 'bizeros_hermes_exception',
					'status_code' => 500,
				);
			}
		}

		return array(
			'success'     => false,
			'message'     => '',
			'error'       => __( 'No compatible Hermes webhook send method was found. Please contact the site administrator.', 'bizeros' ),
			'code'        => 'bizeros_missing_hermes_send_method',
			'status_code' => 500,
		);
	}

	/**
	 * Normalize a Hermes result into the public AJAX response shape.
	 *
	 * @param mixed $result Raw result.
	 * @return array
	 */
	private function normalize_hermes_result( $result ) {
		if ( \is_wp_error( $result ) ) {
			return $this->build_normalized_failure(
				__( 'Hermes webhook could not be reached. Please try again later.', 'bizeros' ),
				$result->get_error_code() ? $result->get_error_code() : 'bizeros_hermes_unreachable',
				0
			);
		}

		if ( true === $result ) {
			return $this->build_normalized_success( '', 200 );
		}

		if ( is_string( $result ) ) {
			if ( '' === trim( $result ) ) {
				return $this->build_normalized_success( '', 200 );
			}

			return $this->build_normalized_success( $result, 200 );
		}

		if ( is_array( $result ) ) {
			$status_code = isset( $result['status_code'] ) ? absint( $result['status_code'] ) : 0;
			$code        = ! empty( $result['code'] ) && is_scalar( $result['code'] ) ? sanitize_key( (string) $result['code'] ) : 'bizeros_hermes_error';

			if ( array_key_exists( 'success', $result ) && false === (bool) $result['success'] ) {
				return $this->build_normalized_failure(
					$this->extract_error_message( $result ),
					$code,
					$status_code
				);
			}

			$message = $this->extract_response_message( $result );

			if ( '' !== $message || ! empty( $result['success'] ) ) {
				return $this->build_normalized_success( $message, $status_code ? $status_code : 200 );
			}

			if ( ! empty( $result['delivered'] ) || ! empty( $result['accepted'] ) || ! empty( $result['acknowledged'] ) ) {
				return $this->build_normalized_success( '', $status_code ? $status_code : 200 );
			}

			return $this->build_normalized_failure(
				__( 'Hermes returned an unexpected webhook response. Please try again later.', 'bizeros' ),
				'bizeros_unexpected_hermes_response',
				$status_code
			);
		}

		return $this->build_normalized_failure(
			__( 'Hermes returned an unexpected webhook response. Please try again later.', 'bizeros' ),
			'bizeros_unexpected_hermes_response',
			0
		);
	}

	/**
	 * Build a normalized success result.
	 *
	 * @param string $message     Response message.
	 * @param int    $status_code HTTP status code.
	 * @return array
	 */
	private function build_normalized_success( $message, $status_code = 200 ) {
		$message = $this->sanitize_response_message( $message );

		if ( '' === $message ) {
			$message = __( self::DEFAULT_WEBHOOK_ACK_MESSAGE, 'bizeros' );
		}

		return array(
			'success'     => true,
			'message'     => $message,
			'error'       => '',
			'code'        => '',
			'status_code' => absint( $status_code ) ? absint( $status_code ) : 200,
		);
	}

	/**
	 * Build a normalized failure result.
	 *
	 * @param string $error       Error message.
	 * @param string $code        Error code.
	 * @param int    $status_code HTTP status code.
	 * @return array
	 */
	private function build_normalized_failure( $error, $code = 'bizeros_hermes_error', $status_code = 0 ) {
		$error = sanitize_text_field( (string) $error );

		if ( '' === $error ) {
			$error = __( 'Hermes could not process the webhook request. Please try again later.', 'bizeros' );
		}

		return array(
			'success'     => false,
			'message'     => '',
			'error'       => $error,
			'code'        => sanitize_key( (string) $code ),
			'status_code' => absint( $status_code ),
		);
	}

	/**
	 * Send a normalized success JSON response.
	 *
	 * @param string $message     Response message.
	 * @param array  $data        Additional response data.
	 * @param int    $status_code HTTP status code.
	 * @return void
	 */
	private function send_success_response( $message, array $data = array(), $status_code = 200 ) {
		$status_code = $this->normalize_ajax_status_code( $status_code, 200 );
		$message     = $this->sanitize_response_message( $message );

		if ( '' === $message ) {
			$message = __( self::DEFAULT_WEBHOOK_ACK_MESSAGE, 'bizeros' );
		}

		$response = array(
			'success'     => true,
			'message'     => $message,
			'error'       => '',
			'code'        => '',
			'status_code' => $status_code,
			'data'        => array_merge(
				array(
					'message' => $message,
				),
				$data
			),
		);

		/**
		 * Filters successful BizerOS AJAX responses.
		 *
		 * @param array        $response Response payload.
		 * @param BizerOS_Ajax $ajax     AJAX handler instance.
		 */
		$response = apply_filters( 'bizeros_ajax_success_response', $response, $this );

		\wp_send_json( $response, $status_code );
	}

	/**
	 * Send a normalized error JSON response.
	 *
	 * @param string $error       Public error message.
	 * @param string $code        Error code.
	 * @param int    $status_code HTTP status code.
	 * @return void
	 */
	private function send_error_response( $error, $code = 'bizeros_ajax_error', $status_code = 400 ) {
		$status_code = $this->normalize_ajax_status_code( $status_code, 400 );
		$error       = sanitize_text_field( (string) $error );

		if ( '' === $error ) {
			$error = __( 'BizerOS could not send your message to Hermes. Please try again later.', 'bizeros' );
		}

		$response = array(
			'success'     => false,
			'message'     => '',
			'error'       => $error,
			'code'        => sanitize_key( (string) $code ),
			'status_code' => $status_code,
			'data'        => array(
				'error' => $error,
				'code'  => sanitize_key( (string) $code ),
			),
		);

		/**
		 * Filters failed BizerOS AJAX responses.
		 *
		 * @param array        $response Response payload.
		 * @param BizerOS_Ajax $ajax     AJAX handler instance.
		 */
		$response = apply_filters( 'bizeros_ajax_error_response', $response, $this );

		\wp_send_json( $response, $status_code );
	}

	/**
	 * Verify the front-end chat nonce.
	 *
	 * @return bool
	 */
	private function verify_nonce() {
		$nonce = '';

		foreach ( array( 'nonce', 'security', '_ajax_nonce', 'bizeros_nonce' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ) {
				$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) );
				break;
			}
		}

		if ( '' === $nonce ) {
			return false;
		}

		return (bool) \wp_verify_nonce( $nonce, $this->get_nonce_action() );
	}

	/**
	 * Determine whether the requester is within the rate limit.
	 *
	 * @param string $session_id Session identifier.
	 * @return bool
	 */
	private function is_rate_limit_allowed( $session_id = '' ) {
		$enabled = (bool) apply_filters( 'bizeros_ajax_rate_limit_enabled', true );

		if ( ! $enabled ) {
			return true;
		}

		$max_requests = absint( apply_filters( 'bizeros_ajax_rate_limit_max_requests', self::DEFAULT_RATE_LIMIT_MAX_REQUESTS ) );
		$window       = absint( apply_filters( 'bizeros_ajax_rate_limit_window', self::DEFAULT_RATE_LIMIT_WINDOW ) );

		if ( 0 === $max_requests || 0 === $window ) {
			return true;
		}

		$identity      = $this->get_rate_limit_identity( $session_id );
		$transient_key = 'bizeros_rl_' . md5( $identity );
		$count         = \get_transient( $transient_key );

		if ( false === $count ) {
			\set_transient( $transient_key, 1, $window );
			return true;
		}

		$count = absint( $count );

		if ( $count >= $max_requests ) {
			return false;
		}

		\set_transient( $transient_key, $count + 1, $window );

		return true;
	}

	/**
	 * Build a rate-limit identity.
	 *
	 * @param string $session_id Session identifier.
	 * @return string
	 */
	private function get_rate_limit_identity( $session_id = '' ) {
		if ( \is_user_logged_in() ) {
			return 'user:' . absint( \get_current_user_id() );
		}

		$ip = $this->get_client_ip();

		if ( '' === $ip && '' !== $session_id ) {
			return 'session:' . $session_id;
		}

		if ( '' === $ip ) {
			return 'anonymous';
		}

		return 'ip:' . $ip;
	}

	/**
	 * Get the client IP address from REMOTE_ADDR only.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$ip = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_scalar( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$ip = (string) apply_filters( 'bizeros_ajax_client_ip', $ip );

		return sanitize_text_field( $ip );
	}

	/**
	 * Get a scalar POST string.
	 *
	 * @param string $key POST field key.
	 * @return string
	 */
	private function get_post_string( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return (string) wp_unslash( $_POST[ $key ] );
	}

	/**
	 * Get a session/conversation ID from the request.
	 *
	 * @return string
	 */
	private function get_session_id_from_request() {
		foreach ( array( 'session_id', 'conversation_id', 'thread_id' ) as $key ) {
			$value = $this->get_post_string( $key );

			if ( '' !== trim( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Prepare and sanitize an incoming chat message.
	 *
	 * @param mixed $message Raw message.
	 * @return string
	 */
	private function prepare_message( $message ) {
		$message = is_scalar( $message ) ? (string) $message : '';
		$message = \wp_check_invalid_utf8( $message );
		$message = str_replace( "\0", '', $message );
		$message = \wp_strip_all_tags( $message );
		$message = trim( $message );

		return $message;
	}

	/**
	 * Prepare a session identifier.
	 *
	 * @param mixed $session_id Raw session identifier.
	 * @return string
	 */
	private function prepare_session_id( $session_id ) {
		$session_id = is_scalar( $session_id ) ? (string) $session_id : '';
		$session_id = sanitize_text_field( $session_id );
		$session_id = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $session_id );
		$session_id = trim( $session_id );

		if ( $this->string_length( $session_id ) > self::MAX_SESSION_ID_LENGTH ) {
			$session_id = $this->substring( $session_id, 0, self::MAX_SESSION_ID_LENGTH );
		}

		return $session_id;
	}

	/**
	 * Sanitize text returned to the browser.
	 *
	 * @param string $message Raw response message.
	 * @return string
	 */
	private function sanitize_response_message( $message ) {
		$message = \wp_check_invalid_utf8( (string) $message );
		$message = str_replace( "\0", '', $message );
		$message = \wp_strip_all_tags( $message );
		$message = trim( $message );

		return $message;
	}

	/**
	 * Extract response text from common API/webhook response shapes.
	 *
	 * @param mixed $data Response data.
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
			'acknowledgement',
			'acknowledgment',
			'status_message',
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
	 * Extract scalar text from a mixed value.
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
	 * Extract an error message from common API/webhook error shapes.
	 *
	 * @param mixed $data Response data.
	 * @return string
	 */
	private function extract_error_message( $data ) {
		$fallback = __( 'Hermes could not process the webhook request. Please try again later.', 'bizeros' );

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
			'status_message',
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

		if ( ! empty( $data['diagnostics']['recommendation'] ) && is_scalar( $data['diagnostics']['recommendation'] ) ) {
			$error = sanitize_text_field( (string) $data['diagnostics']['recommendation'] );

			if ( '' !== $error ) {
				return $error;
			}
		}

		return $fallback;
	}

	/**
	 * Get the configured AJAX action.
	 *
	 * @return string
	 */
	private function get_ajax_action() {
		return defined( 'BIZEROS_AJAX_ACTION' ) ? (string) BIZEROS_AJAX_ACTION : 'bizeros_send_message';
	}

	/**
	 * Get the configured nonce action.
	 *
	 * @return string
	 */
	private function get_nonce_action() {
		return defined( 'BIZEROS_NONCE_ACTION' ) ? (string) BIZEROS_NONCE_ACTION : 'bizeros_chat_nonce';
	}

	/**
	 * Get the current request method.
	 *
	 * @return string
	 */
	private function get_request_method() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || ! is_scalar( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		return strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Get the maximum allowed message length.
	 *
	 * @return int
	 */
	private function get_max_message_length() {
		$api_class = __NAMESPACE__ . '\\BizerOS_Hermes_API';
		$constant  = $api_class . '::MAX_MESSAGE_LENGTH';

		if ( \defined( $constant ) ) {
			$max = absint( \constant( $constant ) );
		} else {
			$max = self::DEFAULT_MAX_MESSAGE_LENGTH;
		}

		$max = absint( apply_filters( 'bizeros_ajax_max_message_length', $max ) );

		return $max ? $max : self::DEFAULT_MAX_MESSAGE_LENGTH;
	}

	/**
	 * Convert internal/remote response codes to a safe public AJAX status code.
	 *
	 * @param int    $status_code Original status code.
	 * @param string $code        Error code.
	 * @return int
	 */
	private function get_public_error_status_code( $status_code, $code = '' ) {
		$status_code = absint( $status_code );
		$code        = sanitize_key( (string) $code );

		if ( in_array(
			$code,
			array(
				'bizeros_missing_traefik_host',
				'bizeros_invalid_hermes_webhook_url',
				'bizeros_invalid_hermes_webhook_base_url',
				'bizeros_missing_hermes_webhook_secret',
				'bizeros_empty_message',
				'bizeros_message_too_long',
			),
			true
		) ) {
			return 400;
		}

		if ( 429 === $status_code ) {
			return 429;
		}

		if ( $status_code >= 400 && $status_code < 500 ) {
			return $status_code;
		}

		return 502;
	}

	/**
	 * Normalize an AJAX HTTP status code.
	 *
	 * @param int $status_code Status code.
	 * @param int $fallback    Fallback status code.
	 * @return int
	 */
	private function normalize_ajax_status_code( $status_code, $fallback ) {
		$status_code = absint( $status_code );

		if ( $status_code < 100 || $status_code > 599 ) {
			return absint( $fallback );
		}

		return $status_code;
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

if ( ! class_exists( __NAMESPACE__ . '\\Ajax', false ) ) {
	class_alias( __NAMESPACE__ . '\\BizerOS_Ajax', __NAMESPACE__ . '\\Ajax' );
}