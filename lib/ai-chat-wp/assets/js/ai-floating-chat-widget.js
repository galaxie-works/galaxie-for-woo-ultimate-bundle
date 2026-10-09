(function () {
    'use strict';

    var STORAGE_KEY_BUBBLE_DISMISSED = 'aicwp_floating_chat_bubble_dismissed';
    var STORAGE_KEY_CHAT_OPENED = 'aicwp_floating_chat_opened';
    var FADE_DURATION_MS = 300;

    function debugLog() {
        if (typeof aicwpChatConfig !== 'undefined' && aicwpChatConfig.debugMode) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[AI Chat Widget]');
            console.log.apply(console, args);
        }
    }

    function dispatchReady(chatId) {
        if (typeof window.jQuery !== 'undefined') {
            try {
                window.jQuery(document).trigger('aicwp-floating-chat-ready', { chatId: chatId });
            } catch (e) {}
        }
        try {
            document.dispatchEvent(new CustomEvent('aicwp-floating-chat-ready', {
                detail: { chatId: chatId }
            }));
        } catch (e) {}
    }

    function AicwpFloatingChatWidget() {
        this.button = document.getElementById('aicwp-floating-chat-button');
        this.popup = document.getElementById('aicwp-floating-chat-popup');
        this.welcomeBubble = document.getElementById('aicwp-floating-welcome-bubble');
        this.iconOpen = document.querySelector('.aicwp-floating-icon-open');
        this.iconClose = document.querySelector('.aicwp-floating-icon-close');
        this.isOpen = false;
        this.chatInitialized = false;
        this.scriptsLoaded = false;
        this.closeTimeoutId = null;
        this.bubbleTimeoutId = null;

        var cfg = (typeof aicwpFloatingChatConfig !== 'undefined') ? aicwpFloatingChatConfig : {};
        this.lazyScripts = (cfg && cfg.lazyScripts) ? cfg.lazyScripts : [];
        this.scriptVersion = (cfg && cfg.scriptVersion) ? cfg.scriptVersion : '';

        this.init();
    }

    AicwpFloatingChatWidget.prototype.init = function () {
        this.checkWelcomeBubbleStatus();
        this.bindEvents();
        this.restoreChatState();
        debugLog('Widget initialized', this.lazyScripts.length > 0 ? '(lazy load enabled)' : '');
    };

    AicwpFloatingChatWidget.prototype.checkWelcomeBubbleStatus = function () {
        if (!this.welcomeBubble) return;
        var dismissed = localStorage.getItem(STORAGE_KEY_BUBBLE_DISMISSED);
        if (dismissed === 'true') {
            this.welcomeBubble.classList.add('hidden');
        } else {
            this.welcomeBubble.classList.remove('hidden');
        }
    };

    AicwpFloatingChatWidget.prototype.restoreChatState = function () {
        var cfg = (typeof aicwpFloatingChatConfig !== 'undefined') ? aicwpFloatingChatConfig : {};
        if (!cfg.keepChatOpened) return;

        var wasOpen = localStorage.getItem(STORAGE_KEY_CHAT_OPENED);
        if (wasOpen === 'true') {
            if (this.popup) {
                this.popup.classList.add('aicwp-no-animation');
            }
            this.openChat();
            debugLog('Restored chat open state from localStorage');
        }
    };

    AicwpFloatingChatWidget.prototype.bindEvents = function () {
        var self = this;

        if (this.button) {
            this.button.addEventListener('click', function (e) {
                e.preventDefault();
                self.toggleChat();
            });
        }

        if (this.welcomeBubble) {
            this.welcomeBubble.addEventListener('click', function (e) {
                e.stopPropagation();
                self.dismissWelcomeBubble();
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && self.isOpen) {
                self.closeChat();
            }
        });
    };

    AicwpFloatingChatWidget.prototype.toggleChat = function () {
        if (this.isOpen) {
            this.closeChat();
        } else {
            if (this.popup) {
                this.popup.classList.remove('aicwp-no-animation');
            }
            this.openChat();
        }
    };

    AicwpFloatingChatWidget.prototype.openChat = function () {
        var self = this;

        this.dismissWelcomeBubble();

        if (typeof AicwpSilkWave !== 'undefined') {
            AicwpSilkWave.start();
        }

        if (this.closeTimeoutId) {
            clearTimeout(this.closeTimeoutId);
            this.closeTimeoutId = null;
        }

        if (this.popup) {
            this.popup.style.opacity = '';
            this.popup.style.transition = '';
            this.popup.style.display = 'block';
            setTimeout(function () { self.scrollToBottom(); }, FADE_DURATION_MS);
        }

        if (this.iconOpen) this.iconOpen.style.display = 'none';
        if (this.iconClose) this.iconClose.style.display = '';

        this.isOpen = true;

        if (!this.chatInitialized) {
            this.chatInitialized = true;
            if (this.lazyScripts.length > 0 && !this.scriptsLoaded) {
                this.lazyLoadAndInit();
            } else {
                this.initializeChat();
            }
        }

        try {
            localStorage.setItem(STORAGE_KEY_CHAT_OPENED, 'true');
        } catch (e) {}

        debugLog('Chat opened');
    };

    AicwpFloatingChatWidget.prototype.scrollToBottom = function () {
        var messagesContainer = document.getElementById('aicwp-floating-chat-instance-messages');
        if (messagesContainer && messagesContainer.scrollHeight) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    };

    AicwpFloatingChatWidget.prototype.closeChat = function () {
        var self = this;
        this.isOpen = false;

        if (this.popup) {
            this.popup.style.transition = 'opacity ' + FADE_DURATION_MS + 'ms';
            this.popup.style.opacity = '0';

            this.closeTimeoutId = setTimeout(function () {
                // reopen guard
                if (!self.isOpen && self.popup) {
                    self.popup.style.display = 'none';
                    self.popup.style.opacity = '';
                    self.popup.style.transition = '';
                }
                self.closeTimeoutId = null;
            }, FADE_DURATION_MS);
        }

        if (typeof AicwpSilkWave !== 'undefined') {
            AicwpSilkWave.stop();
        }

        if (this.iconClose) this.iconClose.style.display = 'none';
        if (this.iconOpen) this.iconOpen.style.display = '';

        try {
            localStorage.removeItem(STORAGE_KEY_CHAT_OPENED);
        } catch (e) {}

        debugLog('Chat closed');
    };

    AicwpFloatingChatWidget.prototype.dismissWelcomeBubble = function () {
        var self = this;

        if (this.welcomeBubble && !this.welcomeBubble.classList.contains('hidden')) {
            this.welcomeBubble.style.transition = 'opacity 200ms';
            this.welcomeBubble.style.opacity = '0';

            if (this.bubbleTimeoutId) clearTimeout(this.bubbleTimeoutId);
            this.bubbleTimeoutId = setTimeout(function () {
                self.welcomeBubble.classList.add('hidden');
                self.welcomeBubble.style.opacity = '';
                self.welcomeBubble.style.transition = '';
                self.bubbleTimeoutId = null;
            }, 200);
        }

        try {
            localStorage.setItem(STORAGE_KEY_BUBBLE_DISMISSED, 'true');
        } catch (e) {}
    };

    AicwpFloatingChatWidget.prototype.lazyLoadAndInit = function () {
        var self = this;
        var chatWrapper = document.getElementById('aicwp-floating-chat-instance');

        // shortcode on same page may have already loaded core
        if (document.querySelector('script[src*="ai-chat-core"]')) {
            this.scriptsLoaded = true;
            this.initializeChat();
            return;
        }

        if (chatWrapper) {
            chatWrapper.classList.add('aicwp-chat-lazy-state');
        }

        var ver = this.scriptVersion;
        var urls = this.lazyScripts.map(function (url) {
            return url + (url.indexOf('?') === -1 ? '?' : '&') + 'ver=' + ver;
        });

        this.loadScriptsSequential(urls, 0, function () {
            self.scriptsLoaded = true;
            // let chatbot-core's ready handler settle
            setTimeout(function () {
                if (chatWrapper) {
                    chatWrapper.classList.remove('aicwp-chat-lazy-state');
                }
                self.initializeChat();
            }, 50);
        });
    };

    AicwpFloatingChatWidget.prototype.loadScriptsSequential = function (urls, index, callback) {
        var self = this;
        if (index >= urls.length) {
            callback();
            return;
        }

        var script = document.createElement('script');
        script.src = urls[index];
        script.onload = function () {
            self.loadScriptsSequential(urls, index + 1, callback);
        };
        script.onerror = function () {
            console.error('[AI Chat] Failed to load:', urls[index]);
            self.loadScriptsSequential(urls, index + 1, callback);
        };
        document.body.appendChild(script);
    };

    AicwpFloatingChatWidget.prototype.initializeChat = function () {
        setTimeout(function () {
            dispatchReady('aicwp-floating-chat-instance');
        }, 100);
    };

    function boot() {
        if (document.getElementById('aicwp-floating-chat-widget')) {
            new AicwpFloatingChatWidget();
        }
    }

    // works in head (lazy mode, defer) or footer
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
