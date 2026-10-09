/**
 * Universal Settings Admin JavaScript
 * Handles post type toggles, reindexing, and AJAX interactions
 *
 * @package AICWP_Plugin
 * @since 1.6.0
 */

(function($) {
    'use strict';

    const UniversalSettings = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            setTimeout(() => {
                this.updateGenerationCount();
                this.loadAllStats();
            }, 300);
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Post type toggle switches
            $('.post-type-toggle').on('change', this.handlePostTypeToggle.bind(this));

            // Add custom post types button
            $('#add-custom-post-types-btn').on('click', this.handleAddCustomPostTypes.bind(this));

            // Manual selection links
            $(document).on('click', '.manual-selection-link', this.handleManualSelection.bind(this));
            $(document).on('click', '.clear-selection-link', this.handleClearSelection.bind(this));

            // Modal controls
            $('.aicwp-modal-close, #modal-cancel, .aicwp-modal-overlay').on('click', this.closeModal.bind(this));
            $('#select-all-posts').on('click', this.selectAllPosts.bind(this));
            $('#deselect-all-posts').on('click', this.deselectAllPosts.bind(this));
            $('#select-pending-posts').on('click', this.selectPendingPosts.bind(this));
            $('#modal-search').on('keyup', this.filterPosts.bind(this));

            // Individual checkbox change — sync to Set and update count
            $(document).on('change', '#modal-posts-list input[type="checkbox"]', (e) => {
                const id = parseInt($(e.target).val());
                if ($(e.target).is(':checked')) {
                    this._modalSelectedIds.add(id);
                } else {
                    this._modalSelectedIds.delete(id);
                }
                this.updateSelectionCount();
            });

            // Load More button
            $(document).on('click', '.load-more-btn', () => {
                this.syncCheckboxesToSet();
                this.loadModalPage(this._modalCurrentPage + 1, false);
            });

            $('#modal-save').on('click', this.saveSelection.bind(this));
            $('#modal-train-now').on('click', this.trainNow.bind(this));

            // Bulk actions
            $('#reindex-all-enabled').on('click', this.handleBulkReindex.bind(this));
            $('#clear-all-embeddings').on('click', this.handleClearEmbeddings.bind(this));

            // Save custom meta fields
            $('#save-custom-meta').on('click', this.handleSaveCustomMeta.bind(this));

            // Collapsible headers
            $('.collapsible-header').on('click', this.handleCollapsibleToggle.bind(this));

            // Delete custom post type
            $(document).on('click', '.delete-custom-type', this.handleDeleteCustomType.bind(this));
        },

        /**
         * Handle collapsible section toggle
         */
        handleCollapsibleToggle: function(e) {
            const $header = $(e.currentTarget);
            const targetId = $header.data('toggle');
            const $content = $('#' + targetId);

            // Toggle header active class
            $header.toggleClass('active');

            // Toggle content visibility with slide animation
            $content.slideToggle(300);

            // Lazy-load detected custom type counts when section is expanded
            if (targetId === 'custom-types-content' && !$content.data('stats-loaded')) {
                $content.data('stats-loaded', true);
                this.loadCustomTypeCounts();
            }
        },

        /**
         * Load counts for detected custom post types via AJAX
         */
        loadCustomTypeCounts: function() {
            const self = this;
            $('[data-custom-type]').each(function() {
                const $el = $(this);
                const postType = $el.data('custom-type');
                $.ajax({
                    url: aicwpUniversalSettings.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aicwp_get_custom_type_count',
                        nonce: aicwpUniversalSettings.nonce,
                        post_type: postType
                    },
                    success: function(response) {
                        if (response.success) {
                            const count = response.data.count;
                            $el.text(self.formatNumber(count));
                            $el.removeClass('loading');
                            $el.addClass(count > 0 ? 'has-content' : 'empty');
                        }
                    }
                });
            });
        },

        /**
         * Handle delete custom post type
         */
        handleDeleteCustomType: function(e) {
            e.preventDefault();
            e.stopPropagation();

            const $button = $(e.currentTarget);
            const $card = $button.closest('.post-type-card');
            const postType = $button.data('post-type');
            const postTypeLabel = $card.find('.post-type-info h3').first().text().trim();

            if (!confirm(`Remove "${postTypeLabel}" from training? This will disable it and remove all its embeddings.`)) {
                return;
            }

            // Disable button during processing
            $button.prop('disabled', true);
            $button.css('opacity', '0.5');

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_remove_custom_post_type',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                        // Fade out and remove card
                        $card.fadeOut(300, function() {
                            $(this).remove();
                            // Update generation count
                            UniversalSettings.updateGenerationCount();
                        });
                    } else {
                        this.showNotice('error', response.data || 'Error removing post type');
                        $button.prop('disabled', false);
                        $button.css('opacity', '1');
                    }
                },
                error: () => {
                    this.showNotice('error', 'Error removing post type');
                    $button.prop('disabled', false);
                    $button.css('opacity', '1');
                }
            });
        },

        /**
         * Handle add custom post types button click
         */
        handleAddCustomPostTypes: function(e) {
            const $button = $(e.currentTarget);

            // Check if button is already disabled (locked in FREE version)
            if ($button.prop('disabled')) {
                return;
            }

            const selectedTypes = [];

            // Collect selected checkboxes
            $('.custom-post-type-checkbox:checked').each(function() {
                selectedTypes.push($(this).val());
            });

            if (selectedTypes.length === 0) {
                alert('Please select at least one post type to add.');
                return;
            }

            // Disable button during processing
            $button.prop('disabled', true);
            const originalText = $button.text();
            $button.text('Adding...');

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_add_custom_post_types',
                    nonce: aicwpUniversalSettings.nonce,
                    post_types: selectedTypes
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                        // Reload page to show new cards
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        this.showNotice('error', response.data || 'Error adding post types');
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: () => {
                    this.showNotice('error', 'Error adding post types');
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        },

        /**
         * Handle post type toggle
         */
        handlePostTypeToggle: function(e) {
            const $toggle = $(e.currentTarget);
            const $card = $toggle.closest('.post-type-card');
            const $toggleSwitch = $toggle.closest('.toggle-switch');
            const postType = $card.data('post-type');
            const enabled = $toggle.is(':checked');

            // Disable toggle and show loading indicator
            $toggle.prop('disabled', true);
            $toggleSwitch.addClass('is-loading');

            // Add spinner if not already present
            if (!$toggleSwitch.find('.toggle-spinner').length) {
                $toggleSwitch.append('<span class="toggle-spinner"></span>');
            }

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_toggle_post_type',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType,
                    enabled: enabled
                },
                success: (response) => {
                    if (response.success) {
                        // Update card state
                        if (enabled) {
                            $card.addClass('enabled');
                        } else {
                            $card.removeClass('enabled');
                        }

                        // Update external pages link active state
                        if (postType === 'ai_external_page') {
                            const $link = $card.find('.external-pages-link');
                            if (enabled) {
                                $link.addClass('active');
                            } else {
                                $link.removeClass('active');
                            }
                        }

                        // Show success message
                        this.showNotice('success', response.data.message);

                        // Refresh badge count for this card
                        this.refreshStats(postType);

                        // Update generation section count
                        this.updateGenerationCount();
                    } else {
                        // Revert toggle on error
                        $toggle.prop('checked', !enabled);

                        // Show error message (check both data and data.message)
                        const errorMsg = response.data?.message || response.data || aicwpUniversalSettings.strings.error;
                        this.showNotice('error', errorMsg);
                    }
                },
                error: () => {
                    // Revert toggle
                    $toggle.prop('checked', !enabled);
                    this.showNotice('error', aicwpUniversalSettings.strings.error);
                },
                complete: () => {
                    // Remove loading state and re-enable toggle
                    $toggle.prop('disabled', false);
                    $toggleSwitch.removeClass('is-loading');
                    $toggleSwitch.find('.toggle-spinner').remove();
                }
            });
        },

        /**
         * Handle reindex button click
         */
        handleReindex: function(e) {
            const $button = $(e.currentTarget);
            const postType = $button.data('post-type');

            if (!confirm(aicwpUniversalSettings.strings.confirm_reindex)) {
                return;
            }

            // Disable button and show loading
            $button.prop('disabled', true);
            const originalText = $button.html();
            $button.html('<span class="aicwp-ui-spinner"></span> ' + aicwpUniversalSettings.strings.reindexing);

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_bulk_reindex_post_type',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                        // Refresh stats after delay
                        setTimeout(() => {
                            this.refreshStats(postType);
                            this.updateGenerationCount();
                        }, 2000);
                    } else {
                        this.showNotice('error', response.data || aicwpUniversalSettings.strings.error);
                    }
                },
                error: () => {
                    this.showNotice('error', aicwpUniversalSettings.strings.error);
                },
                complete: () => {
                    $button.prop('disabled', false);
                    $button.html(originalText);
                }
            });
        },

        /**
         * Handle bulk reindex
         */
        handleBulkReindex: function() {
            if (!confirm('Reindex all enabled post types? This may take a while.')) {
                return;
            }

            const $progress = $('#bulk-progress');
            const $progressBar = $('#bulk-progress-bar');
            const $progressStatus = $('#bulk-progress-status');

            $progress.show();
            $progressBar.val(0);
            $progressStatus.text('Starting bulk reindex...');

            // Get all enabled post types
            const $enabledCards = $('.post-type-card.enabled');
            const totalTypes = $enabledCards.length;
            let completedTypes = 0;

            $enabledCards.each((index, card) => {
                const postType = $(card).data('post-type');

                $.ajax({
                    url: aicwpUniversalSettings.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aicwp_bulk_reindex_post_type',
                        nonce: aicwpUniversalSettings.nonce,
                        post_type: postType
                    },
                    success: (response) => {
                        completedTypes++;
                        const percentage = Math.round((completedTypes / totalTypes) * 100);
                        $progressBar.val(percentage);
                        $progressStatus.text(`Processing: ${completedTypes} / ${totalTypes} types completed`);

                        if (completedTypes === totalTypes) {
                            setTimeout(() => {
                                $progress.hide();
                                this.showNotice('success', 'Bulk reindex completed!');
                                location.reload();
                            }, 2000);
                        }
                    }
                });
            });
        },

        /**
         * Handle clear all embeddings
         */
        handleClearEmbeddings: function() {
            if (!confirm('WARNING: This will delete ALL embeddings. This action cannot be undone. Continue?')) {
                return;
            }

            // TODO: Implement clear embeddings AJAX call
            alert('Clear embeddings functionality - to be implemented');
        },

        /**
         * Handle save custom meta fields
         */
        handleSaveCustomMeta: function() {
            const $button = $('#save-custom-meta');
            const $textarea = $('#custom-meta-fields');
            const metaJson = $textarea.val();

            // Validate JSON
            try {
                const parsed = JSON.parse(metaJson || '{}');

                // Save via options API
                $.ajax({
                    url: aicwpUniversalSettings.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'update_option',
                        option: 'aicwp_custom_meta_fields',
                        value: JSON.stringify(parsed),
                        _ajax_nonce: aicwpUniversalSettings.nonce
                    },
                    success: () => {
                        this.showNotice('success', 'Custom meta fields saved!');
                    },
                    error: () => {
                        this.showNotice('error', 'Failed to save custom meta fields');
                    }
                });
            } catch (e) {
                alert('Invalid JSON format. Please check your syntax.');
                $textarea.focus();
            }
        },

        /**
         * Load stats for all post type cards and detected custom types on page load.
         * Uses a concurrency limit to avoid hammering the database.
         */
        loadAllStats: function() {
            const self = this;
            const maxConcurrent = 2;
            let running = 0;

            // Collect post type cards that need stats
            const queue = [];
            $('.post-type-card[data-post-type]').each(function() {
                queue.push({ type: 'card', postType: $(this).data('post-type') });
            });

            // Detected custom types are in a collapsed section and not activated
            // — skip loading their counts until user expands the section

            function processNext() {
                if (queue.length === 0) return;
                if (running >= maxConcurrent) return;

                const item = queue.shift();
                running++;

                self.refreshStats(item.postType, function() {
                    running--;
                    processNext();
                });
            }

            // Start initial batch
            for (let i = 0; i < maxConcurrent; i++) {
                processNext();
            }
        },

        /**
         * Refresh post type badge count and manual selection links
         */
        refreshStats: function(postType, onComplete) {
            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_get_post_type_stats',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType
                },
                success: (response) => {
                    if (response.success) {
                        const stats = response.data;
                        const $card = $(`.post-type-card[data-post-type="${postType}"]`);

                        // Update badge count
                        const $badge = $card.find('.custom-type-badge');
                        $badge.text(this.formatNumber(stats.total));

                        // Update badge class
                        $badge.removeClass('has-content empty loading');
                        $badge.addClass(stats.total > 0 ? 'has-content' : 'empty');

                        // Update action links - Documents have special handling
                        const $actions = $card.find('.manual-selection-actions');

                        const s = aicwpUniversalSettings.strings;
                        if (postType === 'ai_pdf_document') {
                            $actions.html(`
                                <a href="#" class="pdf-upload-link" id="upload-pdf-btn">
                                    <span class="dashicons dashicons-upload"></span>
                                    ${s.upload_documents}
                                </a>
                            `);
                        } else if (postType === 'ai_external_page') {
                            $actions.html(`
                                <a href="#" class="external-pages-link" id="manage-external-pages-btn">
                                    <span class="dashicons dashicons-admin-site-alt3"></span>
                                    ${s.add_external_pages}
                                </a>
                            `);
                        } else if (stats.has_manual_selection) {
                            $actions.html(`
                                <a href="#" class="manual-selection-link active" data-post-type="${postType}">
                                    <span class="dashicons dashicons-yes"></span>
                                    ${s.manual_selection_active}
                                </a>
                                <a href="#" class="clear-selection-link" data-post-type="${postType}">
                                    ${s.clear}
                                </a>
                            `);
                        } else {
                            $actions.html(`
                                <a href="#" class="manual-selection-link" data-post-type="${postType}">
                                    <span class="dashicons dashicons-admin-generic"></span>
                                    ${s.manual_selection}
                                </a>
                            `);
                        }
                    }
                },
                complete: function() {
                    if (typeof onComplete === 'function') {
                        onComplete();
                    }
                }
            });
        },

        /**
         * Show admin notice
         */
        showNotice: function(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const $notice = $(`
                <div class="notice ${noticeClass} is-dismissible">
                    <p>${message}</p>
                </div>
            `);

            $('.aicwp-universal-settings h1').after($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                $notice.fadeOut(() => $notice.remove());
            }, 5000);
        },

        /**
         * Format number with thousand separators
         */
        formatNumber: function(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        },

        /**
         * Update generation section count
         */
        updateGenerationCount: function() {
            const $countText = $('#listing-count-text');

            // Check if element exists (it's only on database tab)
            if ($countText.length === 0) {
                return;
            }

            // Show loading state
            $countText.text('Loading...');

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_get_total_count',
                    nonce: aicwpUniversalSettings.nonce
                },
                success: (response) => {
                    if (response.success) {
                        const data = response.data;

                        // Debug log
                        console.log('[AI Chat] Count update:', data);

                        // Build count message with list format
                        let html = '';
                        if (data.enabled_count === 0) {
                            html = '<span class="listing-count-empty">No content types enabled. Please enable at least one type above.</span>';
                        } else if (data.total === 0) {
                            html = '<span class="listing-count-empty">No published content found for enabled types.</span>';
                        } else {
                            // Build list of post types with check emojis
                            html = '<div class="listing-type-grid">';

                            if (data.type_breakdown) {
                                data.type_breakdown.forEach((typeData) => {
                                    html += `
                                        <div class="listing-type-item">
                                            <span class="listing-type-check">✓</span>
                                            <span class="listing-type-label">${typeData.label}:</span>
                                            <span class="listing-type-total">${this.formatNumber(typeData.total)}</span>
                                        </div>
                                    `;
                                });
                            } else {
                                // Fallback if no breakdown
                                data.enabled_types.forEach((type) => {
                                    html += `
                                        <div class="listing-type-item">
                                            <span class="listing-type-check">✓</span>
                                            <span class="listing-type-total">${type}</span>
                                        </div>
                                    `;
                                });
                            }

                            html += '</div>';

                            // Total summary line
                            html += `
                                <div class="listing-summary">
                                    Selected: ${this.formatNumber(data.total)} items
                                </div>
                            `;
                        }

                        $countText.html(html);
                    } else {
                        $countText.text(aicwpUniversalSettings.strings.error_loading_count);
                    }
                },
                error: () => {
                    $countText.text(aicwpUniversalSettings.strings.error_loading_count);
                }
            });
        },

        /**
         * Handle manual selection link click
         */
        handleManualSelection: function(e) {
            e.preventDefault();
            const $link = $(e.currentTarget);
            const postType = $link.data('post-type');

            this.openModal(postType);
        },

        /**
         * Handle clear selection link click
         */
        handleClearSelection: function(e) {
            e.preventDefault();
            const $link = $(e.currentTarget);
            const postType = $link.data('post-type');

            if (!confirm('Clear manual selection? All posts of this type will be included in generation.')) {
                return;
            }

            // Save empty selection with clear flag
            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_generate_selected_posts',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType,
                    post_ids: [],
                    clear: 'true'
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                        this.refreshStats(postType);
                        this.updateGenerationCount();
                    } else {
                        this.showNotice('error', response.data || 'Error clearing selection');
                    }
                }
            });
        },

        /**
         * Modal state — persists across pages
         */
        _modalSelectedIds: new Set(),
        _modalTotalPosts: 0,
        _modalCurrentPage: 1,
        _modalTotalPages: 1,
        _modalPostType: '',
        _modalSearch: '',
        _searchDebounce: null,

        /**
         * Open manual selection modal
         */
        openModal: function(postType) {
            const $modal = $('#manual-selection-modal');
            $modal.data('post-type', postType);
            $modal.fadeIn(200);

            // Reset state
            this._modalSelectedIds = new Set();
            this._modalCurrentPage = 1;
            this._modalPostType = postType;
            this._modalSearch = '';
            $('#modal-search').val('');

            this.loadModalPage(1, true);
        },

        /**
         * Load a page of posts into the modal
         */
        loadModalPage: function(page, isFirstLoad) {
            const self = this;
            const $list = $('#modal-posts-list');

            if (isFirstLoad) {
                $list.html('<p class="loading-message"><span class="aicwp-ui-spinner" style="margin-right: 6px;"></span>Loading posts...</p>');
            }

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_get_posts_for_selection',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: this._modalPostType,
                    page: page,
                    per_page: 50,
                    search: this._modalSearch
                },
                success: (response) => {
                    if (response.success) {
                        const data = response.data;
                        this._modalCurrentPage = data.page;
                        this._modalTotalPages = data.total_pages;
                        this._modalTotalPosts = data.total;

                        // On first load, initialize selected IDs from saved selection
                        if (isFirstLoad) {
                            this._modalSelectedIds = new Set((data.selected_ids || []).map(id => parseInt(id)));
                            $('#modal-title').text(`${aicwpUniversalSettings.strings.manual_selection} - ${data.post_type_label}`);
                        }

                        this.renderPostsPage(data.posts, isFirstLoad);
                        this.updateSelectionCount();
                    } else {
                        $list.html(`<p class="error-message">${response.data}</p>`);
                    }
                },
                error: () => {
                    $list.html('<p class="error-message">' + aicwpUniversalSettings.strings.error_loading_posts + '</p>');
                }
            });
        },

        /**
         * Render a page of posts into the modal
         */
        renderPostsPage: function(posts, replace) {
            const $list = $('#modal-posts-list');
            let $container;

            if (replace) {
                $list.html('<div class="posts-checkboxes"></div>');
                $container = $list.find('.posts-checkboxes');
            } else {
                $container = $list.find('.posts-checkboxes');
                // Remove existing load more button
                $list.find('.load-more-container').remove();
            }

            let html = '';
            const s = aicwpUniversalSettings.strings;
            posts.forEach((post) => {
                const postId = parseInt(post.ID);
                const isChecked = this._modalSelectedIds.has(postId);
                const hasEmbedding = parseInt(post.has_embedding) === 1;
                const statusClass = hasEmbedding ? 'has-embedding' : 'no-embedding';

                html += `
                    <label class="post-checkbox-item ${statusClass}" data-has-embedding="${post.has_embedding}">
                        <input type="checkbox" value="${postId}" ${isChecked ? 'checked' : ''}>
                        <span class="post-title">${post.post_title}</span>
                        <span class="post-id">ID: ${postId}</span>
                        <span class="post-status">${hasEmbedding ? '✓ ' + s.indexed : s.pending}</span>
                    </label>
                `;
            });
            $container.append(html);

            // Add "Load More" button if there are more pages
            if (this._modalCurrentPage < this._modalTotalPages) {
                const showing = $container.find('.post-checkbox-item').length;
                $list.append(`
                    <div class="load-more-container" style="text-align: center; padding: 12px;">
                        <button type="button" class="button load-more-btn">
                            Load More (${showing} of ${this._modalTotalPosts})
                        </button>
                    </div>
                `);
            }
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('#manual-selection-modal').fadeOut(200);
            $('#modal-search').val('');
            this._modalSearch = '';
        },

        /**
         * Sync visible checkboxes → Set (call before any Set read after user interaction)
         */
        syncCheckboxesToSet: function() {
            const self = this;
            $('#modal-posts-list input[type="checkbox"]').each(function() {
                const id = parseInt($(this).val());
                if ($(this).is(':checked')) {
                    self._modalSelectedIds.add(id);
                } else {
                    self._modalSelectedIds.delete(id);
                }
            });
        },

        /**
         * Select all visible posts (only what's currently loaded/shown)
         */
        selectAllPosts: function() {
            const self = this;
            $('#modal-posts-list input[type="checkbox"]').each(function() {
                $(this).prop('checked', true);
                self._modalSelectedIds.add(parseInt($(this).val()));
            });
            this.updateSelectionCount();
        },

        /**
         * Deselect all visible posts
         */
        deselectAllPosts: function() {
            const self = this;
            $('#modal-posts-list input[type="checkbox"]').each(function() {
                $(this).prop('checked', false);
                self._modalSelectedIds.delete(parseInt($(this).val()));
            });
            this.updateSelectionCount();
        },

        /**
         * Select pending posts only (server-side)
         */
        selectPendingPosts: function() {
            const self = this;
            this._modalSelectedIds.clear();
            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_get_bulk_post_ids',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: this._modalPostType,
                    filter: 'pending'
                },
                success: (response) => {
                    if (response.success) {
                        response.data.ids.forEach(id => self._modalSelectedIds.add(id));
                        // Update visible checkboxes
                        $('#modal-posts-list .post-checkbox-item').each(function() {
                            const id = parseInt($(this).find('input').val());
                            $(this).find('input').prop('checked', self._modalSelectedIds.has(id));
                        });
                        self.updateSelectionCount();
                    }
                }
            });
        },

        /**
         * Filter posts by search term (server-side, debounced)
         */
        filterPosts: function(e) {
            const searchTerm = $(e.currentTarget).val();
            clearTimeout(this._searchDebounce);

            this._searchDebounce = setTimeout(() => {
                // Sync current checkbox state before reloading
                this.syncCheckboxesToSet();
                this._modalSearch = searchTerm;
                this._modalCurrentPage = 1;
                this.loadModalPage(1, true);
            }, 300);
        },

        /**
         * Update selection count in modal footer
         */
        updateSelectionCount: function() {
            const selected = this._modalSelectedIds.size;
            const total = this._modalTotalPosts;
            const s = aicwpUniversalSettings.strings;
            $('#modal-selection-count').text(`${selected} ${s.selected_of} ${total} ${s.selected}`);
        },

        /**
         * Save selection
         */
        saveSelection: function() {
            this.syncCheckboxesToSet();
            const $modal = $('#manual-selection-modal');
            const postType = $modal.data('post-type');
            const selectedIds = Array.from(this._modalSelectedIds);

            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_generate_selected_posts',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType,
                    post_ids: selectedIds
                },
                success: (response) => {
                    if (response.success) {
                        this.showNotice('success', response.data.message);
                        this.closeModal();
                        this.refreshStats(postType);
                        this.updateGenerationCount();
                    } else {
                        this.showNotice('error', response.data || 'Error saving selection');
                    }
                }
            });
        },

        /**
         * Train Now - Immediately generate embeddings for selected posts
         */
        trainNow: function() {
            this.syncCheckboxesToSet();
            const $modal = $('#manual-selection-modal');
            const $button = $('#modal-train-now');
            const postType = $modal.data('post-type');
            const selectedIds = Array.from(this._modalSelectedIds);

            if (selectedIds.length === 0) {
                this.showNotice('error', 'Please select at least one item to train');
                return;
            }

            // Disable button and show loading state
            $button.prop('disabled', true);
            const originalHtml = $button.html();
            $button.html('<span class="aicwp-ui-spinner"></span> Training...');

            // First, save the selection
            $.ajax({
                url: aicwpUniversalSettings.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_generate_selected_posts',
                    nonce: aicwpUniversalSettings.nonce,
                    post_type: postType,
                    post_ids: selectedIds
                },
                success: (response) => {
                    if (response.success) {
                        // Now generate embeddings for each selected post
                        this.generateEmbeddingsForPosts(selectedIds, $button, originalHtml, postType);
                    } else {
                        this.showNotice('error', response.data || 'Error saving selection');
                        $button.prop('disabled', false);
                        $button.html(originalHtml);
                    }
                },
                error: (xhr, status, error) => {
                    this.showNotice('error', 'Failed to save selection: ' + error);
                    $button.prop('disabled', false);
                    $button.html(originalHtml);
                }
            });
        },

        /**
         * Generate embeddings for multiple posts sequentially
         */
        generateEmbeddingsForPosts: function(postIds, $button, originalHtml, postType) {
            let completed = 0;
            let failed = 0;
            const total = postIds.length;

            const generateNext = (index) => {
                if (index >= total) {
                    // All done
                    const message = failed === 0
                        ? `Successfully trained ${completed} item(s)!`
                        : `Trained ${completed} item(s), ${failed} failed`;

                    this.showNotice(failed === 0 ? 'success' : 'warning', message);
                    this.refreshStats(postType);
                    this.updateGenerationCount();

                    $button.prop('disabled', false);
                    $button.html(originalHtml);

                    // Update selection count since we unchecked items
                    this.updateSelectionCount();
                    return;
                }

                const postId = postIds[index];
                const progress = `${index + 1}/${total}`;
                $button.html(`<span class="aicwp-ui-spinner"></span> Training ${progress}...`);

                $.ajax({
                    url: aicwpUniversalSettings.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'aicwp_manage_database',
                        database_action: 'generate_single',
                        listing_id: postId,
                        nonce: aicwpUniversalSettings.database_nonce
                    },
                    success: (response) => {
                        if (response.success) {
                            completed++;
                            // Update the post status in the modal
                            this.updatePostStatus(postId, 'indexed');
                        } else {
                            failed++;
                        }
                        // Continue to next post
                        generateNext(index + 1);
                    },
                    error: () => {
                        failed++;
                        // Continue to next post even on error
                        generateNext(index + 1);
                    }
                });
            };

            // Start generating from first post
            generateNext(0);
        },

        /**
         * Update post status in the modal list
         */
        updatePostStatus: function(postId, status) {
            const $checkbox = $(`#modal-posts-list input[value="${postId}"]`);
            const $item = $checkbox.closest('.post-checkbox-item');
            const $statusSpan = $item.find('.post-status');

            if (status === 'indexed') {
                // Update status text and styling
                $statusSpan.text('✓ ' + aicwpUniversalSettings.strings.indexed)
                    .removeClass('pending')
                    .addClass('indexed');

                // Update item class
                $item.removeClass('no-embedding')
                    .addClass('has-embedding');

                // Uncheck the checkbox
                $checkbox.prop('checked', false);
            }
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        UniversalSettings.init();
    });

})(jQuery);
