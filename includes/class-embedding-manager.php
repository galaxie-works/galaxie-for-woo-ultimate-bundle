<?php
/**
 * Embedding Manager Class
 *
 * Handles AI embedding generation and management (OpenAI/Gemini)
 *
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Embedding_Manager {

    /**
     * API key (deprecated - use provider instead)
     *
     * @var string
     */
    private $api_key;

    /**
     * AI Provider instance
     *
     * @var AICWP_Provider
     */
    private $provider;

    /**
     * Constructor
     *
     * @param string $api_key Deprecated - API key (kept for backward compatibility)
     */
    public function __construct($api_key = '') {
        // Backward compatibility: if API key is provided, assume OpenAI
        if ($api_key) {
            $this->api_key = $api_key;
            $this->provider = new AICWP_Provider('openai', $api_key);
        } else {
            // Use configured provider from settings
            $this->provider = new AICWP_Provider();
            $this->api_key = $this->provider->get_api_key();
        }
    }
    
    /**
     * Atomically acquire a rate limit slot before making an API call.
     *
     * Uses conditional SQL UPDATE so check + increment happen in one
     * DB operation. No TOCTOU gap, no lost increments under burst traffic.
     *
     * @return bool True if slot acquired (caller may proceed with API call)
     */
    public static function try_acquire_rate_limit() {
        global $wpdb;
        $rate_limit_key = 'aicwp_rate_limit_' . date('Y-m-d-H');
        $max_calls = (int) get_option('aicwp_rate_limit_per_hour', 100);

        // Attempt atomic increment: only succeeds if current count < limit
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = CAST(option_value AS UNSIGNED) + 1
             WHERE option_name = %s
               AND CAST(option_value AS UNSIGNED) < %d",
            $rate_limit_key,
            $max_calls
        ));

        if ($rows === 1) {
            wp_cache_delete($rate_limit_key, 'options');
            return true;
        }

        // Either option doesn't exist yet, or limit is reached
        $current = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $rate_limit_key
        ));

        if ($current > 0) {
            // Option exists but limit is reached
            return false;
        }

        // First call this hour - bootstrap the counter
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, '1', 'no')
             ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1",
            $rate_limit_key
        ));

        wp_cache_delete($rate_limit_key, 'options');

        if ($inserted === false) {
            error_log('AI Chat for WordPress: Rate limit bootstrap failed');
            return true; // fail open
        }

        // Re-check after insert (another request may have won the race and hit the limit)
        $val = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $rate_limit_key
        ));

        return $val <= $max_calls;
    }

    /**
     * Read-only rate limit check for UI / preflight guards.
     * Does NOT reserve quota. Use try_acquire_rate_limit() before API calls.
     *
     * @return bool True if under limit
     */
    public static function check_rate_limit() {
        $rate_limit_key = 'aicwp_rate_limit_' . date('Y-m-d-H');
        $current_calls = (int) get_option($rate_limit_key, 0);
        $max_calls_per_hour = get_option('aicwp_rate_limit_per_hour', 100);

        return $current_calls < $max_calls_per_hour;
    }
    
    /**
     * Generate AI embedding for text (OpenAI/Gemini)
     *
     * @param string $text Text to generate embedding for
     * @param bool $skip_rate_limit Whether to skip rate limiting check (for batch processing)
     * @return array|false Embedding array or false on failure
     * @throws Exception On API errors
     */
    public function generate_embedding($text, $skip_rate_limit = false) {
        if (empty($this->api_key)) {
            return false;
        }

        // Atomically acquire rate limit slot before API call (unless skip is explicitly requested for batch operations)
        if (!$skip_rate_limit && !self::try_acquire_rate_limit()) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        // Get provider-specific configuration
        $endpoint = $this->provider->get_endpoint('embeddings');
        $headers = $this->provider->get_headers();

        // Sanitize text to ensure valid UTF-8 encoding (prevents json_encode failures)
        $sanitized_text = self::sanitize_utf8($text);
        $payload = $this->provider->prepare_embedding_payload($sanitized_text);

        // Encode payload to JSON with error handling
        $json_body = json_encode($payload);
        if ($json_body === false) {
            $json_error = json_last_error_msg();
            error_log("AI Chat for WordPress: JSON encoding failed - " . $json_error);
            throw new Exception('Failed to encode content for API request: ' . $json_error . '. The content may contain invalid characters.');
        }

        // DEBUG: Log model being sent
        if (get_option('aicwp_debug_mode', false)) {
            error_log(sprintf(
                "[AI Search Embedding] Provider: %s | Endpoint: %s | Model: %s | Dimensions: %d",
                $this->provider->get_provider(),
                $endpoint,
                isset($payload['model']) ? $payload['model'] : 'N/A',
                $this->provider->get_embedding_dimensions()
            ));
        }

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => $json_body,
            'timeout' => 60, // Increased from 30 to 60 seconds for more reliability
        ));

        if (is_wp_error($response)) {
            $provider_name = $this->provider->get_provider_name();
            $error_msg = $provider_name . ' API request failed: ' . $response->get_error_message();
            $error_code = $response->get_error_code();

            // Always log critical API errors with more context
            error_log("CRITICAL: Embedding API Error [Code: {$error_code}]: " . $error_msg);

            // Log the full WP_Error object for debugging
            if (get_option('aicwp_debug_mode', false)) {
                error_log("Full WP_Error: " . print_r($response, true));
            }

            throw new Exception($error_msg);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Log non-200 response codes
        if ($response_code !== 200) {
            error_log(sprintf(
                "AI Chat for WordPress: Non-200 response code: %d, Body: %s",
                $response_code,
                substr(wp_remote_retrieve_body($response), 0, 500)
            ));
        }

        // Only log successful responses in debug mode
        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log(
                sprintf("Embedding API Response - Code: %d, Provider: %s | Model: %s | Dimensions: %d",
                    $response_code,
                    $this->provider->get_provider_name(),
                    $this->provider->get_embedding_model(),
                    $this->provider->get_embedding_dimensions()),
                'info'
            );
        }

        if (isset($body['error'])) {
            $provider_name = $this->provider->get_provider_name();
            $error_message = is_array($body['error']) ? ($body['error']['message'] ?? 'Unknown error') : $body['error'];
            $full_error = $provider_name . ' API error: ' . $error_message;

            // Always log critical API errors
            error_log("CRITICAL: Embedding API Error Response: " . $full_error);

            // Detailed response body only in debug mode
            AICWP_Utility_Helper::debug_log(
                "Full response body: " . print_r($body, true),
                'error'
            );

            throw new Exception($full_error);
        }

        // Rate limit already acquired atomically before the API call

        return $this->provider->parse_embedding_response($body);
    }
    
    /**
     * Get content formatted for embedding generation using modular extractor system
     *
     * @param int $post_id Post ID (any post type)
     * @return string Structured content for embedding
     */
    public function get_content_for_embedding($post_id) {
        return AICWP_Content_Extractor_Factory::extract_content($post_id);
    }

    /**
     * Get listing content formatted for embedding generation using structured data approach
     *
     * @deprecated 2.0.0 Use get_content_for_embedding() instead
     * @param int $listing_id Listing post ID
     * @return string Structured content for embedding
     */
    public function get_listing_content_for_embedding($listing_id) {
        // Backward compatibility wrapper
        return $this->get_content_for_embedding($listing_id);
    }

    /**
     * Regenerate all embeddings with improved structured format
     *
     * @param int $batch_size Number of posts to process per batch
     * @param int $start_offset Offset to start from (for resuming)
     * @param array $post_types Post types to process (default: all supported types)
     * @return array Status information
     */
    public function regenerate_structured_embeddings($batch_size = 20, $start_offset = 0, $post_types = array()) {
        global $wpdb;

        // CRITICAL: Allow unlimited execution time for long-running batch operations
        // This prevents PHP timeout errors when processing large batches
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        // Continue processing even if user closes browser
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        // Log start of batch processing
        if (get_option('aicwp_debug_mode', false)) {
            error_log(sprintf(
                'AI Chat for WordPress: Starting batch regeneration - batch_size: %d, offset: %d',
                $batch_size,
                $start_offset
            ));
        }

        if (empty($this->api_key)) {
            return array('error' => 'OpenAI API key not configured');
        }

        // Use enabled post types from database settings instead of hardcoded defaults
        if (empty($post_types)) {
            $post_types = AICWP_Database_Manager::get_enabled_post_types();
        }

        // If no post types are enabled, return early
        if (empty($post_types)) {
            return array(
                'status' => 'complete',
                'message' => 'No post types enabled for embedding generation',
                'processed' => 0,
                'total_posts' => 0,
                'next_offset' => 0
            );
        }

        // Check for manual selections to respect user's specific post choices
        $manual_selections = get_option('aicwp_manual_selections', array());
        $all_post_ids = array();

        // Process each post type individually:
        // - If it has manual selections, use those specific IDs
        // - If it has no manual selections, get ALL published posts of that type
        foreach ($post_types as $post_type) {
            $has_manual_selection_for_type = array_key_exists($post_type, $manual_selections)
                && !empty($manual_selections[$post_type]);

            if ($has_manual_selection_for_type) {
                // This post type has manual selections - use only those specific IDs
                $type_ids = is_array($manual_selections[$post_type])
                    ? array_filter(array_map('intval', $manual_selections[$post_type]))
                    : array();

                if (get_option('aicwp_debug_mode', false)) {
                    error_log("AI Chat for WordPress: Post type '{$post_type}' - using {" . count($type_ids) . "} manually selected items");
                }

                $all_post_ids = array_merge($all_post_ids, $type_ids);
            } else {
                // This post type has NO manual selections - get ALL published posts
                if (get_option('aicwp_debug_mode', false)) {
                    error_log("AI Chat for WordPress: Post type '{$post_type}' - getting ALL published items");
                }

                // Build query args
                $type_query_args = array(
                    'post_type' => $post_type,
                    'post_status' => 'publish',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'orderby' => 'ID',
                    'order' => 'ASC'
                );

                $type_posts = get_posts($type_query_args);

                if (get_option('aicwp_debug_mode', false)) {
                    error_log("AI Chat for WordPress: Post type '{$post_type}' - found {" . count($type_posts) . "} published items");
                }

                $all_post_ids = array_merge($all_post_ids, $type_posts);
            }
        }

        // Remove duplicates and sort
        $all_post_ids = array_unique($all_post_ids);
        sort($all_post_ids);

        if (empty($all_post_ids)) {
            return array(
                'status' => 'complete',
                'message' => 'No posts to process',
                'processed' => 0,
                'total_posts' => 0,
                'next_offset' => 0
            );
        }

        // Get batch of posts for this request (apply pagination)
        $posts = array_slice($all_post_ids, $start_offset, $batch_size);

        // Calculate total
        $total_posts = count($all_post_ids);

        if (get_option('aicwp_debug_mode', false) && $start_offset === 0) {
            error_log("AI Chat for WordPress: Total items to process: {$total_posts}");
        }

        if (empty($posts)) {
            return array(
                'status' => 'complete',
                'message' => 'No more posts to process',
                'processed' => 0,
                'total_posts' => $total_posts,
                'next_offset' => $start_offset
            );
        }

        $processed = 0;
        $errors = array();
        $table_name = AICWP_Database_Manager::get_embeddings_table_name();

        // Log memory usage at start of batch
        if (get_option('aicwp_debug_mode', false)) {
            $memory_used = round(memory_get_usage() / 1024 / 1024, 2);
            $memory_limit = ini_get('memory_limit');
            error_log("AI Chat for WordPress: Memory at batch start: {$memory_used}MB / {$memory_limit}");
        }

        $chunked_count = 0;

        foreach ($posts as $post_id) {
            try {
                // Use Database Manager's generate_single_embedding which handles chunking
                $result = AICWP_Database_Manager::generate_single_embedding($post_id);

                if (!$result['success']) {
                    $error_msg = isset($result['error']) ? $result['error'] : "Failed to generate embedding for post {$post_id}";
                    $errors[] = $error_msg;
                    error_log("AI Chat for WordPress: " . $error_msg);
                    continue;
                }

                $processed++;

                // Track chunked posts
                if (!empty($result['chunked'])) {
                    $chunked_count++;
                    if (get_option('aicwp_debug_mode', false)) {
                        error_log(sprintf(
                            "AI Chat for WordPress: Post %d chunked into %d parts (%d words)",
                            $post_id,
                            $result['chunks_created'] ?? 0,
                            $result['word_count'] ?? 0
                        ));
                    }
                }

                // Log progress periodically in debug mode
                if (get_option('aicwp_debug_mode', false) && $processed % 10 === 0) {
                    $memory_used = round(memory_get_usage() / 1024 / 1024, 2);
                    error_log("AI Chat for WordPress: Processed {$processed} embeddings, Memory: {$memory_used}MB");
                }

                // Free up memory periodically
                if ($processed % 25 === 0) {
                    wp_cache_flush();
                }

                // Add delay to avoid overwhelming the server and API rate limits
                // Optimized to 25ms for faster batch processing
                usleep(25000); // 25ms delay

            } catch (Exception $e) {
                $error_msg = "Error processing post {$post_id}: " . $e->getMessage();
                $errors[] = $error_msg;
                // Always log exceptions to error_log
                error_log("CRITICAL AI Chat for WordPress: " . $error_msg);

                // Log stack trace in debug mode
                if (get_option('aicwp_debug_mode', false)) {
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
            }
        }

        // Total was already calculated above
        $next_offset = min($start_offset + $batch_size, $total_posts);

        // Log completion of batch
        if (get_option('aicwp_debug_mode', false)) {
            error_log(sprintf(
                'AI Chat for WordPress: Batch completed - processed: %d, errors: %d, next_offset: %d, total: %d',
                $processed,
                count($errors),
                $next_offset,
                $total_posts
            ));
        }

        // If there are critical errors, log them always (not just in debug mode)
        if (!empty($errors)) {
            error_log(sprintf(
                'AI Chat for WordPress: Batch had %d errors. First error: %s',
                count($errors),
                isset($errors[0]) ? $errors[0] : 'Unknown'
            ));
        }

        return array(
            'status' => 'processing',
            'processed' => $processed,
            'chunked' => $chunked_count,
            'errors' => $errors,
            'next_offset' => $next_offset,
            'total_posts' => $total_posts,
            'total_listings' => $total_posts, // Add for frontend compatibility
            'post_types' => $post_types
        );
    }
    
    /**
     * Sanitize text to ensure valid UTF-8 encoding
     *
     * This prevents json_encode() failures when content contains:
     * - Invalid UTF-8 sequences
     * - Control characters
     * - Binary data from copy/paste operations
     * - Broken multibyte characters
     *
     * @param string $text Text to sanitize
     * @return string Sanitized text with valid UTF-8 encoding
     */
    public static function sanitize_utf8($text) {
        if (empty($text)) {
            return '';
        }

        // Convert to UTF-8 if it's not already
        if (!mb_check_encoding($text, 'UTF-8')) {
            // Try to detect encoding and convert
            $detected = mb_detect_encoding($text, array('UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'), true);
            if ($detected && $detected !== 'UTF-8') {
                $text = mb_convert_encoding($text, 'UTF-8', $detected);
            } else {
                // Force conversion, replacing invalid characters
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }

        // Remove invalid UTF-8 sequences
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Remove null bytes and other problematic characters
        $text = str_replace(array("\0", "\xEF\xBB\xBF"), '', $text);

        // Ensure the string is valid UTF-8 by encoding/decoding through json
        // This is a fallback that removes any remaining invalid sequences
        $test_encode = json_encode($text);
        if ($test_encode === false) {
            // Last resort: strip all non-ASCII characters and rebuild
            $text = preg_replace('/[^\x20-\x7E\s]/u', '', $text);
        }

        return $text;
    }

    /**
     * Analyze embedding vector for debugging
     *
     * @param array $embedding Embedding vector
     * @return array Analysis results
     */
    public function analyze_embedding($embedding) {
        if (empty($embedding) || !is_array($embedding)) {
            return array('error' => 'Invalid embedding data');
        }
        
        $analysis = array(
            'dimensions' => count($embedding),
            'mean' => array_sum($embedding) / count($embedding),
            'min' => min($embedding),
            'max' => max($embedding),
            'std_dev' => 0
        );
        
        // Calculate standard deviation
        $mean = $analysis['mean'];
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $embedding)) / count($embedding);
        
        $analysis['std_dev'] = sqrt($variance);
        
        // Check for potential issues
        $zero_count = count(array_filter($embedding, function($x) { return $x == 0; }));
        $analysis['zero_percentage'] = ($zero_count / count($embedding)) * 100;
        
        // Determine if embedding looks healthy
        $analysis['health_status'] = 'healthy';
        if ($analysis['zero_percentage'] > 50) {
            $analysis['health_status'] = 'poor - too many zeros';
        } elseif ($analysis['std_dev'] < 0.1) {
            $analysis['health_status'] = 'poor - low variance';
        } elseif (abs($analysis['mean']) > 1) {
            $analysis['health_status'] = 'unusual - high mean';
        }
        
        return $analysis;
    }
    

}
