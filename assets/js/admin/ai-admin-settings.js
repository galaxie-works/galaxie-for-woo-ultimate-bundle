/**
 * AI Chat for WordPress - Admin Settings Module
 *
 * Handles provider selection, API key testing, and form submissions.
 *
 * @package AICWP
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var AicwpAdmin = window.AicwpAdmin || {};
    var i18n = window.aicwp_i18n || {};

    /**
     * Provider Toggle Handler
     * Shows/hides API key fields and updates model dropdowns based on selected provider
     */
    function initProviderToggle() {
        var $providerSelect = $('#aicwp_provider');

        // Handle select change
        $providerSelect.on('change', function() {
            var provider = $(this).val();
            updateProviderUI(provider);
        });

        // Store original value for provider switch detection
        $providerSelect.data('original-value', $providerSelect.val());

        // Handle toggle switch clicks
        $('.ai-provider-option').on('click', function() {
            var selectedValue = $(this).data('value');
            var currentValue = $providerSelect.val();
            var originalProvider = $providerSelect.data('original-value');

            // If clicking on already selected provider, do nothing
            if (selectedValue === currentValue) {
                return;
            }

            // Check if switching requires clearing embeddings
            var totalEmbeddings = AicwpAdmin.getTotalEmbeddings();

            if (originalProvider && selectedValue !== originalProvider && totalEmbeddings >= 2) {
                // Show confirmation modal
                $('#provider-change-modal').fadeIn(200);
                $('#provider-change-modal').data('pending-provider', selectedValue);
            } else {
                // Allow immediate change
                applyProviderChange(selectedValue);
            }
        });

        // Prevent slider from being clickable
        $('.ai-provider-slider').on('click', function(e) {
            e.stopPropagation();
        });

        // Modal cancel button
        $('#modal-cancel-btn, .aicwp-ui-modal-overlay').on('click', function() {
            $('#provider-change-modal').fadeOut(200);
            var currentValue = $providerSelect.val();
            $('.ai-provider-toggle').attr('data-selected', currentValue);
            $('#provider-change-modal').removeData('pending-provider');
        });

        // Modal confirm button
        $('#modal-confirm-btn').on('click', function() {
            var $button = $(this);
            var pendingProvider = $('#provider-change-modal').data('pending-provider');

            if (!pendingProvider) return;

            AicwpAdmin.setButtonState($button, 'loading');

            AicwpAdmin.ajax({
                action: 'aicwp_clear_embeddings_for_provider_switch',
                data: { nonce: window.aicwp_ajax.clear_embeddings_nonce },
                success: function(response) {
                    if (response.success) {
                        applyProviderChange(pendingProvider);
                        $providerSelect.data('original-value', pendingProvider);
                        $('#provider-change-modal').fadeOut(200);

                        var $form = $('.aicwp-ui-ajax-form[data-section="ai-chat-config"]');
                        if ($form.length) {
                            $form.trigger('submit');
                        }
                    } else {
                        alert(i18n.errorClearingEmbeddings || 'Error clearing embeddings. Please try again.');
                    }
                },
                error: function() {
                    alert(i18n.ajaxError || 'An error occurred. Please try again.');
                },
                complete: function() {
                    AicwpAdmin.setButtonState($button, 'reset');
                }
            });
        });
    }

    /**
     * Apply provider change to UI
     */
    function applyProviderChange(provider) {
        var $toggle = $('.ai-provider-toggle');
        $toggle.attr('data-selected', provider);
        $('#aicwp_provider').val(provider).trigger('change');
    }

    /**
     * Update UI based on provider selection
     */
    function updateProviderUI(provider) {
        // Hide all provider fields and model groups
        $('.provider-field').hide();
        $('.model-group-openai, .model-group-gemini, .model-group-mistral, .model-group-openrouter').hide();

        var models = {
            openai: {
                class: 'provider-openai',
                modelGroup: 'model-group-openai',
                label: i18n.openaiModel || 'OpenAI Model',
                help: i18n.openaiModelHelp || 'Select the OpenAI model for chat responses.',
                models: ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-nano', 'gpt-4.1-mini', 'gpt-4.1', 'gpt-5-mini', 'gpt-5-chat-latest', 'gpt-5.1', 'gpt-5.2', 'gpt-5.3-chat-latest', 'gpt-5.4', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5.5'],
                default: 'gpt-5.4-mini'
            },
            gemini: {
                class: 'provider-gemini',
                modelGroup: 'model-group-gemini',
                label: i18n.geminiModel || 'Gemini Model',
                help: i18n.geminiModelHelp || 'Select the Gemini model for chat responses.',
                models: ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-3.1-pro-preview', 'gemini-3-flash-preview', 'gemini-3.1-flash-lite-preview'],
                default: 'gemini-3-flash-preview'
            },
            mistral: {
                class: 'provider-mistral',
                modelGroup: 'model-group-mistral',
                label: i18n.mistralModel || 'Mistral Model',
                help: i18n.mistralModelHelp || 'Select the Mistral model for chat responses.',
                models: ['mistral-small-latest', 'mistral-medium-latest', 'mistral-medium-3.5', 'mistral-large-latest'],
                default: 'mistral-large-latest'
            },
            openrouter: {
                class: 'provider-openrouter',
                modelGroup: 'model-group-openrouter',
                label: i18n.openrouterModel || 'OpenRouter Model',
                help: i18n.openrouterModelHelp || 'Select an OpenRouter model for chat responses.',
                // Any vendor/model slug is considered valid — OpenRouter has 300+ models.
                // This list only governs auto-reset when switching into the openrouter provider.
                models: ['openai/gpt-5-mini', 'openai/gpt-5.1', 'openai/gpt-5.3-chat-latest', 'openai/gpt-5.4', 'openai/gpt-5.4-mini', 'openai/gpt-5.4-nano', 'openai/gpt-5.5', 'openai/gpt-4.1', 'openai/gpt-4.1-mini', 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.6', 'anthropic/claude-haiku-4.5', 'google/gemini-3.1-pro-preview', 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'google/gemini-2.5-flash', 'meta-llama/llama-3.3-70b-instruct', 'mistralai/mistral-large-2512', 'mistralai/mistral-medium-3.1', 'deepseek/deepseek-chat-v3', 'deepseek/deepseek-chat-v3.1', 'deepseek/deepseek-v3.2', 'deepseek/deepseek-v4-pro', 'deepseek/deepseek-v4-flash', 'z-ai/glm-5.1', 'z-ai/glm-5-turbo', 'moonshotai/kimi-k2.5', 'qwen/qwen3.5-flash-02-23', 'qwen/qwen3.6-plus', 'minimax/minimax-m2.7', 'x-ai/grok-4', 'x-ai/grok-4.1-fast', 'x-ai/grok-4.20'],
                default: 'openai/gpt-5.4-mini'
            }
        };

        if (models[provider]) {
            var config = models[provider];
            $('.' + config.class).show();
            $('.' + config.modelGroup).show();
            $('#model-label-text').text(config.label);
            $('#model-help-text').text(config.help);

            // Auto-select default model if current is not valid for this provider
            var currentModel = $('#aicwp_chat_model').val();
            if (config.models.indexOf(currentModel) === -1) {
                $('#aicwp_chat_model').val(config.default).trigger('change');
            }
        }
    }

    /**
     * Model change handler for Gemini 3 warning
     */
    function initModelChangeHandler() {
        var incompatible = ['deepseek/', 'z-ai/', 'minimax/', 'meta-llama/', 'qwen/', 'moonshotai/'];

        function checkMultimodal() {
            var model = $('#aicwp_chat_model').val() || '';
            var isRouter = model.indexOf('/') !== -1;
            var hasFeature = $('#aicwp_chat_enable_speech').is(':checked') || $('#aicwp_chat_enable_image_input').is(':checked');
            var matched = incompatible.some(function(p) { return model.indexOf(p) === 0; });
            $('#openrouter-multimodal-warning').toggle(isRouter && hasFeature && matched);
        }

        $('#aicwp_chat_model').on('change', function() {
            if ($(this).val() === 'gemini-3.1-pro-preview') {
                $('#gemini-3-warning').show();
            } else {
                $('#gemini-3-warning').hide();
            }
            checkMultimodal();
        });

        $(document).on('change', '#aicwp_chat_enable_speech, #aicwp_chat_enable_image_input', checkMultimodal);
        checkMultimodal();
    }

    /**
     * AJAX Form Handler
     * Generic handler for settings forms with section-based saving
     */
    function initAjaxFormHandler() {
        $('.aicwp-ui-ajax-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $message = $form.find('.aicwp-ui-form-message');
            var section = $form.data('section');

            AicwpAdmin.setButtonState($button, 'loading');
            $message.hide();

            // Collect form values
            var formData = collectFormData($form);
            formData.action = 'aicwp_save_settings';
            formData.nonce = window.aicwp_ajax.nonce;
            formData.section = section;


            $.post(window.aicwp_ajax.ajax_url, formData)
                .done(function(response) {

                    if (response.success) {
                        AicwpAdmin.showMessage($message, 'success',
                            '<strong>\u2713 ' + (i18n.success || 'Success!') + '</strong> ' + response.data.message,
                            3000
                        );

                        // Update hidden fields in other forms
                        $.each(formData, function(fieldName, fieldValue) {
                            if (fieldName !== 'action' && fieldName !== 'nonce' && fieldName !== 'section') {
                                $('input[type="hidden"][name="' + fieldName + '"]').val(fieldValue);
                            }
                        });
                    } else {
                        AicwpAdmin.showMessage($message, 'error',
                            '<strong>\u2717 ' + (i18n.error || 'Error!') + '</strong> ' + (response.data.message || 'Unknown error')
                        );
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error('AJAX Error:', xhr, status, error);
                    AicwpAdmin.showMessage($message, 'error',
                        '<strong>\u2717 ' + (i18n.error || 'Error!') + '</strong> ' + (i18n.connectionFailed || 'Connection failed:') + ' ' + error
                    );
                })
                .always(function() {
                    AicwpAdmin.setButtonState($button, 'reset');
                });
        });
    }

    /**
     * Collect form data including checkboxes properly
     */
    function collectFormData($form) {
        var formData = {};

        $form.find('input, textarea, select').each(function() {
            var $input = $(this);
            var name = $input.attr('name');

            if (!name || $input.is(':disabled')) return;

            if ($input.attr('type') === 'checkbox') {
                if (name.endsWith('[]')) {
                    // Array checkbox
                    var baseName = name.replace('[]', '');
                    if ($input.is(':checked')) {
                        if (!formData[baseName]) formData[baseName] = [];
                        formData[baseName].push($input.val());
                    } else if (!formData[baseName]) {
                        formData[baseName] = [];
                    }
                } else {
                    // Regular checkbox
                    formData[name] = $input.is(':checked') ? '1' : '0';
                }
            } else if ($input.attr('type') === 'radio') {
                if ($input.is(':checked')) {
                    formData[name] = $input.val();
                }
            } else {
                formData[name] = $input.val();
            }
        });

        return formData;
    }

    /**
     * API Key Test Handlers
     */
    function initApiKeyTests() {
        // OpenAI
        $('#test-api-key').on('click', function(e) {
            e.preventDefault();
            testApiKey('openai', $(this), '#api-test-result', '#aicwp_api_key');
        });

        // Gemini
        $('#test-gemini-api-key').on('click', function(e) {
            e.preventDefault();
            testApiKey('gemini', $(this), '#gemini-api-test-result', '#aicwp_gemini_api_key');
        });

        // Mistral
        $('#test-mistral-api-key').on('click', function(e) {
            e.preventDefault();
            testApiKey('mistral', $(this), '#mistral-api-test-result', '#aicwp_mistral_api_key');
        });

        // OpenRouter
        $('#test-openrouter-api-key').on('click', function(e) {
            e.preventDefault();
            testApiKey('openrouter', $(this), '#openrouter-api-test-result', '#aicwp_openrouter_api_key');
        });

        $('.aicwp-ui-api-key-remove-button').on('click', function(e) {
            e.preventDefault();

            var target = $(this).data('target');
            var $input = $('#' + target);
            var $flag = $('.aicwp-ui-api-key-remove-flag[data-setting-name="' + target + '"]');
            var placeholderMap = {
                'aicwp_api_key': 'sk-...',
                'aicwp_gemini_api_key': 'AIzaSy...',
                'aicwp_mistral_api_key': '...',
                'aicwp_openrouter_api_key': 'sk-or-v1-...'
            };

            $input.prop('disabled', false);
            $input.attr('name', $input.data('setting-name'));
            $input.val('');
            $input.attr('placeholder', placeholderMap[target] || '');
            $flag.val('1');

            $(this).prop('disabled', true);
            $input.focus();
        });
    }

    /**
     * Test API key for a provider
     */
    function testApiKey(provider, $button, resultSelector, keySelector) {
        var $result = $(resultSelector);
        var $input = $(keySelector);
        var apiKey = '';

        if (!$input.is(':disabled')) {
            apiKey = $input.val().trim();
        }

        AicwpAdmin.setButtonState($button, 'loading');
        $result.removeClass('aicwp-ui-api-test-success aicwp-ui-api-test-error')
            .html(i18n.testingConnection || 'Testing API connection...')
            .show();

        var action = provider === 'openai' ? 'aicwp_test_api_key' :
                     provider === 'gemini' ? 'aicwp_test_gemini_api_key' :
                     provider === 'mistral' ? 'aicwp_test_mistral_api_key' :
                     'aicwp_test_openrouter_api_key';

        AicwpAdmin.ajax({
            action: action,
            data: apiKey ? { api_key: apiKey } : {},
            success: function(response) {

                if (response.success) {
                    $result.removeClass('aicwp-ui-api-test-error').addClass('aicwp-ui-api-test-success')
                        .html(response.data.message)
                        .show();

                    if (response.data.details) {
                        $result.append('<br><small>' + response.data.details + '</small>');
                    }
                } else {
                    $result.removeClass('aicwp-ui-api-test-success').addClass('aicwp-ui-api-test-error')
                        .html(response.data.message || i18n.testFailed || 'Test failed')
                        .show();
                }
            },
            error: function(xhr, status, error) {
                $result.removeClass('aicwp-ui-api-test-success').addClass('aicwp-ui-api-test-error')
                    .html('\u274c ' + (i18n.connectionFailed || 'Connection failed:') + ' ' + error)
                    .show();
            },
            complete: function() {
                AicwpAdmin.setButtonState($button, 'reset');
            }
        });
    }

    /**
     * Clear Cache Handler
     */
    function initClearCache() {
        $('#aicwp-clear-cache-btn').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $status = $('#aicwp-clear-cache-status');
            var originalHtml = $button.html();

            $button.prop('disabled', true)
                .html('<span class="aicwp-ui-spinner" style="margin-right: 5px;"></span>' + (i18n.clearing || 'Clearing...'));
            $status.html('').removeClass('success error');

            AicwpAdmin.ajax({
                action: 'aicwp_clear_cache',
                success: function(response) {
                    if (response.success) {
                        $status.html(response.data.message).addClass('success').css('color', '#46b450');
                    } else {
                        $status.html(response.data.message || i18n.clearCacheFailed || 'Clear cache failed')
                            .addClass('error').css('color', '#dc3232');
                    }
                },
                error: function(xhr, status, error) {
                    $status.html('\u274c ' + (i18n.connectionFailed || 'Connection failed:') + ' ' + error)
                        .addClass('error').css('color', '#dc3232');
                },
                complete: function() {
                    setTimeout(function() {
                        $button.prop('disabled', false).html(originalHtml);
                        $status.fadeOut(3000);
                    }, 2000);
                }
            });
        });
    }

    /**
     * Clear IP Rate Limits Handler
     */
    function initClearRateLimits() {
        $('#clear-ip-rate-limits').on('click', function(e) {
            e.preventDefault();

            var $link = $(this);
            var originalText = $link.text();

            $link.text(i18n.clearing || 'Clearing...');

            AicwpAdmin.ajax({
                action: 'aicwp_clear_ip_rate_limits',
                success: function(response) {
                    if (response.success) {
                        $link.text(response.data.message).css('color', '#46b450');
                    } else {
                        $link.text(response.data.message || i18n.failed || 'Failed');
                    }
                },
                error: function() {
                    $link.text(i18n.connectionFailed || 'Connection failed');
                },
                complete: function() {
                    setTimeout(function() {
                        $link.text(originalText).css('color', '#dc3545');
                    }, 3000);
                }
            });
        });
    }

    /**
     * Create Missing Tables Handler
     */
    function initCreateTables() {
        $('#aicwp-ui-create-missing-tables').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $status = $('#aicwp-ui-create-tables-status');
            var originalHtml = $button.html();

            $button.prop('disabled', true)
                .html('<span class="aicwp-ui-spinner" style="margin-right: 5px;"></span>' + (i18n.creating || 'Creating...'));
            $status.html('').css('color', '');

            AicwpAdmin.ajax({
                action: 'aicwp_create_missing_tables',
                success: function(response) {
                    if (response.success) {
                        $status.html(response.data.message).css('color', '#46b450');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status.html(response.data.message || i18n.failedCreateTables || 'Failed to create tables')
                            .css('color', '#dc3232');
                    }
                },
                error: function(xhr, status, error) {
                    $status.html('\u274c ' + (i18n.connectionFailed || 'Connection failed:') + ' ' + error)
                        .css('color', '#dc3232');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        });
    }

    /**
     * Initialize all settings handlers
     */
    function init() {
        if (typeof window.aicwp_ajax === 'undefined') {
            console.error('AicwpAdmin Settings: AJAX variables not loaded');
            return;
        }

        initProviderToggle();
        initModelChangeHandler();
        initAjaxFormHandler();
        initApiKeyTests();
        initClearCache();
        initClearRateLimits();
        initCreateTables();
        initPostReferencePicker();
        initInfoTooltips();


    }

    /**
     * Info Tooltips (floating, pure JS)
     *
     * Renders a tooltip bubble as a `position: fixed` element appended directly
     * to <body>, so it escapes any ancestor with overflow: hidden (e.g. the
     * admin card at .aicwp-ui-tab-content .aicwp-ui-card). Used by
     * <span class="aicwp-ui-hint-icon" data-tooltip="...">?</span>.
     */
    function initInfoTooltips() {
        var $bubble = null;

        function ensureBubble() {
            if (!$bubble) {
                $bubble = $('<div class="aicwp-ui-tooltip-bubble" role="tooltip" aria-hidden="true"></div>').appendTo('body');
            }
            return $bubble;
        }

        function show(el) {
            var text = el.getAttribute('data-tooltip') || '';
            if (!text) return;
            var $b = ensureBubble();
            $b.text(text);

            // Measure after content is set so width is correct
            var rect = el.getBoundingClientRect();
            var bubbleRect = $b[0].getBoundingClientRect();
            var top = rect.top - bubbleRect.height - 10;
            var left = rect.left + (rect.width / 2) - (bubbleRect.width / 2);

            // Clamp to viewport so it never clips off-screen
            var margin = 8;
            left = Math.max(margin, Math.min(left, window.innerWidth - bubbleRect.width - margin));
            if (top < margin) {
                // Flip below the icon if there's no room above
                top = rect.bottom + 10;
                $b.attr('data-placement', 'bottom');
            } else {
                $b.attr('data-placement', 'top');
            }

            $b.css({ top: top + 'px', left: left + 'px' }).addClass('is-visible').attr('aria-hidden', 'false');
        }

        function hide() {
            if ($bubble) $bubble.removeClass('is-visible').attr('aria-hidden', 'true');
        }

        // Delegated listeners — handles dynamically added tooltips without re-binding
        $(document).on('mouseenter focus', '.aicwp-ui-hint-icon[data-tooltip]', function() {
            show(this);
        });
        $(document).on('mouseleave blur', '.aicwp-ui-hint-icon[data-tooltip]', function() {
            hide();
        });
        // Hide on scroll / resize to avoid stale positioning
        $(window).on('scroll resize', function() {
            hide();
        });
    }

    /**
     * Knowledge Sources Manager
     * Manages pinned post references stored separately from system prompt
     */
    function initPostReferencePicker() {
        var $btn = $('#insert-post-reference-btn');
        var $modal = $('#post-reference-modal');
        var $topic = $('#post-reference-topic');
        var $search = $('#post-reference-search');
        var $results = $('#post-reference-results');
        var $selected = $('#post-reference-selected');
        var $selectedText = $('#post-reference-selected-text');
        var $addBtn = $('#post-reference-add');
        var $list = $('#knowledge-sources-list');
        var $empty = $('#knowledge-sources-empty');
        var searchTimer = null;
        var selectedPost = null;

        if (!$btn.length || !$modal.length) return;

        function resetForm() {
            $topic.val('');
            $search.val('');
            $results.empty().hide();
            $selected.hide();
            $addBtn.prop('disabled', true);
            selectedPost = null;
        }

        function updateAddButton() {
            var hasTopic = $topic.val().trim().length > 0;
            var hasPost = selectedPost !== null;
            $addBtn.prop('disabled', !(hasTopic && hasPost));
        }

        function renderSources(sources) {
            $list.empty();
            if (!sources || !sources.length) {
                $empty.show();
                return;
            }
            $empty.hide();
            sources.forEach(function(source, idx) {
                var html = '<div class="knowledge-source-item" data-index="' + idx + '">';
                html += '<div style="flex: 1; min-width: 0;">';
                html += '<div class="ks-label">' + (i18n.whenUserAsksAbout || 'When user asks about') + ':</div>';
                html += '<div class="ks-topic">' + $('<span>').text(source.topic).html() + '</div>';
                html += '<div class="ks-post">' + $('<span>').text(source.post_title).html() + ' (ID: ' + source.post_id + ')</div>';
                html += '</div>';
                html += '<button type="button" class="knowledge-source-delete" data-index="' + idx + '" title="Delete">&times;</button>';
                html += '</div>';
                $list.append(html);
            });
        }

        // Open modal
        $btn.on('click', function() {
            resetForm();
            $modal.fadeIn(200);
        });

        // Close modal
        function closeModal() {
            clearTimeout(searchTimer);
            $modal.fadeOut(200);
        }
        $('#post-reference-modal-close, #post-reference-modal .aicwp-ui-modal-overlay').on('click', closeModal);

        // Topic input updates Add button state
        $topic.on('input', updateAddButton);

        // Debounced post search
        $search.on('input', function() {
            var query = $(this).val().trim();
            clearTimeout(searchTimer);

            if (query.length < 2) {
                $results.empty().hide();
                return;
            }

            searchTimer = setTimeout(function() {
                $results.html('<div style="text-align: center; padding: 12px; color: #999;">' + (i18n.loading || 'Loading...') + '</div>').show();

                $.post(window.aicwp_ajax.ajax_url, {
                    action: 'aicwp_search_posts_for_reference',
                    nonce: window.aicwp_ajax.nonce,
                    search: query
                }, function(response) {
                    if (!response.success || !response.data || !response.data.results || !response.data.results.length) {
                        $results.html('<div style="text-align: center; padding: 12px; color: #999;">' + (i18n.noResults || 'No results found.') + '</div>');
                        return;
                    }

                    var html = '';
                    response.data.results.forEach(function(post) {
                        html += '<div class="post-reference-item" data-id="' + post.id + '" data-title="' + $('<span>').text(post.title).html() + '" style="display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; transition: background 0.15s;">';
                        html += '<div style="flex: 1; min-width: 0;">';
                        html += '<div style="font-weight: 500; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">' + $('<span>').text(post.title).html() + '</div>';
                        html += '<div style="font-size: 12px; color: #999; margin-top: 2px;">ID: ' + post.id + '</div>';
                        html += '</div>';
                        html += '<span style="font-size: 11px; background: #f1f5f9; color: #64748b; padding: 2px 8px; border-radius: 4px; white-space: nowrap; margin-left: 10px;">' + $('<span>').text(post.type).html() + '</span>';
                        html += '</div>';
                    });

                    $results.html(html);
                });
            }, 300);
        });

        // Select a post from results
        $results.on('click', '.post-reference-item', function() {
            selectedPost = {
                id: $(this).data('id'),
                title: $(this).data('title')
            };

            $selectedText.html(
                '<strong>' + $('<span>').text(selectedPost.title).html() + '</strong>' +
                ' <span style="color: #999;">(ID: ' + selectedPost.id + ')</span>' +
                ' <span class="post-reference-remove" style="cursor: pointer; color: #94a3b8; margin-left: 4px;" title="Remove">&times;</span>'
            );
            $selected.show();
            $results.empty().hide();
            $search.val('');
            updateAddButton();
        });

        // Remove selected post
        $selected.on('click', '.post-reference-remove', function() {
            selectedPost = null;
            $selected.hide();
            $search.focus();
            updateAddButton();
        });

        // Hover effect on search results
        $results.on('mouseenter', '.post-reference-item', function() {
            $(this).css('background', '#f8fafc');
        }).on('mouseleave', '.post-reference-item', function() {
            $(this).css('background', '');
        });

        // Add button — save via AJAX
        $addBtn.on('click', function() {
            if (!selectedPost || !$topic.val().trim()) return;

            $addBtn.prop('disabled', true);

            $.post(window.aicwp_ajax.ajax_url, {
                action: 'aicwp_add_knowledge_source',
                nonce: window.aicwp_ajax.nonce,
                topic: $topic.val().trim(),
                post_id: selectedPost.id,
                post_title: selectedPost.title
            }, function(response) {
                if (response.success) {
                    renderSources(response.data.sources);
                    resetForm();
                }
            });
        });

        // Delete button
        $list.on('click', '.knowledge-source-delete', function() {
            var index = $(this).data('index');
            var $item = $(this).closest('.knowledge-source-item');

            $item.css('opacity', '0.5');

            $.post(window.aicwp_ajax.ajax_url, {
                action: 'aicwp_delete_knowledge_source',
                nonce: window.aicwp_ajax.nonce,
                index: index
            }, function(response) {
                if (response.success) {
                    renderSources(response.data.sources);
                } else {
                    $item.css('opacity', '1');
                }
            });
        });
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
