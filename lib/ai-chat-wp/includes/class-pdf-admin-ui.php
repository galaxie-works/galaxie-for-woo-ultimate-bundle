<?php
/**
 * Document Admin UI - PRO Feature
 *
 * Adds document upload interface to Universal Settings
 * Supports PDF, TXT, MD, XML, CSV files
 *
 * @package AICWP
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_PDF_Admin_UI {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Add PDF upload button to universal settings
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Render PDF management modal (card is now rendered in FREE plugin)
        add_action('admin_footer', array($this, 'render_pdf_modal'));
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on AI Chat for WordPress admin page
        if ($hook !== 'toplevel_page_ai-chat-wp') {
            return;
        }

        // Only load on database tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        if ($active_tab !== 'database') {
            return;
        }

        wp_enqueue_style(
            'aicwp-pdf-admin',
            AICWP_PLUGIN_URL . 'assets/css/pdf-admin.css',
            array(),
            AICWP_VERSION
        );

        wp_enqueue_script(
            'aicwp-pdf-admin',
            AICWP_PLUGIN_URL . 'assets/js/pdf-admin.js',
            array('jquery'),
            AICWP_VERSION,
            true
        );

        wp_localize_script('aicwp-pdf-admin', 'aiChatProPdfConfig', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('aicwp_pdf_upload'),
            'strings' => array(
                'uploading' => __('Uploading...', 'ai-chat-wp'),
                'processing' => __('Processing document...', 'ai-chat-wp'),
                'confirm_delete' => __('Are you sure you want to delete this document? All chunks and embeddings will be removed.', 'ai-chat-wp'),
                'delete_success' => __('Document deleted successfully', 'ai-chat-wp'),
                'upload_success' => __('Document uploaded successfully', 'ai-chat-wp'),
                'train_success' => __('Document queued for training', 'ai-chat-wp'),
                'error' => __('An error occurred', 'ai-chat-wp'),
                'no_documents' => __('No documents uploaded yet.', 'ai-chat-wp'),
                'trained' => __('Trained', 'ai-chat-wp'),
                'partial' => __('Partial', 'ai-chat-wp'),
                'pending_training' => __('Pending training', 'ai-chat-wp'),
                'chunks' => __('Chunks', 'ai-chat-wp'),
                'uploaded' => __('Uploaded', 'ai-chat-wp'),
                'train_now' => __('Train Now', 'ai-chat-wp'),
                'delete' => __('Delete', 'ai-chat-wp'),
                'training' => __('Training', 'ai-chat-wp'),
                'training_complete' => __('Training complete', 'ai-chat-wp'),
                'retry' => __('retry', 'ai-chat-wp'),
            ),
        ));
    }

    /**
     * Render document management modal
     */
    public function render_pdf_modal() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'toplevel_page_ai-chat-wp') {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        if ($active_tab !== 'database') {
            return;
        }
        ?>
        <div id="pdf-upload-modal" class="aicwp-modal" style="display: none;">
            <div class="aicwp-modal-overlay"></div>
            <div class="aicwp-modal-content pdf-modal-content">
                <div class="aicwp-modal-header">
                    <h2><?php _e('Document Manager', 'ai-chat-wp'); ?></h2>
                    <button type="button" class="aicwp-modal-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>

                <div class="aicwp-modal-body">
                    <!-- Upload Section -->
                    <div class="pdf-upload-section">
                        <h3><?php _e('Upload/Manage Documents', 'ai-chat-wp'); ?></h3>
                        <p class="description">
                            <?php _e('Upload documents to make their content searchable. Supported formats: <strong>PDF</strong>, <strong>TXT</strong>, <strong>MD</strong>, <strong>XML</strong>, <strong>CSV</strong>. Files will be automatically chunked and embedded for AI search.', 'ai-chat-wp'); ?>
                        </p>

                        <div class="pdf-upload-form">
                            <input type="file" id="pdf-file-input" accept=".pdf,.txt,.md,.xml,.csv" multiple style="display: none;">
                            <button type="button" class="button button-primary" id="pdf-select-btn">
                                <span class="dashicons dashicons-upload"></span>
                                <?php _e('Select Files', 'ai-chat-wp'); ?>
                            </button>
                            <span class="pdf-upload-status"></span>
                        </div>

                        <div class="pdf-upload-progress" style="display: none;">
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <p class="progress-text"></p>
                        </div>
                    </div>

                    <hr>

                    <!-- Manage Existing Documents Section -->
                    <div class="pdf-manage-section">
                        <h3 style="margin-bottom: 7px;"><?php _e('Uploaded Documents', 'ai-chat-wp'); ?></h3>
                        <span><?php _e('Click "Train Now" to generate embeddings for uploaded documents.', 'ai-chat-wp'); ?></span>

                        <div id="pdf-documents-list">
                            <p class="loading-message"><?php _e('Loading...', 'ai-chat-wp'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="aicwp-modal-footer">
                    <button type="button" class="button" id="pdf-modal-close"><?php _e('Close', 'ai-chat-wp'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize
AICWP_PDF_Admin_UI::get_instance();
