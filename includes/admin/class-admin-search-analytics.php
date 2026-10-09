<?php
/**
 * Admin Search Analytics Handler
 *
 * Handles search analytics rendering and AJAX operations for the admin dashboard.
 *
 * @package AICWP
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class AICWP_Admin_Search_Analytics
 *
 * Manages search analytics display and operations in the admin area.
 */
class AICWP_Admin_Search_Analytics {

    /**
     * Constructor - Register AJAX handlers
     */
    public function __construct() {
        add_action('wp_ajax_aicwp_export_analytics_csv', array($this, 'ajax_export_csv'));
    }

    /**
     * Render the complete search analytics section
     */
    public function render_section() {
        ?>
        <!-- Search Analytics Section -->
        <?php if (get_option('aicwp_enable_analytics') && class_exists('AICWP_Analytics')): ?>
            <?php $this->render_enabled_section(); ?>
        <?php else: ?>
            <?php $this->render_disabled_section(); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Render section when analytics is enabled
     */
    private function render_enabled_section() {
        $analytics_7d = AICWP_Analytics::get_analytics(7);
        $analytics_30d = AICWP_Analytics::get_analytics(30);
        ?>
        <div class="aicwp-ui-card aicwp-ui-card-full-width aicwp-ui-card-toggleable" data-toggle-id="stats-popular-queries">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-sky">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 21-4.34-4.34"></path><circle cx="11" cy="11" r="8"></circle></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e('Popular Search Queries', 'ai-chat-wp'); ?></h3>
                    <p><?php _e('Analytics of the keywords used by AI to provide responses to users.', 'ai-chat-wp'); ?></p>
                </div>
                <span class="dashicons dashicons-arrow-down-alt2 aicwp-ui-card-toggle-icon"></span>
            </div>
            <div class="aicwp-ui-card-body">
                <?php $this->render_stats_boxes($analytics_7d, $analytics_30d); ?>
                <?php $this->render_query_tags($analytics_7d, $analytics_30d); ?>
                <?php $this->render_actions(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render stats boxes
     *
     * @param array $analytics_7d Analytics for last 7 days
     * @param array $analytics_30d Analytics for last 30 days
     */
    private function render_stats_boxes($analytics_7d, $analytics_30d) {
        ?>
        <!-- Statistics Boxes -->
        <div class="aicwp-ui-stats-boxes aicwp-ui-stats-boxes-two-cols">
            <!-- 7 Days Total Searches -->
            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-green">
                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-green">
                    <?php echo $analytics_7d['total_searches']; ?>
                </div>
                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-green">
                    <?php _e('Total Searches in Last 7 Days', 'ai-chat-wp'); ?>
                </div>
            </div>

            <!-- 30 Days Total Searches -->
            <div class="aicwp-ui-stat-box aicwp-ui-stat-box-blue">
                <div class="aicwp-ui-stat-number aicwp-ui-stat-number-blue">
                    <?php echo $analytics_30d['total_searches']; ?>
                </div>
                <div class="aicwp-ui-stat-label aicwp-ui-stat-label-blue">
                    <?php _e('Total Searches in Last 30 Days', 'ai-chat-wp'); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render popular search query tags
     *
     * @param array $analytics_7d Analytics for last 7 days
     * @param array $analytics_30d Analytics for last 30 days
     */
    private function render_query_tags($analytics_7d, $analytics_30d) {
        ?>
        <!-- Popular Search Queries - 7 Days -->
        <div class="aicwp-ui-queries-box aicwp-ui-queries-box-green">
            <h3><?php _e('Last 7 Days (Top 50 Searches)', 'ai-chat-wp'); ?></h3>
            <?php if (!empty($analytics_7d['popular_queries'])): ?>
                <div class="aicwp-ui-query-tags">
                    <?php foreach ($analytics_7d['popular_queries'] as $query => $count): ?>
                        <span class="aicwp-ui-query-tag-green">
                            <strong><?php echo esc_html($query); ?></strong> (<?php echo $count; ?>x)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p><em><?php _e('No search queries recorded yet for the last 7 days.', 'ai-chat-wp'); ?></em></p>
            <?php endif; ?>
        </div>

        <!-- Popular Search Queries - 30 Days -->
        <div class="aicwp-ui-queries-box aicwp-ui-queries-box-blue">
            <h3><?php _e('Last 30 Days (Top 50 Searches)', 'ai-chat-wp'); ?></h3>
            <?php if (!empty($analytics_30d['popular_queries'])): ?>
                <div class="aicwp-ui-query-tags">
                    <?php foreach ($analytics_30d['popular_queries'] as $query => $count): ?>
                        <span class="aicwp-ui-query-tag-blue">
                            <strong><?php echo esc_html($query); ?></strong> (<?php echo $count; ?>x)
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p><em><?php _e('No search queries recorded yet for the last 30 days.', 'ai-chat-wp'); ?></em></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render analytics action buttons
     */
    private function render_actions() {
        ?>
        <!-- Analytics Actions -->
        <div>
            <div class="aicwp-ui-form-actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo esc_url(admin_url('admin-ajax.php?action=aicwp_export_analytics_csv&nonce=' . wp_create_nonce('aicwp_nonce'))); ?>" class="aicwp-ui-button aicwp-ui-button-secondary">
                    <span class="dashicons dashicons-download" style="margin-top: 3px; margin-right: 3px;"></span>
                    <?php _e('Export All Queries to CSV', 'ai-chat-wp'); ?>
                </a>
                <button type="button" id="clear-analytics" class="aicwp-ui-button aicwp-ui-button-danger" onclick="return confirm('Are you sure? This will delete all analytics data.');">
                    <?php _e('Clear Analytics Data', 'ai-chat-wp'); ?>
                </button>
            </div>
            <div class="aicwp-ui-help-text"><?php _e('Analytics data is automatically cleaned up after 10.000 entries to prevent database bloat.', 'ai-chat-wp'); ?></div>
            <div class="aicwp-ui-form-group" style="margin-top: 15px; margin-bottom: 5px;">
                <label class="aicwp-ui-checkbox-label">
                    <input type="checkbox" id="toggle-search-analytics" value="1" <?php checked(get_option('aicwp_enable_analytics'), 1); ?>>
                    <span class="aicwp-ui-checkbox-custom"></span>
                    <span class="aicwp-ui-checkbox-text" style="font-weight: 500;"><?php _e('Enable Search Analytics Tracking', 'ai-chat-wp'); ?></span>
                </label>
                <script>
                jQuery(function($){
                    $('#toggle-search-analytics').on('change', function(){
                        var $cb = $(this),
                            $custom = $cb.next('.aicwp-ui-checkbox-custom'),
                            $spinner = $('<span class="aicwp-ui-spinner aicwp-ui-spinner--small" style="margin-left:0;top:4px"></span>');
                        $custom.hide().after($spinner);
                        AicwpAdmin.ajax({
                            action: 'aicwp_toggle_search_analytics',
                            data: { enabled: $cb.is(':checked') },
                            success: function(r){ if(r.success) location.reload(); },
                            error: function(){ $cb.prop('checked', !$cb.is(':checked')); },
                            complete: function(){ $spinner.remove(); $custom.show(); }
                        });
                    });
                });
                </script>
            </div>
        </div>
        <?php
    }

    /**
     * Render section when analytics is disabled
     */
    private function render_disabled_section() {
        ?>
        <div class="aicwp-ui-card aicwp-ui-card-full-width aicwp-ui-card-toggleable" data-toggle-id="stats-popular-queries">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-sky">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 21-4.34-4.34"></path><circle cx="11" cy="11" r="8"></circle></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e('Popular Search Queries', 'ai-chat-wp'); ?></h3>
                    <p><?php _e('Analytics of the keywords used by AI to provide responses to users.', 'ai-chat-wp'); ?></p>
                </div>
                <span class="dashicons dashicons-arrow-down-alt2 aicwp-ui-card-toggle-icon"></span>
            </div>
            <div class="aicwp-ui-card-body">
                <div style="background: #f0f0f0; padding: 40px 20px; border-radius: 5px; text-align: center;">
                    <h3><?php _e('Search Analytics Disabled', 'ai-chat-wp'); ?></h3>
                    <p><?php _e('Enable search analytics to track search patterns and performance.', 'ai-chat-wp'); ?></p>
                    <div class="aicwp-ui-form-group" style="display: inline-block; margin-top: 10px;">
                        <label class="aicwp-ui-checkbox-label">
                            <input type="checkbox" id="toggle-search-analytics-disabled" value="1">
                            <span class="aicwp-ui-checkbox-custom"></span>
                            <span class="aicwp-ui-checkbox-text" style="font-weight: 500;"><?php _e('Enable Search Analytics Tracking', 'ai-chat-wp'); ?></span>
                        </label>
                        <script>
                        jQuery(function($){
                            $('#toggle-search-analytics-disabled').on('change', function(){
                                var $cb = $(this),
                                    $custom = $cb.next('.aicwp-ui-checkbox-custom'),
                                    $spinner = $('<span class="aicwp-ui-spinner aicwp-ui-spinner--small" style="margin-left:0;top:4px"></span>');
                                $custom.hide().after($spinner);
                                AicwpAdmin.ajax({
                                    action: 'aicwp_toggle_search_analytics',
                                    data: { enabled: $cb.is(':checked') },
                                    success: function(r){ if(r.success) location.reload(); },
                                    error: function(){ $cb.prop('checked', !$cb.is(':checked')); },
                                    complete: function(){ $spinner.remove(); $custom.show(); }
                                });
                            });
                        });
                        </script>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for exporting analytics as CSV
     */
    public function ajax_export_csv() {
        // Verify nonce
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'aicwp_nonce')) {
            wp_die(__('Security check failed.', 'ai-chat-wp'));
        }

        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'ai-chat-wp'));
        }

        if (!class_exists('AICWP_Analytics')) {
            wp_die(__('Analytics class not found.', 'ai-chat-wp'));
        }

        // Get optional days parameter
        $days = isset($_GET['days']) ? intval($_GET['days']) : null;

        // Export CSV (this method outputs directly and exits)
        AICWP_Analytics::export_popular_queries_csv($days);
        exit;
    }
}
