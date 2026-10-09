<?php
/**
 * Content Chunker Class
 *
 * Handles intelligent content chunking for long posts/pages
 * to improve embedding quality.
 *
 * @package AICWP_Plugin
 * @since 2.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Chunker {

    /**
     * Default character threshold - only chunk if content exceeds this
     *
     * Using characters instead of words because PHP's word counting
     * doesn't work reliably for non-Latin scripts (Cyrillic, CJK, Arabic).
     *
     * 7000 chars provides better semantic density per vector
     * while balancing CPU cost of PHP cosine similarity calculations.
     */
    const DEFAULT_THRESHOLD = 7000;

    /**
     * Default target characters per chunk
     *
     * ~3500 chars provides focused semantic meaning per vector
     * for improved search precision.
     */
    const DEFAULT_CHUNK_SIZE = 3500;

    /**
     * Default overlap words between chunks
     */
    const DEFAULT_OVERLAP = 50;

    /**
     * Post type for content chunks
     */
    const CHUNK_POST_TYPE = 'ai_content_chunk';

    /**
     * Initialize the chunker
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'register_chunk_post_type'), 5);

        // Clean up chunks when parent post is deleted
        add_action('before_delete_post', array(__CLASS__, 'delete_chunks_on_parent_delete'));

        // Clean up chunks when parent post is unpublished
        add_action('transition_post_status', array(__CLASS__, 'handle_parent_status_change'), 10, 3);
    }

    /**
     * Register the hidden chunk post type
     */
    public static function register_chunk_post_type() {
        register_post_type(self::CHUNK_POST_TYPE, array(
            'labels' => array(
                'name' => __('Content Chunks', 'ai-chat-wp'),
                'singular_name' => __('Content Chunk', 'ai-chat-wp'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'supports' => array('title', 'editor', 'custom-fields'),
            'exclude_from_search' => true,
        ));
    }

    /**
     * Get post types that should be chunked
     *
     * Only long-form content types (posts, pages) are chunked.
     * Structured data types (listings, products) get single embeddings
     * with content capped at 8000 chars by their extractors.
     *
     * @return array Array of post type slugs
     */
    public static function get_chunked_post_types() {
        // Get all post types enabled for AI search embeddings
        $enabled_types = AICWP_Database_Manager::get_enabled_post_types();

        // Fallback to defaults if no types enabled yet
        if (empty($enabled_types)) {
            $enabled_types = array('post', 'page');
        }

        // Exclude structured data types - they get single embeddings with 8k char cap
        $exclude_from_chunking = array('product');
        $enabled_types = array_diff($enabled_types, $exclude_from_chunking);

        /**
         * Filter to add/remove post types for chunking
         *
         * @param array $post_types Array of post type slugs to chunk
         */
        return apply_filters('aicwp_chunked_post_types', array_values($enabled_types));
    }

    /**
     * Get chunking threshold (minimum characters before chunking)
     *
     * @return int Character threshold
     */
    public static function get_threshold() {
        $threshold = get_option('aicwp_chunk_threshold', self::DEFAULT_THRESHOLD);
        return apply_filters('aicwp_chunk_threshold', intval($threshold));
    }

    /**
     * Get target chunk size in characters
     *
     * @return int Target characters per chunk
     */
    public static function get_chunk_size() {
        $size = get_option('aicwp_chunk_size', self::DEFAULT_CHUNK_SIZE);
        return apply_filters('aicwp_chunk_size', intval($size));
    }

    /**
     * Check if a post should be chunked
     *
     * Uses CHARACTER count (not words) because PHP's word counting
     * fails for non-Latin scripts (Cyrillic, CJK, Arabic).
     *
     * @param int $post_id Post ID
     * @return bool True if post should be chunked
     */
    public static function should_chunk($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            return false;
        }

        // Check if post type is in chunked types list
        $chunked_types = self::get_chunked_post_types();
        if (!in_array($post->post_type, $chunked_types)) {
            return false;
        }

        // Use CHARACTER count for reliable cross-language detection
        $char_count = self::get_char_count($post_id);

        // Check if content exceeds threshold
        return $char_count > self::get_threshold();
    }

    /**
     * Get character count for post content
     *
     * Uses the same cleaning as create_chunks() to ensure consistent
     * threshold checks (removes shortcodes, HTML, etc.).
     *
     * @param int $post_id Post ID
     * @return int Character count
     */
    public static function get_char_count($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        // Use same cleaning as create_chunks() for consistent char count
        $content = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($post->post_content);
        return self::count_chars_utf8($content);
    }

    /**
     * Get word count for post content (uses raw post_content)
     *
     * Uses UTF-8 aware counting to properly handle Cyrillic, CJK, and other
     * non-ASCII languages where PHP's str_word_count() fails.
     *
     * @param int $post_id Post ID
     * @return int Word count
     */
    public static function get_word_count($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $content = strip_tags($post->post_content);
        return self::count_words_utf8($content);
    }

    /**
     * Count words in UTF-8 text (works with Cyrillic, CJK, etc.)
     *
     * PHP's str_word_count() only counts ASCII characters as words,
     * so "Привет мир" returns 0. This method properly counts any language.
     *
     * @param string $text Text to count words in
     * @return int Word count
     */
    public static function count_words_utf8($text) {
        if (empty($text)) {
            return 0;
        }

        // Normalize whitespace
        $text = preg_replace('/\s+/u', ' ', trim($text));

        // preg_replace with /u flag returns null on invalid UTF-8 input
        if (empty($text)) {
            return 0;
        }

        // Split by whitespace (Unicode-aware)
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }

    /**
     * Count characters in UTF-8 text
     *
     * @param string $text Text to count characters in
     * @return int Character count
     */
    public static function count_chars_utf8($text) {
        if (empty($text)) {
            return 0;
        }
        $text = preg_replace('/\s+/u', ' ', trim($text));
        // preg_replace with /u flag returns null on invalid UTF-8 input
        if ($text === null) {
            return 0;
        }
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * Calculate optimal number of chunks based on content length
     *
     * Uses CHARACTER count for reliable cross-language support.
     *
     * @param int $char_count Total character count
     * @return int Number of chunks
     */
    public static function calculate_chunk_count($char_count) {
        $threshold = self::get_threshold();
        $chunk_size = self::get_chunk_size();

        if ($char_count <= $threshold) {
            return 1;
        }

        return (int) ceil($char_count / $chunk_size);
    }

    /**
     * Split content into chunks
     *
     * Intelligent splitting that respects sentence boundaries.
     * Falls back to word-based splitting if sentence detection fails.
     *
     * @param string $content Full content to split
     * @param int $num_chunks Target number of chunks
     * @return array Array of content chunks
     */
    public static function split_content($content, $num_chunks) {
        if ($num_chunks <= 1) {
            return array($content);
        }

        // Clean content - preserve links for LLM context
        $content = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($content);

        if (empty($content)) {
            return array($content);
        }

        $total_words = self::count_words_utf8($content);
        if ($total_words === 0) {
            return array($content);
        }

        $target_words_per_chunk = (int) ceil($total_words / $num_chunks);

        // Try sentence-aware splitting first
        $chunks = self::split_by_sentences($content, $target_words_per_chunk, $num_chunks);

        // Validate we got reasonable chunks - check both total words AND distribution
        $use_fallback = false;
        if (empty($chunks) || self::total_words_in_chunks($chunks) < ($total_words * 0.9)) {
            $use_fallback = true;
        } elseif (!self::is_chunk_distribution_acceptable($chunks, $target_words_per_chunk)) {
            // Check if any chunk is way too big or too small
            $use_fallback = true;
        }

        if ($use_fallback) {
            // Fallback to simple word splitting
            $chunks = self::split_by_words($content, $num_chunks);
        }

        // Debug logging
        if (get_option('aicwp_debug_mode', false)) {
            $chunk_words = self::total_words_in_chunks($chunks);
            $chunk_sizes = array_map(array(__CLASS__, 'count_words_utf8'), $chunks);
            AICWP_Utility_Helper::debug_log(
                sprintf("split_content: %d words -> %d chunks (%d words total, sizes: %s, fallback: %s)",
                    $total_words, count($chunks), $chunk_words, implode('/', $chunk_sizes), $use_fallback ? 'yes' : 'no'),
                'info'
            );
        }

        return $chunks;
    }

    /**
     * Check if chunk size distribution is acceptable
     *
     * A chunk is considered unacceptable if:
     * - It's more than 2.5x the target size, OR
     * - It's less than 0.15x the target size (and target is > 100 words)
     *
     * @param array $chunks Array of chunk strings
     * @param int $target_words Target words per chunk
     * @return bool True if distribution is acceptable
     */
    private static function is_chunk_distribution_acceptable($chunks, $target_words) {
        if (empty($chunks) || $target_words <= 0) {
            return false;
        }

        foreach ($chunks as $chunk) {
            $words = self::count_words_utf8($chunk);

            // Chunk too big (more than 2.5x target)
            if ($words > $target_words * 2.5) {
                return false;
            }

            // Chunk too small (less than 15% of target, but only if target is substantial)
            if ($target_words > 100 && $words < $target_words * 0.15) {
                return false;
            }
        }

        return true;
    }

    /**
     * Split by sentences, respecting word count targets
     *
     * @param string $content Clean content
     * @param int $target_words Target words per chunk
     * @param int $num_chunks Target number of chunks
     * @return array Array of chunks
     */
    private static function split_by_sentences($content, $target_words, $num_chunks) {
        // Split into sentences - match period/exclamation/question followed by space and capital
        // or end of string. Keep the punctuation with the sentence.
        $sentences = preg_split(
            '/(?<=[.!?])\s+(?=[A-Z])/u',
            $content,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (empty($sentences) || count($sentences) < 2) {
            return array(); // Let fallback handle it
        }

        $chunks = array();
        $current_chunk = '';
        $current_words = 0;

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (empty($sentence)) {
                continue;
            }

            $sentence_words = self::count_words_utf8($sentence);

            // If this sentence alone exceeds target, add what we have and start fresh
            if ($sentence_words > $target_words * 1.5) {
                if (!empty($current_chunk)) {
                    $chunks[] = $current_chunk;
                }
                $chunks[] = $sentence;
                $current_chunk = '';
                $current_words = 0;
                continue;
            }

            // If adding this would exceed target by too much, start new chunk
            if ($current_words > 0 && ($current_words + $sentence_words) > $target_words * 1.2) {
                $chunks[] = $current_chunk;
                $current_chunk = $sentence;
                $current_words = $sentence_words;
            } else {
                // Add to current chunk
                $current_chunk .= ($current_chunk ? ' ' : '') . $sentence;
                $current_words += $sentence_words;
            }
        }

        // Don't forget last chunk
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        // Add overlap between chunks for context continuity
        if (count($chunks) > 1) {
            $chunks = self::add_overlap_to_chunks($chunks);
        }

        return $chunks;
    }

    /**
     * Add word overlap between chunks for context continuity
     *
     * Each chunk (except the first) gets the last N words from the
     * previous chunk prepended to maintain semantic context across
     * chunk boundaries.
     *
     * @param array $chunks Array of chunk strings
     * @return array Chunks with overlap added
     */
    private static function add_overlap_to_chunks($chunks) {
        $overlap = self::DEFAULT_OVERLAP;
        $result = array();

        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                // First chunk - no prefix needed
                $result[] = $chunk;
            } else {
                // Get last N words from previous chunk (use original, not already-overlapped)
                $prev_words = preg_split('/\s+/u', $chunks[$index - 1], -1, PREG_SPLIT_NO_EMPTY);
                $overlap_words = array_slice($prev_words, -$overlap);
                $overlap_text = implode(' ', $overlap_words);

                // Prepend overlap to current chunk
                $result[] = $overlap_text . ' ' . $chunk;
            }
        }

        return $result;
    }

    /**
     * Simple word-based splitting (fallback) with overlap
     *
     * Each chunk (except the first) includes overlap words from the end
     * of the previous chunk to maintain context continuity.
     *
     * @param string $content Content to split
     * @param int $num_chunks Number of chunks
     * @return array Array of chunks
     */
    private static function split_by_words($content, $num_chunks) {
        $words = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $total_words = count($words);

        if ($total_words === 0) {
            return array($content);
        }

        $overlap = self::DEFAULT_OVERLAP;
        $words_per_chunk = (int) ceil($total_words / $num_chunks);
        $chunks = array();

        for ($i = 0; $i < $num_chunks; $i++) {
            // Calculate offset - subtract overlap for chunks after the first
            if ($i === 0) {
                $offset = 0;
            } else {
                $offset = ($i * $words_per_chunk) - $overlap;
                // Don't go negative
                $offset = max(0, $offset);
            }

            // Calculate length - add overlap for all chunks except the last
            $length = $words_per_chunk;
            if ($i < $num_chunks - 1) {
                $length += $overlap;
            }

            $chunk_words = array_slice($words, $offset, $length);

            if (!empty($chunk_words)) {
                $chunks[] = implode(' ', $chunk_words);
            }
        }

        return $chunks;
    }

    /**
     * Count total words in all chunks
     *
     * @param array $chunks Array of chunk strings
     * @return int Total word count
     */
    private static function total_words_in_chunks($chunks) {
        $total = 0;
        foreach ($chunks as $chunk) {
            $total += self::count_words_utf8($chunk);
        }
        return $total;
    }

    /**
     * Create chunk posts for a parent post
     *
     * @param int $parent_id Parent post ID
     * @return array Array of created chunk IDs
     */
    public static function create_chunks($parent_id) {
        $parent = get_post($parent_id);

        if (!$parent) {
            return array();
        }

        // Delete existing chunks first
        self::delete_chunks_for_post($parent_id);

        // Use RAW post_content for chunking (not extractor which truncates to 8000 chars)
        // Preserve links for LLM context
        $raw_content = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($parent->post_content);

        $char_count = self::count_chars_utf8($raw_content);
        $num_chunks = self::calculate_chunk_count($char_count);

        if (get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log(
                sprintf("create_chunks: Post %d has %d chars, will create %d chunks",
                    $parent_id, $char_count, $num_chunks),
                'info'
            );
        }

        if ($num_chunks <= 1) {
            // No chunking needed
            return array();
        }

        // Split raw content into chunks
        $chunks = self::split_content($raw_content, $num_chunks);
        $total_chunks = count($chunks);
        $created_ids = array();

        foreach ($chunks as $index => $chunk_content) {
            $chunk_number = $index + 1;

            // Create chunk post
            $chunk_id = wp_insert_post(array(
                'post_type' => self::CHUNK_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sprintf(
                    /* translators: 1: parent post title, 2: chunk number, 3: total chunks */
                    __('%1$s - Chunk %2$d/%3$d', 'ai-chat-wp'),
                    $parent->post_title,
                    $chunk_number,
                    $total_chunks
                ),
                'post_content' => $chunk_content,
                'post_author' => $parent->post_author,
            ));

            if (!is_wp_error($chunk_id) && $chunk_id > 0) {
                // Store chunk metadata
                update_post_meta($chunk_id, '_chunk_parent_id', $parent_id);
                update_post_meta($chunk_id, '_chunk_number', $chunk_number);
                update_post_meta($chunk_id, '_chunk_total', $total_chunks);
                update_post_meta($chunk_id, '_chunk_source_type', $parent->post_type);
                update_post_meta($chunk_id, '_chunk_char_count', self::count_chars_utf8($chunk_content));

                $created_ids[] = $chunk_id;

                if (get_option('aicwp_debug_mode', false)) {
                    AICWP_Utility_Helper::debug_log(
                        sprintf("Created chunk %d/%d for post %d (chunk ID: %d, chars: %d)",
                            $chunk_number, $total_chunks, $parent_id, $chunk_id, self::count_chars_utf8($chunk_content)),
                        'info'
                    );
                }
            }
        }

        return $created_ids;
    }

    /**
     * Delete all chunks for a parent post
     *
     * @param int $parent_id Parent post ID
     * @return int Number of deleted chunks
     */
    public static function delete_chunks_for_post($parent_id) {
        $parent_id = (int) $parent_id;
        if ($parent_id <= 0) {
            return 0;
        }

        global $wpdb;

        // Get all chunk IDs for this parent
        $chunk_ids = $wpdb->get_col($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = '_chunk_parent_id'
            AND pm.meta_value = %d
        ", self::CHUNK_POST_TYPE, $parent_id));

        $deleted = 0;
        $table_name = AICWP_Database_Manager::get_embeddings_table_name();

        foreach ($chunk_ids as $chunk_id) {
            $chunk_id = (int) $chunk_id;
            if ($chunk_id <= 0) {
                continue;
            }

            // Delete embedding for this chunk
            $wpdb->delete($table_name, array('listing_id' => $chunk_id), array('%d'));

            // Delete the chunk post
            wp_delete_post($chunk_id, true);
            $deleted++;
        }

        if ($deleted > 0 && get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log(
                sprintf("Deleted %d chunks for parent post %d", $deleted, $parent_id),
                'info'
            );
        }

        return $deleted;
    }

    /**
     * Get all chunks for a parent post
     *
     * @param int $parent_id Parent post ID
     * @return array Array of chunk post objects
     */
    public static function get_chunks_for_post($parent_id) {
        return get_posts(array(
            'post_type' => self::CHUNK_POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_chunk_parent_id',
                    'value' => $parent_id,
                    'type' => 'NUMERIC',
                ),
            ),
            'meta_key' => '_chunk_number',
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
        ));
    }

    /**
     * Get chunk count for a parent post
     *
     * @param int $parent_id Parent post ID
     * @return int Number of chunks
     */
    public static function get_chunk_count($parent_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND pm.meta_key = '_chunk_parent_id'
            AND pm.meta_value = %d
        ", self::CHUNK_POST_TYPE, $parent_id));
    }

    /**
     * Check if a post is a chunk
     *
     * @param int $post_id Post ID
     * @return bool True if post is a chunk
     */
    public static function is_chunk($post_id) {
        $post = get_post($post_id);
        return $post && $post->post_type === self::CHUNK_POST_TYPE;
    }

    /**
     * Get parent post ID for a chunk
     *
     * @param int $chunk_id Chunk post ID
     * @return int|false Parent post ID or false
     */
    public static function get_chunk_parent_id($chunk_id) {
        $parent_id = get_post_meta($chunk_id, '_chunk_parent_id', true);
        return $parent_id ? intval($parent_id) : false;
    }

    /**
     * Clean up chunks when parent is deleted
     *
     * @param int $post_id Post ID being deleted
     */
    public static function delete_chunks_on_parent_delete($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);

        // Don't trigger on chunk deletion
        if ($post && $post->post_type === self::CHUNK_POST_TYPE) {
            return;
        }

        // Check if this post has chunks
        if (self::get_chunk_count($post_id) > 0) {
            self::delete_chunks_for_post($post_id);
        }
    }

    /**
     * Handle parent post status changes
     *
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public static function handle_parent_status_change($new_status, $old_status, $post) {
        // Validate post object
        if (!$post || !isset($post->ID) || (int) $post->ID <= 0) {
            return;
        }

        // Don't trigger on chunks
        if ($post->post_type === self::CHUNK_POST_TYPE) {
            return;
        }

        // If parent is unpublished/trashed, delete chunks
        if ($old_status === 'publish' && $new_status !== 'publish') {
            if (self::get_chunk_count($post->ID) > 0) {
                self::delete_chunks_for_post($post->ID);
            }
        }
    }

    /**
     * Get embedding info for all chunks of a parent post
     *
     * @param int $parent_id Parent post ID
     * @return array Array of chunk embedding info
     */
    public static function get_chunk_embedding_info($parent_id) {
        global $wpdb;

        $table_name = AICWP_Database_Manager::get_embeddings_table_name();

        $chunks = $wpdb->get_results($wpdb->prepare("
            SELECT
                p.ID as chunk_id,
                p.post_title,
                pm_num.meta_value as chunk_number,
                pm_total.meta_value as chunk_total,
                pm_words.meta_value as word_count,
                e.created_at as embedding_created,
                CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END as has_embedding
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_parent ON p.ID = pm_parent.post_id AND pm_parent.meta_key = '_chunk_parent_id'
            LEFT JOIN {$wpdb->postmeta} pm_num ON p.ID = pm_num.post_id AND pm_num.meta_key = '_chunk_number'
            LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_chunk_total'
            LEFT JOIN {$wpdb->postmeta} pm_words ON p.ID = pm_words.post_id AND pm_words.meta_key = '_chunk_word_count'
            LEFT JOIN {$table_name} e ON p.ID = e.listing_id
            WHERE p.post_type = %s
            AND pm_parent.meta_value = %d
            ORDER BY CAST(pm_num.meta_value AS UNSIGNED) ASC
        ", self::CHUNK_POST_TYPE, $parent_id), ARRAY_A);

        return $chunks ?: array();
    }
}

// Initialize the chunker
AICWP_Content_Chunker::init();
