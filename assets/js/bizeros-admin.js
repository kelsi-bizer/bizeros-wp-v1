/* global bizerosAdminSettings */

(function () {
	'use strict';

	var DEFAULT_SELECTORS = {
		traefikHost: '#bizeros_traefik_host',
		webhookUrl: '#bizeros_hermes_webhook_url',
		webhookRoute: '#bizeros_hermes_webhook_route',
		apiPath: '#bizeros_hermes_webhook_route',
		endpointPreview: '#bizeros_hermes_webhook_endpoint_preview',
		testButton: '[data-bizeros-test-button]',
		testResult: '[data-bizeros-test-result]',
		testSpinner: '[data-bizeros-test-spinner]'
	};

	var DEFAULT_I18N = {
		testRunning: 'Sending a signed test webhook event to Hermes...',
		testSuccess: 'Hermes webhook delivery succeeded.',
		testFailed: 'Hermes webhook delivery failed.',
		testUnauthorized: 'Hermes rejected the webhook signature. Confirm the saved shared secret matches Hermes config.yaml.',
		networkError: 'The webhook connection test could not be completed. Please try again.',
		endpointNeedsHost: 'Enter a TRAEFIK_HOST value or a full Hermes Webhook URL to preview the webhook endpoint.'
	};

	var DIAGNOSTIC_LABELS = {
		endpoint_url: 'Webhook Endpoint',
		webhook_url: 'Webhook URL',
		last_endpoint_url: 'Last Endpoint',
		base_url: 'Base URL',
		webhook_route: 'Webhook Route',
		route: 'Webhook Route',
		webhook_path: 'Webhook Path',
		webhook_port: 'Webhook Port',
		signature_header: 'Signature Header',
		signature_header_name: 'Signature Header',
		signature_algorithm: 'Signature Algorithm',
		auth_scheme: 'Runtime Authentication',
		auth_header_name: 'Runtime Header',
		auth_value_mode: 'Signature Value',
		webhook_secret_saved: 'Webhook Secret Saved',
		webhook_secret_present: 'Webhook Secret Present',
		secret_saved: 'Webhook Secret Saved',
		secret_present: 'Webhook Secret Present',
		secret_fingerprint: 'Secret Fingerprint',
		webhook_secret_fingerprint: 'Secret Fingerprint',
		delivery_mode: 'Delivery Mode',
		payload_mode: 'Payload Mode',
		configured: 'Configured',
		timeout: 'Timeout',
		status_code: 'HTTP Status',
		last_delivery_status_code: 'Last Delivery HTTP Status',
		remote_status_code: 'Remote HTTP Status',
		last_delivery_success: 'Last Delivery Result',
		last_delivery_error: 'Last Delivery Error',
		last_event_type: 'Last Event Type',
		last_action: 'Last Action',
		code: 'Result Code',
		endpoint_error: 'Endpoint Error',
		http_response_body_excerpt: 'HTTP Response Body Excerpt',
		http_error_body_excerpt: 'HTTP Error Body Excerpt',
		recommendation: 'Recommendation',
		default_webhook_config_hint: 'Hermes Config Hint',
		diagnostics_error: 'Diagnostics Error',
		legacy_basic_auth_deprecated: 'Legacy Basic Auth',
		runtime_authentication_method: 'Runtime Authentication'
	};

	var DIAGNOSTIC_ORDER = [
		'endpoint_url',
		'webhook_url',
		'last_endpoint_url',
		'webhook_route',
		'webhook_path',
		'webhook_port',
		'signature_header_name',
		'signature_algorithm',
		'auth_scheme',
		'auth_value_mode',
		'webhook_secret_saved',
		'secret_fingerprint',
		'delivery_mode',
		'payload_mode',
		'last_delivery_success',
		'last_delivery_status_code',
		'remote_status_code',
		'status_code',
		'code',
		'last_event_type',
		'last_action',
		'recommendation',
		'http_response_body_excerpt',
		'http_error_body_excerpt',
		'configured',
		'timeout',
		'endpoint_error',
		'last_delivery_error',
		'default_webhook_config_hint',
		'diagnostics_error',
		'base_url'
	];

	/**
	 * Run a callback when the DOM is ready.
	 *
	 * @param {Function} callback Callback.
	 * @return {void}
	 */
	function domReady(callback) {
		if ('loading' === document.readyState) {
			document.addEventListener('DOMContentLoaded', callback);
			return;
		}

		callback();
	}

	/**
	 * Merge shallow objects.
	 *
	 * @param {Object} target Target.
	 * @param {Object} source Source.
	 * @return {Object} Merged object.
	 */
	function extend(target, source) {
		var key;

		target = target || {};
		source = source || {};

		for (key in source) {
			if (Object.prototype.hasOwnProperty.call(source, key)) {
				target[key] = source[key];
			}
		}

		return target;
	}

	/**
	 * Normalize localized admin settings.
	 *
	 * @param {Object} rawSettings Raw localized settings.
	 * @return {Object} Settings.
	 */
	function normalizeSettings(rawSettings) {
		var settings = rawSettings || {};

		settings.selectors = extend(extend({}, DEFAULT_SELECTORS), settings.selectors || {});
		settings.i18n = extend(extend({}, DEFAULT_I18N), settings.i18n || {});
		settings.ajaxUrl = settings.ajaxUrl || '';
		settings.testAction = settings.testAction || 'bizeros_test_hermes_connection';
		settings.nonce = settings.nonce || '';
		settings.hermesSubdomain = settings.hermesSubdomain || 'hermes-agent-olqc';
		settings.webhookPort = parseInt(settings.webhookPort, 10) || 8644;
		settings.webhookPathPrefix = settings.webhookPathPrefix || '/webhooks/';
		settings.signatureHeader = settings.signatureHeader || 'X-Webhook-Signature';
		settings.signatureAlgorithm = settings.signatureAlgorithm || 'sha256';

		return settings;
	}

	/**
	 * Trim a value as a string.
	 *
	 * @param {*} value Value.
	 * @return {string} Trimmed string.
	 */
	function trim(value) {
		return String(null === value || 'undefined' === typeof value ? '' : value).replace(/^\s+|\s+$/g, '');
	}

	/**
	 * Sanitize a host/subdomain value similarly to PHP.
	 *
	 * @param {*} value Raw host.
	 * @return {string} Sanitized host.
	 */
	function sanitizeHost(value) {
		value = trim(value);
		value = value.replace(/\s+/g, '');
		value = value.replace(/^[a-z][a-z0-9+\-.]*:\/\//i, '');
		value = value.replace(/^\/+/, '');
		value = value.split(/[\/?#]/)[0] || '';
		value = value.toLowerCase();
		value = value.replace(/[^a-z0-9.-]/g, '');
		value = value.replace(/\.{2,}/g, '.');
		value = value.replace(/^[.\-/\s]+|[.\-/\s]+$/g, '');

		return value;
	}

	/**
	 * Strip simple HTML tags.
	 *
	 * @param {string} value Value.
	 * @return {string} Value without tags.
	 */
	function stripTags(value) {
		return String(value || '').replace(/<[^>]*>/g, '');
	}

	/**
	 * Sanitize a Hermes webhook route.
	 *
	 * @param {*} value Raw route.
	 * @return {string} Sanitized route.
	 */
	function sanitizeWebhookRoute(value) {
		var anchor;
		var parser;

		value = stripTags(trim(value));
		value = value.replace(/\0/g, '');
		value = value.replace(/[\x00-\x1F\x7F]/g, '');
		value = value.replace(/\s+/g, '');

		if (/^[a-z][a-z0-9+\-.]*:\/\//i.test(value)) {
			if ('function' === typeof window.URL) {
				try {
					parser = new window.URL(value);
					value = parser.pathname || '';
				} catch (error) {
					anchor = document.createElement('a');
					anchor.href = value;
					value = anchor.pathname || '';
				}
			} else {
				anchor = document.createElement('a');
				anchor.href = value;
				value = anchor.pathname || '';
			}
		}

		value = value.replace(/^\/+/, '');
		value = value.replace(/^webhooks\//i, '');
		value = value.replace(/\/+/g, '/');
		value = value.replace(/[^A-Za-z0-9\-._~\/]/g, '');
		value = value.replace(/^\/+|\/+$/g, '');

		if (!value) {
			value = 'wordpress';
		}

		if (value.length > 120) {
			value = value.substring(0, 120);
		}

		return value;
	}

	/**
	 * Sanitize and validate a full webhook URL.
	 *
	 * @param {*} value Raw URL.
	 * @return {string} Sanitized URL or blank.
	 */
	function sanitizeWebhookUrl(value) {
		var parser;
		var anchor;

		value = stripTags(trim(value));
		value = value.replace(/\0/g, '');
		value = value.replace(/[\x00-\x1F\x7F]/g, '');

		if (!value || !/^[a-z][a-z0-9+\-.]*:\/\//i.test(value)) {
			return '';
		}

		if ('function' === typeof window.URL) {
			try {
				parser = new window.URL(value);

				if ('http:' !== parser.protocol && 'https:' !== parser.protocol) {
					return '';
				}

				return parser.href;
			} catch (error) {
				return '';
			}
		}

		anchor = document.createElement('a');
		anchor.href = value;

		if (!anchor.hostname || ('http:' !== anchor.protocol && 'https:' !== anchor.protocol)) {
			return '';
		}

		return anchor.href;
	}

	/**
	 * Determine whether a URL has a meaningful path.
	 *
	 * @param {string} url URL.
	 * @return {boolean} Whether the URL has a non-root path.
	 */
	function webhookUrlHasPath(url) {
		var parser;
		var anchor;
		var path;

		if (!url) {
			return false;
		}

		if ('function' === typeof window.URL) {
			try {
				parser = new window.URL(url);
				path = parser.pathname || '';
				return !!path.replace(/^\/+|\/+$/g, '');
			} catch (error) {
				return false;
			}
		}

		anchor = document.createElement('a');
		anchor.href = url;
		path = anchor.pathname || '';

		return !!path.replace(/^\/+|\/+$/g, '');
	}

	/**
	 * Append /webhooks/{route} to a configured URL when it is only a base URL.
	 *
	 * @param {string} url Full or base URL.
	 * @param {string} route Webhook route.
	 * @param {string} prefix Path prefix.
	 * @return {string} Endpoint URL.
	 */
	function ensureWebhookUrlPath(url, route, prefix) {
		url = sanitizeWebhookUrl(url);

		if (!url) {
			return '';
		}

		route = sanitizeWebhookRoute(route);
		prefix = prefix || '/webhooks/';

		if (webhookUrlHasPath(url)) {
			return url;
		}

		return url.replace(/\/+$/, '') + prefix.replace(/\/?$/, '/') + route;
	}

	/**
	 * Build the Hermes webhook endpoint preview URL.
	 *
	 * @param {string} host TRAEFIK_HOST.
	 * @param {string} webhookUrl Full webhook URL override.
	 * @param {string} route Webhook route.
	 * @param {Object} settings Settings.
	 * @return {string} Endpoint URL.
	 */
	function buildEndpointUrl(host, webhookUrl, route, settings) {
		var url;
		var subdomain;

		route = sanitizeWebhookRoute(route);

		url = ensureWebhookUrlPath(webhookUrl, route, settings.webhookPathPrefix);

		if (url) {
			return url;
		}

		host = sanitizeHost(host);
		subdomain = sanitizeHost(settings.hermesSubdomain) || 'hermes-agent-olqc';

		if (!host) {
			return '';
		}

		return 'https://' + subdomain + '.' + host + ':' + String(settings.webhookPort || 8644) + settings.webhookPathPrefix.replace(/\/?$/, '/') + route;
	}

	/**
	 * Safely parse JSON.
	 *
	 * @param {string} text Response body.
	 * @return {*|null} Parsed JSON or null.
	 */
	function parseJson(text) {
		if (!trim(text)) {
			return null;
		}

		try {
			return JSON.parse(text);
		} catch (error) {
			return null;
		}
	}

	/**
	 * Encode form data.
	 *
	 * @param {Object} data Data.
	 * @return {string} Encoded form body.
	 */
	function encodeFormData(data) {
		var pairs = [];
		var key;

		for (key in data) {
			if (Object.prototype.hasOwnProperty.call(data, key)) {
				pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(null === data[key] || 'undefined' === typeof data[key] ? '' : data[key]));
			}
		}

		return pairs.join('&');
	}

	/**
	 * Create an element with optional class name and text.
	 *
	 * @param {string} tag Tag name.
	 * @param {string} className Class name.
	 * @param {string} text Text content.
	 * @return {HTMLElement} Element.
	 */
	function createElement(tag, className, text) {
		var element = document.createElement(tag);

		if (className) {
			element.className = className;
		}

		if ('undefined' !== typeof text && null !== text) {
			element.textContent = String(text);
		}

		return element;
	}

	/**
	 * Get diagnostics from a normalized result.
	 *
	 * @param {Object} result Result.
	 * @return {Object} Diagnostics object.
	 */
	function getDiagnostics(result) {
		if (result && result.diagnostics && 'object' === typeof result.diagnostics) {
			return result.diagnostics;
		}

		return {};
	}

	/**
	 * Extract a recommendation from a result or diagnostics object.
	 *
	 * @param {Object} result Result.
	 * @return {string} Recommendation.
	 */
	function getResultRecommendation(result) {
		var diagnostics = getDiagnostics(result);
		var recommendation = '';

		if (result && result.recommendation) {
			recommendation = trim(result.recommendation);
		}

		if (!recommendation && diagnostics && diagnostics.recommendation) {
			recommendation = trim(diagnostics.recommendation);
		}

		return recommendation;
	}

	/**
	 * Format a diagnostic value for display.
	 *
	 * @param {string} key Diagnostic key.
	 * @param {*} value Value.
	 * @return {string} Formatted value.
	 */
	function formatDiagnosticValue(key, value) {
		var normalized;

		if (true === value) {
			if ('last_delivery_success' === key) {
				return 'Delivered';
			}

			return 'Yes';
		}

		if (false === value) {
			if ('last_delivery_success' === key) {
				return 'Failed';
			}

			return 'No';
		}

		if (null === value || 'undefined' === typeof value) {
			return '';
		}

		if ('object' === typeof value) {
			try {
				return JSON.stringify(value);
			} catch (error) {
				return '';
			}
		}

		normalized = trim(value);

		if ('webhook_route' === key || 'route' === key) {
			return normalized || 'wordpress';
		}

		if ('webhook_path' === key) {
			return normalized || '/webhooks/wordpress';
		}

		if ('webhook_port' === key && !normalized) {
			return '8644';
		}

		if ('signature_algorithm' === key && 'sha256' === normalized.toLowerCase()) {
			return 'HMAC-SHA256';
		}

		if ('signature_header' === key || 'signature_header_name' === key || 'auth_header_name' === key) {
			if ('x-webhook-signature' === normalized.toLowerCase()) {
				return 'X-Webhook-Signature';
			}
		}

		if ('auth_scheme' === key || 'runtime_authentication_method' === key) {
			if ('webhook_hmac_sha256' === normalized.toLowerCase() || 'signed_webhook_hmac_sha256' === normalized.toLowerCase()) {
				return 'Signed webhook (HMAC-SHA256)';
			}
		}

		if ('auth_value_mode' === key) {
			if (-1 !== normalized.toLowerCase().indexOf('hmac')) {
				return 'HMAC-SHA256 hex digest of exact JSON body';
			}
		}

		if ('delivery_mode' === key && 'signed_webhook' === normalized) {
			return 'Signed webhook POST';
		}

		if ('payload_mode' === key && 'webhook_event' === normalized) {
			return 'Webhook event JSON';
		}

		if ('legacy_basic_auth_deprecated' === key && normalized) {
			return 'Deprecated; not used for runtime requests';
		}

		return normalized;
	}

	/**
	 * Determine if a diagnostics key is safe to render.
	 *
	 * @param {string} key Key.
	 * @return {boolean} Whether key is safe.
	 */
	function isSafeDiagnosticKey(key) {
		var lower = String(key || '').toLowerCase();

		if (!lower) {
			return false;
		}

		if ('secret_fingerprint' === lower || 'webhook_secret_fingerprint' === lower) {
			return true;
		}

		if ('webhook_secret_saved' === lower || 'webhook_secret_present' === lower || 'secret_saved' === lower || 'secret_present' === lower) {
			return true;
		}

		if ('signature_header' === lower || 'signature_header_name' === lower || 'signature_algorithm' === lower) {
			return true;
		}

		if ('signature' === lower || 'webhook_signature' === lower || 'hmac' === lower) {
			return false;
		}

		if (-1 !== lower.indexOf('secret') && -1 === lower.indexOf('fingerprint') && -1 === lower.indexOf('saved') && -1 === lower.indexOf('present')) {
			return false;
		}

		if (-1 !== lower.indexOf('token') || -1 !== lower.indexOf('password')) {
			return false;
		}

		if ('authorization' === lower || 'api_key' === lower || 'apikey' === lower) {
			return false;
		}

		return true;
	}

	/**
	 * Get normalized result data from a WordPress AJAX response.
	 *
	 * @param {*} parsed Parsed response.
	 * @param {number} httpStatus HTTP status.
	 * @return {Object} Normalized result.
	 */
	function normalizeAjaxResult(parsed, httpStatus) {
		var result = {};
		var data;

		if (parsed && 'object' === typeof parsed) {
			data = parsed.data && 'object' === typeof parsed.data ? parsed.data : parsed;

			if (data && 'object' === typeof data) {
				result = data;
			}

			if ('undefined' === typeof result.success && 'undefined' !== typeof parsed.success) {
				result.success = !!parsed.success;
			}
		}

		if ('undefined' === typeof result.status_code || !result.status_code) {
			result.status_code = httpStatus || 0;
		}

		return result;
	}

	/**
	 * Extract the best public error text from a result.
	 *
	 * @param {Object} result Result.
	 * @param {Object} settings Settings.
	 * @return {string} Error message.
	 */
	function getResultError(result, settings) {
		var statusCode = parseInt(result && result.status_code, 10) || 0;
		var code = String(result && result.code || '');

		if (401 === statusCode || 403 === statusCode || 'bizeros_hermes_webhook_unauthorized' === code || 'bizeros_hermes_webhook_forbidden' === code || 'bizeros_hermes_unauthorized' === code) {
			return settings.i18n.testUnauthorized;
		}

		if (result && result.error) {
			return String(result.error);
		}

		if (result && result.message && !result.success) {
			return String(result.message);
		}

		return settings.i18n.testFailed;
	}

	/**
	 * Render diagnostics into a result panel.
	 *
	 * @param {HTMLElement} container Result container.
	 * @param {Object} result Test result.
	 * @return {void}
	 */
	function renderDiagnostics(container, result) {
		var diagnostics = getDiagnostics(result);
		var merged = {};
		var used = {};
		var table;
		var tbody;
		var row;
		var labelCell;
		var valueCell;
		var key;
		var i;
		var value;

		for (key in diagnostics) {
			if (Object.prototype.hasOwnProperty.call(diagnostics, key)) {
				merged[key] = diagnostics[key];
			}
		}

		if (result && result.status_code) {
			merged.status_code = result.status_code;
		}

		if (result && result.code) {
			merged.code = result.code;
		}

		if (result && result.recommendation && !merged.recommendation) {
			merged.recommendation = result.recommendation;
		}

		table = createElement('table', 'bizeros-admin-diagnostics-table widefat striped');
		tbody = document.createElement('tbody');

		function addRow(rowKey) {
			var displayValue;

			if (!Object.prototype.hasOwnProperty.call(merged, rowKey) || used[rowKey] || !isSafeDiagnosticKey(rowKey)) {
				return;
			}

			if ('auth_header' === rowKey) {
				return;
			}

			if ('route' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'webhook_route') || used.webhook_route)) {
				return;
			}

			if ('webhook_url' === rowKey && Object.prototype.hasOwnProperty.call(merged, 'endpoint_url')) {
				return;
			}

			if ('signature_header' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'signature_header_name') || used.signature_header_name)) {
				return;
			}

			if ('auth_header_name' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'signature_header_name') || used.signature_header_name)) {
				return;
			}

			if ('secret_present' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'webhook_secret_saved') || used.webhook_secret_saved)) {
				return;
			}

			if ('secret_saved' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'webhook_secret_saved') || used.webhook_secret_saved)) {
				return;
			}

			if ('webhook_secret_present' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'webhook_secret_saved') || used.webhook_secret_saved)) {
				return;
			}

			if ('webhook_secret_fingerprint' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'secret_fingerprint') || used.secret_fingerprint)) {
				return;
			}

			if ('remote_status_code' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'last_delivery_status_code') || used.last_delivery_status_code)) {
				return;
			}

			if ('http_error_body_excerpt' === rowKey && (Object.prototype.hasOwnProperty.call(merged, 'http_response_body_excerpt') || used.http_response_body_excerpt)) {
				return;
			}

			value = merged[rowKey];
			displayValue = formatDiagnosticValue(rowKey, value);

			if (!displayValue && false !== value && 0 !== value) {
				return;
			}

			used[rowKey] = true;

			row = document.createElement('tr');
			labelCell = createElement('th', '', DIAGNOSTIC_LABELS[rowKey] || rowKey.replace(/_/g, ' '));
			valueCell = createElement('td', '', displayValue);

			row.appendChild(labelCell);
			row.appendChild(valueCell);
			tbody.appendChild(row);
		}

		for (i = 0; i < DIAGNOSTIC_ORDER.length; i++) {
			addRow(DIAGNOSTIC_ORDER[i]);
		}

		for (key in merged) {
			if (Object.prototype.hasOwnProperty.call(merged, key)) {
				addRow(key);
			}
		}

		if (tbody.childNodes.length) {
			table.appendChild(tbody);
			container.appendChild(table);
		}
	}

	/**
	 * Render the connection test result.
	 *
	 * @param {HTMLElement} resultContainer Result container.
	 * @param {Object} result Result.
	 * @param {Object} settings Settings.
	 * @return {void}
	 */
	function renderTestResult(resultContainer, result, settings) {
		var success = !!(result && result.success);
		var panel;
		var title;
		var message;
		var responseMessage;
		var errorMessage;
		var recommendation;

		if (!resultContainer) {
			return;
		}

		while (resultContainer.firstChild) {
			resultContainer.removeChild(resultContainer.firstChild);
		}

		resultContainer.removeAttribute('hidden');

		panel = createElement(
			'div',
			'bizeros-admin-test-result notice ' + (success ? 'notice-success' : 'notice-error')
		);

		title = createElement(
			'p',
			'bizeros-admin-test-result__title',
			success ? settings.i18n.testSuccess : settings.i18n.testFailed
		);

		title.style.fontWeight = '600';
		panel.appendChild(title);

		recommendation = getResultRecommendation(result || {});

		if (success) {
			responseMessage = result && result.message ? String(result.message) : '';

			if (responseMessage) {
				message = createElement('p', 'bizeros-admin-test-result__message', responseMessage);
				panel.appendChild(message);
			}

			if (recommendation) {
				message = createElement('p', 'bizeros-admin-test-result__message', recommendation);
				panel.appendChild(message);
			}
		} else {
			errorMessage = getResultError(result || {}, settings);

			if (recommendation && -1 === errorMessage.indexOf(recommendation)) {
				errorMessage += ' ' + recommendation;
			}

			message = createElement('p', 'bizeros-admin-test-result__message', errorMessage);
			panel.appendChild(message);
		}

		renderDiagnostics(panel, result || {});

		resultContainer.appendChild(panel);
	}

	/**
	 * Set connection test loading state.
	 *
	 * @param {HTMLElement} button Button.
	 * @param {HTMLElement} spinner Spinner.
	 * @param {HTMLElement} resultContainer Result container.
	 * @param {boolean} loading Loading state.
	 * @param {Object} settings Settings.
	 * @return {void}
	 */
	function setTestLoading(button, spinner, resultContainer, loading, settings) {
		if (button) {
			button.disabled = !!loading;
			button.setAttribute('aria-disabled', loading ? 'true' : 'false');
			button.className = loading ? button.className + (-1 === button.className.indexOf('is-loading') ? ' is-loading' : '') : trim((' ' + button.className + ' ').replace(' is-loading ', ' '));
		}

		if (spinner) {
			if (loading) {
				spinner.className = spinner.className + (-1 === spinner.className.indexOf('is-active') ? ' is-active' : '');
				spinner.removeAttribute('hidden');
			} else {
				spinner.className = trim((' ' + spinner.className + ' ').replace(' is-active ', ' '));
				spinner.setAttribute('hidden', 'hidden');
			}
		}

		if (resultContainer && loading) {
			while (resultContainer.firstChild) {
				resultContainer.removeChild(resultContainer.firstChild);
			}

			resultContainer.removeAttribute('hidden');
			resultContainer.appendChild(createElement('p', 'bizeros-admin-test-result__loading', settings.i18n.testRunning));
		}
	}

	/**
	 * Run the admin-only Hermes webhook connection test.
	 *
	 * @param {Object} settings Settings.
	 * @return {void}
	 */
	function runConnectionTest(settings) {
		var button = document.querySelector(settings.selectors.testButton);
		var resultContainer = document.querySelector(settings.selectors.testResult);
		var spinner = document.querySelector(settings.selectors.testSpinner);
		var messageInput = document.querySelector('[data-bizeros-test-message]');
		var xhr;
		var payload;

		if (!button || !settings.ajaxUrl) {
			return;
		}

		setTestLoading(button, spinner, resultContainer, true, settings);

		payload = {
			action: settings.testAction,
			nonce: settings.nonce,
			message: messageInput ? messageInput.value : ''
		};

		xhr = new XMLHttpRequest();

		xhr.open('POST', settings.ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.timeout = 60000;

		xhr.onreadystatechange = function () {
			var parsed;
			var result;

			if (4 !== xhr.readyState) {
				return;
			}

			setTestLoading(button, spinner, resultContainer, false, settings);

			parsed = parseJson(xhr.responseText);

			if (!parsed) {
				renderTestResult(
					resultContainer,
					{
						success: false,
						error: settings.i18n.networkError,
						code: 'bizeros_test_parse_error',
						status_code: xhr.status || 0
					},
					settings
				);
				return;
			}

			result = normalizeAjaxResult(parsed, xhr.status);
			renderTestResult(resultContainer, result, settings);
		};

		xhr.onerror = function () {
			setTestLoading(button, spinner, resultContainer, false, settings);
			renderTestResult(
				resultContainer,
				{
					success: false,
					error: settings.i18n.networkError,
					code: 'bizeros_test_network_error',
					status_code: xhr.status || 0
				},
				settings
			);
		};

		xhr.ontimeout = function () {
			setTestLoading(button, spinner, resultContainer, false, settings);
			renderTestResult(
				resultContainer,
				{
					success: false,
					error: settings.i18n.networkError,
					code: 'bizeros_test_timeout',
					status_code: 0
				},
				settings
			);
		};

		try {
			xhr.send(encodeFormData(payload));
		} catch (error) {
			setTestLoading(button, spinner, resultContainer, false, settings);
			renderTestResult(
				resultContainer,
				{
					success: false,
					error: settings.i18n.networkError,
					code: 'bizeros_test_send_error',
					status_code: 0
				},
				settings
			);
		}
	}

	/**
	 * Initialize live webhook endpoint preview.
	 *
	 * @param {Object} settings Settings.
	 * @return {void}
	 */
	function initEndpointPreview(settings) {
		var hostInput = document.querySelector(settings.selectors.traefikHost);
		var webhookUrlInput = document.querySelector(settings.selectors.webhookUrl);
		var routeInput = document.querySelector(settings.selectors.webhookRoute || settings.selectors.apiPath);
		var previewInput = document.querySelector(settings.selectors.endpointPreview);
		var previewDescription = document.querySelector('[data-bizeros-endpoint-preview-description]');

		function updatePreview() {
			var host = hostInput ? hostInput.value : '';
			var webhookUrl = webhookUrlInput ? webhookUrlInput.value : '';
			var route = routeInput ? routeInput.value : '';
			var url = buildEndpointUrl(host, webhookUrl, route, settings);

			if (!previewInput) {
				return;
			}

			previewInput.value = url;

			if (previewDescription && !url) {
				previewDescription.textContent = settings.i18n.endpointNeedsHost;
			}
		}

		if (!previewInput) {
			return;
		}

		if (hostInput) {
			hostInput.addEventListener('input', updatePreview);
			hostInput.addEventListener('change', updatePreview);
			hostInput.addEventListener('blur', updatePreview);
		}

		if (webhookUrlInput) {
			webhookUrlInput.addEventListener('input', updatePreview);
			webhookUrlInput.addEventListener('change', updatePreview);
			webhookUrlInput.addEventListener('blur', updatePreview);
		}

		if (routeInput) {
			routeInput.addEventListener('input', updatePreview);
			routeInput.addEventListener('change', updatePreview);
			routeInput.addEventListener('blur', updatePreview);
		}

		updatePreview();
	}

	/**
	 * Initialize the connection test button.
	 *
	 * @param {Object} settings Settings.
	 * @return {void}
	 */
	function initConnectionTest(settings) {
		var button = document.querySelector(settings.selectors.testButton);

		if (!button) {
			return;
		}

		button.addEventListener('click', function (event) {
			event.preventDefault();
			runConnectionTest(settings);
		});
	}

	/**
	 * Initialize BizerOS admin settings page behavior.
	 *
	 * @return {void}
	 */
	function initBizerOSAdminSettings() {
		var settings = normalizeSettings(window.bizerosAdminSettings || {});

		initEndpointPreview(settings);
		initConnectionTest(settings);
	}

	domReady(initBizerOSAdminSettings);
}());