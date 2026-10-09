/**
 * Document Admin UI JavaScript
 * AI Chat & Search Pro
 * Supports PDF, TXT, MD, XML, CSV files
 */

(function($) {
    'use strict';

    const PDFAdmin = {
        init: function() {
            this.bindEvents();
            this.loadPDFList();
        },

        bindEvents: function() {
            // Open PDF modal
            $(document).on('click', '#upload-pdf-btn', function(e) {
                e.preventDefault();
                $('#pdf-upload-modal').fadeIn(200);
                PDFAdmin.loadPDFList();
            });

            // Close modal
            $(document).on('click', '.aicwp-modal-close, #pdf-modal-close, .aicwp-modal-overlay', function(e) {
                if ($(e.target).hasClass('aicwp-modal-overlay') ||
                    $(e.target).closest('.aicwp-modal-close').length ||
                    $(e.target).is('#pdf-modal-close')) {
                    $('#pdf-upload-modal').fadeOut(200);
                }
            });

            // Select PDF files
            $(document).on('click', '#pdf-select-btn', function() {
                $('#pdf-file-input').click();
            });

            // Handle file selection
            $(document).on('change', '#pdf-file-input', function() {
                const files = this.files;
                if (files.length > 0) {
                    PDFAdmin.uploadFiles(files);
                }
            });

            // Delete PDF
            $(document).on('click', '.pdf-delete-btn', function(e) {
                e.preventDefault();
                const filename = $(this).data('filename');
                if (confirm(aiChatProPdfConfig.strings.confirm_delete)) {
                    PDFAdmin.deletePDF(filename);
                }
            });

            // Train PDF
            $(document).on('click', '.pdf-train-btn', function(e) {
                e.preventDefault();
                const $btn = $(this);
                const filename = $btn.data('filename');
                PDFAdmin.trainPDF(filename, $btn);
            });
        },

        /**
         * Upload PDF files
         */
        uploadFiles: function(files) {
            const totalFiles = files.length;
            let uploadedCount = 0;

            $('.pdf-upload-progress').show();
            $('.progress-fill').css('width', '0%');
            $('.progress-text').html('<span class="aicwp-ui-spinner"></span> ' + aiChatProPdfConfig.strings.uploading);

            // Upload files sequentially
            const uploadNext = function(index) {
                if (index >= totalFiles) {
                    // All done — refresh list
                    $('.pdf-upload-progress').fadeOut(300);
                    $('#pdf-file-input').val('');
                    PDFAdmin.showSuccess(aiChatProPdfConfig.strings.upload_success);
                    PDFAdmin.loadPDFList();
                    return;
                }

                const file = files[index];
                const formData = new FormData();
                formData.append('action', 'aicwp_upload_pdf');
                formData.append('nonce', aiChatProPdfConfig.nonce);
                formData.append('pdf_file', file);

                $.ajax({
                    url: aiChatProPdfConfig.ajax_url,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        uploadedCount++;
                        const progress = (uploadedCount / totalFiles) * 100;
                        $('.progress-fill').css('width', progress + '%');
                        $('.progress-text').html(`<span class="aicwp-ui-spinner"></span> ${uploadedCount} / ${totalFiles} ${aiChatProPdfConfig.strings.uploading}`);

                        if (!response.success) {
                            PDFAdmin.showError(response.data.message || aiChatProPdfConfig.strings.error);
                        }

                        // Upload next file
                        uploadNext(index + 1);
                    },
                    error: function(xhr) {
                        PDFAdmin.showError(aiChatProPdfConfig.strings.error);
                        uploadedCount++;
                        // Continue with next file
                        uploadNext(index + 1);
                    }
                });
            };

            // Start uploading
            uploadNext(0);
        },

        /**
         * Load PDF documents list
         */
        loadPDFList: function(onReady) {
            $('#pdf-documents-list').html('<p class="loading-message"><span class="aicwp-ui-spinner"></span> ' + aiChatProPdfConfig.strings.processing + '</p>');

            $.ajax({
                url: aiChatProPdfConfig.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_get_pdf_list',
                    nonce: aiChatProPdfConfig.nonce
                },
                success: function(response) {
                    if (response.success && response.data.documents) {
                        PDFAdmin.renderPDFList(response.data.documents);
                    } else {
                        $('#pdf-documents-list').html('<p class="pdf-no-documents">No documents uploaded yet.</p>');
                    }
                    if (typeof onReady === 'function') {
                        onReady();
                    }
                },
                error: function() {
                    $('#pdf-documents-list').html('<p class="pdf-error">Failed to load document list.</p>');
                }
            });
        },

        /**
         * Render documents list
         */
        renderPDFList: function(documents) {
            const s = aiChatProPdfConfig.strings;
            if (documents.length === 0) {
                $('#pdf-documents-list').html('<p class="pdf-no-documents">' + s.no_documents + '</p>');
                return;
            }

            let html = '';
            documents.forEach(function(doc) {
                const indexedChunks = doc.indexed_chunks || 0;
                const totalChunks = doc.total_chunks || 0;
                const isFullyIndexed = doc.is_fully_indexed || false;

                // Determine status badge
                let statusBadge = '';
                let showTrainButton = false;

                if (isFullyIndexed) {
                    statusBadge = `<span class="pdf-status-badge status-indexed">✓ ${s.trained} (${indexedChunks}/${totalChunks})</span>`;
                } else if (indexedChunks > 0) {
                    statusBadge = `<span class="pdf-status-badge status-partial">⏳ ${s.partial} (${indexedChunks}/${totalChunks})</span>`;
                    showTrainButton = true;
                } else {
                    statusBadge = `<span class="pdf-status-badge status-pending">${s.pending_training}</span>`;
                    showTrainButton = true;
                }

                const fileType = (doc.file_type || 'pdf').toUpperCase();
                const fileIcon = PDFAdmin.getFileIcon(doc.file_type || 'pdf');

                html += `
                    <div class="pdf-document-item">
                        <div class="pdf-document-info">
                            <div class="pdf-document-name">
                                <span class="dashicons ${fileIcon}"></span>
                                ${PDFAdmin.escapeHtml(doc.filename)}
                                <span class="file-type-badge file-type-${(doc.file_type || 'pdf').toLowerCase()}">${fileType}</span>
                            </div>
                            <div class="pdf-document-meta">
                                ${statusBadge}
                                <span><strong>${s.chunks}:</strong> ${totalChunks}</span>
                                <span><strong>${s.uploaded}:</strong> ${PDFAdmin.formatDate(doc.upload_date)}</span>
                            </div>
                        </div>
                        <div class="pdf-document-actions">
                            ${showTrainButton ? `
                                <a href="#" class="pdf-action-btn pdf-train-btn" data-filename="${PDFAdmin.escapeHtml(doc.filename)}">
                                    <span class="dashicons dashicons-controls-play"></span> ${s.train_now}
                                </a>
                            ` : ''}
                            <a href="#" class="pdf-action-btn pdf-delete-btn" data-filename="${PDFAdmin.escapeHtml(doc.filename)}">
                                <span class="dashicons dashicons-trash"></span> ${s.delete}
                            </a>
                        </div>
                    </div>
                `;
            });

            $('#pdf-documents-list').html(html);
        },

        /**
         * Train document (queue embeddings)
         */
        trainPDF: function(filename, $btn) {
            var offset = 0;
            var total = 0;
            var succeeded = 0;
            var failed = 0;
            var retries = 0;
            var MAX_RETRIES = 2;

            // Show inline progress in the document row
            var $item = $btn.closest('.pdf-document-item');
            var $meta = $item.find('.pdf-document-meta');
            $meta.html('<span class="pdf-status-badge status-training"><span class="aicwp-ui-spinner"></span> Preparing...</span>');
            $btn.hide();

            function updateProgress(text) {
                $meta.find('.pdf-status-badge').html(
                    '<span class="aicwp-ui-spinner"></span> ' + text
                );
            }

            function processNextChunk() {
                $.ajax({
                    url: aiChatProPdfConfig.ajax_url,
                    type: 'POST',
                    timeout: 30000,
                    data: {
                        action: 'aicwp_train_pdf',
                        nonce: aiChatProPdfConfig.nonce,
                        filename: filename,
                        offset: offset
                    },
                    success: function(response) {
                        if (!response.success || !response.data) {
                            return handleChunkError();
                        }

                        var data = response.data;
                        total = data.total || total;

                        if (data.done || !data.has_more) {
                            return finish();
                        }

                        if (data.success === false) {
                            return handleChunkError();
                        }

                        // Success — reset retries, advance
                        succeeded++;
                        retries = 0;
                        offset = data.processed;
                        const s = aiChatProPdfConfig.strings;
                        updateProgress(s.training + ' ' + offset + '/' + total);
                        setTimeout(processNextChunk, 300);
                    },
                    error: function() {
                        handleChunkError();
                    }
                });
            }

            function handleChunkError() {
                retries++;
                if (retries <= MAX_RETRIES) {
                    const s = aiChatProPdfConfig.strings;
                    updateProgress(s.training + ' ' + offset + '/' + (total || '?') + ' (' + s.retry + ' ' + retries + ')');
                    setTimeout(processNextChunk, 500);
                } else {
                    // Give up on this batch, skip ahead
                    failed++;
                    retries = 0;
                    offset += 10;
                    const s = aiChatProPdfConfig.strings;
                    updateProgress(s.training + ' ' + Math.min(offset, total || offset) + '/' + (total || '?'));
                    setTimeout(processNextChunk, 300);
                }
            }

            function finish() {
                var msg = aiChatProPdfConfig.strings.training_complete;
                if (failed > 0) {
                    msg += ' (' + failed + ' failed)';
                }
                $meta.html('<span class="pdf-status-badge status-indexed">' + msg + '</span>');
                PDFAdmin.showSuccess(msg);

                setTimeout(function() { PDFAdmin.loadPDFList(); }, 1500);
            }

            processNextChunk();
        },

        /**
         * Delete document
         */
        deletePDF: function(filename) {
            $.ajax({
                url: aiChatProPdfConfig.ajax_url,
                type: 'POST',
                data: {
                    action: 'aicwp_delete_pdf',
                    nonce: aiChatProPdfConfig.nonce,
                    filename: filename
                },
                success: function(response) {
                    if (response.success) {
                        PDFAdmin.showSuccess(aiChatProPdfConfig.strings.delete_success);
                        PDFAdmin.loadPDFList();
                    } else {
                        PDFAdmin.showError(response.data.message || aiChatProPdfConfig.strings.error);
                    }
                },
                error: function() {
                    PDFAdmin.showError(aiChatProPdfConfig.strings.error);
                }
            });
        },

        /**
         * Show success message
         */
        showSuccess: function(message) {
            const html = `<div class="pdf-success">${message}</div>`;
            $('.pdf-upload-section').prepend(html);
            setTimeout(function() {
                $('.pdf-success').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 3000);
        },

        /**
         * Show error message
         */
        showError: function(message) {
            const html = `<div class="pdf-error">${message}</div>`;
            $('.pdf-upload-section').prepend(html);
            setTimeout(function() {
                $('.pdf-error').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        },

        /**
         * Format date
         */
        formatDate: function(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },

        /**
         * Get dashicon class for file type
         */
        getFileIcon: function(fileType) {
            const icons = {
                'pdf': 'dashicons-pdf',
                'txt': 'dashicons-text-page',
                'md': 'dashicons-editor-code',
                'xml': 'dashicons-media-code',
                'csv': 'dashicons-media-spreadsheet'
            };
            return icons[fileType.toLowerCase()] || 'dashicons-media-document';
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        PDFAdmin.init();
    });

})(jQuery);
