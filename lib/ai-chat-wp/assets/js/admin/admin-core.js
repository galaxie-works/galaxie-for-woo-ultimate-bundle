/**
 * AI Chat for WordPress - Admin Core Module
 *
 * Provides shared utilities and AJAX helpers for all admin modules.
 * Must be loaded before other admin modules.
 *
 * @package AICWP
 * @since 1.0.0
 */

(function($) {
    'use strict';

    // Namespace for admin modules
    window.AicwpAdmin = window.AicwpAdmin || {};

    /**
     * AJAX helper with consistent error handling
     *
     * @param {Object} options - AJAX options
     * @param {string} options.action - WordPress AJAX action
     * @param {Object} options.data - Additional data to send
     * @param {Function} options.success - Success callback
     * @param {Function} options.error - Error callback
     * @param {Function} options.complete - Complete callback
     */
    AicwpAdmin.ajax = function(options) {
        var config = window.aicwp_ajax || {};

        if (!config.ajax_url) {
            console.error('AicwpAdmin: AJAX URL not configured');
            if (options.error) {
                options.error(null, 'error', 'AJAX not configured');
            }
            return;
        }

        var data = $.extend({
            action: options.action,
            nonce: config.nonce
        }, options.data || {});

        return $.post(config.ajax_url, data)
            .done(function(response) {
                if (options.success) {
                    options.success(response);
                }
            })
            .fail(function(xhr, status, error) {
                if (options.error) {
                    options.error(xhr, status, error);
                }
            })
            .always(function() {
                if (options.complete) {
                    options.complete();
                }
            });
    };

    /**
     * Button state management
     *
     * @param {jQuery} $button - Button element
     * @param {string} state - 'loading', 'success', 'error', or 'reset'
     * @param {string} text - Optional text to display
     */
    AicwpAdmin.setButtonState = function($button, state, text) {
        var $buttonText = $button.find('.button-text');
        var $buttonSpinner = $button.find('.button-spinner');

        switch (state) {
            case 'loading':
                $button.prop('disabled', true);
                if ($buttonText.length) $buttonText.hide();
                if ($buttonSpinner.length) $buttonSpinner.show();
                break;

            case 'success':
            case 'error':
            case 'reset':
                $button.prop('disabled', false);
                if ($buttonText.length) {
                    $buttonText.show();
                    if (text) $buttonText.text(text);
                }
                if ($buttonSpinner.length) $buttonSpinner.hide();
                break;
        }
    };

    /**
     * Show message in form message area
     *
     * @param {jQuery} $container - Message container
     * @param {string} type - 'success' or 'error'
     * @param {string} message - Message text
     * @param {number} autoHide - Auto-hide after ms (0 = no auto-hide)
     */
    AicwpAdmin.showMessage = function($container, type, message, autoHide) {
        $container
            .removeClass('aicwp-ui-alert-success aicwp-ui-alert-error')
            .addClass('aicwp-ui-alert-' + type)
            .html(message)
            .show();

        if (autoHide && autoHide > 0) {
            setTimeout(function() {
                $container.fadeOut();
            }, autoHide);
        }
    };

    /**
     * Get localized string with fallback
     *
     * @param {string} key - Translation key
     * @param {string} fallback - Fallback text
     * @returns {string}
     */
    AicwpAdmin.i18n = function(key, fallback) {
        var strings = window.aicwp_i18n || {};
        return strings[key] || fallback || key;
    };

    /**
     * Get total embeddings count
     *
     * @returns {number}
     */
    AicwpAdmin.getTotalEmbeddings = function() {
        var config = window.aicwp_ajax || {};
        return parseInt(config.total_embeddings) || 0;
    };

    /**
     * Escape HTML for safe insertion
     *
     * @param {string|number} text - Text to escape
     * @returns {string}
     */
    AicwpAdmin.escapeHtml = function(text) {
        if (text === null || text === undefined) return '';
        if (typeof text !== 'string') text = String(text);
        return $('<div>').text(text).html();
    };

    // Log successful initialization

})(jQuery);
