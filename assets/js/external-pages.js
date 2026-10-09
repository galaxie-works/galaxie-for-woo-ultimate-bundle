/**
 * External Pages Admin JavaScript
 *
 * Handles the admin UI for adding and managing external web pages.
 * Similar to PDF Documents modal pattern.
 *
 * @package AICWP
 * @since 1.8.0
 */

(function($) {
    'use strict';

    var config = window.aicwpExternalPagesConfig || {};
    var baseUrl = config.restUrl || '';
    var nonce = config.nonce || '';
    var strings = config.strings || {};

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        initEventHandlers();
    });

    /**
     * Initialize event handlers
     */
    function initEventHandlers() {
        // Open modal from card link
        $(document).on('click', '#manage-external-pages-btn', function(e) {
            e.preventDefault();
            openModal();
        });

        // Modal close handlers
        $(document).on('click', '#external-pages-modal .aicwp-modal-close, #external-pages-modal .aicwp-modal-overlay, #external-pages-modal-close', function() {
            closeModal();
        });

        // Form submission
        $('#aicwp-ui-add-pages-form').on('submit', function(e) {
            e.preventDefault();
            handleFormSubmit($(this));
        });

        // Delete button
        $(document).on('click', '.external-page-delete', function() {
            var id = $(this).data('id');
            if (confirm(strings.confirmDelete || 'Delete this external page?')) {
                deletePage(id);
            }
        });

        // Close modal on escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    }

    /**
     * Open the modal and load pages
     */
    function openModal() {
        $('#external-pages-modal').fadeIn(200);
        $('#aicwp-ui-add-results').hide();
        $('#aicwp-ui-add-pages-form')[0].reset();
        loadPages();
    }

    /**
     * Close the modal
     */
    function closeModal() {
        $('#external-pages-modal').fadeOut(200);
    }

    /**
     * Load and display external pages
     */
    function loadPages() {
        var $list = $('#external-pages-list');
        $list.html('<p class="loading-message"><span class="aicwp-ui-spinner"></span> ' + (strings.loading || 'Loading...') + '</p>');

        $.ajax({
            url: baseUrl,
            method: 'GET',
            headers: { 'X-WP-Nonce': nonce }
        })
        .done(function(data) {
            renderPages(data.pages || []);
        })
        .fail(function() {
            $list.html('<p class="external-pages-error">' + (strings.error || 'Error loading pages') + '</p>');
        });
    }

    /**
     * Render the pages list (all pages are embedded immediately after adding)
     */
    function renderPages(pages) {
        var $list = $('#external-pages-list');

        if (pages.length === 0) {
            $list.html('<p class="external-pages-empty">' + (strings.emptyState || 'No external pages added yet.') + '</p>');
            return;
        }

        var html = '';
        pages.forEach(function(page) {
            html += '<div class="external-page-item" data-id="' + page.id + '">';
            html += '    <div class="external-page-icon">';
            html += '        <span class="dashicons dashicons-admin-site-alt3"></span>';
            html += '    </div>';
            html += '    <div class="external-page-info">';
            html += '        <div class="external-page-title">' + escapeHtml(page.title) + '</div>';
            html += '        <div class="external-page-url"><a href="' + escapeHtml(page.url) + '" target="_blank" rel="noopener">' + escapeHtml(truncate(page.url, 50)) + '</a></div>';
            html += '    </div>';
            html += '    <div class="external-page-status status-trained">';
            html += '        ✓ Indexed';
            html += '    </div>';
            html += '    <div class="external-page-actions">';
            html += '        <a href="#" class="external-page-action-btn external-page-delete" data-id="' + page.id + '">';
            html += '            <span class="dashicons dashicons-trash"></span> Delete';
            html += '        </a>';
            html += '    </div>';
            html += '</div>';
        });

        $list.html(html);
    }

    /**
     * Handle form submission
     */
    function handleFormSubmit($form) {
        var $resultsDiv = $('#aicwp-ui-add-results');
        var urls = $form.find('textarea[name="urls"]').val();
        var sourceName = $form.find('input[name="source_name"]').val();

        $resultsDiv.html('<p class="loading-message"><span class="aicwp-ui-spinner"></span> ' + (strings.validating || 'Validating URLs...') + '</p>').show();

        // Step 1: Validate URLs
        $.ajax({
            url: baseUrl + '/validate',
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            contentType: 'application/json',
            data: JSON.stringify({
                urls: urls,
                source_name: sourceName
            })
        })
        .done(function(validation) {
            if (validation.code) {
                $resultsDiv.removeClass('has-warning has-success').addClass('has-error')
                    .html('<p class="external-pages-error">' + escapeHtml(validation.message) + '</p>');
                return;
            }

            var validUrls = validation.valid_urls || [];
            var invalid = validation.invalid || [];
            var srcName = validation.source_name || '';

            if (validUrls.length === 0) {
                var html = '<p class="external-pages-warning">' + (strings.noValidUrls || 'No valid URLs to process.') + '</p>';
                if (invalid.length > 0) {
                    html += '<ul class="validation-errors">';
                    invalid.forEach(function(item) {
                        html += '<li><strong>' + escapeHtml(truncate(item.url, 40)) + '</strong> — ' + escapeHtml(item.error) + '</li>';
                    });
                    html += '</ul>';
                }
                $resultsDiv.removeClass('has-error has-success').addClass('has-warning').html(html);
                return;
            }

            // Step 2: Process URLs sequentially
            $resultsDiv.removeClass('has-warning has-error has-success');
            processUrlsSequentially(validUrls, srcName, invalid, $resultsDiv, $form);
        })
        .fail(function(xhr) {
            var message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : (strings.error || 'Error');
            $resultsDiv.removeClass('has-warning has-success').addClass('has-error')
                .html('<p class="external-pages-error">' + escapeHtml(message) + '</p>');
        });
    }

    /**
     * Process URLs one by one
     */
    function processUrlsSequentially(urls, sourceName, initialInvalid, $resultsDiv, $form) {
        var results = {
            added: [],
            failed: [],
            skipped: initialInvalid
        };
        var current = 0;
        var total = urls.length;

        function updateProgress() {
            var progressText = (strings.processing || 'Processing URL %d of %d...').replace('%d', current + 1).replace('%d', total);
            $resultsDiv.html(
                '<p class="loading-message"><span class="aicwp-ui-spinner"></span> ' + progressText + '</p>' +
                '<p class="progress-url">' + escapeHtml(truncate(urls[current], 50)) + '</p>'
            );
        }

        function processNext() {
            if (current >= urls.length) {
                showFinalResults(results, $resultsDiv);
                $form.find('textarea[name="urls"]').val('');
                loadPages();
                return;
            }

            updateProgress();

            $.ajax({
                url: baseUrl + '/add',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                contentType: 'application/json',
                data: JSON.stringify({
                    url: urls[current],
                    source_name: sourceName
                })
            })
            .done(function(result) {
                if (result.success) {
                    results.added.push({
                        url: urls[current],
                        title: result.title,
                        id: result.id
                    });
                } else {
                    results.failed.push({
                        url: urls[current],
                        error: result.message || 'Unknown error'
                    });
                }
                current++;
                processNext();
            })
            .fail(function(xhr) {
                var errorMsg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Request failed';
                results.failed.push({
                    url: urls[current],
                    error: errorMsg
                });
                current++;
                processNext();
            });
        }

        processNext();
    }

    /**
     * Show final results summary
     */
    function showFinalResults(results, $container) {
        // Determine the state based on results
        var hasSuccess = results.added.length > 0;
        var hasErrors = results.failed.length > 0;
        var hasWarnings = results.skipped.length > 0;

        // Set appropriate class
        $container.removeClass('has-warning has-error has-success');
        if (hasErrors) {
            $container.addClass('has-error');
        } else if (hasSuccess) {
            $container.addClass('has-success');
        } else if (hasWarnings) {
            $container.addClass('has-warning');
        }

        var html = '';

        if (hasSuccess) {
            var successMsg = (strings.success || '%d page(s) added successfully.').replace('%d', results.added.length);
            html += '<p class="result-success">✓ ' + successMsg + '</p>';
        }

        if (hasWarnings) {
            var skippedMsg = (strings.skipped || '%d skipped:').replace('%d', results.skipped.length);
            html += '<p class="result-warning">⚠ ' + skippedMsg + ' ';
            html += results.skipped.map(function(s) {
                return truncate(s.url, 30) + ' (' + s.error + ')';
            }).join(', ');
            html += '</p>';
        }

        if (hasErrors) {
            var failedMsg = (strings.failed || '%d failed:').replace('%d', results.failed.length);
            html += '<p class="result-error">✕ ' + failedMsg + '</p>';
            html += '<ul class="failed-list">';
            results.failed.forEach(function(f) {
                html += '<li><strong>' + escapeHtml(truncate(f.url, 40)) + '</strong> — ' + escapeHtml(f.error) + '</li>';
            });
            html += '</ul>';
        }

        $container.html(html);
    }

    /**
     * Delete a page
     */
    function deletePage(id) {
        var $item = $('.external-page-item[data-id="' + id + '"]');
        $item.css('opacity', '0.5');

        $.ajax({
            url: baseUrl + '/' + id,
            method: 'DELETE',
            headers: { 'X-WP-Nonce': nonce }
        })
        .done(function() {
            $item.slideUp(300, function() {
                $(this).remove();
                // Check if list is empty
                if ($('.external-page-item').length === 0) {
                    $('#external-pages-list').html('<p class="external-pages-empty">' + (strings.emptyState || 'No external pages added yet.') + '</p>');
                }
            });
        })
        .fail(function() {
            $item.css('opacity', '1');
            alert(strings.error || 'Error deleting page');
        });
    }

    /**
     * Truncate string to specified length
     */
    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len - 3) + '...' : str;
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})(jQuery);
