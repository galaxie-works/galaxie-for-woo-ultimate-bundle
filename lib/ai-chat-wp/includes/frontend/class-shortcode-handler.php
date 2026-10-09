<?php

/**
 * Shortcode Handler Class
 * 
 * Handles frontend shortcode rendering
 * 
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Shortcode_Handler
{

    /**
     * Constructor
     */
    public function __construct()
    {
        // Register shortcodes
        add_shortcode('aicwp_search', array($this, 'search_shortcode'));

        // Universal AI search field shortcode (inline results, no quick picks button)
        add_shortcode('ai_search_field', array($this, 'ai_search_field_shortcode'));
    }

    /**
     * Search shortcode handler
     * 
     * @param array $atts Shortcode attributes
     * @return string Shortcode output
     */
    public function search_shortcode($atts)
    {
        // Get max results from admin settings
        $max_results_setting = get_option('aicwp_max_results', 10);

        $atts = shortcode_atts(array(
            'placeholder' => __('Search anything, just ask!', 'ai-chat-wp'),
            'button_text' => __('AI Quick Picks', 'ai-chat-wp'),
            'value' => '',
            'show_toggle' => 'true',
            'limit' => $max_results_setting,
            'post_types' => 'all', // Comma-separated post types (e.g. post,page,product) or 'all' enabled types
            'button_action' => 'quick_picks' // quick_picks, popular_searches, disable
        ), $atts);

        // Get debug mode from admin settings
        $debug_mode = get_option('aicwp_debug_mode', false);

        ob_start();
?>
        <div class="ai-chat-wp-container" data-limit="<?php echo esc_attr($atts['limit']); ?>" data-types="<?php echo esc_attr($atts['post_types']); ?>" data-debug="<?php echo $debug_mode ? 'true' : 'false'; ?>" data-button-action="<?php echo esc_attr($atts['button_action']); ?>">
            <!-- Modern Search Bar -->
            <div class="ai-search-form-wrapper">
                <div class="search-input-wrapper">
                    <div class="search-input-icon">
                        <span class="search-stars">✨</span>
                    </div>
                    <input
                        type="text"
                        name="ai_search_input"
                        placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                        value="<?php echo esc_attr($atts['value']); ?>"
                        class="ai-search-input">
                    <input type="text" name="ai_search_hp" tabindex="-1" autocomplete="off">
                    <?php
                    // Debugging line to check the button text
                    if ($atts['button_action'] === 'quick_picks'): ?>
                        <div class="ai-btn-container">
                            <button type="button" class="ai-search-button" data-action="<?php echo esc_attr($atts['button_action']); ?>">
                                <?php
                                // Replace thunder emoji with FontAwesome icon
                                $button_text = $atts['button_text'];

                                // Remove thunder emoji if present and extract just the text
                                $text = preg_replace('/^⚡\s*/', '', $button_text);

                                ?>
                                <i class="fa fa-bolt ai-button-icon"></i>
                                <span class="button-text"><?php echo esc_html($text); ?></span>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- AI Processing Status -->
                <div class="ai-processing-status">
                    <div class="processing-step active">
                        <div class="step-icon"><i class="fa fa-magic"></i></div>
                        <div class="step-text"><?php _e('AI analyzing your search query...', 'ai-chat-wp'); ?></div>
                    </div>
                </div>

                <?php
                // Check if search suggestions are enabled AND button is not fully disabled
                $suggestions_enabled = get_option('aicwp_suggestions_enabled', true);
                if ($suggestions_enabled && $atts['button_action'] !== 'disable'):
                    $suggestions_source = get_option('aicwp_suggestions_source', 'top_searches');
                    $suggestions = array();

                    if ($suggestions_source === 'custom') {
                        // Use custom suggestions
                        $custom_suggestions = get_option('aicwp_custom_suggestions', '');
                        if (!empty($custom_suggestions)) {
                            $suggestions = array_map('trim', explode(',', $custom_suggestions));
                            $suggestions = array_filter($suggestions); // Remove empty items
                        }
                    } elseif ($suggestions_source === 'top_searches_10') {
                        // Use top 10 searches (no fallback - only show if we have real data)
                        $suggestions = $this->get_top_search_suggestions(10);
                    } else {
                        // Use top 5 searches (default - no fallback - only show if we have real data)
                        $suggestions = $this->get_top_search_suggestions(5);
                    }

                    if (!empty($suggestions)):
                ?>
                        <div class="search-suggestions" data-behavior="<?php echo esc_attr($atts['button_action']); ?>">
                            <h4><?php _e('Popular searches', 'ai-chat-wp'); ?></h4>
                            <div class="suggestion-tags">
                                <?php foreach ($suggestions as $suggestion): ?>
                                    <span class="suggestion-tag"><?php echo esc_html($suggestion); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                <?php
                    endif;
                endif;
                ?>
            </div>

            <div class="search-results-container" style="display: none;">
                <div class="ai-results-header">
                    <div class="ai-header-gradient">
                        <div class="ai-header-content">
                            <h3 class="ai-header-title"><?php _e('AI Top Picks', 'ai-chat-wp'); ?></h3>
                            <div class="ai-header-subtitle">
                                <span class="results-count-text"><?php printf(__('Top %d results matching', 'ai-chat-wp'), 0); ?></span>
                                <span class="query-highlight"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="results-list-ai"></div>
            </div>

            <div class="no-results" style="display: none;">
                <h3><?php _e('No results found', 'ai-chat-wp'); ?></h3>
                <p><?php _e('Try adjusting your search terms or using different keywords.', 'ai-chat-wp'); ?></p>
            </div>
        </div>
<?php
        return ob_get_clean();
    }

    /**
     * Get top search suggestions from analytics
     * 
     * @param int $limit Number of suggestions to return (default: 5)
     * @return array Top search queries
     */
    private function get_top_search_suggestions($limit = 5)
    {
        // Check if analytics are enabled
        if (!get_option('aicwp_enable_analytics', false)) {
            return array();
        }

        // Get recent search logs (last 7 days)
        $logs = get_option('aicwp_logs', array());
        $cutoff_time = current_time('timestamp') - (7 * DAY_IN_SECONDS);

        // Filter logs to last 7 days
        $recent_logs = array_filter($logs, function ($log) use ($cutoff_time) {
            return isset($log['timestamp']) && $log['timestamp'] > $cutoff_time;
        });

        if (empty($recent_logs)) {
            return array();
        }

        // Count query frequency
        $query_counts = array();
        foreach ($recent_logs as $log) {
            $query = trim($log['query']);
            if (strlen($query) > 2) { // Ignore very short queries
                $query_counts[$query] = ($query_counts[$query] ?? 0) + 1;
            }
        }

        // Sort by frequency and return top results based on limit
        arsort($query_counts);
        $top_queries = array_keys(array_slice($query_counts, 0, $limit, true));

        return $top_queries;
    }

    /**
     * Universal AI Search Field shortcode handler
     * Reuses the [aicwp_search] template but validates post_types against enabled types
     * No quick picks button - just search on Enter key
     *
     * @param array $atts Shortcode attributes
     * @return string Shortcode output
     */
    public function ai_search_field_shortcode($atts)
    {
        // Get default settings
        $max_results_setting = get_option('aicwp_max_results', 10);
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());

        // Default to all enabled post types if none specified
        $default_post_types = !empty($enabled_post_types) ? implode(',', $enabled_post_types) : '';

        $atts = shortcode_atts(array(
            'placeholder' => __('Search anything...', 'ai-chat-wp'),
            'post_types' => $default_post_types, // Comma-separated post types: product,post,page
            'limit' => $max_results_setting,
        ), $atts, 'ai_search_field');

        // Validate post_types - only allow enabled post types
        $requested_types = array_map('trim', explode(',', $atts['post_types']));
        $valid_types = array_intersect($requested_types, $enabled_post_types);

        // If no valid types remain, show error
        if (empty($valid_types)) {
            return '<div class="ai-search-field-error" style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; margin: 10px 0;">
                <strong>' . esc_html__('AI Search Configuration Error:', 'ai-chat-wp') . '</strong>
                <p style="margin: 5px 0 0;">' . esc_html__('No valid content types specified or content not trained yet. Please check your shortcode settings.', 'ai-chat-wp') . '</p>
            </div>';
        }

        // Build post types string for data attribute
        $post_types_data = implode(',', $valid_types);

        // Get debug mode from admin settings
        $debug_mode = get_option('aicwp_debug_mode', false);

        // Use the SAME HTML structure as the [aicwp_search] shortcode
        // data-button-action="disable" prevents redirect and removes quick picks button
        // data-universal="true" tells JS this is the universal shortcode (no redirect on Enter)
        ob_start();
?>
        <div class="ai-chat-wp-container" data-limit="<?php echo esc_attr($atts['limit']); ?>" data-types="<?php echo esc_attr($post_types_data); ?>" data-debug="<?php echo $debug_mode ? 'true' : 'false'; ?>" data-button-action="disable" data-universal="true">
            <!-- Modern Search Bar -->
            <div class="ai-search-form-wrapper">
                <div class="search-input-wrapper">
                    <div class="search-input-icon">
                        <span class="search-stars">✨</span>
                    </div>
                    <input
                        type="text"
                        name="ai_search_input"
                        placeholder="<?php echo esc_attr($atts['placeholder']); ?>"
                        class="ai-search-input">
                    <input type="text" name="ai_search_hp" tabindex="-1" autocomplete="off">
                    <button type="button" class="ai-search-submit-btn"><i class="fa fa-search"></i></button>
                </div>

                <!-- AI Processing Status -->
                <div class="ai-processing-status">
                    <div class="processing-step active">
                        <div class="step-icon"><i class="fa fa-magic"></i></div>
                        <div class="step-text"><?php _e('AI analyzing your search query...', 'ai-chat-wp'); ?></div>
                    </div>
                </div>
            </div>

            <div class="search-results-container" style="display: none;">
                <div class="ai-results-header">
                    <div class="ai-header-gradient">
                        <div class="ai-header-content">
                            <h3 class="ai-header-title"><?php _e('AI Top Picks', 'ai-chat-wp'); ?></h3>
                            <div class="ai-header-subtitle">
                                <span class="results-count-text"><?php printf(__('Top %d results matching', 'ai-chat-wp'), 0); ?></span>
                                <span class="query-highlight"></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="results-list-ai"></div>
            </div>

            <div class="no-results" style="display: none;">
                <h3><?php _e('No results found', 'ai-chat-wp'); ?></h3>
                <p><?php _e('Try adjusting your search terms or using different keywords.', 'ai-chat-wp'); ?></p>
            </div>
        </div>
<?php
        return ob_get_clean();
    }
}
