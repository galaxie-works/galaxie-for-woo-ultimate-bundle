/**
 * AI Chat for WordPress - Admin UI Module
 *
 * Handles general UI interactions: sticky footers, collapsible sections,
 * shortcode generator, visual radio cards.
 *
 * @package AICWP
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var i18n = window.aicwp_i18n || {};

    /**
     * Position sticky footer to match form width
     */
    function initStickyFooters() {
        function positionStickyFooter($form) {
            var $footer = $form.find('.aicwp-ui-sticky-footer');
            if ($form.length && $footer.length) {
                var formOffset = $form.offset();
                var formWidth = $form.outerWidth();
                $footer.css({
                    left: formOffset.left + 'px',
                    width: formWidth + 'px'
                });
            }
        }

        // Position all sticky footers
        function positionAllFooters() {
            $('#settings-form, #ai-chat-settings-form').each(function() {
                positionStickyFooter($(this));
            });
        }

        positionAllFooters();
        $(window).on('resize', positionAllFooters);
    }

    /**
     * Initialize collapsible sections
     */
    function initCollapsibleSections() {
        // Toggle handler
        $('.aicwp-ui-collapsible-header').on('click', function() {
            var $header = $(this);
            var $content = $header.next('.aicwp-ui-collapsible-content');
            var sectionId = $header.data('section');

            $header.toggleClass('is-open');
            $content.toggleClass('is-open');

            // Save state to localStorage
            if (sectionId) {
                var isOpen = $header.hasClass('is-open');
                localStorage.setItem('aicwp_ui_collapse_' + sectionId, isOpen ? 'open' : 'closed');
            }
        });

        // Restore saved states
        $('.aicwp-ui-collapsible-header').each(function() {
            var $header = $(this);
            var sectionId = $header.data('section');
            if (sectionId) {
                var savedState = localStorage.getItem('aicwp_ui_collapse_' + sectionId);
                if (savedState === 'open') {
                    $header.addClass('is-open');
                    $header.next('.aicwp-ui-collapsible-content').addClass('is-open');
                }
            }
        });
    }

    /**
     * Initialize shortcode generator
     */
    function initShortcodeGenerator() {
        var $postTypes = $('.shortcode-post-type');
        var $placeholder = $('#shortcode-placeholder');
        var $limit = $('#shortcode-limit');
        var $output = $('#generated-shortcode');
        var $copyBtn = $('#copy-shortcode-btn');

        // Skip if elements don't exist
        if (!$output.length) return;

        var defaultPlaceholder = i18n.searchPlaceholder || 'Search anything...';

        function updateShortcode() {
            var postTypes = [];
            $postTypes.filter(':checked').each(function() {
                postTypes.push($(this).val());
            });

            var placeholder = $placeholder.val().trim();
            var limit = parseInt($limit.val()) || 10;

            var shortcode = '[ai_search_field';

            if (postTypes.length > 0) {
                shortcode += ' post_types="' + postTypes.join(',') + '"';
            }

            if (placeholder && placeholder !== defaultPlaceholder) {
                shortcode += ' placeholder="' + placeholder + '"';
            }

            if (limit !== 10) {
                shortcode += ' limit="' + limit + '"';
            }

            shortcode += ']';

            $output.val(shortcode);
        }

        // Update shortcode when options change
        $postTypes.add($placeholder).add($limit).on('change input', updateShortcode);

        // Initial generation
        updateShortcode();

        // Copy to clipboard
        $copyBtn.on('click', function() {
            $output.select();
            document.execCommand('copy');

            var $btn = $(this);
            var originalHtml = $btn.html();
            $btn.html('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-right: 5px;"><path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>' + (i18n.copied || 'Copied!'));

            setTimeout(function() {
                $btn.html(originalHtml);
            }, 2000);
        });
    }

    /**
     * Initialize visual radio card selection
     */
    function initVisualRadioCards() {
        $('.aicwp-ui-visual-radio-card input[type="radio"]').on('change', function() {
            var $group = $(this).closest('.aicwp-ui-visual-radio-group');
            $group.find('.aicwp-ui-visual-radio-card').removeClass('selected');
            $(this).closest('.aicwp-ui-visual-radio-card').addClass('selected');
        });
    }

    /**
     * Initialize theme toggle (light/system/dark)
     */
    function initThemeToggle() {
        var $toggle = $('.aicwp-ui-theme-toggle');
        if (!$toggle.length) return;

        var $buttons = $toggle.find('.aicwp-ui-theme-btn');
        var $hiddenInput = $toggle.find('input[type="hidden"]');

        $buttons.on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var value = $btn.data('value');

            // Update active state
            $buttons.removeClass('active');
            $btn.addClass('active');

            // Update hidden input
            $hiddenInput.val(value);
        });
    }

    /**
     * Initialize position toggle (left/right)
     */
    function initPositionToggle() {
        var $toggle = $('.aicwp-ui-position-toggle').not('.aicwp-ui-offset-device-toggle').not('#ai-chat-audit-filters .aicwp-ui-position-toggle');
        if (!$toggle.length) return;

        var $buttons = $toggle.find('.aicwp-ui-position-btn');
        var $hiddenInput = $toggle.find('input[type="hidden"]');

        $buttons.on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var value = $btn.data('value');

            $buttons.removeClass('active');
            $btn.addClass('active');
            $hiddenInput.val(value);
        });
    }

    /**
     * Initialize offset device toggle (Desktop/Mobile)
     */
    function initOffsetDeviceToggle() {
        var $toggle = $('.aicwp-ui-offset-device-toggle');
        if (!$toggle.length) return;

        var $buttons = $toggle.find('.aicwp-ui-position-btn');

        $buttons.on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var target = $btn.data('target');

            $buttons.removeClass('active');
            $btn.addClass('active');

            $('.aicwp-ui-offset-panel').hide();
            $('#' + target).show();
        });
    }

    /**
     * Initialize header style toggle (simple/image/animated)
     */
    function initHeaderStyleToggle() {
        var $toggle = $('.aicwp-ui-header-style-toggle');
        if (!$toggle.length) return;

        var $buttons = $toggle.find('.aicwp-ui-header-style-btn');
        var $hiddenInput = $toggle.find('input[type="hidden"]');
        var $bgPanel = $('#aicwp-ui-header-bg-panel');
        var $animatedPanel = $('#aicwp-ui-header-animated-panel');

        function stopAnimatedPreview() {
            if (typeof AicwpSilkWave !== 'undefined') {
                AicwpSilkWave.destroy();
            }
        }

        function initAnimatedBgPreview() {
            var container = document.getElementById('aicwp-ui-animated-bg-preview');
            if (!container || container.offsetWidth === 0 || typeof AicwpSilkWave === 'undefined') return;

            var $input = $('#aicwp_animated_bg_color');
            var baseColor = ($input.length && $input.val()) ? $input.val() : '#1560d0';

            AicwpSilkWave.init(container, baseColor);

            // Update color live when picker changes (cached, not per-frame)
            $input.off('colorpickerchange.silkwave').on('colorpickerchange.silkwave', function() {
                var newColor = $input.val() || '#1560d0';
                AicwpSilkWave.setColor(newColor);
            });
        }

        function showPanelForValue(value, animate) {
            if (value === 'image') {
                stopAnimatedPreview();
                animate ? $bgPanel.slideDown(200) : $bgPanel.show();
                animate ? $animatedPanel.slideUp(200) : $animatedPanel.hide();
            } else if (value === 'animated') {
                animate ? $bgPanel.slideUp(200) : $bgPanel.hide();
                animate ? $animatedPanel.slideDown(200, function() { initAnimatedBgPreview(); }) : $animatedPanel.show();
                if (!animate) initAnimatedBgPreview();
            } else {
                stopAnimatedPreview();
                animate ? $bgPanel.slideUp(200) : $bgPanel.hide();
                animate ? $animatedPanel.slideUp(200) : $animatedPanel.hide();
            }
        }

        $buttons.on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var value = $btn.data('value');

            $buttons.removeClass('active');
            $btn.addClass('active');
            $hiddenInput.val(value);

            showPanelForValue(value, true);
        });

        // Init correct panel on page load
        var currentValue = $hiddenInput.val();
        if (currentValue === 'animated') {
            initAnimatedBgPreview();
        }
    }

    /**
     * Initialize quality slider (min match percentage)
     */
    function initQualitySlider() {
        var $qualitySlider = $('#aicwp_min_match_percentage');
        if (!$qualitySlider.length) return;

        function updateQualitySlider($slider) {
            var value = parseInt($slider.val());
            var $badge = $('#min-match-badge');
            var $label = $('#min-match-label');
            var $display = $('#min-match-display');
            var $track = $('#min-match-track');

            var qualityClass = '';
            var labelText = '';
            var qualityClasses = 'quality-very-low quality-low quality-below-avg quality-balanced quality-high quality-very-high';

            // Update badge text
            $badge.text(value + '%');

            // Determine quality class and label based on value
            if (value < 20) {
                qualityClass = 'quality-very-low';
                labelText = i18n.qualityVeryLow || 'Loose - many results, lower relevance';
            } else if (value < 40) {
                qualityClass = 'quality-low';
                labelText = i18n.qualityLow || 'Broad - more results, some may be less relevant';
            } else if (value < 60) {
                qualityClass = 'quality-balanced';
                labelText = i18n.qualityBalanced || 'Balanced - good mix of quantity and quality';
            } else if (value < 80) {
                qualityClass = 'quality-high';
                labelText = i18n.qualityHigh || 'Quality focused - pay attention because <strong>you might start getting little results</strong>';
            } else {
                qualityClass = 'quality-very-high';
                labelText = i18n.qualityVeryHigh || 'Very strict \u2014 <strong>you might get little to no results</strong>';
            }

            // Update classes on all elements
            $badge.removeClass(qualityClasses).addClass(qualityClass);
            $display.removeClass(qualityClasses).addClass(qualityClass);
            $slider.removeClass(qualityClasses).addClass(qualityClass);
            $track.removeClass(qualityClasses).addClass(qualityClass);

            $label.html(labelText);

            // Update track glow position
            var percentage = (value / 100) * 100;
            $track.css('--slider-glow-position', percentage + '%');
        }

        updateQualitySlider($qualitySlider);
        $qualitySlider.on('input change', function() {
            updateQualitySlider($(this));
        });
    }

    /**
     * Initialize checkbox card styling
     */
    function initCheckboxCards() {
        // Hover effect
        $('.aicwp-ui-checkbox-card').hover(
            function() { $(this).css('border-color', '#0073ee'); },
            function() {
                var $checkbox = $(this).find('.shortcode-post-type');
                if (!$checkbox.is(':checked')) {
                    $(this).css('border-color', '#e0e0e0');
                }
            }
        );

        // Checked state
        $('.shortcode-post-type').on('change', function() {
            var $card = $(this).closest('.aicwp-ui-checkbox-card');
            if ($(this).is(':checked')) {
                $card.css({'background': '#f0f7ff', 'border-color': '#0073ee'});
            } else {
                $card.css({'background': '#fff', 'border-color': '#e0e0e0'});
            }
        }).trigger('change');
    }

    /**
     * Initialize toggleable cards with localStorage persistence
     */
    function initToggleableCards() {
        var STORAGE_KEY = 'aicwp_ui_collapsed_cards';

        // Get collapsed state from localStorage
        function getCollapsedCards() {
            try {
                var stored = localStorage.getItem(STORAGE_KEY);
                return stored ? JSON.parse(stored) : {};
            } catch(e) {
                return {};
            }
        }

        // Save collapsed state to localStorage
        function saveCollapsedCards(state) {
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
            } catch(e) {}
        }

        // Initialize: apply collapsed class, then add js-ready to disable early CSS
        var DEFAULTS = { 'database-management': true, 'semantic-search-field': true, 'developer-debug': true };
        var collapsedState = window.aicwpCollapsedCards || getCollapsedCards();
        for (var id in DEFAULTS) {
            if (!(id in collapsedState)) collapsedState[id] = DEFAULTS[id];
        }
        $('.aicwp-ui-card-toggleable').each(function() {
            var $card = $(this);
            var toggleId = $card.data('toggle-id');
            if (toggleId && collapsedState[toggleId] === true) {
                $card.addClass('is-collapsed');
            }
            $card.addClass('js-ready');
        });

        // Handle click on card header
        $(document).on('click', '.aicwp-ui-card-toggleable .aicwp-ui-card-header', function(e) {
            if ($(e.target).is('button, input, select, a')) {
                return;
            }

            var $card = $(this).closest('.aicwp-ui-card-toggleable');
            var toggleId = $card.data('toggle-id');

            $card.toggleClass('is-collapsed');

            if (toggleId) {
                var collapsedState = getCollapsedCards();
                collapsedState[toggleId] = $card.hasClass('is-collapsed');
                saveCollapsedCards(collapsedState);
            }
        });
    }

    /**
     * Initialize color picker fields
     */
    function initColorPicker() {
        if (!$.fn.wpColorPicker) return;

        $('.aicwp-ui-color-picker').wpColorPicker();

        // Add Select/Apply button and hex input next to each swatch
        $('.aicwp-ui-color-picker').each(function() {
            var $input = $(this);
            var $container = $input.closest('.wp-picker-container');
            var $swatchBtn = $container.find('.wp-color-result');
            var $holder = $container.find('.wp-picker-holder');

            // Select/Apply button
            if (!$container.find('.aicwp-ui-color-action-btn').length) {
                var $actionBtn = $('<button type="button" class="aicwp-ui-color-action-btn">Select</button>');
                $swatchBtn.after($actionBtn);

                $actionBtn.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var isOpen = $swatchBtn.attr('aria-expanded') === 'true';
                    $swatchBtn.trigger('click');
                    if (isOpen) {
                        $actionBtn.text('Select');
                    }
                });
            }

            // Hex input below the picker
            if (!$holder.find('.aicwp-ui-hex-input').length) {
                var currentVal = $input.val() || $input.data('default-color') || '#000000';
                var $hexInput = $('<input type="text" class="aicwp-ui-hex-input" maxlength="7" placeholder="#000000" />');
                $hexInput.val(currentVal);
                $holder.append($hexInput);

                // Hex input → picker
                $hexInput.on('input', function() {
                    var val = $(this).val();
                    if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                        $input.wpColorPicker('color', val);
                    }
                });

                // Picker → hex input (on iris change)
                $input.wpColorPicker('option', 'change', function(event, ui) {
                    $hexInput.val(ui.color.toString());
                    // Custom event for non-iris listeners (e.g. animated bg preview)
                    $input.trigger('colorpickerchange');
                });
            }

            // Sync picker-open class, button text, and hex value
            function syncPickerState() {
                var isOpen = $swatchBtn.attr('aria-expanded') === 'true';
                $container.toggleClass('picker-open', isOpen);
                $container.find('.aicwp-ui-color-action-btn').text(isOpen ? 'Apply' : 'Select');
                if (isOpen) {
                    $container.find('.aicwp-ui-hex-input').val($input.val());
                }
            }

            $swatchBtn.on('click', function() {
                setTimeout(syncPickerState, 0);
            });

            // Catch all close paths (click outside, etc.)
            var observer = new MutationObserver(syncPickerState);
            observer.observe($swatchBtn[0], { attributes: true, attributeFilter: ['aria-expanded'] });
        });

        // Move animated bg color picker holder to body to escape overflow:hidden
        var $waveInput = $('#aicwp_animated_bg_color');
        if ($waveInput.length) {
            var $waveContainer = $waveInput.closest('.wp-picker-container');
            var $waveHolder = $waveContainer.find('.wp-picker-holder');
            var $waveSwatch = $waveContainer.find('.wp-color-result');
            var $waveHexInput = $waveContainer.find('.aicwp-ui-hex-input');

            // Create a body-level wrapper with its own class for styling
            var $waveDropdown = $('<div class="aicwp-ui-wave-color-dropdown"></div>').appendTo('body').hide();
            $waveHolder.detach().appendTo($waveDropdown).css({
                position: 'static',
                opacity: 1,
                visibility: 'visible'
            });
            // Move hex input into the dropdown too
            if ($waveHexInput.length) {
                $waveHexInput.detach().appendTo($waveDropdown).show();
            }

            function positionWaveDropdown() {
                var isOpen = $waveSwatch.attr('aria-expanded') === 'true';
                if (isOpen) {
                    var offset = $waveSwatch.offset();
                    $waveDropdown.css({
                        top: offset.top + $waveSwatch.outerHeight() + 6,
                        left: offset.left
                    }).show();
                } else {
                    $waveDropdown.hide();
                }
            }

            var waveObserver = new MutationObserver(positionWaveDropdown);
            waveObserver.observe($waveSwatch[0], { attributes: true, attributeFilter: ['aria-expanded'] });
            positionWaveDropdown();
        }
    }

    /**
     * Initialize system prompt character counter
     */
    function initSystemPromptCounter() {
        var $textarea = $('#aicwp_chat_system_prompt');
        var $counter = $('#system-prompt-counter');

        if (!$textarea.length || !$counter.length) return;

        var maxLength = parseInt($textarea.data('max-length')) || 2000;

        function updateCounter() {
            var length = $textarea.val().length;
            $counter.text(length + ' / ' + maxLength + ' ' + (i18n.characters || 'characters'));
            var percentage = (length / maxLength) * 100;
            if (percentage > 90) {
                $counter.css('color', '#d63638');
            } else if (percentage > 75) {
                $counter.css('color', '#dba617');
            } else {
                $counter.css('color', '#666');
            }
        }

        $textarea.on('input', updateCounter);
        updateCounter();
    }

    /**
     * Initialize settings toggle handlers
     */
    function initSettingsToggles() {
        // Toggle terms notice text field visibility
        $('#aicwp_chat_terms_notice_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#aicwp_chat_terms_notice_text_wrapper').slideDown(300);
            } else {
                $('#aicwp_chat_terms_notice_text_wrapper').slideUp(300);
            }
        });

        // Toggle contact form examples field visibility
        $('#aicwp_contact_form_allow_ai_send').on('change', function() {
            if ($(this).is(':checked')) {
                $('#aicwp_contact_form_examples_wrapper').slideDown(300);
            } else {
                $('#aicwp_contact_form_examples_wrapper').slideUp(300);
            }
        });

        // Toggle quick buttons wrapper visibility
        $('#aicwp_chat_quick_buttons_enabled').on('change', function() {
            if ($(this).is(':checked')) {
                $('#aicwp_chat_quick_buttons_wrapper').slideDown(300);
            } else {
                $('#aicwp_chat_quick_buttons_wrapper').slideUp(300);
            }
        });
    }

    /**
     * Initialize quick buttons management (remove handler only - add needs PHP)
     */
    function initQuickButtonsRemove() {
        $(document).on('click', '.aicwp-remove-quick-button', function() {
            var $container = $('#aicwp-quick-buttons-container');
            if ($container.find('.aicwp-quick-button-row').length > 1) {
                $(this).closest('.aicwp-quick-button-row').remove();
                // Re-index remaining rows
                $container.find('.aicwp-quick-button-row').each(function(i) {
                    $(this).find('input, select').each(function() {
                        var name = $(this).attr('name');
                        if (name) {
                            $(this).attr('name', name.replace(/\[\d+\]/, '[' + i + ']'));
                        }
                    });
                });
            }
        });
    }

    /**
     * Initialize modal handlers
     */
    function initModalHandlers() {
        // Open contact form configuration modal
        $(document).on('click', '.aicwp-configure-contact-form', function() {
            $('#contact-form-config-modal').fadeIn(200);
        });

        // Close contact form modal
        $('#contact-form-modal-close, #contact-form-config-modal .aicwp-ui-modal-overlay').on('click', function() {
            $('#contact-form-config-modal').fadeOut(200);
        });
    }

    /**
     * Manage backdrop-filter on stacked modals.
     * When multiple modals are open, only the topmost one keeps backdrop-filter
     * to avoid GPU-heavy double blur causing sluggish scrolling.
     */
    function manageModalBlur() {
        var $visible = $('.aicwp-ui-modal:visible');
        if ($visible.length <= 1) {
            // Single or no modal - restore blur on the one visible
            $visible.find('.aicwp-ui-modal-overlay').css('backdrop-filter', '');
            return;
        }
        // Remove blur from all except the last one in DOM (topmost)
        $visible.not(':last').find('.aicwp-ui-modal-overlay').css('backdrop-filter', 'none');
        $visible.last().find('.aicwp-ui-modal-overlay').css('backdrop-filter', '');
    }

    // Watch all modal fade in/out
    $(document).on('fadeInComplete', '.aicwp-ui-modal', manageModalBlur);
    // Also catch programmatic display changes via MutationObserver fallback
    var modalObserver = new MutationObserver(function(mutations) {
        var check = false;
        mutations.forEach(function(m) {
            if (m.target.classList && m.target.classList.contains('aicwp-ui-modal')) check = true;
        });
        if (check) manageModalBlur();
    });
    $(document).ready(function() {
        $('.aicwp-ui-modal').each(function() {
            modalObserver.observe(this, { attributes: true, attributeFilter: ['style'] });
        });
    });

    /**
     * Initialize quick button color pickers (simple swatch with iris popup)
     */
    function initQuickButtonColorPickers() {
        if (!$.fn.iris) return;

        // Create shared tooltip element
        var $tooltip = $('<div class="aicwp-quick-btn-tooltip">' + (i18n.color || 'Buttons Color') + '</div>').appendTo('body').hide();

        // Show tooltip on hover
        $(document).on('mouseenter', '.aicwp-quick-btn-color-swatch', function() {
            var $swatch = $(this);
            if ($swatch.hasClass('picker-open')) return;
            var offset = $swatch.offset();
            $tooltip.css({
                top: offset.top - $tooltip.outerHeight() - 6,
                left: offset.left + ($swatch.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
            }).fadeIn(150);
        });

        $(document).on('mouseleave', '.aicwp-quick-btn-color-swatch', function() {
            $tooltip.hide();
        });

        // Shared dropdown container appended to body
        var $dropdown = null;

        function closeDropdown() {
            if ($dropdown) {
                $dropdown.remove();
                $dropdown = null;
            }
            $('.aicwp-quick-btn-color-swatch.picker-open').removeClass('picker-open');
            $(document).off('mousedown.quickBtnColor');
        }

        // Use delegated click for dynamically added rows
        $(document).on('click', '.aicwp-quick-btn-color-swatch', function(e) {
            e.preventDefault();
            e.stopPropagation();
            $tooltip.hide();

            var $swatch = $(this);
            var $wrap = $swatch.closest('.aicwp-quick-button-color-wrap');
            var $input = $wrap.find('.aicwp-quick-button-color-value');

            // If already open on this swatch, close it
            if ($swatch.hasClass('picker-open')) {
                closeDropdown();
                return;
            }

            // Close any other open picker
            closeDropdown();

            var currentColor = $input.val() || $input.data('default-color') || '#0073ee';

            // Create dropdown container on body
            $dropdown = $('<div class="aicwp-quick-btn-dropdown"></div>').appendTo('body');

            // Position below the swatch using absolute coords
            var swatchOffset = $swatch.offset();
            $dropdown.css({
                top: swatchOffset.top + $swatch.outerHeight() + 6,
                left: swatchOffset.left
            });

            // Create temp input for iris inside dropdown
            var $tempInput = $('<input type="text" />').appendTo($dropdown).hide();
            $tempInput.val(currentColor);

            $tempInput.iris({
                hide: false,
                width: 220,
                palettes: ['#000000', '#ffffff', '#dd3333', '#dd9933', '#eeee22', '#81d742', '#1e73be', '#8224e3'],
                change: function(event, ui) {
                    var color = ui.color.toString();
                    $swatch.css('background-color', color);
                    $input.val(color);
                    $dropdown.find('.aicwp-ui-hex-input').val(color);
                }
            });

            // Add reset link and hex input
            var currentHex = $input.val() || $input.data('default-color') || '#0073ee';
            var $footer = $('<div class="aicwp-quick-btn-picker-footer"></div>');
            var $resetLink = $('<a href="#" class="aicwp-quick-btn-reset">' + (i18n.reset || 'Reset') + '</a>');
            var $hexInput = $('<input type="text" class="aicwp-ui-hex-input" maxlength="7" placeholder="#000000" style="display:block;position:static;margin:0;" />').val(currentHex);

            $footer.append($resetLink).append($hexInput);
            $dropdown.append($footer);

            // Hex input → iris
            $hexInput.on('input', function() {
                var val = $(this).val();
                if (/^#[0-9a-fA-F]{6}$/.test(val)) {
                    $tempInput.iris('color', val);
                }
            });

            // Reset → clear color, revert to gray
            $resetLink.on('click', function(e) {
                e.preventDefault();
                $input.val('');
                $swatch.css('background-color', '#ebebeb');
                closeDropdown();
            });

            $swatch.addClass('picker-open');

            // Close picker when clicking outside
            $(document).on('mousedown.quickBtnColor', function(ev) {
                if (!$(ev.target).closest('.aicwp-quick-btn-dropdown').length &&
                    !$(ev.target).closest('.aicwp-quick-btn-color-swatch').length) {
                    closeDropdown();
                }
            });
        });
    }

    /**
     * Initialize quick button type change handler
     */
    function initQuickButtonTypeChange() {
        $(document).on('change', '.aicwp-quick-button-type', function() {
            var $row = $(this).closest('.aicwp-quick-button-row');
            var $valueInput = $row.find('.aicwp-quick-button-value');
            var $configureBtn = $row.find('.aicwp-configure-contact-form');
            var type = $(this).val();

            if (type === 'contact') {
                $valueInput.hide().val('');
                $configureBtn.show();
            } else if (type === 'url') {
                $valueInput.show().attr('placeholder', i18n.placeholderUrl || 'https://example.com');
                $configureBtn.hide();
            } else {
                $valueInput.show().attr('placeholder', i18n.placeholderMessage || 'Message to send');
                $configureBtn.hide();
            }
        });
    }

    /**
     * Initialize contact form AJAX handlers (test email, save settings)
     */
    function initContactFormHandlers() {
        var config = window.aicwp_ajax || {};

        // Send test email
        $('#contact-form-test-email-btn').on('click', function() {
            var $btn = $(this);
            var $result = $('#contact-form-test-result');
            var $btnText = $btn.find('.button-text');
            var $spinner = $btn.find('.button-spinner');

            $btn.prop('disabled', true);
            $btnText.hide();
            $spinner.show();
            $result.hide();

            $.ajax({
                url: window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'aicwp_test_contact_email',
                    nonce: config.test_email_nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success').text(response.data.message).show();
                    } else {
                        $result.removeClass('success').addClass('error').text(response.data.message).show();
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error').text(i18n.requestFailed || 'Request failed. Please try again.').show();
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btnText.show();
                    $spinner.hide();
                }
            });
        });

        // Save contact form settings
        $('#contact-form-save-settings-btn').on('click', function() {
            var $btn = $(this);
            var $result = $('#contact-form-save-result');
            var $btnText = $btn.find('.button-text');
            var $spinner = $btn.find('.button-spinner');

            $btn.prop('disabled', true);
            $btnText.hide();
            $spinner.show();
            $result.hide();

            $.ajax({
                url: window.ajaxurl,
                method: 'POST',
                data: {
                    action: 'aicwp_save_contact_form_settings',
                    nonce: config.contact_form_nonce,
                    recipient: $('#aicwp_contact_form_recipient').val(),
                    from_name: $('#aicwp_contact_form_from_name').val(),
                    from_email: $('#aicwp_contact_form_from_email').val(),
                    subject: $('#aicwp_contact_form_subject').val(),
                    success_message: $('#aicwp_contact_form_success_message').val()
                },
                success: function(response) {
                    if (response.success) {
                        $result.removeClass('error').addClass('success').text(response.data.message).show();
                        setTimeout(function() {
                            $('#contact-form-config-modal').fadeOut(200);
                        }, 1500);
                    } else {
                        $result.removeClass('success').addClass('error').text(response.data.message).show();
                    }
                },
                error: function() {
                    $result.removeClass('success').addClass('error').text(i18n.requestFailed || 'Request failed. Please try again.').show();
                },
                complete: function() {
                    $btn.prop('disabled', false);
                    $btnText.show();
                    $spinner.hide();
                }
            });
        });
    }

    /**
     * AI Chat tab sidebar navigation: show one section at a time.
     */
    function initChatSettingsNav() {
        var $sidebar = $('.aicwp-ui-chat-sidebar');
        if (!$sidebar.length) return;

        var heightTimeout = null;

        function switchSection(target) {
            if (!target) return;
            var $btn = $sidebar.find('.aicwp-ui-chat-sidebar-item[data-target="' + target + '"]');
            if (!$btn.length) return;

            $sidebar.find('.aicwp-ui-chat-sidebar-item')
                .removeClass('is-active')
                .attr('aria-selected', 'false');
            $btn.addClass('is-active').attr('aria-selected', 'true');

            var $next = $('.aicwp-ui-card[data-chat-section="' + target + '"]');
            if (!$next.length) return;

            var $current = $('.aicwp-ui-card[data-chat-section].is-active');
            var formEl = document.getElementById('ai-chat-settings-form');

            if (!formEl || !$current.length || $current[0] === $next[0]) {
                $('.aicwp-ui-card[data-chat-section]').removeClass('is-active');
                $next.addClass('is-active');
                return;
            }

            var startHeight = formEl.offsetHeight;
            var endHeight = startHeight - $current[0].offsetHeight + $next[0].offsetHeight;

            formEl.style.height = startHeight + 'px';

            $('.aicwp-ui-card[data-chat-section]').removeClass('is-active');
            $next.addClass('is-active');

            void formEl.offsetHeight;

            formEl.style.height = endHeight + 'px';

            if (heightTimeout) clearTimeout(heightTimeout);
            heightTimeout = setTimeout(function() {
                formEl.style.height = '';
                heightTimeout = null;
            }, 370);
        }

        // Restore section from URL hash
        var hash = window.location.hash.replace('#', '');
        if (hash) {
            switchSection(hash);
        }

        $sidebar.on('click', '.aicwp-ui-chat-sidebar-item', function() {
            var target = $(this).data('target');
            switchSection(target);
            if (target) {
                window.location.hash = target;
            }
        });
    }

    /**
     * Initialize all UI handlers
     */
    function init() {
        initStickyFooters();
        initCollapsibleSections();
        initShortcodeGenerator();
        initVisualRadioCards();
        initThemeToggle();
        initPositionToggle();
        initOffsetDeviceToggle();
        initHeaderStyleToggle();
        initQualitySlider();
        initCheckboxCards();
        initToggleableCards();
        initColorPicker();
        initSystemPromptCounter();
        initSettingsToggles();
        initQuickButtonsRemove();
        initQuickButtonTypeChange();
        initQuickButtonColorPickers();
        initModalHandlers();
        initChatSettingsNav();
        initContactFormHandlers();

        console.log('AicwpAdmin Admin UI loaded');
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
