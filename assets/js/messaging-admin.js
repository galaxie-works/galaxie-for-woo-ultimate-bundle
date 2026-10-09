/**
 * Messaging Admin — WhatsApp & Telegram modal handlers
 *
 * Shared logic for open/close, save settings, test connection, copy URL.
 * Config passed via wp_localize_script as `aicwpMessagingAdmin`.
 */
jQuery(function($) {

    var config = window.aicwpMessagingAdmin || {};

    // ── Generic modal open/close ──────────────────────────────
    $(document).on('click', '[data-open-modal]', function() {
        $('#' + $(this).data('open-modal')).fadeIn(200);
    });
    $(document).on('click', '.aicwp-ui-modal .aicwp-modal-close, .aicwp-ui-modal .aicwp-ui-modal-overlay', function() {
        $(this).closest('.aicwp-ui-modal').fadeOut(200);
    });

    // ── Generic save settings ─────────────────────────────────
    $(document).on('click', '[data-save-action]', function() {
        var $btn     = $(this);
        var channel  = $btn.data('save-action');
        var $modal   = $btn.closest('.aicwp-ui-modal');
        var $result  = $modal.find('.aicwp-ui-result-message');
        var $btnText = $btn.find('.button-text');
        var $spinner = $btn.find('.button-spinner');

        var data = {
            action: config[channel + '_save_action'] || '',
            nonce: config[channel + '_save_nonce'] || ''
        };

        $modal.find('[data-field]').each(function() {
            data[$(this).data('field')] = $(this).val();
        });

        $btn.prop('disabled', true);
        $btnText.hide();
        $spinner.show();
        $result.hide();

        $.ajax({
            url: window.ajaxurl,
            method: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success').text(response.data.message).show();
                } else {
                    $result.removeClass('success').addClass('error').text(response.data.message).show();
                }
            },
            error: function() {
                $result.removeClass('success').addClass('error').text(config.requestFailed || 'Request failed.').show();
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btnText.show();
                $spinner.hide();
            }
        });
    });

    // ── Generic test connection ───────────────────────────────
    $(document).on('click', '[data-test-action]', function() {
        var $btn    = $(this);
        var channel = $btn.data('test-action');
        var $result = $btn.siblings('.aicwp-ui-test-result');
        var originalText = $btn.data('label') || 'Test Connection';

        $btn.prop('disabled', true).text(config.testing || 'Testing...');
        $result.empty();

        $.post(ajaxurl, {
            action: config[channel + '_test_action'] || '',
            nonce: config[channel + '_test_nonce'] || ''
        }, function(response) {
            $btn.prop('disabled', false).text(originalText);
            var cls = response.success ? 'aicwp-ui-test-success' : 'aicwp-ui-test-error';
            var icon = response.success ? '\u2713 ' : '\u2717 ';
            $result.html('<span class="' + cls + '">' + icon + $('<span>').text(response.data.message).html() + '</span>');
        }).fail(function() {
            $btn.prop('disabled', false).text(originalText);
            $result.html('<span class="aicwp-ui-test-error">\u2717 ' + (config.connectionFailed || 'Connection failed') + '</span>');
        });
    });

    // ── Copy URL ──────────────────────────────────────────────
    $(document).on('click', '[data-copy-url]', function() {
        var $btn = $(this);
        var url = $btn.data('copy-url');
        var temp = $('<input>').val(url).appendTo('body').select();
        document.execCommand('copy');
        temp.remove();
        $btn.text(config.copied || 'Copied!');
        setTimeout(function() { $btn.text(config.copy || 'Copy'); }, 2000);
    });

});
