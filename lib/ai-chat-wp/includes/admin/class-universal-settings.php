<?php
/**
 * Universal Settings Admin Interface
 *
 * Clean admin interface for configuring content sources
 *
 * @package AICWP_Plugin
 * @since 1.6.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Universal_Settings {

    /**
     * Default post types that cannot be removed
     *
     * @var array
     */
    private $default_types = array();

    /**
     * Get whitelisted and enabled post types (delegates to Database Manager)
     *
     * @return array Filtered array of enabled post types
     */
    public static function get_enabled_post_types() {
        return AICWP_Database_Manager::get_enabled_post_types();
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers
        add_action('wp_ajax_aicwp_get_post_type_stats', array($this, 'ajax_get_post_type_stats'));
        add_action('wp_ajax_aicwp_toggle_post_type', array($this, 'ajax_toggle_post_type'));
        add_action('wp_ajax_aicwp_get_posts_for_selection', array($this, 'ajax_get_posts_for_selection'));
        add_action('wp_ajax_aicwp_generate_selected_posts', array($this, 'ajax_generate_selected_posts'));
        add_action('wp_ajax_aicwp_get_total_count', array($this, 'ajax_get_total_count'));
        add_action('wp_ajax_aicwp_add_custom_post_types', array($this, 'ajax_add_custom_post_types'));
        add_action('wp_ajax_aicwp_remove_custom_post_type', array($this, 'ajax_remove_custom_post_type'));
        add_action('wp_ajax_aicwp_get_custom_type_count', array($this, 'ajax_get_custom_type_count'));
        add_action('wp_ajax_aicwp_get_bulk_post_ids', array($this, 'ajax_get_bulk_post_ids'));
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register settings with explicit args for multisite compatibility
        $settings_args = array(
            'type' => 'array',
            'sanitize_callback' => null, // We handle sanitization in AJAX handlers
            'default' => array(),
        );

        register_setting('aicwp_content_sources', 'aicwp_enabled_post_types', $settings_args);
        register_setting('aicwp_content_sources', 'aicwp_custom_meta_fields', $settings_args);

        // Add allowed_options filter for multisite compatibility
        add_filter('allowed_options', array($this, 'add_allowed_options'));
    }

    /**
     * Add plugin options to allowed options list for multisite compatibility
     *
     * @param array $allowed_options Array of allowed options
     * @return array Modified array of allowed options
     */
    public function add_allowed_options($allowed_options) {
        $allowed_options['aicwp_content_sources'] = array(
            'aicwp_enabled_post_types',
            'aicwp_custom_meta_fields',
            'aicwp_custom_post_types',
            'aicwp_manual_selections',
        );

        return $allowed_options;
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on plugin admin page
        if ($hook !== 'toplevel_page_ai-chat-wp') {
            return;
        }

        // Only load on database tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        if ($active_tab !== 'database') {
            return;
        }

        wp_enqueue_style(
            'aicwp-universal-settings',
            AICWP_PLUGIN_URL . 'assets/css/universal-settings.css',
            array(),
            AICWP_VERSION
        );

        wp_enqueue_script(
            'aicwp-universal-settings',
            AICWP_PLUGIN_URL . 'assets/js/universal-settings.js',
            array('jquery'),
            AICWP_VERSION,
            true
        );

        wp_localize_script('aicwp-universal-settings', 'aicwpUniversalSettings', array(
            'ajax_url' => get_admin_url(get_current_blog_id(), 'admin-ajax.php'),
            'nonce' => wp_create_nonce('aicwp_universal_settings'),
            'database_nonce' => wp_create_nonce('aicwp_nonce'),
            'strings' => array(
                'confirm_toggle' => __('Enable embeddings for this content type?', 'ai-chat-wp'),
                'confirm_reindex' => __('This will regenerate all embeddings for this content type. Continue?', 'ai-chat-wp'),
                'reindexing' => __('Reindexing...', 'ai-chat-wp'),
                'success' => __('Success!', 'ai-chat-wp'),
                'error' => __('Error occurred', 'ai-chat-wp'),
                'upload_documents' => __('Upload documents', 'ai-chat-wp'),
                'add_external_pages' => __('Add external pages', 'ai-chat-wp'),
                'manual_selection_active' => __('Manual selection active', 'ai-chat-wp'),
                'clear' => __('Clear', 'ai-chat-wp'),
                'manual_selection' => __('Manual selection', 'ai-chat-wp'),
                'selected_of' => __('of', 'ai-chat-wp'),
                'selected' => __('selected', 'ai-chat-wp'),
                'indexed' => __('Indexed', 'ai-chat-wp'),
                'pending' => __('Pending', 'ai-chat-wp'),
                'error_loading_posts' => __('Error loading posts', 'ai-chat-wp'),
                'error_loading_count' => __('Error loading content count', 'ai-chat-wp'),
            )
        ));
    }

    /**
     * Render just the content sources cards (no wrapping)
     */
    public function render_content_sources_cards() {
        // Get enabled post types from option (raw, no defaults applied by helper)
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());

        // Get custom types that have been added
        $custom_post_types_added = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_post_types_added)) {
            $custom_post_types_added = array();
        }

        // Default types + added custom types (exclude ai_pdf_document from loop, it's rendered separately)
        $default_types = array('post', 'page', 'product');
        $allowed_post_types = array_merge($default_types, $custom_post_types_added);

        // Store default types for checking if a type is custom (include ai_pdf_document for validation)
        $this->default_types = array_merge($default_types, array('ai_pdf_document'));

        // Get only allowed post types
        $post_types = array();
        foreach ($allowed_post_types as $post_type_name) {
            $post_type_obj = get_post_type_object($post_type_name);
            if ($post_type_obj) {
                $post_types[$post_type_name] = $post_type_obj;
            }
        }

        // Get detected custom post types that haven't been added yet
        // Counts are loaded lazily via AJAX to prevent timeout on large databases
        $detected_custom_types = AICWP_Database_Manager::get_detected_custom_post_types();
        $available_custom_types = array();

        foreach ($detected_custom_types as $custom_type) {
            if (!in_array($custom_type['name'], $custom_post_types_added)) {
                $custom_type['count'] = 0; // Placeholder, loaded via JS
                $available_custom_types[] = $custom_type;
            }
        }

        // Get manual selections once (cheap option lookup, no DB queries)
        $manual_selections = get_option('aicwp_manual_selections', array());

        ?>
        <div class="aicwp-universal-settings">

            <?php $this->maybe_render_memory_limit_notice(); ?>

            <!-- Post Types Grid -->
            <div class="aicwp-post-types-grid" style="margin-top: 0;">
                <?php foreach ($post_types as $post_type):
                    $is_enabled = in_array($post_type->name, $enabled_post_types);
                    $is_custom = !in_array($post_type->name, $this->default_types);

                    // Stats loaded lazily via AJAX to prevent timeout on large databases
                    $has_manual_selection = array_key_exists($post_type->name, $manual_selections);
                    $badge_class = 'loading';
                ?>
                    <div class="post-type-card <?php echo $is_enabled ? 'enabled' : ''; ?> <?php echo $is_custom ? 'is-custom' : ''; ?>"
                         data-post-type="<?php echo esc_attr($post_type->name); ?>">

                        <!-- Card Header -->
                        <div class="card-header">
                            <div class="post-type-icon">
                                <?php echo $this->get_post_type_icon($post_type->name); ?>
                            </div>
                            <div class="post-type-info">
                                <h3>
                                    <?php if ($is_custom): ?>
                                    <span class="dashicons dashicons-no-alt delete-custom-type" data-post-type="<?php echo esc_attr($post_type->name); ?>" title="<?php _e('Remove this post type', 'ai-chat-wp'); ?>"></span>
                                    <?php endif; ?>
                                    <?php echo esc_html($post_type->label); ?>
                                    <span class="custom-type-badge <?php echo $badge_class; ?>">
                                        <span class="aicwp-ui-spinner aicwp-ui-spinner--small"></span>
                                    </span>
                                </h3>
                                <code><?php echo esc_html($post_type->name); ?></code>

                                <!-- Manual selection link -->
                                <div class="manual-selection-actions">
                                    <?php if ($has_manual_selection): ?>
                                        <a href="#" class="manual-selection-link active" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                            <span class="dashicons dashicons-yes"></span>
                                            <?php _e('Manual selection active', 'ai-chat-wp'); ?>
                                        </a>
                                        <a href="#" class="clear-selection-link" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                            <?php _e('Clear', 'ai-chat-wp'); ?>
                                        </a>
                                    <?php else: ?>
                                        <a href="#" class="manual-selection-link" data-post-type="<?php echo esc_attr($post_type->name); ?>">
                                            <span class="dashicons dashicons-admin-generic"></span>
                                            <?php _e('Manual selection', 'ai-chat-wp'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Toggle Switch -->
                            <label class="toggle-switch">
                                <input
                                    type="checkbox"
                                    class="post-type-toggle"
                                    value="<?php echo esc_attr($post_type->name); ?>"
                                    <?php checked($is_enabled); ?>
                                >
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php
                // Render Documents card (always, not dependent on listing post type)
                $this->render_pdf_documents_card();
                ?>

                <?php
                // Render External Pages card
                $this->render_external_pages_card();
                ?>

                <?php
                // Allow extensions to add additional custom cards
                do_action('aicwp_after_post_types_grid');
                ?>
            </div>

            <!-- Detected Custom Post Types Section -->
            <?php if (!empty($available_custom_types)): ?>
            <div class="aicwp-custom-types-section">
                <h3 class="collapsible-header" data-toggle="custom-types-content">
                    <span class="collapsible-header-text">
                        <?php _e('Detected Custom Post Types', 'ai-chat-wp'); ?>
                        <span class="custom-type-badge has-content">
                            <?php echo count($available_custom_types); ?>
                        </span>
                    </span>
                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                </h3>
                <div id="custom-types-content" class="collapsible-content" style="display: none;">
                    <p><?php _e('Select custom post types to add to your training content. Once added, they will appear above as cards.', 'ai-chat-wp'); ?></p>

                    <div class="custom-types-checkboxes">
                    <?php foreach ($available_custom_types as $custom_type): ?>
                        <label>
                            <input type="checkbox"
                                   class="custom-post-type-checkbox"
                                   value="<?php echo esc_attr($custom_type['name']); ?>">
                            <span>
                                <strong><?php echo esc_html($custom_type['label']); ?></strong>
                                <span class="custom-type-badge loading" data-custom-type="<?php echo esc_attr($custom_type['name']); ?>">
                                    <span class="aicwp-ui-spinner aicwp-ui-spinner--small"></span>
                                </span>
                                <br>
                                <code><?php echo esc_html($custom_type['name']); ?></code>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                    <button type="button"
                            id="add-custom-post-types-btn"
                            class="aicwp-ui-button aicwp-ui-button-primary">
                        <?php _e('Add Selected Types', 'ai-chat-wp'); ?>
                    </button>
                </div> <!-- Close collapsible-content -->
            </div>
            <?php endif; ?>

            <!-- Manual Selection Modal -->
            <div id="manual-selection-modal" class="aicwp-modal" style="display: none;">
                <div class="aicwp-modal-overlay"></div>
                <div class="aicwp-modal-content">
                    <div class="aicwp-modal-header">
                        <h2 id="modal-title"><?php _e('Manual Selection', 'ai-chat-wp'); ?></h2>
                        <button type="button" class="aicwp-modal-close">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="aicwp-modal-body">
                        <div class="aicwp-modal-controls">
                            <input type="text" id="modal-search" placeholder="<?php _e('Search...', 'ai-chat-wp'); ?>" class="widefat" />
                            <div class="button-group">
                                <button type="button" id="select-all-posts" class="button button-small"><?php _e('Select All', 'ai-chat-wp'); ?></button>
                                <button type="button" id="deselect-all-posts" class="button button-small"><?php _e('Deselect All', 'ai-chat-wp'); ?></button>
                                <button type="button" id="select-pending-posts" class="button button-small"><?php _e('Select Pending Only', 'ai-chat-wp'); ?></button>                            </div>
                        </div>
                        <div id="modal-posts-list" class="aicwp-posts-list">
                            <p class="loading-message"><span class="aicwp-ui-spinner" style="margin-right: 6px;"></span><?php _e('Loading posts...', 'ai-chat-wp'); ?></p>
                        </div>
                    </div>
                    <div class="aicwp-modal-footer">
                        <span id="modal-selection-count"></span>
                        <div class="modal-footer-buttons">
                            <button type="button" class="button" id="modal-cancel"><?php _e('Cancel', 'ai-chat-wp'); ?></button>
                            <button type="button" class="button button-secondary" id="modal-save"><?php _e('Save Selection', 'ai-chat-wp'); ?></button>
                            <button type="button" class="button button-primary" id="modal-train-now">
                                <span class="dashicons dashicons-update"></span>
                                <?php _e('Train Now', 'ai-chat-wp'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Show a warning if PHP memory limit is too low for the amount of enabled content.
     */
    private function maybe_render_memory_limit_notice() {
        $wp_limit     = defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : '40M';
        $server_limit = ini_get('memory_limit');
        $wp_bytes     = wp_convert_hr_to_bytes($wp_limit);
        $server_bytes = wp_convert_hr_to_bytes($server_limit);

        // Unlimited server memory — no warning needed
        if ($server_bytes === -1) {
            return;
        }

        // Effective limit is whichever is lower
        $effective_bytes = min($wp_bytes, $server_bytes);

        // Count only enabled post types, respecting manual selections
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());
        if (!is_array($enabled_post_types) || empty($enabled_post_types)) {
            return;
        }

        $manual_selections = get_option('aicwp_manual_selections', array());
        $total_posts = 0;
        foreach ($enabled_post_types as $pt) {
            if (array_key_exists($pt, $manual_selections)) {
                $selected_ids = is_array($manual_selections[$pt])
                    ? array_filter(array_map('intval', $manual_selections[$pt]))
                    : array();
                $total_posts += count($selected_ids);
            } else {
                $counts = wp_count_posts($pt);
                $total_posts += isset($counts->publish) ? (int) $counts->publish : 0;
            }
        }

        // Tiered memory recommendations based on content size
        $tiers = array(
            5000  => array('limit' => 1024, 'label' => '1 GB'),
            2500  => array('limit' => 512,  'label' => '512 MB'),
        );

        $recommended = null;
        foreach ($tiers as $threshold => $tier) {
            if ($total_posts >= $threshold) {
                $min_bytes = $tier['limit'] * 1024 * 1024;
                if ($effective_bytes < $min_bytes) {
                    $recommended = $tier;
                    break;
                }
            }
        }

        if ($recommended === null) {
            return;
        }

        ?>
        <div class="aicwp-ui-notice aicwp-ui-notice-warning aicwp-ui-memory-notice" style="margin-bottom: 16px; padding: 14px 18px; border-radius: 6px; display: flex; align-items: flex-start; gap: 10px; border-left: 4px solid #ffc107; position: relative;">
            <span class="dashicons dashicons-warning" style="color: #856404; margin-top: 2px;"></span>
            <div style="flex: 1;">
                <strong><?php _e('Low PHP Memory Limit Detected', 'ai-chat-wp'); ?></strong>
                <p style="margin: 6px 0 0;">
                    <?php
                    printf(
                        /* translators: 1: WP memory limit, 2: server memory limit, 3: total number of content items, 4: recommended memory size */
                        __('Your site has %3$s content items to train. WordPress memory limit is <strong>%1$s</strong> and server memory limit is <strong>%2$s</strong> — we recommend at least <strong>%4$s</strong> for both. Set <code>define(\'WP_MEMORY_LIMIT\', \'%4$s\');</code> in <code>wp-config.php</code> and increase the server limit in your hosting panel.', 'ai-chat-wp'),
                        esc_html($wp_limit),
                        esc_html($server_limit),
                        '<strong>' . number_format_i18n($total_posts) . '</strong>',
                        esc_html($recommended['label'])
                    );
                    ?>
                </p>
            </div>
            <button type="button" class="aicwp-ui-memory-notice-dismiss" style="background: none; border: none; cursor: pointer; padding: 0; margin: 0; line-height: 1; color: #856404; font-size: 18px; opacity: 0.7;" title="<?php esc_attr_e('Dismiss', 'ai-chat-wp'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <script>
        jQuery(function($){
            $('.aicwp-ui-memory-notice-dismiss').on('click', function(){
                $(this).closest('.aicwp-ui-memory-notice').fadeOut(200);
            });
        });
        </script>
        <?php
    }

    /**
     * Render Documents card (PDF, TXT, MD, XML, CSV)
     */
    private function render_pdf_documents_card() {
        $post_type = 'ai_pdf_document';

        // Check if documents are enabled
        $enabled_types = get_option('aicwp_enabled_post_types', array());
        $is_enabled = in_array($post_type, $enabled_types);

        // Get document count
        $pdf_count = wp_count_posts($post_type);
        $total = isset($pdf_count->publish) ? $pdf_count->publish : 0;

        // Get indexed count
        global $wpdb;
        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $indexed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e.listing_id)
             FROM {$embeddings_table} e
             INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            $post_type
        ));

        $badge_class = $total > 0 ? 'has-content' : 'empty';
        ?>
        <div class="post-type-card pdf-card <?php echo $is_enabled ? 'enabled' : ''; ?>"
             data-post-type="<?php echo esc_attr($post_type); ?>">

            <!-- Card Header -->
            <div class="card-header">
                <div class="post-type-icon">
                    <span class="dashicons dashicons-media-document"></span>
                </div>
                <div class="post-type-info">
                    <h3>
                        <?php _e('Documents', 'ai-chat-wp'); ?>
                        <span class="custom-type-badge <?php echo $badge_class; ?>">
                            <?php echo number_format($total); ?>
                        </span>
                    </h3>
                    <code>ai_document</code>

                    <!-- Upload documents button -->
                    <div class="manual-selection-actions">
                        <a href="#" class="pdf-upload-link" id="upload-pdf-btn">
                            <span class="dashicons dashicons-upload"></span>
                            <?php _e('Upload documents', 'ai-chat-wp'); ?>
                        </a>
                    </div>
                </div>

                <!-- Toggle Switch -->
                <label class="toggle-switch">
                    <input
                        type="checkbox"
                        class="post-type-toggle"
                        value="<?php echo esc_attr($post_type); ?>"
                        <?php checked($is_enabled); ?>
                    >
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Render External Pages card
     */
    private function render_external_pages_card() {
        $post_type = 'ai_external_page';

        // Check if external pages are enabled
        $enabled_types = get_option('aicwp_enabled_post_types', array());
        $is_enabled = in_array($post_type, $enabled_types);

        // Get total count
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ai_external_page' AND post_status = 'publish'"
        );

        // Get indexed count
        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $indexed = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e.listing_id)
             FROM {$embeddings_table} e
             INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
             WHERE p.post_type = %s AND p.post_status = 'publish'",
            $post_type
        ));

        $badge_class = $total > 0 ? 'has-content' : 'empty';
        ?>
        <div class="post-type-card external-pages-card <?php echo $is_enabled ? 'enabled' : ''; ?>"
             data-post-type="<?php echo esc_attr($post_type); ?>">

            <!-- Card Header -->
            <div class="card-header">
                <div class="post-type-icon">
                    <span class="dashicons dashicons-admin-site-alt3"></span>
                </div>
                <div class="post-type-info">
                    <h3>
                        <?php _e('External Pages', 'ai-chat-wp'); ?>
                        <span class="custom-type-badge <?php echo $badge_class; ?>">
                            <?php echo number_format($total); ?>
                        </span>
                    </h3>
                    <code>ai_external_page</code>

                    <!-- Add external pages button -->
                    <div class="manual-selection-actions">
                        <a href="#" class="external-pages-link" id="manage-external-pages-btn">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                            <?php _e('Add external pages', 'ai-chat-wp'); ?>
                        </a>
                    </div>
                </div>

                <!-- Toggle Switch -->
                <label class="toggle-switch">
                    <input
                        type="checkbox"
                        class="post-type-toggle"
                        value="<?php echo esc_attr($post_type); ?>"
                        <?php checked($is_enabled); ?>
                    >
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Get post type icon
     */
    private function get_post_type_icon($post_type) {
        $icons = array(
            'post' => 'dashicons-admin-post',
            'page' => 'dashicons-admin-page',
            'product' => 'dashicons-products',
            'attachment' => 'dashicons-admin-media',
        );

        $icon_class = isset($icons[$post_type]) ? $icons[$post_type] : 'dashicons-admin-generic';

        return '<span class="dashicons ' . $icon_class . '"></span>';
    }

    /**
     * Get post type statistics
     * Supports three states:
     * 1. No manual selection → count all posts
     * 2. Manual selection with 0 posts → count 0
     * 3. Manual selection with specific IDs → count those
     *
     * Posts are considered "indexed" if they have:
     * - A direct embedding, OR
     * - Content chunks (which have their own embeddings)
     */
    private function get_post_type_stats($post_type) {
        global $wpdb;

        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Check if there's a manual selection for this post type
        $manual_selections = get_option('aicwp_manual_selections', array());
        $has_manual_selection = array_key_exists($post_type, $manual_selections);

        if ($has_manual_selection) {
            // Manual selection active
            $selected_ids = is_array($manual_selections[$post_type])
                ? array_filter(array_map('intval', $manual_selections[$post_type]))
                : array();

            if (empty($selected_ids)) {
                return array(
                    'total' => 0,
                    'indexed' => 0,
                    'pending' => 0,
                    'has_manual_selection' => true
                );
            }

            $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));

            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE ID IN ($placeholders) AND post_status = 'publish'",
                ...$selected_ids
            ));

            // Count indexed — query from embeddings/chunks side (fast, uses indexes)
            // Direct embeddings among selected posts
            $direct = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$embeddings_table} e
                 WHERE e.listing_id IN ($placeholders)",
                ...$selected_ids
            ));

            // Chunk-covered posts among selected (no direct embedding)
            $via_chunks = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
                 WHERE chunk.post_type = %s
                 AND pm.meta_key = '_chunk_parent_id'
                 AND CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)
                 AND CAST(pm.meta_value AS UNSIGNED) NOT IN (
                     SELECT e2.listing_id FROM {$embeddings_table} e2
                     WHERE e2.listing_id IN ($placeholders)
                 )",
                ...array_merge(array($chunk_post_type), $selected_ids, $selected_ids)
            ));

            $indexed = $direct + $via_chunks;
        } else {
            // No manual selection — count all published posts
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = %s AND post_status = 'publish'",
                $post_type
            ));

            // Count indexed — query from embeddings/chunks side (fast, starts from smaller tables)
            // Posts with direct embedding
            $direct = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT e.listing_id)
                 FROM {$embeddings_table} e
                 INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                 WHERE p.post_type = %s AND p.post_status = 'publish'",
                $post_type
            ));

            // Posts covered by chunks but without direct embedding
            $via_chunks = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
                 INNER JOIN {$wpdb->posts} parent ON CAST(pm.meta_value AS UNSIGNED) = parent.ID
                 WHERE chunk.post_type = %s
                 AND pm.meta_key = '_chunk_parent_id'
                 AND parent.post_type = %s
                 AND parent.post_status = 'publish'
                 AND parent.ID NOT IN (
                     SELECT e2.listing_id FROM {$embeddings_table} e2
                 )",
                $chunk_post_type,
                $post_type
            ));

            $indexed = $direct + $via_chunks;
        }

        return array(
            'total' => $total,
            'indexed' => $indexed,
            'pending' => max(0, $total - $indexed),
            'has_manual_selection' => $has_manual_selection
        );
    }

    /**
     * AJAX: Get post type stats
     */
    public function ajax_get_post_type_stats() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);
        $stats = $this->get_post_type_stats($post_type);

        wp_send_json_success($stats);
    }

    /**
     * AJAX: Get published post count for a custom post type
     */
    public function ajax_get_custom_type_count() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        global $wpdb;
        $post_type = sanitize_text_field($_POST['post_type']);
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ));

        wp_send_json_success(array('count' => $count));
    }

    /**
     * AJAX: Toggle post type
     */
    public function ajax_toggle_post_type() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);

        // Handle boolean conversion properly (AJAX sends 'true'/'false' as strings or 1/0)
        $enabled_raw = $_POST['enabled'] ?? false;
        $enabled = filter_var($enabled_raw, FILTER_VALIDATE_BOOLEAN);

        // Validate post type exists
        if (!post_type_exists($post_type)) {
            wp_send_json_error('Invalid post type');
        }

        // Get custom types that have been added
        $custom_post_types_added = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_post_types_added)) {
            $custom_post_types_added = array();
        }

        // Validate post type is either default or has been added as custom
        $default_types = array('post', 'page', 'product', 'ai_pdf_document', 'ai_external_page');
        $allowed_post_types = array_merge($default_types, $custom_post_types_added);
        if (!in_array($post_type, $allowed_post_types)) {
            wp_send_json_error('Post type not available for training');
        }

        // Get current enabled post types (raw, no filtering)
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());
        if (!is_array($enabled_post_types)) {
            $enabled_post_types = array();
        }

        if ($enabled) {
            // Enable: Add if not already in array
            if (!in_array($post_type, $enabled_post_types)) {
                $enabled_post_types[] = $post_type;
            }
        } else {
            // Disable: Remove from array
            $enabled_post_types = array_diff($enabled_post_types, array($post_type));

            // Allow empty array - user can disable all post types to show 0 counts
        }

        // Save the option
        $enabled_post_types = array_values($enabled_post_types); // Re-index
        update_option('aicwp_enabled_post_types', $enabled_post_types);

        wp_send_json_success(array(
            'message' => $enabled ? __('Post type enabled', 'ai-chat-wp') : __('Post type disabled', 'ai-chat-wp'),
            'enabled_post_types' => $enabled_post_types
        ));
    }

    /**
     * AJAX: Get posts for manual selection (paginated, with server-side search)
     */
    public function ajax_get_posts_for_selection() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);
        $per_page  = isset($_POST['per_page']) ? absint($_POST['per_page']) : 50;
        $page      = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $search    = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $filter    = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : ''; // 'pending', 'indexed'

        // Validate post type exists
        if (!post_type_exists($post_type)) {
            wp_send_json_error('Invalid post type');
        }

        // Validate post type is allowed
        $custom_post_types_added = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_post_types_added)) {
            $custom_post_types_added = array();
        }
        $default_types = array('post', 'page', 'product', 'ai_pdf_document', 'ai_external_page');
        $allowed_post_types = array_merge($default_types, $custom_post_types_added);
        if (!in_array($post_type, $allowed_post_types)) {
            wp_send_json_error('Post type not available for training');
        }

        global $wpdb;
        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $chunk_post_type  = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Pre-collect chunk parent IDs (single fast query instead of per-row EXISTS)
        $chunk_parents_subquery = $wpdb->prepare(
            "SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS parent_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
             WHERE chunk.post_type = %s AND pm.meta_key = '_chunk_parent_id'",
            $chunk_post_type
        );

        // Build WHERE conditions
        $where = array();
        $where[] = $wpdb->prepare("p.post_type = %s", $post_type);
        $where[] = "p.post_status = 'publish'";

        // Search filter
        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare("p.post_title LIKE %s", $like);
        }

        // Status filter (pending/indexed)
        if ($filter === 'pending') {
            $where[] = "e.listing_id IS NULL AND cp.parent_id IS NULL";
        } elseif ($filter === 'indexed') {
            $where[] = "(e.listing_id IS NOT NULL OR cp.parent_id IS NOT NULL)";
        }

        $where_clause = implode(' AND ', $where);
        $offset = ($page - 1) * $per_page;

        // Get total count for pagination
        $total_query = "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$embeddings_table} e ON p.ID = e.listing_id
            LEFT JOIN ({$chunk_parents_subquery}) cp ON p.ID = cp.parent_id
            WHERE {$where_clause}";
        $total_count = (int) $wpdb->get_var($total_query);

        // Get paginated posts
        $posts = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_modified,
                   CASE WHEN e.listing_id IS NOT NULL OR cp.parent_id IS NOT NULL THEN 1 ELSE 0 END as has_embedding
            FROM {$wpdb->posts} p
            LEFT JOIN {$embeddings_table} e ON p.ID = e.listing_id
            LEFT JOIN ({$chunk_parents_subquery}) cp ON p.ID = cp.parent_id
            WHERE {$where_clause}
            ORDER BY p.post_title ASC
            LIMIT {$per_page} OFFSET {$offset}
        ", ARRAY_A);

        // Get currently selected post IDs
        $manual_selections = get_option('aicwp_manual_selections', array());
        $selected_ids = isset($manual_selections[$post_type]) ? $manual_selections[$post_type] : array();

        wp_send_json_success(array(
            'posts'           => $posts ?: array(),
            'post_type'       => $post_type,
            'post_type_label' => get_post_type_object($post_type)->label,
            'selected_ids'    => $selected_ids,
            'page'            => $page,
            'per_page'        => $per_page,
            'total'           => $total_count,
            'total_pages'     => ceil($total_count / $per_page),
        ));
    }

    /**
     * AJAX: Get all post IDs matching a filter (for bulk select operations)
     */
    public function ajax_get_bulk_post_ids() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);
        $filter    = isset($_POST['filter']) ? sanitize_text_field($_POST['filter']) : 'all';

        if (!post_type_exists($post_type)) {
            wp_send_json_error('Invalid post type');
        }

        global $wpdb;
        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $chunk_post_type  = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        $chunk_parents_subquery = $wpdb->prepare(
            "SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS parent_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
             WHERE chunk.post_type = %s AND pm.meta_key = '_chunk_parent_id'",
            $chunk_post_type
        );

        $where = array();
        $where[] = $wpdb->prepare("p.post_type = %s", $post_type);
        $where[] = "p.post_status = 'publish'";

        if ($filter === 'pending') {
            $where[] = "e.listing_id IS NULL AND cp.parent_id IS NULL";
        } elseif ($filter === 'indexed') {
            $where[] = "(e.listing_id IS NOT NULL OR cp.parent_id IS NOT NULL)";
        }

        $where_clause = implode(' AND ', $where);

        $ids = $wpdb->get_col("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$embeddings_table} e ON p.ID = e.listing_id
            LEFT JOIN ({$chunk_parents_subquery}) cp ON p.ID = cp.parent_id
            WHERE {$where_clause}
        ");

        wp_send_json_success(array(
            'ids' => array_map('intval', $ids),
        ));
    }

    /**
     * AJAX: Save manual selection for a post type
     */
    public function ajax_generate_selected_posts() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);
        $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : array();

        // Validate post type exists
        if (!post_type_exists($post_type)) {
            wp_send_json_error('Invalid post type');
        }

        // Get custom types that have been added
        $custom_post_types_added = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_post_types_added)) {
            $custom_post_types_added = array();
        }

        // Validate post type is either default or has been added as custom
        $default_types = array('post', 'page', 'product', 'ai_pdf_document', 'ai_external_page');
        $allowed_post_types = array_merge($default_types, $custom_post_types_added);
        if (!in_array($post_type, $allowed_post_types)) {
            wp_send_json_error('Post type not available for training');
        }

        // Get current manual selections
        $manual_selections = get_option('aicwp_manual_selections', array());

        // Distinguish between "clear selection" (button) vs "save with 0 selected" (modal)
        // If post_ids is empty but request came from modal save, treat as 0 posts selected
        // If post_ids is empty from clear button, remove manual selection entirely

        $is_clear_request = isset($_POST['clear']) && $_POST['clear'] === 'true';

        if (empty($post_ids)) {
            if ($is_clear_request) {
                // Clear button clicked - remove manual selection (revert to all)
                unset($manual_selections[$post_type]);
            } else {
                // Modal saved with 0 posts - treat as explicit empty selection
                $manual_selections[$post_type] = array();
            }
        } else {
            // Save manual selection for this post type
            $manual_selections[$post_type] = $post_ids;
        }

        // Update with autoload false to prevent caching issues
        update_option('aicwp_manual_selections', $manual_selections, false);

        // Clear WordPress cache to ensure option is immediately available
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('aicwp_manual_selections', 'options');

        // Debug log
        if (get_option('aicwp_debug_mode', false)) {
            error_log(sprintf(
                '[AI Chat] Manual selection saved for %s: %s (is_clear: %s)',
                $post_type,
                json_encode($manual_selections[$post_type] ?? 'REMOVED'),
                $is_clear_request ? 'true' : 'false'
            ));
        }

        // Get updated stats
        $stats = $this->get_post_type_stats($post_type);

        wp_send_json_success(array(
            'message' => empty($post_ids)
                ? __('Manual selection cleared - all posts will be processed', 'ai-chat-wp')
                : sprintf(__('Saved selection of %d posts', 'ai-chat-wp'), count($post_ids)),
            'total' => count($post_ids),
            'stats' => $stats
        ));
    }

    /**
     * AJAX: Get total count across all enabled post types
     * Respects manual selections for accurate counts
     *
     * Posts are considered "indexed" if they have:
     * - A direct embedding, OR
     * - Content chunks (which have their own embeddings)
     */
    public function ajax_get_total_count() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        global $wpdb;

        // Get whitelisted enabled post types
        $enabled_post_types = self::get_enabled_post_types();

        // If no post types are enabled, return zero counts immediately
        if (empty($enabled_post_types)) {
            wp_send_json_success(array(
                'total' => 0,
                'indexed' => 0,
                'pending' => 0,
                'enabled_types' => array(),
                'enabled_count' => 0
            ));
            return;
        }

        // Get manual selections
        $manual_selections = get_option('aicwp_manual_selections', array());
        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Debug log
        if (get_option('aicwp_debug_mode', false)) {
            error_log('[AI Chat] ajax_get_total_count called');
            error_log('[AI Chat] Enabled post types: ' . json_encode($enabled_post_types));
            error_log('[AI Chat] Manual selections: ' . json_encode($manual_selections));
        }

        // Calculate total and indexed counts respecting manual selections
        $total = 0;
        $indexed = 0;
        $type_breakdown = array(); // Store per-type breakdown

        foreach ($enabled_post_types as $post_type) {
            // Three states for manual selection:
            // 1. Not set (not in array) → count all posts
            // 2. Set to empty array ([]) → count 0 posts (user explicitly selected 0)
            // 3. Set to array with IDs ([123, 456]) → count those posts

            if (array_key_exists($post_type, $manual_selections)) {
                $selected_ids = is_array($manual_selections[$post_type])
                    ? array_filter(array_map('intval', $manual_selections[$post_type]))
                    : array();

                if (empty($selected_ids)) {
                    continue;
                }

                $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));

                $type_total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts}
                     WHERE ID IN ($placeholders) AND post_status = 'publish'",
                    ...$selected_ids
                ));

                // Count indexed from embeddings/chunks side (fast)
                $direct = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$embeddings_table} e
                     WHERE e.listing_id IN ($placeholders)",
                    ...$selected_ids
                ));

                $via_chunks = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
                     WHERE chunk.post_type = %s
                     AND pm.meta_key = '_chunk_parent_id'
                     AND CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)
                     AND CAST(pm.meta_value AS UNSIGNED) NOT IN (
                         SELECT e2.listing_id FROM {$embeddings_table} e2
                         WHERE e2.listing_id IN ($placeholders)
                     )",
                    ...array_merge(array($chunk_post_type), $selected_ids, $selected_ids)
                ));

                $type_indexed = $direct + $via_chunks;

                $total += $type_total;
                $indexed += $type_indexed;

                $post_type_obj = get_post_type_object($post_type);
                if ($post_type_obj && $type_total > 0) {
                    $type_breakdown[] = array(
                        'label' => $post_type_obj->label,
                        'total' => $type_total,
                        'indexed' => $type_indexed
                    );
                }
            } else {
                // No manual selection — count all published posts
                $type_total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts}
                     WHERE post_type = %s AND post_status = 'publish'",
                    $post_type
                ));

                // Count indexed from embeddings/chunks side (fast)
                $direct = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT e.listing_id)
                     FROM {$embeddings_table} e
                     INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                     WHERE p.post_type = %s AND p.post_status = 'publish'",
                    $post_type
                ));

                $via_chunks = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
                     INNER JOIN {$wpdb->posts} parent ON CAST(pm.meta_value AS UNSIGNED) = parent.ID
                     WHERE chunk.post_type = %s
                     AND pm.meta_key = '_chunk_parent_id'
                     AND parent.post_type = %s
                     AND parent.post_status = 'publish'
                     AND parent.ID NOT IN (
                         SELECT e2.listing_id FROM {$embeddings_table} e2
                     )",
                    $chunk_post_type,
                    $post_type
                ));

                $type_indexed = $direct + $via_chunks;

                $total += $type_total;
                $indexed += $type_indexed;

                $post_type_obj = get_post_type_object($post_type);
                if ($post_type_obj && $type_total > 0) {
                    $type_breakdown[] = array(
                        'label' => $post_type_obj->label,
                        'total' => $type_total,
                        'indexed' => $type_indexed
                    );
                }
            }
        }

        // Get enabled type labels for display
        $type_labels = array();
        foreach ($enabled_post_types as $post_type) {
            $post_type_obj = get_post_type_object($post_type);
            if ($post_type_obj) {
                $type_labels[] = $post_type_obj->label;
            }
        }

        wp_send_json_success(array(
            'total' => (int) $total,
            'indexed' => (int) $indexed,
            'pending' => max(0, (int) $total - (int) $indexed),
            'enabled_types' => $type_labels,
            'enabled_count' => count($enabled_post_types),
            'type_breakdown' => $type_breakdown
        ));
    }

    /**
     * AJAX: Add custom post types to the training-ready list
     */
    public function ajax_add_custom_post_types() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $selected_types = isset($_POST['post_types']) ? (array) $_POST['post_types'] : array();
        $selected_types = array_map('sanitize_text_field', $selected_types);

        if (empty($selected_types)) {
            wp_send_json_error(__('No post types selected', 'ai-chat-wp'));
        }

        // Validate all selected types exist
        foreach ($selected_types as $post_type) {
            if (!post_type_exists($post_type)) {
                wp_send_json_error(sprintf(__('Invalid post type: %s', 'ai-chat-wp'), $post_type));
            }
        }

        // Get current custom types
        $custom_post_types = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_post_types)) {
            $custom_post_types = array();
        }

        // Add selected types (merge and remove duplicates)
        $custom_post_types = array_unique(array_merge($custom_post_types, $selected_types));
        update_option('aicwp_custom_post_types', $custom_post_types);

        // Also automatically enable the newly added types
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());
        if (!is_array($enabled_post_types)) {
            $enabled_post_types = array();
        }
        $enabled_post_types = array_unique(array_merge($enabled_post_types, $selected_types));
        update_option('aicwp_enabled_post_types', $enabled_post_types);

        wp_send_json_success(array(
            'message' => sprintf(
                _n(
                    '%d post type added successfully',
                    '%d post types added successfully',
                    count($selected_types),
                    'ai-chat-wp'
                ),
                count($selected_types)
            ),
            'added_types' => $selected_types,
            'reload_required' => true
        ));
    }

    /**
     * AJAX: Remove custom post type
     */
    public function ajax_remove_custom_post_type() {
        check_ajax_referer('aicwp_universal_settings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }

        $post_type = sanitize_text_field($_POST['post_type']);

        // Validate post type exists
        if (!post_type_exists($post_type)) {
            wp_send_json_error('Invalid post type');
        }

        // Don't allow removing default types
        $default_types = array('post', 'page', 'product');
        if (in_array($post_type, $default_types)) {
            wp_send_json_error(__('Cannot remove default post types', 'ai-chat-wp'));
        }

        // Get current custom types
        $custom_post_types = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_post_types)) {
            $custom_post_types = array();
        }

        // Remove from custom types list
        $custom_post_types = array_diff($custom_post_types, array($post_type));
        update_option('aicwp_custom_post_types', array_values($custom_post_types));

        // Also remove from enabled types
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());
        if (is_array($enabled_post_types)) {
            $enabled_post_types = array_diff($enabled_post_types, array($post_type));
            update_option('aicwp_enabled_post_types', array_values($enabled_post_types));
        }

        // Clear manual selections for this type
        $manual_selections = get_option('aicwp_manual_selections', array());
        if (isset($manual_selections[$post_type])) {
            unset($manual_selections[$post_type]);
            update_option('aicwp_manual_selections', $manual_selections);
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Post type "%s" removed successfully', 'ai-chat-wp'), $post_type),
            'post_type' => $post_type
        ));
    }
}

// Initialize
new AICWP_Universal_Settings();
