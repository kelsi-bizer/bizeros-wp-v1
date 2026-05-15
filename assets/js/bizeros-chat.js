/* global bizerosChatSettings */

(function () {
	'use strict';

	var DEFAULT_SELECTORS = {
		root: '[data-bizeros-chat]',
		form: '[data-bizeros-form]',
		input: '[data-bizeros-input]',
		sendButton: '[data-bizeros-send]',
		promptButton: '[data-bizeros-prompt]',
		conversation: '[data-bizeros-conversation]',
		messages: '[data-bizeros-messages]',
		status: '[data-bizeros-status]'
	};

	var DEFAULT_I18N = {
		agentLabel: 'Miles',
		userLabel: 'You',
		sendLabel: 'Send message',
		sending: 'Sending...',
		thinking: 'Miles is thinking...',
		emptyMessage: 'Please enter a message before sending.',
		genericError: 'BizerOS could not send your message. Please try again.',
		connectionError: 'Hermes could not be reached. Please try again later.',
		messageTooLong: 'Your message is too long. Please shorten it and try again.',
		conversationOpen: 'Chat conversation opened.'
	};

	/**
	 * Run a callback when the document is ready.
	 *
	 * @param {Function} callback Callback function.
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
	 * Merge two shallow objects.
	 *
	 * @param {Object} target Target object.
	 * @param {Object} source Source object.
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
	 * Trim a value as a string.
	 *
	 * @param {*} value Value to trim.
	 * @return {string} Trimmed string.
	 */
	function trim(value) {
		return String(value || '').replace(/^\s+|\s+$/g, '');
	}

	/**
	 * Get the first usable character for an avatar.
	 *
	 * @param {string} value Source text.
	 * @param {string} fallback Fallback character.
	 * @return {string} Avatar character.
	 */
	function getInitial(value, fallback) {
		value = trim(value);

		if (!value) {
			return fallback || '?';
		}

		return value.charAt(0).toUpperCase();
	}

	/**
	 * Build a per-page-session conversation identifier.
	 *
	 * @return {string} Session identifier.
	 */
	function createSessionId() {
		return 'bizeros-' + String(Date.now()) + '-' + Math.random().toString(36).slice(2, 12);
	}

	/**
	 * Encode an object as x-www-form-urlencoded.
	 *
	 * @param {Object} data Data object.
	 * @return {string} Encoded body.
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
	 * Safely parse JSON.
	 *
	 * @param {string} text Response text.
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
	 * Extract a message from common response shapes.
	 *
	 * @param {*} response Response object.
	 * @return {string} Message text.
	 */
	function extractMessage(response) {
		var keys;
		var i;
		var value;

		if ('string' === typeof response) {
			return trim(response);
		}

		if (!response || 'object' !== typeof response) {
			return '';
		}

		keys = ['message', 'response', 'reply', 'answer', 'content', 'text', 'output_text', 'result'];

		for (i = 0; i < keys.length; i++) {
			if ('undefined' !== typeof response[keys[i]] && null !== response[keys[i]]) {
				value = response[keys[i]];

				if ('string' === typeof value || 'number' === typeof value || 'boolean' === typeof value) {
					value = trim(value);

					if (value) {
						return value;
					}
				}

				if ('object' === typeof value) {
					value = extractMessage(value);

					if (value) {
						return value;
					}
				}
			}
		}

		if (response.data) {
			value = extractMessage(response.data);

			if (value) {
				return value;
			}
		}

		return '';
	}

	/**
	 * Extract an error message from common response shapes.
	 *
	 * @param {*} response Response object.
	 * @param {Object} settings Settings object.
	 * @return {string} Error text.
	 */
	function extractError(response, settings) {
		var keys;
		var i;
		var value;

		if ('string' === typeof response) {
			value = trim(response);
			return value || settings.i18n.genericError;
		}

		if (!response || 'object' !== typeof response) {
			return settings.i18n.genericError;
		}

		keys = ['error', 'errors', 'detail', 'details', 'message'];

		for (i = 0; i < keys.length; i++) {
			if ('undefined' !== typeof response[keys[i]] && null !== response[keys[i]]) {
				value = response[keys[i]];

				if ('string' === typeof value || 'number' === typeof value || 'boolean' === typeof value) {
					value = trim(value);

					if (value) {
						return value;
					}
				}

				if ('object' === typeof value) {
					value = extractError(value, settings);

					if (value) {
						return value;
					}
				}
			}
		}

		if (response.data) {
			value = extractError(response.data, settings);

			if (value) {
				return value;
			}
		}

		return settings.i18n.genericError;
	}

	/**
	 * Normalize localized settings.
	 *
	 * @param {Object} rawSettings Localized settings.
	 * @return {Object} Settings object.
	 */
	function normalizeSettings(rawSettings) {
		var settings = rawSettings || {};

		settings.selectors = extend(extend({}, DEFAULT_SELECTORS), settings.selectors || {});
		settings.i18n = extend(extend({}, DEFAULT_I18N), settings.i18n || {});
		settings.ajaxUrl = settings.ajaxUrl || '';
		settings.action = settings.action || 'bizeros_send_message';
		settings.nonce = settings.nonce || '';
		settings.agentName = settings.agentName || settings.i18n.agentLabel || 'Miles';
		settings.maxMessageLength = parseInt(settings.maxMessageLength, 10) || 8000;

		return settings;
	}

	/**
	 * BizerOS front-end chat controller.
	 *
	 * @param {HTMLElement} root Chat root element.
	 * @param {Object} settings Localized settings.
	 */
	function BizerOSChat(root, settings) {
		this.root = root;
		this.settings = settings;
		this.form = root.querySelector(settings.selectors.form);
		this.input = root.querySelector(settings.selectors.input);
		this.sendButton = root.querySelector(settings.selectors.sendButton);
		this.promptButtons = root.querySelectorAll(settings.selectors.promptButton);
		this.conversation = root.querySelector(settings.selectors.conversation);
		this.messages = root.querySelector(settings.selectors.messages);
		this.status = root.querySelector(settings.selectors.status);
		this.agentName = root.getAttribute('data-agent-name') || settings.agentName || settings.i18n.agentLabel || 'Miles';
		this.sessionId = createSessionId();
		this.isSending = false;
		this.loadingMessage = null;
	}

	/**
	 * Initialize chat events.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.init = function () {
		var i;

		if (!this.form || !this.input || !this.messages) {
			return;
		}

		if (this.root.getAttribute('data-bizeros-initialized')) {
			return;
		}

		this.root.setAttribute('data-bizeros-initialized', 'true');

		this.form.addEventListener('submit', this.handleFormSubmit.bind(this));
		this.input.addEventListener('input', this.handleInputChange.bind(this));
		this.input.addEventListener('keydown', this.handleInputKeydown.bind(this));

		for (i = 0; i < this.promptButtons.length; i++) {
			this.promptButtons[i].addEventListener('click', this.handlePromptClick.bind(this));
		}

		if (this.sendButton) {
			this.sendButton.setAttribute('aria-label', this.settings.i18n.sendLabel);
		}

		this.autoResizeInput();
		this.setSending(false);
	};

	/**
	 * Handle typed form submission.
	 *
	 * @param {Event} event Submit event.
	 * @return {void}
	 */
	BizerOSChat.prototype.handleFormSubmit = function (event) {
		event.preventDefault();
		this.submitMessage(this.input.value, true);
	};

	/**
	 * Handle textarea input.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.handleInputChange = function () {
		this.autoResizeInput();
	};

	/**
	 * Submit on Enter, while preserving Shift+Enter for new lines.
	 *
	 * @param {KeyboardEvent} event Keydown event.
	 * @return {void}
	 */
	BizerOSChat.prototype.handleInputKeydown = function (event) {
		var isEnter = 'Enter' === event.key || 13 === event.keyCode;

		if (!isEnter || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey || event.isComposing) {
			return;
		}

		event.preventDefault();
		this.submitMessage(this.input.value, true);
	};

	/**
	 * Handle prompt card clicks.
	 *
	 * @param {Event} event Click event.
	 * @return {void}
	 */
	BizerOSChat.prototype.handlePromptClick = function (event) {
		var button = event.currentTarget;
		var message = button.getAttribute('data-prompt') || button.textContent || '';

		event.preventDefault();
		this.submitMessage(message, false);
	};

	/**
	 * Submit a message to the AJAX proxy.
	 *
	 * @param {string} rawMessage Raw message.
	 * @param {boolean} clearInput Whether to clear the input after sending.
	 * @return {void}
	 */
	BizerOSChat.prototype.submitMessage = function (rawMessage, clearInput) {
		var message = trim(rawMessage);
		var self = this;
		var loadingMessage;

		if (this.isSending) {
			return;
		}

		if (!message) {
			this.setStatus(this.settings.i18n.emptyMessage);
			this.focusInput();
			return;
		}

		if (message.length > this.settings.maxMessageLength) {
			this.setStatus(this.settings.i18n.messageTooLong);
			this.focusInput();
			return;
		}

		this.openConversation();
		this.appendMessage('user', message);

		if (clearInput) {
			this.input.value = '';
			this.autoResizeInput();
		}

		this.setSending(true);
		this.setStatus(this.settings.i18n.thinking);
		loadingMessage = this.appendTypingMessage();
		this.loadingMessage = loadingMessage;

		this.sendAjaxMessage(
			message,
			function (responseMessage) {
				self.removeLoadingMessage();

				if (!responseMessage) {
					responseMessage = self.settings.i18n.genericError;
				}

				self.appendMessage('agent', responseMessage);
				self.setStatus('');
			},
			function (errorMessage) {
				self.removeLoadingMessage();
				self.appendMessage('error', errorMessage || self.settings.i18n.genericError);
				self.setStatus(errorMessage || self.settings.i18n.genericError);
			},
			function () {
				self.setSending(false);
			}
		);
	};

	/**
	 * Open the conversation panel.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.openConversation = function () {
		if (!this.conversation) {
			return;
		}

		if (this.conversation.hasAttribute('hidden')) {
			this.conversation.removeAttribute('hidden');
			this.root.className += -1 === this.root.className.indexOf('is-open') ? ' is-open' : '';
			this.setStatus(this.settings.i18n.conversationOpen);
		}
	};

	/**
	 * Update loading/sending state.
	 *
	 * @param {boolean} isSending Whether a message is being sent.
	 * @return {void}
	 */
	BizerOSChat.prototype.setSending = function (isSending) {
		var i;

		this.isSending = !!isSending;

		if (this.isSending) {
			this.addClass(this.root, 'is-sending');
			this.addClass(this.root, 'is-loading');
			this.root.setAttribute('aria-busy', 'true');
		} else {
			this.removeClass(this.root, 'is-sending');
			this.removeClass(this.root, 'is-loading');
			this.root.setAttribute('aria-busy', 'false');
		}

		if (this.sendButton) {
			this.sendButton.disabled = this.isSending;
			this.sendButton.setAttribute('aria-disabled', this.isSending ? 'true' : 'false');
		}

		for (i = 0; i < this.promptButtons.length; i++) {
			this.promptButtons[i].setAttribute('aria-disabled', this.isSending ? 'true' : 'false');
		}
	};

	/**
	 * Send an AJAX request to WordPress.
	 *
	 * @param {string} message Message text.
	 * @param {Function} onSuccess Success callback.
	 * @param {Function} onError Error callback.
	 * @param {Function} onComplete Complete callback.
	 * @return {void}
	 */
	BizerOSChat.prototype.sendAjaxMessage = function (message, onSuccess, onError, onComplete) {
		var xhr;
		var payload;
		var body;
		var settings = this.settings;

		if (!settings.ajaxUrl) {
			onError(settings.i18n.connectionError);
			onComplete();
			return;
		}

		payload = {
			action: settings.action,
			nonce: settings.nonce,
			message: message,
			session_id: this.sessionId
		};

		body = encodeFormData(payload);
		xhr = new XMLHttpRequest();

		xhr.open('POST', settings.ajaxUrl, true);
		xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		xhr.timeout = 45000;

		xhr.onreadystatechange = function () {
			var parsed;
			var responseMessage;
			var errorMessage;
			var isHttpSuccess;

			if (4 !== xhr.readyState) {
				return;
			}

			isHttpSuccess = xhr.status >= 200 && xhr.status < 300;
			parsed = parseJson(xhr.responseText);

			if (isHttpSuccess && parsed && true === parsed.success) {
				responseMessage = extractMessage(parsed);
				onSuccess(responseMessage || settings.i18n.genericError);
				onComplete();
				return;
			}

			if (isHttpSuccess && !parsed && trim(xhr.responseText)) {
				onSuccess(trim(xhr.responseText));
				onComplete();
				return;
			}

			errorMessage = parsed ? extractError(parsed, settings) : settings.i18n.connectionError;
			onError(errorMessage);
			onComplete();
		};

		xhr.onerror = function () {
			onError(settings.i18n.connectionError);
			onComplete();
		};

		xhr.ontimeout = function () {
			onError(settings.i18n.connectionError);
			onComplete();
		};

		try {
			xhr.send(body);
		} catch (error) {
			onError(settings.i18n.connectionError);
			onComplete();
		}
	};

	/**
	 * Append a rendered chat message.
	 *
	 * @param {string} role Message role: user, agent, or error.
	 * @param {string} text Message text.
	 * @return {HTMLElement} Message element.
	 */
	BizerOSChat.prototype.appendMessage = function (role, text) {
		var message = document.createElement('div');
		var avatar = document.createElement('span');
		var bubble = document.createElement('div');
		var name = document.createElement('span');
		var content = document.createElement('span');
		var label;
		var avatarText;

		role = 'assistant' === role ? 'agent' : role;

		if ('user' === role) {
			label = this.settings.i18n.userLabel;
			avatarText = getInitial(label, 'Y');
		} else if ('error' === role) {
			label = 'BizerOS';
			avatarText = '!';
		} else {
			label = this.agentName;
			avatarText = getInitial(this.agentName, 'M');
		}

		message.className = 'bizeros-chat__message bizeros-chat__message--' + role;
		message.setAttribute('data-bizeros-message', '');
		message.setAttribute('data-bizeros-role', role);

		avatar.className = 'bizeros-chat__message-avatar';
		avatar.setAttribute('aria-hidden', 'true');
		avatar.textContent = avatarText;

		bubble.className = 'bizeros-chat__message-content bizeros-chat__bubble';

		name.className = 'bizeros-chat__message-name';
		name.textContent = label;

		content.className = 'bizeros-chat__message-text';
		content.textContent = text;

		bubble.appendChild(name);
		bubble.appendChild(content);
		message.appendChild(avatar);
		message.appendChild(bubble);

		this.messages.appendChild(message);
		this.scrollMessagesToBottom();

		return message;
	};

	/**
	 * Append the agent typing indicator.
	 *
	 * @return {HTMLElement} Loading message element.
	 */
	BizerOSChat.prototype.appendTypingMessage = function () {
		var message = document.createElement('div');
		var avatar = document.createElement('span');
		var bubble = document.createElement('div');
		var name = document.createElement('span');
		var typing = document.createElement('span');
		var dot;
		var i;

		message.className = 'bizeros-chat__message bizeros-chat__message--agent bizeros-chat__message--loading';
		message.setAttribute('data-bizeros-message', '');
		message.setAttribute('data-bizeros-role', 'agent');
		message.setAttribute('data-bizeros-loading', 'true');

		avatar.className = 'bizeros-chat__message-avatar';
		avatar.setAttribute('aria-hidden', 'true');
		avatar.textContent = getInitial(this.agentName, 'M');

		bubble.className = 'bizeros-chat__message-content bizeros-chat__bubble';

		name.className = 'bizeros-chat__message-name';
		name.textContent = this.agentName;

		typing.className = 'bizeros-chat__typing';
		typing.setAttribute('aria-label', this.settings.i18n.thinking);

		for (i = 0; i < 3; i++) {
			dot = document.createElement('span');
			dot.className = 'bizeros-chat__typing-dot';
			dot.setAttribute('aria-hidden', 'true');
			typing.appendChild(dot);
		}

		bubble.appendChild(name);
		bubble.appendChild(typing);
		message.appendChild(avatar);
		message.appendChild(bubble);

		this.messages.appendChild(message);
		this.scrollMessagesToBottom();

		return message;
	};

	/**
	 * Remove the current loading message.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.removeLoadingMessage = function () {
		if (this.loadingMessage && this.loadingMessage.parentNode) {
			this.loadingMessage.parentNode.removeChild(this.loadingMessage);
		}

		this.loadingMessage = null;
	};

	/**
	 * Auto-size the textarea.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.autoResizeInput = function () {
		if (!this.input) {
			return;
		}

		this.input.style.height = 'auto';
		this.input.style.height = Math.min(this.input.scrollHeight, 190) + 'px';
	};

	/**
	 * Scroll the message list to the bottom.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.scrollMessagesToBottom = function () {
		var messages = this.messages;

		if (!messages) {
			return;
		}

		window.setTimeout(function () {
			messages.scrollTop = messages.scrollHeight;
		}, 0);
	};

	/**
	 * Update the screen-reader status text.
	 *
	 * @param {string} message Status message.
	 * @return {void}
	 */
	BizerOSChat.prototype.setStatus = function (message) {
		if (this.status) {
			this.status.textContent = message || '';
		}
	};

	/**
	 * Focus the input field.
	 *
	 * @return {void}
	 */
	BizerOSChat.prototype.focusInput = function () {
		if (this.input && 'function' === typeof this.input.focus) {
			this.input.focus();
		}
	};

	/**
	 * Add a class without requiring classList support.
	 *
	 * @param {HTMLElement} element Element.
	 * @param {string} className Class name.
	 * @return {void}
	 */
	BizerOSChat.prototype.addClass = function (element, className) {
		if (!element) {
			return;
		}

		if (element.classList) {
			element.classList.add(className);
			return;
		}

		if (-1 === (' ' + element.className + ' ').indexOf(' ' + className + ' ')) {
			element.className += ' ' + className;
		}
	};

	/**
	 * Remove a class without requiring classList support.
	 *
	 * @param {HTMLElement} element Element.
	 * @param {string} className Class name.
	 * @return {void}
	 */
	BizerOSChat.prototype.removeClass = function (element, className) {
		if (!element) {
			return;
		}

		if (element.classList) {
			element.classList.remove(className);
			return;
		}

		element.className = trim((' ' + element.className + ' ').replace(' ' + className + ' ', ' '));
	};

	/**
	 * Initialize all BizerOS chat instances on the page.
	 *
	 * @return {void}
	 */
	function initBizerOSChats() {
		var settings = normalizeSettings(window.bizerosChatSettings || {});
		var roots = document.querySelectorAll(settings.selectors.root);
		var i;
		var chat;

		if (!roots || !roots.length) {
			return;
		}

		for (i = 0; i < roots.length; i++) {
			chat = new BizerOSChat(roots[i], settings);
			chat.init();
		}
	}

	domReady(initBizerOSChats);
}());