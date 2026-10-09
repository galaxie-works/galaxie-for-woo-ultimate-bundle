/**
 * AI Chat for WordPress - Admin Media Module
 *
 * Handles WordPress media uploader for custom icons and chat avatars.
 *
 * @package AICWP
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var i18n = window.aicwp_i18n || {};
    var customIconFrame;
    var chatAvatarFrame;

    /**
     * Initialize custom icon uploader
     */
    function initCustomIconUploader() {
        $('#aicwp-upload-custom-icon').on('click', function(e) {
            e.preventDefault();

            // If the media frame already exists, reopen it
            if (customIconFrame) {
                customIconFrame.open();
                return;
            }

            // Create the media frame
            customIconFrame = wp.media({
                title: i18n.selectCustomIcon || 'Select Custom Icon',
                button: {
                    text: i18n.useThisIcon || 'Use this icon'
                },
                library: {
                    type: ['image/svg+xml', 'image/png']
                },
                multiple: false
            });

            // When an image is selected
            customIconFrame.on('select', function() {
                var attachment = customIconFrame.state().get('selection').first().toJSON();

                // Set the hidden input value
                $('#aicwp_floating_custom_icon').val(attachment.id);

                // Resolve current icon size (fallback to 32)
                var iconSize = parseInt($('#aicwp_floating_custom_icon_size').val(), 10);
                if (!iconSize || iconSize < 1) { iconSize = 32; }

                // Update the preview with button circle style
                var btnColor = i18n.buttonColor || '#222222';
                var imgStyle = 'width:' + iconSize + 'px;height:' + iconSize + 'px;max-width:' + iconSize + 'px;max-height:' + iconSize + 'px;object-fit:contain;';
                var previewHtml = '<div class="aicwp-ui-media-placeholder" style="width: 60px; height: 60px; background-color: ' + btnColor + '; border-radius: 100px; display: flex; align-items: center; justify-content: center;">' +
                    '<img src="' + attachment.url + '" alt="Custom icon" id="aicwp-custom-icon-preview-img" style="' + imgStyle + '" /></div>';
                $('#aicwp-custom-icon-preview').html(previewHtml);

                // Show remove button if it doesn't exist
                if ($('#aicwp-remove-custom-icon').length === 0) {
                    $('.aicwp-ui-media-buttons').append(
                        '<button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-remove-custom-icon" style="margin-left: 5px;">' +
                        (i18n.remove || 'Remove') + '</button>'
                    );
                }

                // Show the icon size input
                $('#aicwp-custom-icon-size-wrapper').show();
            });

            // Open the modal
            customIconFrame.open();
        });

        // Remove custom icon handler
        $(document).on('click', '#aicwp-remove-custom-icon', function(e) {
            e.preventDefault();

            // Clear the hidden input
            $('#aicwp_floating_custom_icon').val('');

            // Reset the preview to placeholder
            var btnColor = i18n.buttonColor || '#222222';
            var pluginUrl = i18n.pluginUrl || '';
            var placeholderHtml = '<div class="aicwp-ui-media-placeholder" style="width: 60px; height: 60px; background-color: ' + btnColor + '; border-radius: 100px; display: flex; align-items: center; justify-content: center;">' +
                '<img src="' + pluginUrl + 'assets/icons/chat.svg" alt="Default icon" width="28" height="28" /></div>';
            $('#aicwp-custom-icon-preview').html(placeholderHtml);

            // Hide the icon size input
            $('#aicwp-custom-icon-size-wrapper').hide();

            // Remove the remove button
            $(this).remove();
        });

        // Live-update preview icon size while editing the input
        $(document).on('input change', '#aicwp_floating_custom_icon_size', function() {
            var size = parseInt($(this).val(), 10);
            if (!size || size < 1) { size = 32; }
            var $img = $('#aicwp-custom-icon-preview-img');
            if ($img.length) {
                $img.css({
                    'width': size + 'px',
                    'height': size + 'px',
                    'max-width': size + 'px',
                    'max-height': size + 'px',
                    'object-fit': 'contain'
                });
            }
        });
    }

    /**
     * Initialize chat avatar uploader
     */
    function initChatAvatarUploader() {
        $('#aicwp-upload-chat-avatar').on('click', function(e) {
            e.preventDefault();

            // If the media frame already exists, reopen it
            if (chatAvatarFrame) {
                chatAvatarFrame.open();
                return;
            }

            // Create the media frame
            chatAvatarFrame = wp.media({
                title: i18n.selectChatAvatar || 'Select Chat Avatar',
                button: {
                    text: i18n.useThisImage || 'Use this image'
                },
                library: {
                    type: ['image']
                },
                multiple: false
            });

            // When an image is selected
            chatAvatarFrame.on('select', function() {
                var attachment = chatAvatarFrame.state().get('selection').first().toJSON();

                // Set the hidden input value
                $('#aicwp_chat_avatar').val(attachment.id);

                // Update the preview (escape URL for security)
                var $img = $('<img>')
                    .attr('src', attachment.url)
                    .attr('alt', 'Chat avatar')
                    .css({'width': '38px', 'height': '38px', 'border-radius': '100px', 'object-fit': 'cover'});
                $('#aicwp-chat-avatar-preview').empty().append($img);

                // Show remove button if it doesn't exist
                if ($('#aicwp-remove-chat-avatar').length === 0) {
                    $('#aicwp-upload-chat-avatar').after(
                        '<button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-remove-chat-avatar" style="margin-left: 5px;">' +
                        (i18n.remove || 'Remove') + '</button>'
                    );
                }
            });

            // Open the modal
            chatAvatarFrame.open();
        });

        // Remove chat avatar handler
        $(document).on('click', '#aicwp-remove-chat-avatar', function(e) {
            e.preventDefault();

            // Clear the hidden input
            $('#aicwp_chat_avatar').val('');

            // Reset the preview to placeholder
            var placeholderHtml = '<div class="aicwp-ui-media-placeholder" style="width: 38px; height: 38px; border: 2px dashed #ddd; border-radius: 100px; display: flex; align-items: center; justify-content: center; color: #999; box-sizing: content-box;">' +
                '<i class="sl sl-icon-user" style="font-size: 14px;"></i></div>';
            $('#aicwp-chat-avatar-preview').html(placeholderHtml);

            // Remove the remove button
            $(this).remove();
        });
    }

    /**
     * Initialize header background image uploader
     */
    var headerBgFrame;

    function initHeaderBgUploader() {
        $('#aicwp-upload-header-bg').on('click', function(e) {
            e.preventDefault();

            // If the media frame already exists, reopen it
            if (headerBgFrame) {
                headerBgFrame.open();
                return;
            }

            // Create the media frame
            headerBgFrame = wp.media({
                title: i18n.selectHeaderBg || 'Select Header Background',
                button: {
                    text: i18n.useThisImage || 'Use this image'
                },
                library: {
                    type: ['image']
                },
                multiple: false
            });

            // When an image is selected
            headerBgFrame.on('select', function() {
                var attachment = headerBgFrame.state().get('selection').first().toJSON();

                // Set the hidden input value
                $('#aicwp_floating_header_bg').val(attachment.id);

                // Update the preview with frame
                var $frame = $('<div class="aicwp-ui-header-bg-preview-frame"></div>');
                var $img = $('<img>').attr('src', attachment.url).attr('alt', 'Header background');
                $frame.append($img);
                $('#aicwp-header-bg-preview').empty().append($frame);

                // Show remove button if it doesn't exist
                if ($('#aicwp-remove-header-bg').length === 0) {
                    $('#aicwp-upload-header-bg').after(
                        '<button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-remove-header-bg" style="margin-left: 5px;">' +
                        (i18n.remove || 'Remove') + '</button>'
                    );
                }
            });

            // Open the modal
            headerBgFrame.open();
        });

        // Remove header background handler
        $(document).on('click', '#aicwp-remove-header-bg', function(e) {
            e.preventDefault();

            // Clear the hidden input
            $('#aicwp_floating_header_bg').val('');

            // Reset the preview to placeholder
            var placeholderHtml = '<div class="aicwp-ui-header-bg-preview-frame aicwp-ui-header-bg-placeholder">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg></div>';
            $('#aicwp-header-bg-preview').html(placeholderHtml);

            // Remove the remove button
            $(this).remove();
        });
    }

    /**
     * Initialize all media handlers
     */
    function init() {
        // Check if WordPress media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            console.warn('AicwpAdmin Media: WordPress media library not available');
            return;
        }

        initCustomIconUploader();
        initChatAvatarUploader();
        initHeaderBgUploader();

    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
