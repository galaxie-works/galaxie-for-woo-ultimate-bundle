/**
 * Webhook Admin — action rows, test, and save handlers
 *
 * Modal open/close is already handled by messaging-admin.js via data-open-modal.
 * Config passed via wp_localize_script as `aicwpWebhookAdmin`.
 */
jQuery(function($) {

    var config = window.aicwpWebhookAdmin || {};

    // ── Add action row ────────────────────────────────────────
    $('#webhook-add-action').on('click', function() {
        var $container = $('#webhook-actions-container');
        var newRow = '<div class="webhook-action-row">' +
            '<div class="webhook-action-header">' +
                '<div>' +
                    '<label class="aicwp-ui-label">' + (config.actionName || 'Action Name') + '</label>' +
                    '<input type="text" class="aicwp-ui-input webhook-action-label" value="" placeholder="' + (config.webhookLabelPlaceholder || 'e.g., Cancel Order') + '" />' +
                '</div>' +
                '<button type="button" class="aicwp-ui-button aicwp-ui-button-secondary webhook-remove-action" title="' + (config.remove || 'Remove') + '">' +
                    '<span class="remove-icon">&times;</span>' +
                '</button>' +
            '</div>' +
            '<div class="webhook-action-group">' +
                '<label class="aicwp-ui-label">' + (config.aiInstructions || 'AI Instructions') + '</label>' +
                '<textarea class="aicwp-ui-input webhook-action-description" rows="2" maxlength="300" placeholder="' + (config.webhookDescPlaceholder || 'e.g., User wants to cancel their order. Collect their name, email, and order number.') + '"></textarea>' +
            '</div>' +
            '<div>' +
                '<label class="aicwp-ui-label">' + (config.dataFields || 'Data Fields') + '</label>' +
                '<input type="text" class="aicwp-ui-input webhook-action-fields" value="" placeholder="' + (config.webhookFieldsPlaceholder || 'e.g., name, email, booking_number, reason') + '" />' +
                '<p class="aicwp-ui-help-text webhook-action-fields-help">' + (config.webhookFieldsHelp || 'Comma-separated field names. AI will collect these from the user. Use snake_case (e.g., phone_number).') + '</p>' +
            '</div>' +
        '</div>';
        $container.append(newRow);
        $('#webhook-actions-empty').hide();
    });

    // ── Remove action row ─────────────────────────────────────
    $(document).on('click', '.webhook-remove-action', function() {
        $(this).closest('.webhook-action-row').remove();
        if ($('#webhook-actions-container .webhook-action-row').length === 0) {
            $('#webhook-actions-empty').show();
        }
    });

    // ── Test webhook ──────────────────────────────────────────
    $('#webhook-test-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#webhook-test-result');
        var $btnText = $btn.find('.button-text');
        var $spinner = $btn.find('.button-spinner');

        $btn.prop('disabled', true);
        $btnText.hide();
        $spinner.show();
        $result.removeClass('aicwp-ui-api-test-success aicwp-ui-api-test-error').hide();

        $.ajax({
            url: window.ajaxurl,
            method: 'POST',
            data: {
                action: config.test_action,
                nonce: config.test_nonce,
                webhook_url: $('#aicwp_webhook_url').val(),
                webhook_secret: $('#aicwp_webhook_secret').val()
            },
            success: function(response) {
                if (response && response.success) {
                    $result.removeClass('aicwp-ui-api-test-error').addClass('aicwp-ui-api-test-success').text(response.data.message).show();
                } else {
                    var msg = (response && response.data && response.data.message) ? response.data.message : (config.requestFailed || 'Request failed. Please try again.');
                    $result.removeClass('aicwp-ui-api-test-success').addClass('aicwp-ui-api-test-error').text(msg).show();
                }
            },
            error: function() {
                $result.removeClass('aicwp-ui-api-test-success').addClass('aicwp-ui-api-test-error').text(config.requestFailed || 'Request failed. Please try again.').show();
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btnText.show();
                $spinner.hide();
            }
        });
    });

    // ── Save settings ─────────────────────────────────────────
    $('#webhook-save-settings-btn').on('click', function() {
        var $btn = $(this);
        var $result = $('#webhook-save-result');
        var $btnText = $btn.find('.button-text');
        var $spinner = $btn.find('.button-spinner');

        // Collect actions data
        var actions = [];
        $('#webhook-actions-container .webhook-action-row').each(function() {
            var $row = $(this);
            actions.push({
                label: $row.find('.webhook-action-label').val(),
                description: $row.find('.webhook-action-description').val(),
                fields: $row.find('.webhook-action-fields').val()
            });
        });

        $btn.prop('disabled', true);
        $btnText.hide();
        $spinner.show();
        $result.hide();

        $.ajax({
            url: window.ajaxurl,
            method: 'POST',
            data: {
                action: config.save_action,
                nonce: config.save_nonce,
                webhook_url: $('#aicwp_webhook_url').val(),
                webhook_secret: $('#aicwp_webhook_secret').val(),
                webhook_instructions: $('#aicwp_webhook_instructions').val(),
                actions: actions
            },
            success: function(response) {
                if (response.success) {
                    $result.removeClass('error').addClass('success').text(response.data.message).show();
                    setTimeout(function() {
                        $('#webhook-config-modal').fadeOut(200);
                    }, 1500);
                } else {
                    $result.removeClass('success').addClass('error').text(response.data.message).show();
                }
            },
            error: function() {
                $result.removeClass('success').addClass('error').text(config.requestFailed || 'Request failed. Please try again.').show();
            },
            complete: function() {
                $btn.prop('disabled', false);
                $btnText.show();
                $spinner.hide();
            }
        });
    });

});
