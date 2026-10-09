/**
 * AI Chat for WordPress - Conversation Auditor admin UI
 *
 * Handles: per-row analyze button, Analysis sub-tab, backlog batch, detail modal,
 * weekly report, send email.
 */
(function($) {
    'use strict';

    var D = window.aiChatAuditData || {};
    var I = D.i18n || {};

    var listState = {
        offset: 0,
        filters: { sentiment: '', gaps: '' }
    };
    var backlog = { running: false, offset: 0, processed: 0, total: 0, range: '30d' };
    var currentDetailId = null;

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function ajax(action, data) {
        var payload = $.extend({ action: action, nonce: D.nonce }, data || {});
        return $.post(D.ajaxUrl, payload);
    }

    function relTime(ts) {
        if (!ts) return '';
        var diff = Math.round((Date.now() / 1000) - parseInt(ts, 10));
        if (diff < 0) diff = 0;
        if (diff < 45) return (I.justNow || 'just now');
        if (diff < 3600) return Math.round(diff / 60) + 'm ' + (I.ago || 'ago');
        if (diff < 86400) return Math.round(diff / 3600) + 'h ' + (I.ago || 'ago');
        if (diff < 604800) return Math.round(diff / 86400) + 'd ' + (I.ago || 'ago');
        return Math.round(diff / 604800) + 'w ' + (I.ago || 'ago');
    }

    // ============================================================
    // Initial data load
    // ============================================================
    function initAuditSection() {
        if (!$('#ai-chat-audit-list').length) return;
        loadAuditList(true);
    }

    // ============================================================
    // Settings modal (Configure button)
    // ============================================================
    function initSettingsModal() {
        var $modal = $('#ai-chat-audit-settings-modal');
        if (!$modal.length) return;

        $('#ai-chat-audit-configure-btn').on('click', function() {
            resetBacklogUI();
            $modal.fadeIn(150);
        });

        $('#ai-chat-audit-settings-close').on('click', function() {
            if (backlog.running) return;
            $modal.fadeOut(150);
        });
        $modal.find('.aicwp-ui-modal-overlay').on('click', function() {
            if (backlog.running) return;
            $modal.fadeOut(150);
        });

        $('#ai-chat-audit-clear-all').on('click', function(e) {
            e.preventDefault();
            if (!window.confirm(I.confirmClearAll || 'Delete all analysis data? This cannot be undone.')) return;
            ajax('ai_chat_audit_clear_all').done(function(res) {
                if (res && res.success) {
                    showAuditToast(I.clearedAll || 'All analysis data cleared.');
                    loadAuditList(true);
                    refreshStats();
                }
            });
        });

        $('#ai-chat-audit-settings-form').on('submit', function(e) {
            e.preventDefault();

            var $form = $(this);
            var $btn  = $form.find('button[type="submit"]');
            var orig  = $btn.text();
            $btn.prop('disabled', true).text(I.saving || 'Saving...');

            // Only collect named form fields (backlog controls have IDs but no name,
            // so they are skipped automatically).
            var formData = {};
            $form.find('input[name], select[name]').each(function() {
                var name = this.name;
                if (!name) return;
                if (this.type === 'checkbox') {
                    formData[name] = this.checked ? 1 : 0;
                } else {
                    formData[name] = $(this).val();
                }
            });

            formData.action  = 'aicwp_save_settings';
            formData.nonce   = (window.aicwp_ajax && window.aicwp_ajax.nonce) || '';
            formData.section = 'audit';

            var saveUrl = (window.aicwp_ajax && window.aicwp_ajax.ajax_url) || D.ajaxUrl;

            $.post(saveUrl, formData).done(function(res) {
                if (res && res.success) {
                    showAuditToast(I.settingsSaved || 'Settings saved.', 'success');
                    // Update auto-status chip on the card header immediately.
                    var isOn = !!formData.aicwp_audit_enabled;
                    $('#ai-chat-audit-auto-status-value')
                        .text(isOn ? (I.enabled || 'enabled') : (I.disabled || 'disabled'))
                        .removeClass('is-enabled is-disabled')
                        .addClass(isOn ? 'is-enabled' : 'is-disabled');
                    // Only close if backlog isn't running.
                    if (!backlog.running) {
                        $modal.fadeOut(150);
                    }
                    refreshStats();
                } else {
                    showAuditToast((res && res.data && res.data.message) || 'Save failed.', 'error');
                }
            }).fail(function() {
                showAuditToast('Connection error.', 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(orig);
            });
        });
    }

    function refreshStats() {
        ajax('ai_chat_audit_get_stats').done(function(res) {
            if (!res || !res.success) return;
            updateStatStrip(res.data);
        });
    }

    function showAuditToast(msg, type) {
        var $t = $('<div class="aicwp-ui-audit-toast"></div>').text(msg);
        if (type === 'error') $t.addClass('is-error');
        $('body').append($t);
        setTimeout(function() { $t.addClass('is-visible'); }, 10);
        setTimeout(function() {
            $t.removeClass('is-visible');
            setTimeout(function() { $t.remove(); }, 300);
        }, 2500);
    }

    // ============================================================
    // Per-row analyze button (rendered inside each conversation card)
    // ============================================================
    function setRowButtonState($btn, state) {
        var labels = {
            'not_analyzed': I.analyzeWithAI || 'Analyze with AI',
            'analyzing':    I.analyzing || 'Analyzing...',
            'analyzed':     I.viewAnalysis || 'View analysis'
        };

        $btn.data('state', state)
            .attr('data-state', state)
            .attr('title', labels[state])
            .removeClass('aicwp-ui-audit-btn-not_analyzed aicwp-ui-audit-btn-analyzing aicwp-ui-audit-btn-analyzed')
            .addClass('aicwp-ui-audit-btn-' + state);

        $btn.find('.ai-chat-audit-analyze-label').text(labels[state]);

        // Sparkle fill: filled when analyzed, outlined otherwise. During
        // "analyzing" the sparkle is hidden by CSS and the loader shows instead.
        $btn.find('.aicwp-ui-audit-sparkle').attr('fill', state === 'analyzed' ? 'currentColor' : 'none');
    }

    function initRowButton() {
        $(document).on('click', '.ai-chat-audit-analyze-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn = $(this);
            var state = $btn.data('state');
            var convId = $btn.data('id');

            if (state === 'analyzing') return;

            if (state === 'analyzed') {
                // View-only: fetch the stored analysis by conversation_id. Never
                // hits the analyze endpoint, so no tokens are spent even if the
                // customer added new messages since the last analysis.
                openDetailModal(null, convId);
                return;
            }

            setRowButtonState($btn, 'analyzing');

            ajax('ai_chat_audit_analyze_single', { conversation_id: convId }).done(function(res) {
                if (res && res.success) {
                    setRowButtonState($btn, 'analyzed');
                } else {
                    setRowButtonState($btn, 'not_analyzed');
                    if (res && res.data && res.data.message) {
                        alert(res.data.message);
                    }
                }
            }).fail(function() {
                setRowButtonState($btn, 'not_analyzed');
            });
        });
    }

    // ============================================================
    // Analysis list
    // ============================================================
    function initFilters() {
        $('#ai-chat-audit-filters').on('click', '.aicwp-ui-position-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $btn    = $(this);
            var filter  = $btn.data('filter');
            var value   = $btn.data('value');
            var $toggle = $btn.closest('.aicwp-ui-position-toggle');

            if (value === '') {
                // "All" - reset all buttons in this toggle group.
                $toggle.find('.aicwp-ui-position-btn').removeClass('active');
                $btn.addClass('active');
                // Clear all filters that belong to buttons in this toggle.
                $toggle.find('.aicwp-ui-position-btn').each(function() {
                    var f = $(this).data('filter');
                    if (f) listState.filters[f] = '';
                });
            } else if ($btn.hasClass('active')) {
                // Already active - deactivate and check if toggle needs "All" back.
                $btn.removeClass('active');
                listState.filters[filter] = '';
                var hasActive = $toggle.find('.aicwp-ui-position-btn.active').length;
                if (!hasActive) {
                    $toggle.find('.aicwp-ui-position-btn[data-value=""]').addClass('active');
                }
            } else {
                // Activate - deactivate "All" and others in same filter group.
                $toggle.find('.aicwp-ui-position-btn[data-value=""]').removeClass('active');
                $toggle.find('.aicwp-ui-position-btn[data-filter="' + filter + '"]').removeClass('active');
                $btn.addClass('active');
                listState.filters[filter] = value;
            }
            loadAuditList(true);
        });
        $('#ai-chat-audit-load-more').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).html('<span class="aicwp-ui-spinner" style="margin-right: 6px; zoom: 0.8;"></span>' + (I.loading || 'Loading...'));
            loadAuditList(false);
        });
    }

    function loadAuditList(reset) {
        if (reset) listState.offset = 0;

        var $list = $('#ai-chat-audit-list');
        if (reset && !$list.find('.aicwp-ui-audit-loading').length) {
            $list.css({ opacity: '0.5', position: 'relative' });
            if (!$list.find('.aicwp-ui-audit-overlay-spinner').length) {
                $list.append('<div class="aicwp-ui-audit-overlay-spinner"><span class="aicwp-ui-spinner"></span></div>');
            }
        }

        var payload = $.extend({ offset: listState.offset }, listState.filters);

        ajax('ai_chat_audit_get_list', payload).done(function(res) {
            $list.css({ opacity: '', position: '' }).find('.aicwp-ui-audit-overlay-spinner').remove();
            if (!res || !res.success) {
                $list.html('<div class="aicwp-ui-audit-empty">' + ((res && res.data && res.data.message) || 'Error') + '</div>');
                return;
            }
            var data = res.data;
            var rows = data.rows || [];

            if (reset && rows.length === 0) {
                $list.html(renderEmptyState());
                $('#ai-chat-audit-load-more-wrap').hide();
                updateStatStrip(data.stats);
                return;
            }

            var html = reset ? '' : $list.html();
            if (reset) html = '';
            for (var i = 0; i < rows.length; i++) {
                html += renderRow(rows[i]);
            }
            $list.html(html);

            listState.offset += rows.length;
            var $loadMoreBtn = $('#ai-chat-audit-load-more');
            if (data.has_more) {
                var template = I.loadMoreRemaining || 'Load more (%d remaining)';
                $loadMoreBtn.prop('disabled', false).text(template.replace('%d', data.remaining || 0));
                $('#ai-chat-audit-load-more-wrap').show();
            } else {
                $loadMoreBtn.prop('disabled', false);
                $('#ai-chat-audit-load-more-wrap').hide();
            }

            updateStatStrip(data.stats);
        }).fail(function() {
            $list.css({ opacity: '', position: '' }).find('.aicwp-ui-audit-overlay-spinner').remove();
            $list.html('<div class="aicwp-ui-audit-empty">Connection error.</div>');
        });
    }

    function renderRow(r) {
        var sentClass = 'aicwp-ui-audit-sent-' + (r.sentiment || 'neutral');
        var gapBadge = r.gap_count > 0
            ? '<span class="aicwp-ui-audit-badge aicwp-ui-audit-badge-gap">' + r.gap_count + ' ' + (r.gap_count === 1 ? (I.gap || 'data gap') : (I.gaps || 'data gaps')) + '</span>'
            : '';

        return '<div class="aicwp-ui-audit-row" data-id="' + r.id + '" data-cid="' + escapeHtml(r.conversation_id) + '">' +
                '<div class="aicwp-ui-audit-row-main">' +
                    '<div class="aicwp-ui-audit-row-title">' + escapeHtml(r.title) + '</div>' +
                    '<div class="aicwp-ui-audit-row-meta">' +
                        '<span class="aicwp-ui-audit-badge ' + sentClass + '">' + sentimentLabel(r.sentiment) + '</span>' +
                        gapBadge +
                        '<span class="aicwp-ui-audit-row-msgs">' + r.message_count + ' ' + (I.messages || 'messages') + '</span>' +
                    '</div>' +
                '</div>' +
                '<div class="aicwp-ui-audit-row-time">' + relTime(r.analyzed_at_ts) + '</div>' +
            '</div>';
    }

    function sentimentLabel(s) {
        if (s === 'negative') return I.negative || 'Negative';
        return I.positive || 'Positive';
    }

    function renderEmptyState() {
        return '<div class="aicwp-ui-audit-empty-state">' +
                (I.noAnalysesYet || 'No conversations.') +
            '</div>';
    }

    function updateStatStrip(stats) {
        if (!stats) return;
        $('#stat-analyzed').text(numberFormat(stats.analyzed));
        $('#stat-gaps').text(numberFormat(stats.data_gaps));
        $('#stat-positive').text(stats.positive_pct + '%');
    }

    function numberFormat(n) {
        return (n || 0).toString();
    }

    // ============================================================
    // Backlog batch processing (UI lives inside the Configure modal body)
    // ============================================================
    function initBacklog() {
        $('#ai-chat-audit-backlog-start').on('click', startBacklog);
        $('#ai-chat-audit-backlog-stop').on('click', stopBacklog);
    }

    function resetBacklogUI() {
        $('#ai-chat-audit-backlog-progress').hide();
        $('.aicwp-ui-audit-progress-fill').css('width', '0%');
        $('.aicwp-ui-audit-progress-text').text('');
        $('#ai-chat-audit-backlog-start').show();
        $('#ai-chat-audit-backlog-stop').hide();
        $('#ai-chat-audit-backlog-range').removeClass('is-locked');
    }

    function startBacklog() {
        if (backlog.running) return;
        backlog.running = true;
        backlog.offset = 0;
        backlog.processed = 0;
        backlog.total = 0;
        backlog.range = $('#ai-chat-audit-backlog-range').val() || '14d';

        $('#ai-chat-audit-backlog-start').hide();
        $('#ai-chat-audit-backlog-stop').show();
        $('#ai-chat-audit-backlog-range').addClass('is-locked');
        $('#ai-chat-audit-backlog-progress').show();
        $('.aicwp-ui-audit-progress-fill').css('width', '0%');
        $('.aicwp-ui-audit-progress-text').html('<span class="aicwp-ui-spinner" style="margin-right: 6px; zoom: 0.8;"></span>' + (I.processing || 'Processing...'));

        runBacklogBatch();
    }

    function stopBacklog() {
        backlog.running = false;
        $('#ai-chat-audit-backlog-start').show();
        $('#ai-chat-audit-backlog-stop').hide();
        $('#ai-chat-audit-backlog-range').removeClass('is-locked');
    }

    function runBacklogBatch() {
        if (!backlog.running) return;

        ajax('ai_chat_audit_backlog_batch', {
            range: backlog.range,
            offset: backlog.offset
        }).done(function(res) {
            if (!res || !res.success) {
                $('.aicwp-ui-audit-progress-text').text(I.batchError || 'Error');
                stopBacklog();
                return;
            }
            var d = res.data;
            backlog.processed += (d.processed || 0);
            // d.total = all unanalyzed BEFORE this batch ran (counted once on first response).
            if (d.total && backlog.total === 0) backlog.total = d.total;

            var pct = backlog.total > 0 ? Math.min(100, Math.round((backlog.processed / backlog.total) * 100)) : 0;
            $('.aicwp-ui-audit-progress-fill').css('width', pct + '%');
            var progressLabel = (I.batchProgress || 'Processed %1$d of %2$d')
                .replace('%1$d', backlog.processed)
                .replace('%2$d', backlog.total);
            $('.aicwp-ui-audit-progress-text').html('<span class="aicwp-ui-spinner" style="margin-right: 6px; zoom: 0.8;"></span>' + progressLabel);

            if (d.has_more && d.processed > 0 && backlog.running) {
                setTimeout(runBacklogBatch, 500);
            } else {
                $('.aicwp-ui-audit-progress-fill').css('width', '100%');
                $('.aicwp-ui-audit-progress-text').text(I.batchComplete || 'Done');
                stopBacklog();
                loadAuditList(true);
            }
        }).fail(function() {
            $('.aicwp-ui-audit-progress-text').text(I.batchError || 'Error');
            stopBacklog();
        });
    }

    // ============================================================
    // Detail modal
    // ============================================================
    function initDetailModal() {
        $(document).on('click', '.aicwp-ui-audit-row', function() {
            var id = $(this).data('id');
            var cid = $(this).data('cid');
            openDetailModal(id, cid);
        });

        $('#ai-chat-audit-modal-close, #ai-chat-audit-detail-modal .aicwp-ui-modal-overlay').on('click', closeDetailModal);

        $('#ai-chat-audit-modal-delete').on('click', function() {
            if (!currentDetailId) return;
            if (!window.confirm(I.confirmDelete || 'Delete?')) return;
            ajax('ai_chat_audit_delete', { id: currentDetailId }).done(function(res) {
                if (res && res.success) {
                    var cid = $('#ai-chat-audit-detail-modal').data('cid');
                    $('.aicwp-ui-audit-row[data-id="' + currentDetailId + '"]').remove();
                    var $btn = $('.ai-chat-audit-analyze-btn[data-id="' + cid + '"]');
                    if ($btn.length) setRowButtonState($btn, 'not_analyzed');
                    closeDetailModal();
                    loadAuditList(true);
                }
            });
        });

        $('#ai-chat-audit-modal-view-conv').on('click', function() {
            var cid = $('#ai-chat-audit-detail-modal').data('cid');
            if (!cid) return;
            closeDetailModal();
            navigateToConversationCard(cid);
        });
    }

    function openDetailModal(id, cid) {
        currentDetailId = id || null;
        var $modal = $('#ai-chat-audit-detail-modal');
        $modal.data('cid', cid).show();
        // Restore footer for regular detail view
        $modal.find('.aicwp-ui-modal-footer').show();
        $('#aicwp-ui-items-load-more-wrap').hide();
        $('#ai-chat-audit-modal-body').html('<div class="aicwp-ui-audit-loading"><span class="aicwp-ui-spinner"></span></div>');

        var payload = id ? { id: id } : { conversation_id: cid };
        ajax('ai_chat_audit_get_detail', payload).done(function(res) {
            if (!res || !res.success) {
                $('#ai-chat-audit-modal-body').html('<p>' + ((res && res.data && res.data.message) || 'Error') + '</p>');
                return;
            }
            if (res.data && res.data.id) currentDetailId = res.data.id;
            renderDetailModal(res.data);
        });
    }

    function renderDetailModal(d) {
        $('#ai-chat-audit-modal-title').text(d.title || '');

        // Trivial conversations flagged by AI as "skip" - show a compact notice.
        if (d.suggested_action === 'skip') {
            var skipHtml = '<div class="aicwp-ui-audit-modal-meta">' +
                    '<span>' + formatDate(d.analyzed_at) + '</span>' +
                '</div>' +
                '<p class="aicwp-ui-audit-summary" style="color:#666;">' +
                    (I.skipNotice || 'This conversation had no meaningful content to audit.') +
                '</p>';
            $('#ai-chat-audit-modal-body').html(skipHtml);
            return;
        }

        var sentLabel = sentimentLabel(d.sentiment);
        var meta = '<div class="aicwp-ui-audit-modal-meta">' +
                '<span>' + formatDate(d.analyzed_at) + '</span>' +
                '<span class="aicwp-ui-audit-badge aicwp-ui-audit-sent-' + d.sentiment + '">' + sentLabel + '</span>' +
            '</div>';

        var html = meta;
        html += '<h4 class="aicwp-ui-audit-section-title">' + (I.summary || 'Summary') + '</h4>';
        html += '<p class="aicwp-ui-audit-summary">' + escapeHtml(d.summary || '') + '</p>';

        if (d.data_gaps && d.data_gaps.length) {
            html += '<h4 class="aicwp-ui-audit-section-title">' + (I.dataGaps || 'Data Gaps') + ' (' + d.data_gaps.length + ')</h4>';
            html += '<ul class="aicwp-ui-audit-gap-list">';
            for (var j = 0; j < d.data_gaps.length; j++) {
                html += '<li class="aicwp-ui-audit-gap-item"><span class="aicwp-ui-audit-warn">&#9888;</span> ' + escapeHtml(d.data_gaps[j].question || '') + '</li>';
            }
            html += '</ul>';
        }

        $('#ai-chat-audit-modal-body').html(html);
    }

    function formatDate(s) {
        if (!s) return '';
        var d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return escapeHtml(s);
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function closeDetailModal() {
        $('#ai-chat-audit-detail-modal').hide();
        currentDetailId = null;
    }

    // ============================================================
    // Stat-box aggregate modal (data gaps)
    // ============================================================
    var itemsOffset = 0;
    var itemsType   = '';
    var itemsHasMore = false;

    $(document).on('click', '.aicwp-ui-stat-box[data-stat-type]', function(e) {
        if ($(e.target).hasClass('aicwp-ui-audit-stat-info')) return; // don't trigger on ? tooltip
        itemsType = $(this).data('stat-type');
        itemsOffset = 0;
        openItemsModal();
    });

    function openItemsModal() {
        var $modal = $('#ai-chat-audit-detail-modal');
        $modal.data('cid', '').show();
        // Hide footer (View conversation / Delete) for aggregate view
        $modal.find('.aicwp-ui-modal-footer').hide();
        var description = $('.aicwp-ui-stat-box[data-stat-type="gaps"]').find('.aicwp-ui-audit-stat-info').data('tooltip') || '';
        if (itemsOffset === 0) {
            $('#ai-chat-audit-modal-body').html('<div class="aicwp-ui-audit-loading"><span class="aicwp-ui-spinner"></span></div>');
        }
        $('#ai-chat-audit-modal-title').text(I.dataGaps || 'Data Gaps');

        ajax('ai_chat_audit_get_items', { type: itemsType, offset: itemsOffset }).done(function(res) {
            if (!res || !res.success) {
                if (itemsOffset === 0) $('#ai-chat-audit-modal-body').html('<p>' + ((res && res.data && res.data.message) || 'Error') + '</p>');
                return;
            }
            var d = res.data;
            itemsHasMore = d.has_more;
            var html = '';

            // Show description paragraph on first load
            if (itemsOffset === 0) {
                html += '<p style="color:#666;font-size:13px;margin:0 0 12px;">' + escapeHtml(description) + '</p>';
            }

            if (d.items.length) {
                html += '<ul class="aicwp-ui-audit-gap-list">';
                for (var j = 0; j < d.items.length; j++) {
                    var item = d.items[j];
                    html += '<li class="aicwp-ui-audit-gap-item aicwp-ui-audit-clickable-item" data-analysis-id="' + (item.analysis_id || '') + '" data-conversation-id="' + escapeHtml(item.conversation_id || '') + '"><span class="aicwp-ui-audit-warn">&#9888;</span> ' + escapeHtml(item.question || '') + '</li>';
                }
                html += '</ul>';
            } else if (itemsOffset === 0) {
                html = '<p style="color:#666;text-align:center;padding:20px;">' + (I.noResults || 'No items found.') + '</p>';
            }

            if (itemsOffset === 0) {
                $('#ai-chat-audit-modal-body').html(html);
                // Re-append load-more button inside body (wiped by .html above)
                $('#ai-chat-audit-modal-body').append('<div class="aicwp-ui-pagination" id="aicwp-ui-items-load-more-wrap" style="display:none;"><button type="button" class="aicwp-ui-pagination-btn" id="aicwp-ui-items-load-more">' + (I.loadMore || 'Load more') + '</button></div>');
            } else {
                $('#ai-chat-audit-modal-body').append(html);
            }

            if (d.has_more) {
                $('#aicwp-ui-items-load-more').prop('disabled', false).text(I.loadMore || 'Load more');
                $('#aicwp-ui-items-load-more-wrap').show();
            } else {
                $('#aicwp-ui-items-load-more').prop('disabled', false);
                $('#aicwp-ui-items-load-more-wrap').hide();
            }
            itemsOffset = d.offset;
        });
    }

    $(document).on('click', '#aicwp-ui-items-load-more', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="aicwp-ui-spinner" style="margin-right: 6px; zoom: 0.8;"></span>' + (I.loading || 'Loading...'));
        openItemsModal();
    });

    // Scroll to a conversation card AND auto-expand its <details> messages block.
    function scrollToConvCard($card) {
        $card.find('details.chat-history-details').attr('open', 'open');
        $('html, body').animate({ scrollTop: $card.offset().top - 80 }, 300);
    }

    // Jump to a conversation card by ID. If it's not on the current
    // chat-history page, use the existing search input to pull it up
    // (search matches conversation_id LIKE) and then scroll to it.
    function navigateToConversationCard(cid) {
        var $card = $('.aicwp-ui-conversation-card[data-conversation-id="' + cid + '"]');
        if ($card.length) {
            scrollToConvCard($card);
            return;
        }

        var $searchInput = $('#conversation-search-input');
        var $searchBtn   = $('#conversation-search-btn');
        if (!$searchInput.length || !$searchBtn.length) return;

        $searchInput.val(cid);
        $searchBtn.trigger('click');

        // Poll for the card to appear after the AJAX search loads.
        var attempts = 0;
        var timer = setInterval(function() {
            attempts++;
            var $newCard = $('.aicwp-ui-conversation-card[data-conversation-id="' + cid + '"]');
            if ($newCard.length) {
                clearInterval(timer);
                scrollToConvCard($newCard);
            } else if (attempts > 40) { // ~4s timeout
                clearInterval(timer);
                showAuditToast(I.notFound || 'Conversation not found in history.', 'error');
            }
        }, 100);
    }

    // ============================================================
    // Stat-box tooltips (reuses .aicwp-ui-tooltip-bubble from free plugin)
    // ============================================================
    function initStatTooltips() {
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

            var rect = el.getBoundingClientRect();
            var bubbleRect = $b[0].getBoundingClientRect();
            var top = rect.top - bubbleRect.height - 10;
            var left = rect.left + (rect.width / 2) - (bubbleRect.width / 2);

            var margin = 8;
            left = Math.max(margin, Math.min(left, window.innerWidth - bubbleRect.width - margin));
            if (top < margin) {
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

        $(document).on('mouseenter focus', '.aicwp-ui-audit-stat-info[data-tooltip]', function() {
            show(this);
        });
        $(document).on('mouseleave blur', '.aicwp-ui-audit-stat-info[data-tooltip]', function() {
            hide();
        });
        $(window).on('scroll resize', hide);
    }

    // ============================================================
    // Item detail stacked modal (opens on top of items list)
    // ============================================================
    function initItemDetailModal() {
        $(document).on('click', '.aicwp-ui-audit-clickable-item', function(e) {
            e.stopPropagation();
            var analysisId = $(this).data('analysis-id');
            var conversationId = $(this).data('conversation-id');
            if (!analysisId && !conversationId) return;
            openItemDetailModal(analysisId, conversationId);
        });

        $('#ai-chat-audit-item-detail-modal-close, #ai-chat-audit-item-detail-modal .aicwp-ui-modal-overlay').on('click', function() {
            $('#ai-chat-audit-item-detail-modal').hide();
        });

        $('#ai-chat-audit-item-detail-modal-view-conv').on('click', function() {
            var cid = $(this).data('conversation-id');
            if (!cid) return;
            $('#ai-chat-audit-item-detail-modal').hide();
            closeDetailModal();
            navigateToConversationCard(cid);
        });
    }

    function openItemDetailModal(id, cid) {
        var $modal = $('#ai-chat-audit-item-detail-modal');
        $modal.data('cid', cid).show();
        $('#ai-chat-audit-item-detail-modal-body').html('<div class="aicwp-ui-audit-loading"><span class="aicwp-ui-spinner"></span></div>');

        var payload = id ? { id: id } : { conversation_id: cid };
        ajax('ai_chat_audit_get_detail', payload).done(function(res) {
            if (!res || !res.success) {
                $('#ai-chat-audit-item-detail-modal-body').html('<p>' + ((res && res.data && res.data.message) || 'Error') + '</p>');
                return;
            }
            renderItemDetailModal(res.data);
        });
    }

    function renderItemDetailModal(d) {
        $('#ai-chat-audit-item-detail-modal-title').text(d.title || '');
        $('#ai-chat-audit-item-detail-modal-view-conv').data('conversation-id', d.conversation_id || '');

        if (d.suggested_action === 'skip') {
            var skipHtml = '<div class="aicwp-ui-audit-modal-meta">' +
                    '<span>' + formatDate(d.analyzed_at) + '</span>' +
                '</div>' +
                '<p class="aicwp-ui-audit-summary" style="color:#666;">' +
                    (I.skipNotice || 'This conversation had no meaningful content to audit.') +
                '</p>';
            $('#ai-chat-audit-item-detail-modal-body').html(skipHtml);
            return;
        }

        var sentLabel = sentimentLabel(d.sentiment);
        var meta = '<div class="aicwp-ui-audit-modal-meta">' +
                '<span>' + formatDate(d.analyzed_at) + '</span>' +
                '<span class="aicwp-ui-audit-badge aicwp-ui-audit-sent-' + d.sentiment + '">' + sentLabel + '</span>' +
            '</div>';

        var html = meta;
        html += '<h4 class="aicwp-ui-audit-section-title">' + (I.summary || 'Summary') + '</h4>';
        html += '<p class="aicwp-ui-audit-summary">' + escapeHtml(d.summary || '') + '</p>';

        if (d.data_gaps && d.data_gaps.length) {
            html += '<h4 class="aicwp-ui-audit-section-title">' + (I.dataGaps || 'Data Gaps') + ' (' + d.data_gaps.length + ')</h4>';
            html += '<ul class="aicwp-ui-audit-gap-list">';
            for (var j = 0; j < d.data_gaps.length; j++) {
                html += '<li class="aicwp-ui-audit-gap-item"><span class="aicwp-ui-audit-warn">&#9888;</span> ' + escapeHtml(d.data_gaps[j].question || '') + '</li>';
            }
            html += '</ul>';
        }

        $('#ai-chat-audit-item-detail-modal-body').html(html);
    }

    // ============================================================
    // Init
    // ============================================================
    $(function() {
        initRowButton();
        initFilters();
        initBacklog();
        initDetailModal();
        initItemDetailModal();
        initSettingsModal();
        initStatTooltips();
        initAuditSection();
    });

})(jQuery);
