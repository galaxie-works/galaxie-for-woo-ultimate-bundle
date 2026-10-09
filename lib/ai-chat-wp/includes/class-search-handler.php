<?php
/**
 * Search Handler Class
 * 
 * Handles AJAX search requests and coordinates between AI and fallback search engines
 * 
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Search_Handler {

    /**
     * AI Search Engine instance
     *
     * @var AICWP_AI_Engine
     */
    private $ai_engine;

    /**
     * API key (deprecated - provider handles this now)
     *
     * @var string
     */
    private $api_key;

    /**
     * Last search notice (e.g., "no embeddings")
     * Static so it can be accessed after the filter runs
     *
     * @var array|null ['notice' => string, 'notice_type' => string]
     */
    private static $last_search_notice = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize AI engine without passing API key (let it use provider settings)
        $this->ai_engine = new AICWP_AI_Engine();

        // Keep for backward compatibility
        $provider = new AICWP_Provider();
        $this->api_key = $provider->get_api_key();
        
        // Register AJAX handlers
        add_action('wp_ajax_aicwp_search', array($this, 'handle_search'));
        add_action('wp_ajax_nopriv_aicwp_search', array($this, 'handle_search'));
        add_action('wp_ajax_aicwp_manage_database', array($this, 'handle_database_action'));
        add_action('wp_ajax_aicwp_view_embedding', array($this, 'handle_view_embedding'));
        add_action('wp_ajax_aicwp_test', array($this, 'handle_test_action'));
        add_action('wp_ajax_aicwp_analytics', array($this, 'handle_analytics_action'));
    }

    /**
     * Handle AJAX search request
     */
    public function handle_search() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'aicwp_nonce')) {
            wp_die('Security check failed');
        }
        
        // Honeypot check - if filled, silently reject
        $honeypot = isset($_POST['ai_search_hp']) ? sanitize_text_field(wp_unslash($_POST['ai_search_hp'])) : '';
        if (!empty($honeypot)) {
            wp_send_json_success(array(
                'listings' => array(),
                'total_found' => 0,
            ));
            return;
        }

        // Enhanced input validation
        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        $limit = max(1, min(50, intval($_POST['limit'] ?? 10))); // Limit between 1-50
        $offset = max(0, intval($_POST['offset'] ?? 0));

        // Post types to search: comma-separated WordPress post types (e.g., 'product,post,page')
        // or 'all' for every post type enabled in admin settings
        $listing_types = 'all';
        if (!empty($_POST['post_types'])) {
            $listing_types = sanitize_text_field($_POST['post_types']);
        }

        // DEBUG: Log the received limit value
        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log('AI CHAT SEARCH DEBUG: Received limit value: ' . $limit . ' (from POST: ' . (isset($_POST['limit']) ? $_POST['limit'] : 'not set') . ')');
        }
        
        // Check if AI search is enabled in admin settings  
        $ai_enabled_in_settings = true; // AI search is always enabled
        $use_ai = filter_var($_POST['use_ai'] ?? true, FILTER_VALIDATE_BOOLEAN) && $ai_enabled_in_settings;
        
        // Validate query length
        if (strlen($query) < 2) {
            wp_send_json_error(array(
                'message' => __('Search query must be at least 2 characters long', 'ai-chat-wp')
            ));
            return;
        }
        
        if (strlen($query) > 500) {
            wp_send_json_error(array(
                'message' => __('Search query is too long (maximum 500 characters)', 'ai-chat-wp')
            ));
            return;
        }
        
        // Get debug mode from admin settings instead of POST data
        $debug = get_option('aicwp_debug_mode', false);
        
        // Debug logging
        if ($debug) {
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress - handle_search called with debug: ' . ($debug ? 'true' : 'false'));
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress - POST data: ' . print_r($_POST, true));
        }
        
        $start_time = microtime(true);
        $debug_info = array();
        
        if ($debug) {
            $debug_info['query'] = $query;
            $debug_info['use_ai'] = $use_ai;
            $debug_info['limit'] = $limit;
            $debug_info['offset'] = $offset;
            $debug_info['listing_types'] = $listing_types;
            $debug_info['start_time'] = date('H:i:s');
            AICWP_Utility_Helper::debug_log('=== AI CHAT SEARCH DEBUG START ===');
            AICWP_Utility_Helper::debug_log('Query: ' . $query);
            AICWP_Utility_Helper::debug_log('Use AI: ' . ($use_ai ? 'YES' : 'NO'));
            AICWP_Utility_Helper::debug_log('API Key configured: ' . (!empty($this->api_key) ? 'YES' : 'NO'));
            AICWP_Utility_Helper::debug_log('Limit: ' . $limit . ', Offset: ' . $offset);
            AICWP_Utility_Helper::debug_log('Listing Types: ' . $listing_types);
            AICWP_Utility_Helper::debug_log('Start Time: ' . date('H:i:s'));
        }
        
        try {
            if ($use_ai && !empty($this->api_key)) {
                // Check short-lived cache - skip API call if same query was just searched
                $cache_key = 'ai_search_rc_' . md5($query);
                $cached = !$debug ? get_transient($cache_key) : false;
                if ($cached !== false && is_array($cached) && !empty($cached['listings'])) {
                    if ($debug) {
                        AICWP_Utility_Helper::debug_log('AI Search: Returning cached results for "' . $query . '"');
                    }
                    $results = $cached;
                } else {
                    if ($debug) {
                        $debug_info['search_mode'] = 'AI Search';
                        $debug_info['api_key_status'] = 'configured';
                    }

                    // Auto-detect batch processing based on embedding count
                    // Threshold: 5000 embeddings, Batch size: 3000 (safe for 256MB PHP)
                    $auto_batch_threshold = 5000;
                    $auto_batch_size = 3000;

                    $total_embeddings = AICWP_Database_Manager::count_embeddings_for_search($listing_types);

                    if ($total_embeddings > $auto_batch_threshold) {
                        if ($debug) {
                            $debug_info['batch_processing'] = 'auto-enabled';
                            $debug_info['total_embeddings'] = $total_embeddings;
                            $debug_info['batch_size'] = $auto_batch_size;
                            AICWP_Utility_Helper::debug_log('SEARCH MODE: Auto-batch enabled (' . $total_embeddings . ' embeddings > ' . $auto_batch_threshold . ' threshold)');
                        }
                        $results = $this->ai_engine->search_with_batching($query, $limit, $offset, $listing_types, $debug, $auto_batch_size);
                    } else {
                        if ($debug) {
                            $debug_info['batch_processing'] = 'disabled (small dataset)';
                            $debug_info['total_embeddings'] = $total_embeddings;
                            AICWP_Utility_Helper::debug_log('SEARCH MODE: Standard processing (' . $total_embeddings . ' embeddings <= ' . $auto_batch_threshold . ' threshold)');
                        }
                        $results = $this->ai_engine->search($query, $limit, $offset, $listing_types, $debug);
                    }
                } // end cache else
            } else {
                if ($debug) {
                    $debug_info['search_mode'] = 'Regular Search';
                    $debug_info['api_key_status'] = empty($this->api_key) ? 'missing' : 'configured';
                    AICWP_Utility_Helper::debug_log('FALLBACK: Using regular search (AI=' . ($use_ai ? 'requested but no API key' : 'not requested') . ')', 'warning');
                }
                $results = AICWP_Fallback_Engine::search($query, $limit, $offset, $listing_types);
            }
            
            // Calculate processing time and log analytics
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            $search_type = ($use_ai && !empty($this->api_key)) ? 'ai' : 'traditional';
            $results_count = isset($results['total_found']) ? $results['total_found'] : count($results['listings']);
            
            // Log search analytics
            AICWP_Analytics::log_search($query, $results_count, $search_type, $processing_time);

            // Cache full results so repeated handle_search() calls can reuse them
            if (!$debug && $search_type === 'ai' && !empty($results['listings'])) {
                set_transient('ai_search_rc_' . md5($query), $results, 15);
            }
            
            if ($debug) {
                $debug_info['processing_time'] = $processing_time . 'ms';
                $debug_info['results_found'] = $results_count;
                
                if (isset($results['debug'])) {
                    $debug_info = array_merge($debug_info, $results['debug']);
                }
                
                $results['debug'] = $debug_info;
            }
            
            wp_send_json_success($results);
            
        } catch (Exception $e) {
            // Log error and fallback to regular search
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress Error: ' . $e->getMessage(), 'error');
            
            if ($debug) {
                $debug_info['error'] = $e->getMessage();
                $debug_info['fallback_triggered'] = true;
                AICWP_Utility_Helper::debug_log('ERROR FALLBACK: ' . $e->getMessage(), 'error');
                AICWP_Utility_Helper::debug_log('FALLBACK: Switching to regular search due to error', 'warning');
            }
            
            $fallback_results = AICWP_Fallback_Engine::search($query, $limit, $offset, $listing_types);
            $fallback_results['is_fallback'] = true;
            $fallback_results['fallback_reason'] = __('AI search temporarily unavailable', 'ai-chat-wp');
            
            if ($debug) {
                $processing_time = round((microtime(true) - $start_time) * 1000, 2);
                $debug_info['processing_time'] = $processing_time . 'ms';
                $debug_info['results_found'] = count($fallback_results['listings']);
                $fallback_results['debug'] = $debug_info;
                AICWP_Utility_Helper::debug_log('FALLBACK RESULTS: Found ' . count($fallback_results['listings']) . ' results via regular search');
                AICWP_Utility_Helper::debug_log('=== AI CHAT SEARCH DEBUG END (WITH ERROR) ===');
            }
            
            wp_send_json_success($fallback_results);
        }
    }
    
    /**
     * Handle database management actions
     */
    public function handle_database_action() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'aicwp_nonce')) {
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Database action nonce verification failed', 'error');
            }
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Database action insufficient permissions', 'error');
            }
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $action = sanitize_text_field($_POST['database_action']);
        AICWP_Utility_Helper::debug_log('Database action requested: ' . $action);

        try {
            $response = array();

            switch ($action) {
                case 'get_stats':
                    $response = AICWP_Database_Manager::get_database_stats();
                    AICWP_Utility_Helper::debug_log('Database stats retrieved successfully');
                    break;
                    
                case 'get_embedding':
                    $listing_id = intval($_POST['listing_id']);
                    if (!$listing_id) {
                        wp_send_json_error('Invalid listing ID');
                        return;
                    }
                    $response = $this->get_embedding_data($listing_id);
                    break;
                    
                case 'generate_single':
                    $listing_id = intval($_POST['listing_id']);
                    if (!$listing_id) {
                        wp_send_json_error('Invalid listing ID');
                        return;
                    }
                    $response = AICWP_Database_Manager::generate_single_embedding($listing_id);
                    break;
                    
                case 'start_regeneration':
                    $batch_size = intval($_POST['batch_size']) ?: 20;
                    $start_offset = intval($_POST['start_offset']) ?: 0;
                    $embedding_manager = new AICWP_Embedding_Manager();
                    $response = $embedding_manager->regenerate_structured_embeddings($batch_size, $start_offset);
                    if (isset($response['error'])) {
                        wp_send_json_error($response['error']);
                        return;
                    }
                    break;
                    
                case 'clear_all':
                    $success = AICWP_Database_Manager::clear_all_embeddings();
                    $response = array('success' => $success);
                    break;

                case 'delete_by_post_type':
                    $post_type = sanitize_text_field($_POST['post_type'] ?? '');
                    if (empty($post_type)) {
                        wp_send_json_error('Post type is required');
                        return;
                    }
                    $response = AICWP_Database_Manager::delete_embeddings_by_post_type($post_type);
                    if (!$response['success']) {
                        wp_send_json_error($response['error']);
                        return;
                    }
                    break;

                case 'delete_single':
                    $listing_id = intval($_POST['listing_id']);
                    if (!$listing_id) {
                        wp_send_json_error('Invalid listing ID');
                        return;
                    }
                    $deleted = AICWP_Database_Manager::delete_embedding($listing_id);
                    $response = array('success' => $deleted, 'listing_id' => $listing_id);
                    break;

                case 'delete_chunks':
                    $parent_id = intval($_POST['parent_id']);
                    if (!$parent_id) {
                        wp_send_json_error('Invalid parent ID');
                        return;
                    }
                    $result = AICWP_Database_Manager::delete_chunks_by_parent($parent_id);
                    $response = $result;
                    break;

                default:
                    if (get_option('aicwp_debug_mode', false)) {
                        AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Invalid database action: ' . $action, 'error');
                    }
                    wp_send_json_error('Invalid action: ' . $action);
                    return;
            }
            
            wp_send_json_success($response);
            
        } catch (Exception $e) {
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Database action error: ' . $e->getMessage(), 'error');
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle view embedding request
     */
    public function handle_view_embedding() {
        // Verify nonce
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'aicwp_nonce')) {
            wp_die('Security check failed');
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $listing_id = intval($_GET['listing_id']);
        
        if (!$listing_id) {
            wp_die('Invalid listing ID');
        }
        
        // Get embedding data
        $embedding_data = AICWP_Database_Manager::get_embedding_by_listing_id($listing_id);
        
        if (!$embedding_data) {
            wp_die('No embedding found for this listing');
        }
        
        // Get listing details
        $post = get_post($listing_id);
        $listing_title = $post ? $post->post_title : 'Unknown Listing';
        
        // Analyze embedding
        $embedding_manager = new AICWP_Embedding_Manager();
        $analysis = $embedding_manager->analyze_embedding($embedding_data['embedding']);
        
        // Output embedding details
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>Embedding Details: <?php echo esc_html($listing_title); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
                .header { border-bottom: 2px solid #ccc; padding-bottom: 10px; margin-bottom: 20px; }
                .section { margin-bottom: 30px; padding: 15px; background: #f9f9f9; border-radius: 5px; }
                .embedding-preview { max-height: 200px; overflow-y: auto; background: white; padding: 10px; border: 1px solid #ddd; }
                .stat { display: inline-block; margin: 5px 15px 5px 0; }
                .health-good { color: green; font-weight: bold; }
                .health-poor { color: red; font-weight: bold; }
                .health-unusual { color: orange; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Embedding Details</h1>
                <h2><?php echo esc_html($listing_title); ?> (ID: <?php echo $listing_id; ?>)</h2>
            </div>
            
            <div class="section">
                <h3>Embedding Analysis</h3>
                <div class="stat">Dimensions: <strong><?php echo $analysis['dimensions']; ?></strong></div>
                <div class="stat">Mean: <strong><?php echo round($analysis['mean'], 6); ?></strong></div>
                <div class="stat">Min: <strong><?php echo round($analysis['min'], 6); ?></strong></div>
                <div class="stat">Max: <strong><?php echo round($analysis['max'], 6); ?></strong></div>
                <div class="stat">Std Dev: <strong><?php echo round($analysis['std_dev'], 6); ?></strong></div>
                <div class="stat">Zero %: <strong><?php echo round($analysis['zero_percentage'], 2); ?>%</strong></div>
                <div class="stat">Health: <strong class="health-<?php echo str_replace(array(' ', '-'), array('-', '-'), $analysis['health_status']); ?>"><?php echo esc_html($analysis['health_status']); ?></strong></div>
            </div>
            
            <div class="section">
                <h3>Embedding Vector (First 50 values)</h3>
                <div class="embedding-preview">
                    <?php
                    $preview_data = array_slice($embedding_data['embedding'], 0, 50);
                    foreach ($preview_data as $i => $value) {
                        echo sprintf("% 8.6f", $value);
                        if (($i + 1) % 10 === 0) echo "<br>";
                        else echo " ";
                    }
                    ?>
                </div>
            </div>
            
            <div class="section">
                <h3>Database Info</h3>
                <div class="stat">Created: <strong><?php echo esc_html($embedding_data['created_at']); ?></strong></div>
                <div class="stat">Updated: <strong><?php echo esc_html($embedding_data['updated_at']); ?></strong></div>
                <div class="stat">Content Hash: <strong><?php echo esc_html($embedding_data['content_hash']); ?></strong></div>
            </div>
            
            <div class="section">
                <h3>Source Content</h3>
                <div style="background: white; padding: 10px; border: 1px solid #ddd; white-space: pre-line;">
                    <?php 
                    $embedding_manager = new AICWP_Embedding_Manager();
                    echo esc_html($embedding_manager->get_listing_content_for_embedding($listing_id)); 
                    ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    /**
     * Handle test AJAX action for debugging
     */
    public function handle_test_action() {
        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Test action called');
        }
        wp_send_json_success(array(
            'message' => 'AJAX is working correctly!',
            'timestamp' => current_time('mysql'),
            'url' => get_admin_url(get_current_blog_id(), 'admin-ajax.php')
        ));
    }
    
    /**
     * Get embedding data for a specific listing
     * 
     * @param int $listing_id Listing ID
     * @return array Embedding data
     */
    private function get_embedding_data($listing_id) {
        try {
            global $wpdb;

            $table_name = AICWP_Database_Manager::get_embeddings_table_name();

            // Get post data
            $post = get_post($listing_id);
            if (!$post) {
                return array('error' => 'Listing not found');
            }

            // Check if this post is a content chunk
            $is_chunk = AICWP_Content_Chunker::is_chunk($listing_id);
            $parent_id = $is_chunk ? AICWP_Content_Chunker::get_chunk_parent_id($listing_id) : null;

            // Check if this post has chunks (for non-chunk posts)
            $chunk_count = $is_chunk ? 0 : AICWP_Content_Chunker::get_chunk_count($listing_id);
            $has_chunks = $chunk_count > 0;

            // Get embedding data for this specific post
            $embedding_row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE listing_id = %d",
                $listing_id
            ), ARRAY_A);

            $result = array(
                'listing_id' => $listing_id,
                'title' => $post->post_title,
                'post_type' => $post->post_type,
                'embedding_exists' => !empty($embedding_row),
                'is_chunk' => $is_chunk,
                'has_chunks' => $has_chunks,
                'chunk_count' => $chunk_count
            );

            // If this is a chunk, add parent info
            if ($is_chunk && $parent_id) {
                $parent = get_post($parent_id);
                if ($parent) {
                    $result['parent_id'] = $parent_id;
                    $result['parent_title'] = $parent->post_title;
                    $result['chunk_number'] = get_post_meta($listing_id, '_chunk_number', true);
                    $result['chunk_total'] = get_post_meta($listing_id, '_chunk_total', true);
                }
            }

            // If this post has chunks, get chunk embedding info
            if ($has_chunks) {
                $result['chunks'] = AICWP_Content_Chunker::get_chunk_embedding_info($listing_id);
            }

            if ($embedding_row) {
                try {
                    // Get processed content
                    $embedding_manager = new AICWP_Embedding_Manager();
                    $processed_content = $embedding_manager->get_listing_content_for_embedding($listing_id);

                    // Get embedding vector preview (first 10 dimensions)
                    $embedding_vector = null;
                    $embedding_preview = array();
                    $vector_dimensions = 0;

                    if (!empty($embedding_row['embedding'])) {
                        $embedding_vector = AICWP_Database_Manager::decompress_embedding_from_storage($embedding_row['embedding']);
                        if (is_array($embedding_vector) && count($embedding_vector) > 0) {
                            $embedding_preview = array_slice($embedding_vector, 0, 10);
                            $vector_dimensions = count($embedding_vector);
                        }
                    }

                    // Calculate word and character count
                    $word_count = str_word_count($processed_content);
                    $character_count = mb_strlen($processed_content);

                    $result = array_merge($result, array(
                        'created_at' => $embedding_row['created_at'],
                        'processed_content' => $processed_content,
                        'word_count' => $word_count,
                        'character_count' => $character_count,
                        'embedding_preview' => $embedding_preview,
                        'vector_dimensions' => $vector_dimensions
                    ));
                } catch (Exception $e) {
                    AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Error processing embedding data: ' . $e->getMessage(), 'error');
                    $result['error'] = 'Error processing embedding data: ' . $e->getMessage();
                }
            }

            return $result;

        } catch (Exception $e) {
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Error in get_embedding_data: ' . $e->getMessage(), 'error');
            return array('error' => 'Database error: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle analytics management actions
     */
    public function handle_analytics_action() {
        // Verify nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'aicwp_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        $action = sanitize_text_field($_POST['analytics_action']);
        
        try {
            switch ($action) {
                case 'clear_analytics':
                    AICWP_Analytics::clear_analytics();
                    wp_send_json_success('Analytics data cleared successfully');
                    break;
                    
                default:
                    wp_send_json_error('Unknown analytics action: ' . $action);
                    break;
            }
        } catch (Exception $e) {
            AICWP_Utility_Helper::debug_log('AI Chat for WordPress - Analytics action error: ' . $e->getMessage(), 'error');
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
}
