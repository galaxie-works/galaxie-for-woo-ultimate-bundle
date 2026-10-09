<?php
/**
 * Background Processing for AI Chat for WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Background processing class
 */
class AICWP_Background_Processor {
    
    /**
     * Hook into WordPress
     */
    public static function init() {
        add_action('aicwp_process_listing', array(__CLASS__, 'process_single_listing'));
        add_action('aicwp_bulk_process_listings', array(__CLASS__, 'process_all_listings'));
    }
    
    /**
     * Process a single post (any post type)
     *
     * Handles both regular posts and chunked content for long posts/pages.
     */
    public static function process_single_listing($listing_id) {
        global $wpdb;

        $post = get_post($listing_id);

        if (!$post || $post->post_status !== 'publish') {
            return false;
        }

        // Skip processing chunk posts directly - they're processed via their parent
        if ($post->post_type === AICWP_Content_Chunker::CHUNK_POST_TYPE) {
            return self::process_chunk_embedding($listing_id);
        }

        try {
            // Initialize AI provider to get correct API key and configuration
            $provider = new AICWP_Provider();
            $api_key = $provider->get_api_key();

            if (empty($api_key)) {
                AICWP_Utility_Helper::debug_log('No API key configured', 'error');
                return false;
            }

            $table_name = $wpdb->prefix . 'aicwp_embeddings';

            // Check if this post should be chunked
            if (AICWP_Content_Chunker::should_chunk($listing_id)) {
                return self::process_chunked_post($listing_id, $provider, $table_name);
            }

            // Not chunked - use normal single embedding flow
            // But first, clean up any existing chunks (post might have been shortened)
            $existing_chunks = AICWP_Content_Chunker::get_chunk_count($listing_id);
            if ($existing_chunks > 0) {
                AICWP_Content_Chunker::delete_chunks_for_post($listing_id);
                AICWP_Utility_Helper::debug_log(
                    "Deleted $existing_chunks old chunks for post $listing_id (content now below threshold)",
                    'info'
                );
            }

            // Collect post content using factory pattern
            $content = self::collect_content($listing_id);
            $content_hash = md5($content);

            // Check if we already have current embedding
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT content_hash FROM $table_name WHERE listing_id = %d",
                $listing_id
            ));

            if ($existing && $existing->content_hash === $content_hash) {
                // Content hasn't changed, skip
                return true;
            }

            // Generate embedding using provider abstraction
            $embedding = self::generate_embedding($content, $provider);

            if (!$embedding) {
                AICWP_Utility_Helper::debug_log("Failed to generate embedding for listing $listing_id", 'error');
                return false;
            }

            // Store embedding
            $result = $wpdb->replace($table_name, array(
                'listing_id' => $listing_id,
                'embedding' => AICWP_Database_Manager::compress_embedding_for_storage($embedding),
                'content_hash' => $content_hash,
                'updated_at' => current_time('mysql')
            ));

            if ($result === false) {
                AICWP_Utility_Helper::debug_log("Failed to store embedding for listing $listing_id", 'error');
                return false;
            }

            AICWP_Utility_Helper::debug_log("Successfully processed listing $listing_id", 'info');
            return true;

        } catch (Exception $e) {
            if (get_option('aicwp_debug_mode', false)) {
                error_log("AI Chat for WordPress: Error processing listing $listing_id - " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Process a chunked post - creates chunks and generates embeddings for each
     *
     * @param int $post_id Post ID
     * @param AICWP_Provider $provider AI provider instance
     * @param string $table_name Embeddings table name
     * @return bool Success status
     */
    private static function process_chunked_post($post_id, $provider, $table_name) {
        global $wpdb;

        $post = get_post($post_id);
        $word_count = AICWP_Content_Chunker::get_word_count($post_id);

        AICWP_Utility_Helper::debug_log(
            sprintf("Processing chunked post %d (%s) - %d words",
                $post_id, $post->post_title, $word_count),
            'info'
        );

        // Delete any existing parent embedding (we'll use chunk embeddings instead)
        $wpdb->delete($table_name, array('listing_id' => $post_id), array('%d'));

        // Create chunks
        $chunk_ids = AICWP_Content_Chunker::create_chunks($post_id);

        if (empty($chunk_ids)) {
            AICWP_Utility_Helper::debug_log(
                "No chunks created for post $post_id - falling back to single embedding",
                'warning'
            );
            return false;
        }

        $success_count = 0;
        $total_chunks = count($chunk_ids);

        // Generate embedding for each chunk
        foreach ($chunk_ids as $chunk_id) {
            try {
                $result = self::process_chunk_embedding($chunk_id, $provider, $table_name);
                if ($result) {
                    $success_count++;
                }

                // Small delay between API calls to avoid rate limiting
                usleep(50000); // 50ms

            } catch (Exception $e) {
                AICWP_Utility_Helper::debug_log(
                    sprintf("Error processing chunk %d for post %d: %s",
                        $chunk_id, $post_id, $e->getMessage()),
                    'error'
                );
            }
        }

        AICWP_Utility_Helper::debug_log(
            sprintf("Chunked post %d: %d/%d chunks successfully embedded",
                $post_id, $success_count, $total_chunks),
            'info'
        );

        return $success_count > 0;
    }

    /**
     * Process embedding for a single chunk
     *
     * @param int $chunk_id Chunk post ID
     * @param AICWP_Provider|null $provider AI provider instance (optional)
     * @param string|null $table_name Embeddings table name (optional)
     * @return bool Success status
     */
    private static function process_chunk_embedding($chunk_id, $provider = null, $table_name = null) {
        global $wpdb;

        if (!$provider) {
            $provider = new AICWP_Provider();
            if (empty($provider->get_api_key())) {
                return false;
            }
        }

        if (!$table_name) {
            $table_name = $wpdb->prefix . 'aicwp_embeddings';
        }

        // Get chunk content
        $content = self::collect_content($chunk_id);
        if (empty($content)) {
            return false;
        }

        $content_hash = md5($content);

        // Check if we already have current embedding
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT content_hash FROM $table_name WHERE listing_id = %d",
            $chunk_id
        ));

        if ($existing && $existing->content_hash === $content_hash) {
            // Content hasn't changed, skip
            return true;
        }

        // Generate embedding
        $embedding = self::generate_embedding($content, $provider);

        if (!$embedding) {
            return false;
        }

        // Store embedding
        $result = $wpdb->replace($table_name, array(
            'listing_id' => $chunk_id,
            'embedding' => AICWP_Database_Manager::compress_embedding_for_storage($embedding),
            'content_hash' => $content_hash,
            'updated_at' => current_time('mysql')
        ));

        return $result !== false;
    }
    
    /**
     * Process all existing posts (supports multiple post types)
     */
    public static function process_all_listings() {
        // Get configured post types or use defaults
        $post_types = get_option('aicwp_post_types', array('post', 'page', 'product'));

        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );

        $listings = get_posts($args);

        if (get_option('aicwp_debug_mode', false)) {
            error_log("AI Chat for WordPress: Starting bulk processing of " . count($listings) . " listings");
        }

        $processed = 0;
        $failed = 0;

        foreach ($listings as $listing_id) {
            // Add delay to avoid rate limiting
            if ($processed > 0 && $processed % 3 === 0) {
                sleep(1); // 1 second delay every 3 requests
            }

            if (self::process_single_listing($listing_id)) {
                $processed++;
            } else {
                $failed++;
            }

            // Break if too many failures
            if ($failed > 10) {
                if (get_option('aicwp_debug_mode', false)) {
                    error_log("AI Chat for WordPress: Too many failures ($failed), stopping bulk process");
                }
                break;
            }
        }

        if (get_option('aicwp_debug_mode', false)) {
            error_log("AI Chat for WordPress: Bulk processing complete. Processed: $processed, Failed: $failed");
        }
        
        // Schedule cleanup of old embeddings
        wp_schedule_single_event(time() + 300, 'aicwp_cleanup_embeddings');
    }
    
    /**
     * Collect content from any post type using factory pattern
     */
    public static function collect_content($post_id) {
        return AICWP_Content_Extractor_Factory::extract_content($post_id);
    }

    /**
     * Legacy method - backward compatibility
     * @deprecated 2.0.0 Use collect_content() instead
     */
    public static function collect_listing_content($listing_id) {
        return self::collect_content($listing_id);
    }
    
    /**
     * Generate embedding using configured AI provider (OpenAI/Gemini)
     *
     * @param string $text Text to generate embedding for
     * @param AICWP_Provider $provider AI provider instance
     * @return array|false Embedding array or false on failure
     */
    private static function generate_embedding($text, $provider) {
        $start_time = microtime(true);

        $provider_name = $provider->get_provider_name();

        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log("Making {$provider_name} API call for " . strlen($text) . " characters", 'info');
        }

        // Get provider-specific configuration
        $endpoint = $provider->get_endpoint('embeddings');
        $headers = $provider->get_headers();

        // Sanitize text to ensure valid UTF-8 encoding (prevents json_encode failures)
        $sanitized_text = AICWP_Embedding_Manager::sanitize_utf8($text);
        $payload = $provider->prepare_embedding_payload($sanitized_text);

        // Encode payload to JSON with error handling
        $json_body = json_encode($payload);
        if ($json_body === false) {
            $json_error = json_last_error_msg();
            error_log("AI Chat for WordPress: JSON encoding failed in background processor - " . $json_error);
            throw new Exception('Failed to encode content for API request: ' . $json_error);
        }

        $response = wp_remote_post($endpoint, array(
            'headers' => $headers,
            'body' => $json_body,
            'timeout' => 60,
        ));

        $duration = microtime(true) - $start_time;

        if (is_wp_error($response)) {
            $error_msg = $provider_name . ' API request failed: ' . $response->get_error_message();
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("API Error after {$duration}s: {$error_msg}", 'error');
            }
            throw new Exception($error_msg);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log("API Response after {$duration}s: HTTP {$http_code}", 'info');
        }

        if ($http_code !== 200) {
            $error_msg = "{$provider_name} API HTTP error: {$http_code}";
            if (isset($body['error']['message'])) {
                $error_msg .= " - " . $body['error']['message'];
            }
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("API HTTP Error: {$error_msg}", 'error');
            }
            throw new Exception($error_msg);
        }

        if (isset($body['error'])) {
            $error_msg = $provider_name . ' API error: ' . ($body['error']['message'] ?? 'Unknown error');
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("API Response Error: {$error_msg}", 'error');
            }
            throw new Exception($error_msg);
        }

        // Parse embedding using provider abstraction
        $embedding = $provider->parse_embedding_response($body);

        if (!$embedding) {
            $error_msg = $provider_name . ' API response missing embedding data';
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("API Response Missing Data: " . json_encode($body), 'error');
            }
            throw new Exception($error_msg);
        }

        if (get_option('aicwp_debug_mode', false)) {
            $embedding_count = count($embedding);
            AICWP_Utility_Helper::debug_log("Successfully received embedding with {$embedding_count} dimensions in {$duration}s", 'info');
        }

        return $embedding;
    }
    
    /**
     * Cleanup old embeddings for deleted posts
     */
    public static function cleanup_embeddings() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'aicwp_embeddings';

        // Delete embeddings for non-existent or non-published posts
        $wpdb->query("
            DELETE e FROM $table_name e
            LEFT JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.ID IS NULL OR p.post_status != 'publish'
        ");

        if (get_option('aicwp_debug_mode', false)) {
            error_log("AI Chat for WordPress: Embedding cleanup completed");
        }
    }
}

// Initialize background processor
add_action('aicwp_cleanup_embeddings', array('AICWP_Background_Processor', 'cleanup_embeddings'));
AICWP_Background_Processor::init();
