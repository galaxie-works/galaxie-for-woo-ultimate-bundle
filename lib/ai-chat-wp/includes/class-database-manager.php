<?php
/**
 * Database Manager Class
 * 
 * Handles database operations and embedding storage
 * 
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Database_Manager {

    /**
     * Get all detected custom post types (excluding default and internal types)
     *
     * @return array Array of custom post types with their labels
     */
    public static function get_detected_custom_post_types() {
        // Default types that appear as cards
        $default_types = array('post', 'page', 'product');

        // WordPress internal types to exclude
        $excluded_types = array(
            'attachment', 'revision', 'nav_menu_item', 'custom_css',
            'customize_changeset', 'oembed_cache', 'user_request',
            'wp_block', 'wp_template', 'wp_template_part',
            'wp_global_styles', 'wp_navigation', 'acf-field-group', 'acf-field'
        );

        // Get all public post types
        $all_post_types = get_post_types(array(
            'public' => true,
            'show_ui' => true,
        ), 'objects');

        $custom_types = array();
        foreach ($all_post_types as $post_type => $post_type_obj) {
            // Skip default types and excluded types
            if (in_array($post_type, $default_types) || in_array($post_type, $excluded_types)) {
                continue;
            }

            $custom_types[$post_type] = array(
                'name' => $post_type,
                'label' => $post_type_obj->label,
                'singular_label' => $post_type_obj->labels->singular_name ?? $post_type_obj->label,
            );
        }

        return $custom_types;
    }

    /**
     * Get whitelisted and enabled post types (centralized helper)
     *
     * @return array Filtered array of enabled post types
     */
    public static function get_enabled_post_types() {
        // Default types always available
        $default_types = array('post', 'page', 'product', 'ai_pdf_document', 'ai_external_page');

        // Get custom types that have been added
        $custom_types = get_option('aicwp_custom_post_types', array());
        if (!is_array($custom_types)) {
            $custom_types = array();
        }

        // Combine default + custom types
        $allowed_post_types = array_merge($default_types, $custom_types);

        // Get enabled post types from option
        // Default to no enabled post types on first install
        $enabled_post_types = get_option('aicwp_enabled_post_types', array());
        if (!is_array($enabled_post_types)) {
            $enabled_post_types = array();
        }

        // Filter to only include allowed types
        $enabled_post_types = array_intersect($enabled_post_types, $allowed_post_types);

        // Return empty array if no post types are enabled
        // This allows proper handling of "0 posts to index" scenario
        return array_values($enabled_post_types); // Re-index array
    }

    /**
     * Get the embeddings table name
     *
     * @return string Table name
     */
    public static function get_embeddings_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aicwp_embeddings';
    }
    
    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $table_name = self::get_embeddings_table_name();
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            listing_id bigint(20) NOT NULL,
            embedding longtext NOT NULL,
            content_hash varchar(32) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY listing_id (listing_id),
            KEY content_hash (content_hash)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Compress embedding vector for storage using half-precision floats
     * 
     * @param array $embedding_vector Array of floating point values
     * @return string Compressed base64-encoded binary data
     */
    public static function compress_embedding_for_storage($embedding_vector) {
        if (empty($embedding_vector) || !is_array($embedding_vector)) {
            return '';
        }
        
        try {
            // Convert to half-precision floats (16-bit) for massive memory savings
            $packed = '';
            foreach ($embedding_vector as $value) {
                // Quantize to 16-bit signed integer for efficient storage
                // Range: -32768 to 32767, good enough for normalized embeddings (-1 to 1)
                $quantized = intval(round($value * 32767));
                $quantized = max(-32767, min(32767, $quantized)); // Clamp to valid range
                $packed .= pack('s', $quantized); // 's' = signed 16-bit little-endian
            }
            
            // Compress the packed data and encode as base64 for database storage
            return base64_encode(gzcompress($packed, 6)); // Level 6 = good compression vs speed balance
            
        } catch (Exception $e) {
            error_log('AI Chat for WordPress - Embedding compression error: ' . $e->getMessage());
            // Fallback to JSON if compression fails (backward compatibility)
            return json_encode($embedding_vector);
        }
    }
    
    /**
     * Decompress embedding from storage back to float array
     * 
     * @param string $compressed_data Compressed base64-encoded data or JSON string
     * @return array|false Embedding vector array or false on failure
     */
    public static function decompress_embedding_from_storage($compressed_data) {
        if (empty($compressed_data)) {
            return false;
        }
        
        try {
            // BACKWARD COMPATIBILITY: Check if it's JSON (legacy format)
            if (substr(trim($compressed_data), 0, 1) === '[') {
                // It's JSON - decode normally for backward compatibility
                return json_decode($compressed_data, true);
            }
            
            // It's compressed binary data - decompress it
            $packed = gzuncompress(base64_decode($compressed_data));
            if ($packed === false) {
                // Decompression failed, try JSON fallback
                return json_decode($compressed_data, true);
            }
            
            // Unpack 16-bit signed integers back to floats
            $quantized_values = unpack('s*', $packed);
            if ($quantized_values === false) {
                return false;
            }
            
            // Convert back to normalized float values
            $embedding = array();
            foreach ($quantized_values as $quantized) {
                $embedding[] = $quantized / 32767.0; // Convert back to -1.0 to 1.0 range
            }
            
            return $embedding;
            
        } catch (Exception $e) {
            error_log('AI Chat for WordPress - Embedding decompression error: ' . $e->getMessage());
            // Final fallback to JSON
            return json_decode($compressed_data, true);
        }
    }
    
    /**
     * Get database statistics
     *
     * @return array Database statistics
     */
    public static function get_database_stats() {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        if (!$table_exists) {
            // Create table if it doesn't exist
            self::create_tables();
        }

        // Get whitelisted enabled post types
        $enabled_post_types = self::get_enabled_post_types();

        try {
            // Get total embeddings count
            $total_embeddings = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}") ?: 0;

            // If no post types are enabled, return early with zero stats
            if (empty($enabled_post_types)) {
                return array(
                    'table_exists' => $table_exists,
                    'total_embeddings' => (int) $total_embeddings,
                    'total_listings' => 0,
                    'without_embeddings' => 0,
                    'coverage_percentage' => 0,
                    'recent_embeddings' => 0,
                    'recent_items' => array(),
                    'missing_items' => array()
                );
            }

            // Build post type condition
            $post_types_placeholders = implode(',', array_fill(0, count($enabled_post_types), '%s'));

            // Get recent embeddings (last 10) - show post type in results
            // Include both regular posts and content chunks
            $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;
            $post_types_with_chunks = array_merge($enabled_post_types, array($chunk_post_type));
            $all_types_placeholders = implode(',', array_fill(0, count($post_types_with_chunks), '%s'));

            $recent_items = $wpdb->get_results($wpdb->prepare("
                SELECT e.listing_id,
                       p.post_title as title,
                       p.post_type,
                       p.post_type as listing_type,
                       e.created_at,
                       pm_parent.meta_value as chunk_parent_id,
                       pm_num.meta_value as chunk_number,
                       pm_total.meta_value as chunk_total
                FROM {$table_name} e
                INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                LEFT JOIN {$wpdb->postmeta} pm_parent ON p.ID = pm_parent.post_id AND pm_parent.meta_key = '_chunk_parent_id'
                LEFT JOIN {$wpdb->postmeta} pm_num ON p.ID = pm_num.post_id AND pm_num.meta_key = '_chunk_number'
                LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_chunk_total'
                WHERE p.post_status = 'publish' AND p.post_type IN ($all_types_placeholders)
                ORDER BY e.created_at DESC
                LIMIT 10
            ", ...$post_types_with_chunks), ARRAY_A);

            // Enhance chunk items with parent title
            foreach ($recent_items as &$item) {
                if (!empty($item['chunk_parent_id'])) {
                    $parent = get_post(intval($item['chunk_parent_id']));
                    if ($parent) {
                        $item['parent_title'] = $parent->post_title;
                        $item['listing_type'] = sprintf(
                            __('Chunk %d/%d', 'ai-chat-wp'),
                            intval($item['chunk_number']),
                            intval($item['chunk_total'])
                        );
                    }
                }
            }
            unset($item);

            // Get total published posts across enabled post types
            // IMPORTANT: Respect manual selections (same 3-state logic as Universal Settings)
            $manual_selections = get_option('aicwp_manual_selections', array());
            $total_listings = 0;

            foreach ($enabled_post_types as $post_type) {
                if (array_key_exists($post_type, $manual_selections)) {
                    // Manual selection active
                    $selected_ids = is_array($manual_selections[$post_type])
                        ? array_filter(array_map('intval', $manual_selections[$post_type]))
                        : array();

                    if (empty($selected_ids)) {
                        // User explicitly selected 0 posts - don't count
                        continue;
                    }

                    // Count only selected posts
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
                    $type_total = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts}
                         WHERE ID IN ($placeholders) AND post_status = 'publish'",
                        ...$selected_ids
                    ));
                    $total_listings += $type_total;
                } else {
                    // No manual selection - count all posts
                    $type_total = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts}
                         WHERE post_type = %s AND post_status = 'publish'",
                        $post_type
                    ));
                    $total_listings += $type_total;
                }
            }

            // Count indexed posts (from embeddings/chunks side — fast, uses indexes)
            $total_indexed = 0;

            foreach ($enabled_post_types as $post_type) {
                if (array_key_exists($post_type, $manual_selections)) {
                    $selected_ids = is_array($manual_selections[$post_type])
                        ? array_filter(array_map('intval', $manual_selections[$post_type]))
                        : array();

                    if (empty($selected_ids)) {
                        continue;
                    }

                    $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));

                    // Direct embeddings among selected
                    $direct = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT e.listing_id) FROM {$table_name} e
                         WHERE e.listing_id IN ($placeholders)",
                        ...$selected_ids
                    ));

                    // Chunk-covered among selected (no direct embedding)
                    $via_chunks = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT CAST(pm.meta_value AS UNSIGNED))
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
                         WHERE chunk.post_type = %s
                         AND pm.meta_key = '_chunk_parent_id'
                         AND CAST(pm.meta_value AS UNSIGNED) IN ($placeholders)
                         AND CAST(pm.meta_value AS UNSIGNED) NOT IN (
                             SELECT e2.listing_id FROM {$table_name} e2
                             WHERE e2.listing_id IN ($placeholders)
                         )",
                        ...array_merge(array($chunk_post_type), $selected_ids, $selected_ids)
                    ));

                    $total_indexed += $direct + $via_chunks;
                } else {
                    // Direct embeddings for this post type
                    $direct = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT e.listing_id)
                         FROM {$table_name} e
                         INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                         WHERE p.post_type = %s AND p.post_status = 'publish'",
                        $post_type
                    ));

                    // Chunk-covered posts without direct embedding
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
                             SELECT e2.listing_id FROM {$table_name} e2
                         )",
                        $chunk_post_type,
                        $post_type
                    ));

                    $total_indexed += $direct + $via_chunks;
                }
            }

            $without_embeddings = max(0, $total_listings - $total_indexed);
            
            // Get recent activity
            $recent_embeddings = $wpdb->get_var("
                SELECT COUNT(*) 
                FROM {$table_name} 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ") ?: 0;
            
            // Calculate coverage percentage
            $coverage_percentage = $total_listings > 0 ? round(($total_embeddings / $total_listings) * 100, 1) : 0;
            
            return array(
                'table_exists' => $table_exists,
                'total_embeddings' => (int) $total_embeddings,
                'total_listings' => (int) $total_listings,
                'without_embeddings' => (int) $without_embeddings,
                'coverage_percentage' => $coverage_percentage,
                'recent_embeddings' => (int) $recent_embeddings,
                'recent_items' => $recent_items ?: array(),
                'missing_items' => self::get_missing_embeddings(10) // Get up to 10 missing
            );
            
        } catch (Exception $e) {
            error_log('AI Chat for WordPress - Database stats error: ' . $e->getMessage());
            return array(
                'error' => $e->getMessage(),
                'table_exists' => $table_exists,
                'total_embeddings' => 0,
                'total_listings' => 0,
                'without_embeddings' => 0,
                'coverage_percentage' => 0,
                'recent_embeddings' => 0,
                'recent_items' => array(),
                'missing_items' => array()
            );
        }
    }
    
    /**
     * Get posts that are missing embeddings (across all enabled post types)
     *
     * IMPORTANT: Respects manual selections (same 3-state logic as stats)
     * Excludes posts that have content chunks - those are covered by chunk embeddings.
     *
     * @param int $limit Number of missing embeddings to return
     * @return array Array of post data without embeddings
     */
    public static function get_missing_embeddings($limit = 10) {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Get whitelisted enabled post types
        $enabled_post_types = self::get_enabled_post_types();

        // If no post types are enabled, return empty array
        if (empty($enabled_post_types)) {
            return array();
        }

        // Get manual selections
        $manual_selections = get_option('aicwp_manual_selections', array());

        try {
            // Pre-collect chunk parent IDs (single fast query)
            $chunk_parents_subquery = $wpdb->prepare(
                "SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS parent_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} chunk ON pm.post_id = chunk.ID
                 WHERE chunk.post_type = %s AND pm.meta_key = '_chunk_parent_id'",
                $chunk_post_type
            );

            $all_missing = array();

            foreach ($enabled_post_types as $post_type) {
                // Build WHERE conditions
                $where = array();
                $where[] = "p.post_status = 'publish'";
                $where[] = "e.listing_id IS NULL";
                $where[] = "cp.parent_id IS NULL";

                if (array_key_exists($post_type, $manual_selections)) {
                    $selected_ids = is_array($manual_selections[$post_type])
                        ? array_filter(array_map('intval', $manual_selections[$post_type]))
                        : array();

                    if (empty($selected_ids)) {
                        continue;
                    }

                    $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));
                    $where[] = $wpdb->prepare("p.ID IN ($placeholders)", ...$selected_ids);
                } else {
                    $where[] = $wpdb->prepare("p.post_type = %s", $post_type);
                }

                $where_clause = implode(' AND ', $where);

                $type_missing = $wpdb->get_results("
                    SELECT p.ID as listing_id,
                           p.post_title as title,
                           p.post_type,
                           p.post_type as listing_type,
                           p.post_modified as created_at
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$table_name} e ON p.ID = e.listing_id
                    LEFT JOIN ({$chunk_parents_subquery}) cp ON p.ID = cp.parent_id
                    WHERE {$where_clause}
                    ORDER BY p.post_modified DESC
                    LIMIT {$limit}
                ", ARRAY_A);

                if ($type_missing) {
                    $all_missing = array_merge($all_missing, $type_missing);
                }
            }

            // Sort by modified date and limit across all types
            usort($all_missing, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            return array_slice($all_missing, 0, $limit);

        } catch (Exception $e) {
            error_log('AI Chat for WordPress - Missing embeddings error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Search embeddings by title or ID
     *
     * @param string $search_term Search term (title or ID)
     * @param int $limit Maximum number of results
     * @return array Array of matching embeddings
     */
    public static function search_embeddings($search_term, $limit = 20) {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Get enabled post types
        $enabled_post_types = self::get_enabled_post_types();

        // If no post types are enabled, return empty array
        if (empty($enabled_post_types)) {
            return array();
        }

        // Include chunk post type
        $post_types_with_chunks = array_merge($enabled_post_types, array($chunk_post_type));
        $all_types_placeholders = implode(',', array_fill(0, count($post_types_with_chunks), '%s'));

        try {
            // Search by ID or title
            $search_term = sanitize_text_field($search_term);
            $is_numeric = is_numeric($search_term);

            if ($is_numeric) {
                // Search by exact ID
                $results = $wpdb->get_results($wpdb->prepare("
                    SELECT e.listing_id,
                           p.post_title as title,
                           p.post_type,
                           p.post_type as listing_type,
                           e.created_at,
                           pm_parent.meta_value as chunk_parent_id,
                           pm_num.meta_value as chunk_number,
                           pm_total.meta_value as chunk_total
                    FROM {$table_name} e
                    INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                    LEFT JOIN {$wpdb->postmeta} pm_parent ON p.ID = pm_parent.post_id AND pm_parent.meta_key = '_chunk_parent_id'
                    LEFT JOIN {$wpdb->postmeta} pm_num ON p.ID = pm_num.post_id AND pm_num.meta_key = '_chunk_number'
                    LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_chunk_total'
                    WHERE p.post_status = 'publish'
                    AND p.post_type IN ($all_types_placeholders)
                    AND (e.listing_id = %d OR pm_parent.meta_value = %s)
                    ORDER BY e.created_at DESC
                    LIMIT %d
                ", ...array_merge($post_types_with_chunks, array(intval($search_term), $search_term, $limit))), ARRAY_A);
            } else {
                // Search by title (LIKE search)
                $like_term = '%' . $wpdb->esc_like($search_term) . '%';
                $results = $wpdb->get_results($wpdb->prepare("
                    SELECT e.listing_id,
                           p.post_title as title,
                           p.post_type,
                           p.post_type as listing_type,
                           e.created_at,
                           pm_parent.meta_value as chunk_parent_id,
                           pm_num.meta_value as chunk_number,
                           pm_total.meta_value as chunk_total
                    FROM {$table_name} e
                    INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                    LEFT JOIN {$wpdb->postmeta} pm_parent ON p.ID = pm_parent.post_id AND pm_parent.meta_key = '_chunk_parent_id'
                    LEFT JOIN {$wpdb->postmeta} pm_num ON p.ID = pm_num.post_id AND pm_num.meta_key = '_chunk_number'
                    LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_chunk_total'
                    WHERE p.post_status = 'publish'
                    AND p.post_type IN ($all_types_placeholders)
                    AND p.post_title LIKE %s
                    ORDER BY e.created_at DESC
                    LIMIT %d
                ", ...array_merge($post_types_with_chunks, array($like_term, $limit))), ARRAY_A);
            }

            // Enhance chunk items with parent title
            foreach ($results as &$item) {
                if (!empty($item['chunk_parent_id'])) {
                    $parent = get_post(intval($item['chunk_parent_id']));
                    if ($parent) {
                        $item['parent_title'] = $parent->post_title;
                        $item['listing_type'] = sprintf(
                            __('Chunk %d/%d', 'ai-chat-wp'),
                            intval($item['chunk_number']),
                            intval($item['chunk_total'])
                        );
                    }
                }
            }
            unset($item);

            return $results ?: array();

        } catch (Exception $e) {
            error_log('AI Chat for WordPress - Search embeddings error: ' . $e->getMessage());
            return array();
        }
    }

    /**
     * Generate embedding for a single post (on-demand)
     *
     * Handles both regular posts and chunked content for long posts/pages.
     *
     * @param int $listing_id Post ID to generate embedding for
     * @return array Result array with success/error status
     */
    public static function generate_single_embedding($listing_id) {
        // Get whitelisted enabled post types
        $enabled_post_types = self::get_enabled_post_types();

        // Validate post
        $post = get_post($listing_id);
        if (!$post || !in_array($post->post_type, $enabled_post_types) || $post->post_status !== 'publish') {
            return array(
                'success' => false,
                'error' => 'Invalid post or post type not enabled for embeddings'
            );
        }


        // Check API key (provider-aware for OpenAI/Gemini)
        $provider = new AICWP_Provider();
        $api_key = $provider->get_api_key();
        if (empty($api_key)) {
            return array(
                'success' => false,
                'error' => 'No ' . $provider->get_provider_name() . ' API key configured'
            );
        }

        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'aicwp_embeddings';

            // Check if this post should be chunked
            if (AICWP_Content_Chunker::should_chunk($listing_id)) {
                return self::generate_chunked_embeddings($listing_id, $provider, $table_name);
            }

            // Not chunked - use normal single embedding flow
            // But first, clean up any existing chunks (post might have been shortened)
            $existing_chunks = AICWP_Content_Chunker::get_chunk_count($listing_id);
            if ($existing_chunks > 0) {
                AICWP_Content_Chunker::delete_chunks_for_post($listing_id);
            }

            // Collect listing content to get character count
            $content = AICWP_Background_Processor::collect_listing_content($listing_id);
            $chars_processed = strlen($content);

            // Generate embedding using embedding manager (uses configured provider)
            $embedding_manager = new AICWP_Embedding_Manager();
            $embedding = $embedding_manager->generate_embedding($content, true);

            if (!$embedding) {
                return array(
                    'success' => false,
                    'error' => 'Failed to generate embedding via ' . $provider->get_provider_name() . ' API'
                );
            }

            $embedding_dimensions = count($embedding);
            $content_hash = md5($content);

            $result = $wpdb->replace($table_name, array(
                'listing_id' => $listing_id,
                'embedding' => self::compress_embedding_for_storage($embedding),
                'content_hash' => $content_hash,
                'updated_at' => current_time('mysql')
            ));

            if ($result === false) {
                return array(
                    'success' => false,
                    'error' => 'Failed to store embedding in database'
                );
            }

            return array(
                'success' => true,
                'chars_processed' => $chars_processed,
                'embedding_dimensions' => $embedding_dimensions,
                'listing_title' => $post->post_title,
                'chunked' => false
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Generate embeddings for a chunked post
     *
     * @param int $post_id Post ID
     * @param AICWP_Provider $provider AI provider instance
     * @param string $table_name Embeddings table name
     * @return array Result array with success/error status
     */
    private static function generate_chunked_embeddings($post_id, $provider, $table_name) {
        global $wpdb;

        $post = get_post($post_id);
        $word_count = AICWP_Content_Chunker::get_word_count($post_id);

        // Delete any existing parent embedding (we'll use chunk embeddings instead)
        $wpdb->delete($table_name, array('listing_id' => $post_id), array('%d'));

        // Create chunks
        $chunk_ids = AICWP_Content_Chunker::create_chunks($post_id);

        if (empty($chunk_ids)) {
            return array(
                'success' => false,
                'error' => 'Failed to create content chunks'
            );
        }

        $embedding_manager = new AICWP_Embedding_Manager();
        $success_count = 0;
        $total_chunks = count($chunk_ids);
        $total_chars = 0;
        $embedding_dimensions = 0;

        // Generate embedding for each chunk
        foreach ($chunk_ids as $chunk_id) {
            try {
                $content = AICWP_Content_Extractor_Factory::extract_content($chunk_id);
                if (empty($content)) {
                    continue;
                }

                $total_chars += strlen($content);
                $content_hash = md5($content);

                $embedding = $embedding_manager->generate_embedding($content, true);

                if ($embedding) {
                    $embedding_dimensions = count($embedding);

                    $result = $wpdb->replace($table_name, array(
                        'listing_id' => $chunk_id,
                        'embedding' => self::compress_embedding_for_storage($embedding),
                        'content_hash' => $content_hash,
                        'updated_at' => current_time('mysql')
                    ));

                    if ($result !== false) {
                        $success_count++;
                    }
                }

                // Small delay between API calls to avoid rate limiting
                usleep(50000); // 50ms

            } catch (Exception $e) {
                if (get_option('aicwp_debug_mode', false)) {
                    error_log("AI Chat for WordPress: Error processing chunk $chunk_id: " . $e->getMessage());
                }
            }
        }

        if ($success_count === 0) {
            return array(
                'success' => false,
                'error' => 'Failed to generate embeddings for any chunks'
            );
        }

        return array(
            'success' => true,
            'chars_processed' => $total_chars,
            'embedding_dimensions' => $embedding_dimensions,
            'listing_title' => $post->post_title,
            'chunked' => true,
            'chunks_created' => $total_chunks,
            'chunks_embedded' => $success_count,
            'word_count' => $word_count
        );
    }

    /**
     * Process post for embedding generation
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public static function process_listing_on_save($post_id, $post) {
        // Get whitelisted enabled post types
        $enabled_post_types = self::get_enabled_post_types();

        // Debug: Log all save_post calls
        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log("process_listing_on_save called for post {$post_id}, type: {$post->post_type}, status: {$post->post_status}");
        }

        // Check if auto-training is disabled
        if (get_option('aicwp_disable_auto_training', false)) {
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("Skipping post {$post_id} - auto-training is disabled", 'info');
            }
            return;
        }

        // Only process enabled post types
        if (!in_array($post->post_type, $enabled_post_types) || $post->post_status !== 'publish') {
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("Skipping post {$post_id} - post type not enabled or not published", 'info');
            }
            return;
        }

        // PDF documents are trained via the dedicated batch AJAX endpoint (Train Now button).
        // Skip auto-training on save to avoid PHP timeout fatals with many chunks.
        if ($post->post_type === 'ai_pdf_document') {
            return;
        }

        // Check if API key is configured (provider-aware for OpenAI/Gemini)
        $provider = new AICWP_Provider();
        $api_key = $provider->get_api_key();
        if (empty($api_key)) {
            if (get_option('aicwp_debug_mode', false)) {
                $provider_name = $provider->get_provider_name();
                AICWP_Utility_Helper::debug_log("Skipping post {$post_id} - no {$provider_name} API key configured", 'warning');
            }
            return;
        }

        // Check embedding generation throttling
        $delay_minutes = get_option('aicwp_embedding_delay', 5);
        if ($delay_minutes > 0) {
            $last_processing_key = 'aicwp_last_embedding_' . $post_id;
            $last_processing_time = get_transient($last_processing_key);
            
            if ($last_processing_time !== false) {
                // Still within throttle period - skip processing
                if (get_option('aicwp_debug_mode', false)) {
                    error_log("AI Chat for WordPress: Skipping embedding regeneration for listing {$post_id} - still within {$delay_minutes} minute throttle period");
                }
                return;
            }
            
            // Set throttle marker to prevent rapid successive calls
            set_transient($last_processing_key, time(), $delay_minutes * MINUTE_IN_SECONDS);
        }
        
        // Get current content for hashing
        $embedding_manager = new AICWP_Embedding_Manager($api_key);
        $content = $embedding_manager->get_listing_content_for_embedding($post_id);
        $content_hash = md5($content);
        
        global $wpdb;
        $table_name = self::get_embeddings_table_name();
        
        // Check if we already have an embedding with the same content hash
        $existing_hash = $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$table_name} WHERE listing_id = %d",
            $post_id
        ));
        
        // Debug: Log hash comparison details
        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log("Listing {$post_id} - existing_hash: " . var_export($existing_hash, true) . ", new_hash: {$content_hash}");
        }
        
        if ($existing_hash === $content_hash) {
            // Content hasn't changed, no need to regenerate
            if (get_option('aicwp_debug_mode', false)) {
                AICWP_Utility_Helper::debug_log("Skipping embedding regeneration for listing {$post_id} - content hash unchanged", 'info');
            }
            return;
        }
        
        // Debug log: Successfully passed all checks, proceeding with embedding generation
        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log("Scheduling embedding regeneration for listing {$post_id} - passed throttling and content hash checks", 'info');
        }

        // Schedule background processing
        if (class_exists('AICWP_Background_Processor')) {
            if (get_option('aicwp_debug_mode', false)) {
                error_log("AI Chat for WordPress: Scheduling background processing for listing {$post_id}");
            }
            
            // Use WordPress action to trigger background processing
            wp_schedule_single_event(time(), 'aicwp_process_listing', array($post_id));
            
            if (get_option('aicwp_debug_mode', false)) {
                error_log("AI Chat for WordPress: Background processing scheduled for listing {$post_id}");
            }
        } else {
            if (get_option('aicwp_debug_mode', false)) {
                error_log("AI Chat for WordPress: Background processor class not found for listing {$post_id}");
            }
        }
    }
    
    /**
     * Get all embeddings for search with optional location filtering
     *
     * Automatically includes content chunks (ai_content_chunk) for chunked posts/pages.
     * Chunk results are returned with their chunk IDs - caller must map back to parent posts.
     *
     * @param string $listing_types Comma-separated post types or 'all' (all enabled post types)
     * @param array $detected_locations Array of detected locations for filtering
     * @param array $location_filtered_ids Optional pre-filtered listing IDs from SQL location search
     * @return array Database results with embeddings
     */
    public static function get_embeddings_for_search($listing_types = 'all', $detected_locations = array(), $location_filtered_ids = array()) {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // SQL location pre-filter (from chatbot location parameter)
        // If we have pre-filtered IDs, ONLY load embeddings for those IDs (massive performance boost)
        $id_condition = '';
        if (!empty($location_filtered_ids)) {
            $id_placeholders = implode(',', array_fill(0, count($location_filtered_ids), '%d'));
            $id_condition = $wpdb->prepare(" AND e.listing_id IN ($id_placeholders)", $location_filtered_ids);
        }

        // IMPORTANT: Filter by post type FIRST in SQL WHERE clause (correct architectural approach)
        // SQL filtering happens BEFORE retrieving embeddings for performance
        // 'all' = every post type enabled in admin settings
        // When specific type is passed (e.g. 'product' for chatbot), respect that filter
        // CHUNKING: Also include ai_content_chunk posts that belong to the requested types
        if ($listing_types === 'all') {
            $types_array = self::get_enabled_post_types();
        } else {
            $types_array = array_map('trim', explode(',', $listing_types));
        }

        if (empty($types_array)) {
            $type_condition = " AND 1=0";
        } else {
            // Also include chunks that belong to posts of these types
            $types_placeholders = implode(',', array_fill(0, count($types_array), '%s'));
            $type_condition = $wpdb->prepare(
                " AND (p.post_type IN ($types_placeholders) OR (p.post_type = %s AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_source
                    WHERE pm_source.post_id = p.ID
                    AND pm_source.meta_key = '_chunk_source_type'
                    AND pm_source.meta_value IN ($types_placeholders)
                )))",
                ...array_merge($types_array, array($chunk_post_type), $types_array)
            );
        }

        $query = "
            SELECT DISTINCT e.listing_id, e.embedding, p.post_title, p.post_status, p.post_type
            FROM {$table_name} e
            INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.post_status = 'publish'
            {$id_condition}
            {$type_condition}
        ";

        return $wpdb->get_results($query);
    }
    
    /**
     * Store embedding in database
     * 
     * @param int $listing_id Listing ID
     * @param array $embedding Embedding vector
     * @param string $content_hash Content hash
     * @return bool Success status
     */
    public static function store_embedding($listing_id, $embedding, $content_hash) {
        global $wpdb;
        
        $table_name = self::get_embeddings_table_name();
        
        // Use new compression method for storage efficiency
        $compressed_embedding = self::compress_embedding_for_storage($embedding);
        
        $result = $wpdb->replace(
            $table_name,
            array(
                'listing_id' => $listing_id,
                'embedding' => $compressed_embedding,
                'content_hash' => $content_hash,
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s')
        );
        
        return $result !== false;
    }
    
    /**
     * Get embedding by listing ID
     * 
     * @param int $listing_id Listing ID
     * @return array|null Embedding data or null if not found
     */
    public static function get_embedding_by_listing_id($listing_id) {
        global $wpdb;
        
        $table_name = self::get_embeddings_table_name();
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE listing_id = %d",
            $listing_id
        ), ARRAY_A);
        
        if ($result && !empty($result['embedding'])) {
            $result['embedding'] = self::decompress_embedding_from_storage($result['embedding']);
        }
        
        return $result;
    }
    
    /**
     * Delete embedding by listing ID
     *
     * Also deletes the post if it's an ai_external_page (since those only exist for AI training).
     * If it's a chunk belonging to an ai_external_page, deletes all chunks and the parent page.
     *
     * @param int $listing_id Listing ID
     * @return bool Success status
     */
    public static function delete_embedding($listing_id) {
        $listing_id = (int) $listing_id;
        if ($listing_id <= 0) {
            return false;
        }

        global $wpdb;

        $table_name = self::get_embeddings_table_name();

        // Check if this is an ai_external_page or a chunk of one
        $post = get_post($listing_id);
        $is_external_page = ($post && $post->post_type === 'ai_external_page');
        $external_page_parent_id = null;

        // Check if this is a chunk belonging to an ai_external_page
        if ($post && $post->post_type === 'ai_content_chunk') {
            $parent_id = get_post_meta($listing_id, '_chunk_parent_id', true);
            if ($parent_id) {
                $parent_post = get_post($parent_id);
                if ($parent_post && $parent_post->post_type === 'ai_external_page') {
                    $external_page_parent_id = $parent_id;
                }
            }
        }

        // If this is a chunk of an ai_external_page, delete everything related
        $external_page_parent_id = (int) $external_page_parent_id;
        if ($external_page_parent_id > 0) {
            // Delete all chunks and their embeddings for this parent
            AICWP_Content_Chunker::delete_chunks_for_post($external_page_parent_id);
            // Delete the parent page itself
            wp_delete_post($external_page_parent_id, true);
            return true;
        }

        // Standard deletion
        $result = $wpdb->delete(
            $table_name,
            array('listing_id' => $listing_id),
            array('%d')
        );

        // If it's a direct ai_external_page embedding, also delete the post
        if ($is_external_page && $result !== false) {
            wp_delete_post($listing_id, true);
        }

        return $result !== false;
    }

    /**
     * Delete all chunks and their embeddings for a parent post
     *
     * @param int $parent_id Parent post ID
     * @return array Result with success status and count of deleted chunks
     */
    public static function delete_chunks_by_parent($parent_id) {
        $parent_id = (int) $parent_id;
        if ($parent_id <= 0) {
            return array('success' => true, 'deleted_count' => 0);
        }

        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Get all chunk IDs for this parent
        $chunk_ids = $wpdb->get_col($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = '_chunk_parent_id'
            AND pm.meta_value = %s
        ", $chunk_post_type, $parent_id));

        if (empty($chunk_ids)) {
            return array('success' => true, 'deleted_count' => 0);
        }

        $deleted_count = 0;

        foreach ($chunk_ids as $chunk_id) {
            $chunk_id = (int) $chunk_id;
            if ($chunk_id <= 0) {
                continue;
            }

            // Delete embedding
            $wpdb->delete($table_name, array('listing_id' => $chunk_id), array('%d'));

            // Delete the chunk post
            wp_delete_post($chunk_id, true);
            $deleted_count++;
        }

        return array('success' => true, 'deleted_count' => $deleted_count);
    }

    /**
     * Clear all embeddings
     *
     * Also deletes all content chunk posts and ai_external_page posts.
     *
     * @return bool Success status
     */
    public static function clear_all_embeddings() {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Delete all content chunk posts first
        $chunk_ids = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} WHERE post_type = %s
        ", $chunk_post_type));

        foreach ($chunk_ids as $chunk_id) {
            $chunk_id = (int) $chunk_id;
            if ($chunk_id > 0) {
                wp_delete_post($chunk_id, true);
            }
        }

        // Delete all ai_external_page posts (they only exist for AI training)
        $external_page_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ai_external_page'
        ");

        foreach ($external_page_ids as $page_id) {
            $page_id = (int) $page_id;
            if ($page_id > 0) {
                wp_delete_post($page_id, true);
            }
        }

        // Then truncate the embeddings table
        $result = $wpdb->query("TRUNCATE TABLE {$table_name}");

        return $result !== false;
    }

    /**
     * Delete embeddings for a specific post type
     *
     * Also deletes any content chunks associated with posts of this type.
     *
     * @param string $post_type Post type to delete embeddings for
     * @return array Result with success status and count of deleted embeddings
     */
    public static function delete_embeddings_by_post_type($post_type) {
        global $wpdb;

        // Validate post type exists
        if (!post_type_exists($post_type)) {
            return array(
                'success' => false,
                'error' => 'Invalid post type'
            );
        }

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // Get count of direct embeddings before deletion
        $count_before = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT e.listing_id)
            FROM {$table_name} e
            INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.post_type = %s
        ", $post_type));

        // Get all post IDs of this type that have chunks
        $parent_ids_with_chunks = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            INNER JOIN {$wpdb->posts} parent ON pm.meta_value = parent.ID
            WHERE pm.meta_key = '_chunk_parent_id'
            AND p.post_type = %s
            AND parent.post_type = %s
        ", $chunk_post_type, $post_type));

        // Delete chunks and their embeddings for posts of this type
        $chunks_deleted = 0;
        if (!empty($parent_ids_with_chunks)) {
            foreach ($parent_ids_with_chunks as $parent_id) {
                $chunks_deleted += AICWP_Content_Chunker::delete_chunks_for_post(intval($parent_id));
            }
        }

        // For ai_external_page, get post IDs before deleting embeddings
        // so we can also delete the posts themselves
        $external_page_ids = array();
        if ($post_type === 'ai_external_page') {
            $external_page_ids = $wpdb->get_col($wpdb->prepare("
                SELECT DISTINCT p.ID
                FROM {$wpdb->posts} p
                WHERE p.post_type = %s AND p.post_status = 'publish'
            ", $post_type));
        }

        // Delete embeddings for posts of this type
        $result = $wpdb->query($wpdb->prepare("
            DELETE e FROM {$table_name} e
            INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.post_type = %s
        ", $post_type));

        if ($result === false) {
            return array(
                'success' => false,
                'error' => 'Database error occurred'
            );
        }

        // For ai_external_page, also delete the posts themselves
        // since they only exist to hold content for AI training
        $posts_deleted = 0;
        if ($post_type === 'ai_external_page' && !empty($external_page_ids)) {
            foreach ($external_page_ids as $page_id) {
                $page_id = (int) $page_id;
                if ($page_id > 0 && wp_delete_post($page_id, true)) {
                    $posts_deleted++;
                }
            }
        }

        return array(
            'success' => true,
            'deleted_count' => $count_before + $chunks_deleted,
            'chunks_deleted' => $chunks_deleted,
            'posts_deleted' => $posts_deleted,
            'post_type' => $post_type
        );
    }
    
    /**
     * Get embeddings count for search with optional filtering (for batch planning)
     *
     * Includes content chunks for chunked posts/pages.
     *
     * @param string $listing_types Comma-separated post types or 'all' (all enabled post types)
     * @param array $detected_locations Array of detected locations for filtering
     * @return int Total count of embeddings that would be returned
     */
    public static function count_embeddings_for_search($listing_types = 'all', $detected_locations = array()) {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // IMPORTANT: Filter by post type FIRST in SQL WHERE clause (correct architectural approach)
        // SQL filtering happens BEFORE counting embeddings for performance
        // 'all' = every post type enabled in admin settings
        // When specific type is passed (e.g. 'product' for chatbot), respect that filter
        // CHUNKING: Also include ai_content_chunk posts that belong to the requested types
        if ($listing_types === 'all') {
            $types_array = self::get_enabled_post_types();
        } else {
            $types_array = array_map('trim', explode(',', $listing_types));
        }

        if (empty($types_array)) {
            $type_condition = " AND 1=0";
        } else {
            // Also include chunks that belong to posts of these types
            $types_placeholders = implode(',', array_fill(0, count($types_array), '%s'));
            $type_condition = $wpdb->prepare(
                " AND (p.post_type IN ($types_placeholders) OR (p.post_type = %s AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_source
                    WHERE pm_source.post_id = p.ID
                    AND pm_source.meta_key = '_chunk_source_type'
                    AND pm_source.meta_value IN ($types_placeholders)
                )))",
                ...array_merge($types_array, array($chunk_post_type), $types_array)
            );
        }

        $query = "
            SELECT COUNT(DISTINCT e.listing_id) as total_count
            FROM {$table_name} e
            INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.post_status = 'publish'
            {$type_condition}
        ";

        $result = $wpdb->get_var($query);
        return intval($result);
    }

    /**
     * Check if any embeddings exist for a given post type
     *
     * @param string $post_type Post type to check (e.g., 'post', 'product')
     * @return bool True if at least one embedding exists
     */
    public static function has_any_embeddings($post_type = 'post') {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();

        // Quick check - just need to find one
        $count = $wpdb->get_var($wpdb->prepare("
            SELECT 1
            FROM {$table_name} e
            INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            LIMIT 1
        ", $post_type));

        return !empty($count);
    }

    /**
     * Get embeddings in batches for memory-efficient processing
     *
     * Includes content chunks for chunked posts/pages.
     *
     * @param string $listing_types Comma-separated post types or 'all' (all enabled post types)
     * @param array $detected_locations Array of detected locations for filtering
     * @param int $batch_size Number of embeddings per batch (default: 1500)
     * @param int $offset Starting offset for this batch
     * @return array Database results with embeddings for this batch
     */
    public static function get_embeddings_batch($listing_types = 'all', $detected_locations = array(), $batch_size = 1500, $offset = 0) {
        global $wpdb;

        $table_name = self::get_embeddings_table_name();
        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;

        // IMPORTANT: Filter by post type FIRST in SQL WHERE clause (correct architectural approach)
        // SQL filtering happens BEFORE retrieving embeddings in batches for performance
        // 'all' = every post type enabled in admin settings
        // When specific type is passed (e.g. 'product' for chatbot), respect that filter
        // CHUNKING: Also include ai_content_chunk posts that belong to the requested types
        if ($listing_types === 'all') {
            $types_array = self::get_enabled_post_types();
        } else {
            $types_array = array_map('trim', explode(',', $listing_types));
        }

        if (empty($types_array)) {
            $type_condition = " AND 1=0";
        } else {
            // Also include chunks that belong to posts of these types
            $types_placeholders = implode(',', array_fill(0, count($types_array), '%s'));
            $type_condition = $wpdb->prepare(
                " AND (p.post_type IN ($types_placeholders) OR (p.post_type = %s AND EXISTS (
                    SELECT 1 FROM {$wpdb->postmeta} pm_source
                    WHERE pm_source.post_id = p.ID
                    AND pm_source.meta_key = '_chunk_source_type'
                    AND pm_source.meta_value IN ($types_placeholders)
                )))",
                ...array_merge($types_array, array($chunk_post_type), $types_array)
            );
        }

        $query = "
            SELECT DISTINCT e.listing_id, e.embedding, p.post_title, p.post_status, p.post_type
            FROM {$table_name} e
            INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
            WHERE p.post_status = 'publish'
            {$type_condition}
            ORDER BY e.listing_id
            LIMIT %d OFFSET %d
        ";

        return $wpdb->get_results($wpdb->prepare($query, $batch_size, $offset));
    }

    /**
     * Map chunk IDs to parent post IDs
     *
     * Used after search to convert chunk matches back to parent posts.
     * Returns both similarities and a chunk mapping for RAG context retrieval.
     *
     * @param array $similarities Array of post_id => similarity_score
     * @param bool $return_extended If true, returns extended structure with chunk mapping
     * @return array If $return_extended: ['similarities' => [...], 'chunk_mapping' => [...]]
     *               Otherwise: Array of parent_id => best_similarity_score
     */
    public static function map_chunks_to_parents($similarities, $return_extended = false) {
        if (empty($similarities)) {
            return $return_extended ? array('similarities' => array(), 'chunk_mapping' => array()) : $similarities;
        }

        $chunk_post_type = AICWP_Content_Chunker::CHUNK_POST_TYPE;
        $mapped_similarities = array();
        $chunk_mapping = array(); // parent_id => [chunk_ids that matched]

        foreach ($similarities as $post_id => $similarity) {
            $post = get_post($post_id);

            if (!$post) {
                continue;
            }

            // Check if this is a chunk
            if ($post->post_type === $chunk_post_type) {
                // Get parent ID
                $parent_id = AICWP_Content_Chunker::get_chunk_parent_id($post_id);

                if ($parent_id) {
                    // Track which chunks matched for this parent
                    if (!isset($chunk_mapping[$parent_id])) {
                        $chunk_mapping[$parent_id] = array();
                    }
                    $chunk_mapping[$parent_id][] = array(
                        'chunk_id' => $post_id,
                        'similarity' => $similarity
                    );

                    // Use parent ID, keep best similarity if parent already in results
                    if (!isset($mapped_similarities[$parent_id]) || $similarity > $mapped_similarities[$parent_id]) {
                        $mapped_similarities[$parent_id] = $similarity;
                    }
                }
            } else {
                // Not a chunk - use as-is
                if (!isset($mapped_similarities[$post_id]) || $similarity > $mapped_similarities[$post_id]) {
                    $mapped_similarities[$post_id] = $similarity;
                }
            }
        }

        // Sort chunks by similarity (best first) for each parent
        foreach ($chunk_mapping as $parent_id => &$chunks) {
            usort($chunks, function($a, $b) {
                return $b['similarity'] <=> $a['similarity'];
            });
        }

        if ($return_extended) {
            return array(
                'similarities' => $mapped_similarities,
                'chunk_mapping' => $chunk_mapping
            );
        }

        return $mapped_similarities;
    }

    /**
     * Get content from matching chunks for RAG context
     *
     * @param int $parent_id The parent post ID
     * @param array $chunk_mapping The chunk mapping from map_chunks_to_parents
     * @param int $max_chunks Maximum chunks to include (default: 3)
     * @return string Combined chunk content
     */
    public static function get_chunk_content_for_rag($parent_id, $chunk_mapping, $max_chunks = 3) {
        if (!isset($chunk_mapping[$parent_id]) || empty($chunk_mapping[$parent_id])) {
            return '';
        }

        $chunks = array_slice($chunk_mapping[$parent_id], 0, $max_chunks);
        $content_parts = array();

        foreach ($chunks as $chunk_info) {
            $chunk_id = $chunk_info['chunk_id'];
            $chunk_post = get_post($chunk_id);

            if ($chunk_post) {
                $chunk_number = get_post_meta($chunk_id, '_chunk_number', true);
                $total_chunks = get_post_meta($chunk_id, '_chunk_total', true);

                $content_parts[] = sprintf(
                    "[Chunk %d/%d]\n%s",
                    $chunk_number,
                    $total_chunks,
                    $chunk_post->post_content
                );
            }
        }

        return implode("\n\n---\n\n", $content_parts);
    }
}
