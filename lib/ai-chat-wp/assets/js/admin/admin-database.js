/**
 * AI Chat for WordPress - Admin Database Module
 *
 * Handles database status display, actions, and embedding search.
 *
 * @package AICWP
 * @since 1.0.0
 */

(function($) {
    'use strict';

    var AicwpAdmin = window.AicwpAdmin || {};
    var i18n = window.aicwp_i18n || {};

    /**
     * Refresh database status
     */
    function refreshDatabaseStatus() {
        $('#status-content').html('<p><span class="aicwp-ui-spinner" style="margin-right: 6px;"></span>' +
            (i18n.loading || 'Loading...') + '</p>');

        AicwpAdmin.ajax({
            action: 'aicwp_manage_database',
            data: { database_action: 'get_stats' },
            success: function(response) {
                if (response.success) {
                    displayDatabaseStatus(response.data);
                    updateListingDetectionInfo(response.data);
                } else {
                    $('#status-content').html('<p style="color: red;">' +
                        (i18n.error || 'Error:') + ' ' + (response.data || 'Unknown error') + '</p>');
                    $('#listing-count-text').text(i18n.errorLoadingInfo || 'Error loading listing information');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error Details:', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    readyState: xhr.readyState,
                    statusCode: xhr.status
                });
                $('#status-content').html('<p style="color: red;">' +
                    (i18n.ajaxError || 'AJAX error:') + ' ' + error + ' (Status: ' + xhr.status + ')</p>');
            }
        });
    }

    /**
     * Display database status in the admin panel
     */
    function displayDatabaseStatus(data) {
        var html = '<table class="widefat">';

        if (data.error) {
            html += '<tr><td colspan="2" style="color: red;"><strong>' +
                (i18n.error || 'Error:') + '</strong> ' + data.error + '</td></tr>';
        }

        html += '<tr><td><strong>' + (i18n.processedEmbeddings || 'Processed Embeddings') + ':</strong></td><td>' + data.total_embeddings + '</td></tr>';
        html += '<tr><td><strong>' + (i18n.missingEmbeddings || 'Missing Embeddings') + ':</strong></td><td>' + data.without_embeddings + '</td></tr>';
        html += '<tr><td><strong>' + (i18n.recentActivity || 'Recent Activity (24h)') + ':</strong></td><td>' + data.recent_embeddings + '</td></tr>';
        html += '</table>';

        // Recent embeddings section
        if (data.recent_items && data.recent_items.length > 0) {
            html += '<h4 style="margin-top: 20px;">' + (i18n.recentEmbeddings || 'Recent Embeddings') + ':</h4>';

            // Search input
            html += '<div style="margin: 10px 0; display: flex; gap: 10px; align-items: center;">';
            html += '<input type="text" id="embedding-search-input" placeholder="' +
                (i18n.searchPlaceholder || 'Search by title or ID...') + '" style="width: 300px;">';
            html += '<button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="embedding-search-btn">' +
                (i18n.search || 'Search') + '</button>';
            html += '<span id="embedding-search-status" style="color: #666;"></span>';
            html += '</div>';
            html += '<div id="embedding-search-results" style="display: none; margin-bottom: 15px;"></div>';

            html += '<table class="widefat" style="margin-top: 10px;">';
            html += '<thead><tr><th>ID</th><th>' + (i18n.title || 'Title') + '</th><th>' +
                (i18n.created || 'Created') + '</th></tr></thead><tbody>';

            data.recent_items.forEach(function(item) {
                var isChunk = item.chunk_parent_id && item.chunk_parent_id !== '';
                var displayTitle = item.title || 'N/A';

                if (isChunk && item.parent_title) {
                    displayTitle = 'Chunk: ' + item.parent_title;
                }

                var rowClass = isChunk ? ' style="background-color: #f9f9f9;"' : '';

                html += '<tr' + rowClass + '>';
                html += '<td><a href="#" class="embedding-link" data-id="' + item.listing_id +
                    '" data-parent="' + (item.chunk_parent_id || '') + '">' + item.listing_id + '</a></td>';
                html += '<td>' + AicwpAdmin.escapeHtml(displayTitle) + '</td>';
                html += '<td>' + AicwpAdmin.escapeHtml(item.created_at) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            html += '<p class="description"><em>' + (i18n.clickIdToView || 'Click on any ID to view its embedding data.') +
                ' "Chunk" ' + (i18n.indicatesChunk || 'indicates that content was split into parts for better accuracy.') + '</em></p>';
        } else {
            html += '<p><em>' + (i18n.noRecentEmbeddings || 'No recent embeddings found.') + '</em></p>';
        }

        // Missing embeddings section
        if (data.missing_items && data.missing_items.length > 0 && data.total_embeddings > 0) {
            html += '<h4 style="margin-top: 30px;">' + (i18n.missingEmbeddings || 'Missing Embeddings') + ':</h4>';
            html += '<div style="margin: 10px 0;">';
            html += '<button type="button" class="button button-secondary" id="select-all-missing">' +
                (i18n.selectAll || 'Select All') + '</button> ';
            html += '<button type="button" class="button button-secondary" id="deselect-all-missing">' +
                (i18n.deselectAll || 'Deselect All') + '</button> ';
            html += '<button type="button" class="button button-primary" id="generate-selected-missing" style="margin-left: 10px;" disabled>' +
                (i18n.generateSelected || 'Generate Selected') + '</button>';
            html += '</div>';
            html += '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">';
            html += '<table class="widefat" style="margin: 0;">';
            html += '<thead><tr><th style="width: 40px;"><input type="checkbox" id="missing-select-all"></th><th>ID</th><th>' +
                (i18n.title || 'Title') + '</th><th>' + (i18n.lastModified || 'Last Modified') + '</th><th>' +
                (i18n.action || 'Action') + '</th></tr></thead><tbody>';

            data.missing_items.forEach(function(item) {
                html += '<tr>';
                html += '<td><input type="checkbox" class="missing-item-checkbox" data-id="' + item.listing_id + '"></td>';
                html += '<td>' + item.listing_id + '</td>';
                html += '<td>' + AicwpAdmin.escapeHtml(item.title || 'N/A') + '</td>';
                html += '<td>' + AicwpAdmin.escapeHtml(item.created_at) + '</td>';
                html += '<td><button type="button" class="button button-primary button-small generate-embedding-btn" data-id="' +
                    item.listing_id + '">' + (i18n.generate || 'Generate') + '</button></td>';
                html += '</tr>';
            });

            html += '</tbody></table>';
            html += '</div>';
            html += '<p class="description"><em>' + (i18n.clickGenerateOrSelect || 'Click "Generate" for individual items or select multiple and use "Generate Selected".') + '</em></p>';
        } else if (data.without_embeddings > 0 && data.total_embeddings === 0) {
            html += '<h4 style="margin-top: 30px;">' + (i18n.missingEmbeddings || 'Missing Embeddings') + ':</h4>';
            html += '<p><em>' + (i18n.allMissing || 'All') + ' ' + data.without_embeddings + ' ' +
                (i18n.listingsAreMissing || 'items are missing embeddings. Use the "Generate Structured Embeddings" tool above to process them in bulk.') + '</em></p>';
        }

        $('#status-content').html(html);

        // Bind event handlers
        bindStatusEventHandlers();
    }

    /**
     * Bind event handlers for status display
     */
    function bindStatusEventHandlers() {
        // Embedding link clicks
        $('.embedding-link').on('click', function(e) {
            e.preventDefault();
            if (typeof AicwpAdmin.checkEmbeddingData === 'function') {
                AicwpAdmin.checkEmbeddingData($(this).data('id'));
            }
        });

        // Generate embedding button clicks
        $('.generate-embedding-btn').on('click', function(e) {
            e.preventDefault();
            if (typeof AicwpAdmin.generateSingleEmbedding === 'function') {
                AicwpAdmin.generateSingleEmbedding($(this).data('id'), $(this));
            }
        });

        // Select all / deselect all
        $('#select-all-missing, #missing-select-all').on('change click', function(e) {
            var isChecked = $(this).prop('checked') || $(this).attr('id') === 'select-all-missing';
            $('.missing-item-checkbox').prop('checked', isChecked);
            if (typeof AicwpAdmin.updateGenerateSelectedButton === 'function') {
                AicwpAdmin.updateGenerateSelectedButton();
            }
        });

        $('#deselect-all-missing').on('click', function(e) {
            $('.missing-item-checkbox').prop('checked', false);
            $('#missing-select-all').prop('checked', false);
            if (typeof AicwpAdmin.updateGenerateSelectedButton === 'function') {
                AicwpAdmin.updateGenerateSelectedButton();
            }
        });

        // Individual checkbox changes
        $('.missing-item-checkbox').on('change', function() {
            if (typeof AicwpAdmin.updateGenerateSelectedButton === 'function') {
                AicwpAdmin.updateGenerateSelectedButton();
            }
            var totalCheckboxes = $('.missing-item-checkbox').length;
            var checkedCheckboxes = $('.missing-item-checkbox:checked').length;
            $('#missing-select-all').prop('checked', checkedCheckboxes === totalCheckboxes);
        });

        // Generate selected button
        $('#generate-selected-missing').on('click', function(e) {
            e.preventDefault();
            var selectedIds = [];
            $('.missing-item-checkbox:checked').each(function() {
                selectedIds.push($(this).data('id'));
            });

            if (selectedIds.length > 0 && typeof AicwpAdmin.generateBulkEmbeddings === 'function') {
                AicwpAdmin.generateBulkEmbeddings(selectedIds);
            }
        });

        // Embedding search
        $('#embedding-search-btn').on('click', function(e) {
            e.preventDefault();
            performEmbeddingSearch();
        });

        $('#embedding-search-input').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                performEmbeddingSearch();
            }
        });
    }

    /**
     * Perform embedding search
     */
    function performEmbeddingSearch() {
        var searchTerm = $('#embedding-search-input').val().trim();
        if (!searchTerm) {
            $('#embedding-search-status').text(i18n.enterSearchTerm || 'Please enter a search term.');
            return;
        }

        $('#embedding-search-status').text(i18n.searching || 'Searching...');
        $('#embedding-search-results').hide();

        AicwpAdmin.ajax({
            action: 'aicwp_search_embeddings',
            data: { search_term: searchTerm },
            success: function(response) {
                if (response.success) {
                    displayEmbeddingSearchResults(response.data);
                } else {
                    $('#embedding-search-status').text(response.data.message ||
                        (i18n.searchFailed || 'Search failed.'));
                }
            },
            error: function() {
                $('#embedding-search-status').text(i18n.searchRequestFailed || 'Search request failed.');
            }
        });
    }

    /**
     * Display embedding search results
     */
    function displayEmbeddingSearchResults(data) {
        var resultsHtml = '';

        if (data.results && data.results.length > 0) {
            $('#embedding-search-status').text(data.count + ' ' + (i18n.resultsFound || 'result(s) found'));

            resultsHtml += '<table class="widefat" style="margin-top: 10px; background: #f9f9f9;">';
            resultsHtml += '<thead><tr><th>ID</th><th>' + (i18n.title || 'Title') + '</th><th>' +
                (i18n.type || 'Type') + '</th><th>' + (i18n.created || 'Created') + '</th></tr></thead><tbody>';

            data.results.forEach(function(item) {
                var isChunk = item.chunk_parent_id && item.chunk_parent_id !== '';
                var displayTitle = item.title || 'N/A';

                if (isChunk && item.parent_title) {
                    displayTitle = '<span style="color: #666;">\ud83d\udcc4</span> ' + AicwpAdmin.escapeHtml(item.parent_title);
                } else {
                    displayTitle = AicwpAdmin.escapeHtml(displayTitle);
                }

                var rowClass = isChunk ? ' style="background-color: #fff;"' : '';

                resultsHtml += '<tr' + rowClass + '>';
                resultsHtml += '<td><a href="#" class="embedding-link" data-id="' + item.listing_id +
                    '" data-parent="' + (item.chunk_parent_id || '') + '">' + item.listing_id + '</a></td>';
                resultsHtml += '<td>' + displayTitle + '</td>';
                resultsHtml += '<td>' + (item.listing_type || 'N/A') + '</td>';
                resultsHtml += '<td>' + AicwpAdmin.escapeHtml(item.created_at) + '</td>';
                resultsHtml += '</tr>';
            });

            resultsHtml += '</tbody></table>';
            resultsHtml += '<button type="button" class="button button-link" id="clear-embedding-search" style="margin-top: 5px;">' +
                (i18n.clearSearchResults || 'Clear search results') + '</button>';
        } else {
            $('#embedding-search-status').text(i18n.noResultsFound || 'No results found.');
        }

        $('#embedding-search-results').html(resultsHtml).show();

        // Re-bind embedding links
        $('#embedding-search-results .embedding-link').on('click', function(e) {
            e.preventDefault();
            if (typeof AicwpAdmin.checkEmbeddingData === 'function') {
                AicwpAdmin.checkEmbeddingData($(this).data('id'));
            }
        });

        // Clear search results
        $('#clear-embedding-search').on('click', function(e) {
            e.preventDefault();
            $('#embedding-search-input').val('');
            $('#embedding-search-results').hide().html('');
            $('#embedding-search-status').text('');
        });
    }

    /**
     * Update listing detection info (placeholder)
     */
    function updateListingDetectionInfo(data) {
        // Placeholder for future implementation
    }

    /**
     * Initialize database action buttons
     */
    function initDatabaseActions() {
        // Refresh status button
        $('#refresh-status').on('click', function() {
            refreshDatabaseStatus();
        });

        // Clear database button
        $('#clear-database').on('click', function() {
            if (confirm(i18n.confirmClearAll || 'Are you sure? This will delete all embeddings and cannot be undone.')) {
                performDatabaseAction('clear_all', i18n.clearingDatabase || 'Clearing database...');
            }
        });

        // Delete by post type
        $('#delete-by-post-type').on('click', function() {
            var postType = $('#delete-post-type-select').val();
            if (!postType) {
                alert(i18n.selectPostType || 'Please select a post type');
                return;
            }

            var postTypeLabel = $('#delete-post-type-select option:selected').text();
            if (confirm((i18n.confirmDeletePostType || 'Are you sure you want to delete all embeddings for') + ' ' +
                postTypeLabel + '? ' + (i18n.cannotBeUndone || 'This cannot be undone.'))) {
                performDatabaseActionWithPostType('delete_by_post_type', postType,
                    (i18n.deletingEmbeddingsFor || 'Deleting embeddings for') + ' ' + postTypeLabel + '...');
            }
        });

        // Clear analytics
        $('#clear-analytics').on('click', function() {
            performAnalyticsAction('clear_analytics', i18n.clearingAnalytics || 'Clearing analytics data...');
        });

    }

    /**
     * Perform database action
     */
    function performDatabaseAction(actionType, loadingMessage) {
        $('#result-message-content').text(loadingMessage).removeClass('aicwp-ui-alert-error').addClass('aicwp-ui-alert-success');
        $('#action-result').show();

        AicwpAdmin.ajax({
            action: 'aicwp_manage_database',
            data: { database_action: actionType },
            success: function(response) {
                if (response.success) {
                    $('#result-message-content').text(i18n.actionCompleted || 'Action completed successfully!');
                    setTimeout(refreshDatabaseStatus, 2000);
                } else {
                    $('#result-message-content').text((i18n.error || 'Error:') + ' ' + (response.data || 'Unknown error'))
                        .removeClass('aicwp-ui-alert-success').addClass('aicwp-ui-alert-error');
                }
            },
            error: function() {
                $('#result-message-content').text(i18n.ajaxErrorOccurred || 'AJAX error occurred')
                    .removeClass('aicwp-ui-alert-success').addClass('aicwp-ui-alert-error');
            }
        });
    }

    /**
     * Perform database action with post type
     */
    function performDatabaseActionWithPostType(actionType, postType, loadingMessage) {
        $('#result-message-content').text(loadingMessage).removeClass('aicwp-ui-alert-error').addClass('aicwp-ui-alert-success');
        $('#action-result').show();

        AicwpAdmin.ajax({
            action: 'aicwp_manage_database',
            data: {
                database_action: actionType,
                post_type: postType
            },
            success: function(response) {
                if (response.success) {
                    var deletedCount = response.data.deleted_count || 0;
                    $('#result-message-content').text((i18n.successfullyDeleted || 'Successfully deleted') + ' ' +
                        deletedCount + ' ' + (i18n.embeddingsForPostType || 'embedding(s) for post type:') + ' ' + postType);
                    $('#delete-post-type-select').val('');
                    setTimeout(refreshDatabaseStatus, 2000);
                } else {
                    $('#result-message-content').text((i18n.error || 'Error:') + ' ' + (response.data || 'Unknown error'))
                        .removeClass('aicwp-ui-alert-success').addClass('aicwp-ui-alert-error');
                }
            },
            error: function() {
                $('#result-message-content').text(i18n.ajaxErrorOccurred || 'AJAX error occurred')
                    .removeClass('aicwp-ui-alert-success').addClass('aicwp-ui-alert-error');
            }
        });
    }

    /**
     * Perform analytics action
     */
    function performAnalyticsAction(actionType, loadingMessage) {
        $('#result-message-content').text(loadingMessage).removeClass('aicwp-ui-alert-error').addClass('aicwp-ui-alert-success');
        $('#action-result').show();

        AicwpAdmin.ajax({
            action: 'aicwp_analytics',
            data: { analytics_action: actionType },
            success: function(response) {
                if (response.success) {
                    $('#result-message-content').text(i18n.analyticsCleared || 'Analytics data cleared successfully!');
                    setTimeout(function() { window.location.reload(); }, 2000);
                } else {
                    $('#result-message-content').text((i18n.error || 'Error:') + ' ' + (response.data || 'Unknown error'))
                        .removeClass('aicwp-ui-alert-success').addClass('aicwp-ui-alert-error');
                }
            },
            error: function() {
                $('#result-message-content').text(i18n.ajaxErrorOccurred || 'AJAX error occurred')
                    .removeClass('aicwp-ui-alert-success').addClass('aicwp-ui-alert-error');
            }
        });
    }

    // Make refreshDatabaseStatus available globally
    AicwpAdmin.refreshDatabaseStatus = refreshDatabaseStatus;

    /**
     * Initialize all database handlers
     */
    function init() {
        if (typeof window.aicwp_ajax === 'undefined') {
            console.error('AicwpAdmin Database: AJAX variables not loaded');
            $('#status-content').html('<p style="color: red;">' +
                (i18n.ajaxConfigError || 'Error: AJAX configuration not loaded. Please refresh the page.') + '</p>');
            return;
        }

        initDatabaseActions();

        // Load initial status
        refreshDatabaseStatus();

    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
