<?php
/**
 * Content Extractor Factory
 *
 * Routes post types to their appropriate content extractors.
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_Factory {

    /**
     * Get the appropriate extractor for a post type
     *
     * @param string $post_type Post type slug
     * @return object Content extractor instance
     */
    public static function get_extractor($post_type) {
        // Allow Pro plugin to provide extractors for premium post types
        $pro_extractor = apply_filters('aicwp_content_extractor', null, $post_type);
        if ($pro_extractor !== null) {
            return $pro_extractor;
        }

        switch ($post_type) {
            case 'post':
                return new AICWP_Content_Extractor_Post();

            // Page and Product extractors are Pro features
            // Without Pro, return null extractor that produces no content
            case 'page':
            case 'product':
                // Pro plugin provides real extractors via filter above
                // Free version returns empty - no embeddings generated
                return new AICWP_Content_Extractor_Null();

            case 'ai_pdf_document':
                return new AICWP_Content_Extractor_PDF();

            case 'ai_content_chunk':
                return new AICWP_Content_Extractor_Chunk();

            case 'ai_external_page':
                return new AICWP_Content_Extractor_External_Page();

            default:
                return new AICWP_Content_Extractor_Default();
        }
    }

    /**
     * Get extractor for a specific post ID
     *
     * @param int $post_id Post ID
     * @return object|false Content extractor instance or false if post not found
     */
    public static function get_extractor_for_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        return self::get_extractor($post->post_type);
    }

    /**
     * Extract content using the appropriate extractor
     *
     * @param int $post_id Post ID
     * @return string Extracted content or empty string on failure
     */
    public static function extract_content($post_id) {
        $extractor = self::get_extractor_for_post($post_id);

        if (!$extractor) {
            return '';
        }

        return $extractor->extract_content($post_id);
    }

    /**
     * Auto-detect and extract public custom fields from post meta
     *
     * Shared helper method that all extractors can use.
     * Automatically finds and extracts custom fields that pass two layers of filtering.
     *
     * @param int $post_id Post ID
     * @param array $already_extracted Array of meta keys already extracted by the specific extractor
     * @return string Formatted custom fields string or empty
     */
    public static function extract_custom_fields($post_id, $already_extracted = array()) {
        // Check if custom fields inclusion is disabled
        if (get_option('aicwp_disable_custom_fields', false)) {
            return '';
        }

        $meta = get_post_meta($post_id);

        if (empty($meta)) {
            return '';
        }

        // Layer 1: Exact WordPress core system fields to exclude
        $exclude_exact = array(
            '_edit_lock',
            '_edit_last',
            '_thumbnail_id',
            '_encloseme',
            '_pingme',
            '_wp_page_template',
            '_wp_trash_meta_status',
            '_wp_trash_meta_time',
        );

        // Layer 1: Pattern-based exclusions for known system/plugin fields
        $exclude_patterns = array(
            '_oembed_',      // oEmbed cache (creates many entries)
            '_wp_old_',      // Old slugs
            '_wp_attached',  // Attachment metadata
            'elementor',     // Elementor page builder
            'gdlr',          // GoodLayers page builder
            'rank_math',     // Rank Math SEO
            'yoast',         // Yoast SEO
            '_genesis',      // Genesis theme
        );

        // Allow filtering of exclusions
        $exclude_exact = apply_filters('aicwp_custom_fields_exclude_exact', $exclude_exact);
        $exclude_patterns = apply_filters('aicwp_custom_fields_exclude_patterns', $exclude_patterns);

        $custom_fields = array();
        $max_fields = 20;
        $field_count = 0;

        foreach ($meta as $key => $values) {
            if ($field_count >= $max_fields) {
                break;
            }

            // Skip fields already extracted by the specific extractor
            if (in_array($key, $already_extracted, true)) {
                continue;
            }

            // Layer 1: Skip exact match system fields
            if (in_array($key, $exclude_exact, true)) {
                continue;
            }

            // Skip numeric keys
            if (is_numeric($key)) {
                continue;
            }

            // Layer 1: Skip known system field patterns
            $skip = false;
            foreach ($exclude_patterns as $pattern) {
                if (stripos($key, $pattern) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            // Layer 2: Get value and skip if empty
            $value = isset($values[0]) ? $values[0] : '';
            if (empty($value) && $value !== '0') {
                continue;
            }

            // Layer 2: Skip serialized data (arrays, objects)
            if (is_serialized($value)) {
                continue;
            }

            // Layer 2: Skip non-string/non-numeric values
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }

            // Layer 2: Skip very long values (not useful for search)
            if (strlen($value) > 1000) {
                continue;
            }

            // Layer 2: Skip values that look like URLs, JSON, HTML, or hashes
            if (
                filter_var($value, FILTER_VALIDATE_URL) ||
                preg_match('/^[\[\{]/', trim($value)) ||  // JSON
                preg_match('/<[^>]+>/', $value) ||        // HTML
                preg_match('/^[a-f0-9]{32,}$/i', $value)  // Hashes
            ) {
                continue;
            }

            // Clean and format the value
            $value = strip_tags($value);
            $value = preg_replace('/\s+/', ' ', $value);
            $value = trim($value);

            // Layer 2: Skip if value became empty after cleaning
            if (empty($value)) {
                continue;
            }

            // Create human-readable label from meta key
            $label = self::format_field_label($key);

            $custom_fields[] = $label . ": " . $value;
            $field_count++;
        }

        // Allow filtering of extracted custom fields
        $custom_fields = apply_filters('aicwp_extracted_custom_fields', $custom_fields, $post_id);

        return implode('. ', $custom_fields);
    }

    /**
     * Convert HTML links to readable text format and strip remaining tags
     *
     * Converts <a href="URL">text</a> to "text (URL)" before stripping other HTML tags.
     * This preserves URL information for LLM context.
     *
     * @param string $content HTML content
     * @return string Cleaned content with preserved link URLs
     */
    public static function preserve_links_and_strip_tags($content) {
        if (empty($content)) {
            return '';
        }

        // FIRST: Remove script/style tags AND their content (strip_tags only removes tags, not content)
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $content);
        $content = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $content);
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        $content = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $content);

        // Remove page builder shortcodes
        $content = preg_replace('/\[elementor[^\]]*\]/', '', $content);
        $content = preg_replace('/\[vc_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/vc_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[et_pb_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/et_pb_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[gdlr_core_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/gdlr_core_[^\]]*\]/', '', $content);

        // Remove Flavor documentation theme shortcode TAGS (keep content inside)
        $content = preg_replace('/\[lore_alert_message[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/lore_alert_message\]/', '', $content);
        // Separators - replace with markdown horizontal rule
        $content = preg_replace('/\[lore_separator[^\]]*\]/', '---', $content);
        // Accordion items - convert to Q&A format
        $content = preg_replace_callback(
            '/\[lore_accordion_item[^\]]*title=["\']([^"\']+)["\'][^\]]*\](.*?)\[\/lore_accordion_item\]/s',
            function($matches) {
                $question = trim($matches[1]);
                $answer = trim($matches[2]);
                return "Q: {$question}\nA: {$answer}";
            },
            $content
        );
        // Clean up accordion wrapper tags if present
        $content = preg_replace('/\[lore_accordion[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/lore_accordion\]/', '', $content);

        // Convert <a href="URL">text</a> to "text (URL)"
        // Handle both single and double quotes for href attribute
        $content = preg_replace_callback(
            '/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            function($matches) {
                $url = trim($matches[1]);
                $text = trim(strip_tags($matches[2])); // Strip any nested tags in link text

                // Skip empty links or javascript/anchor links
                if (empty($url) || strpos($url, 'javascript:') === 0 || $url === '#') {
                    return $text;
                }

                // Skip if text is empty
                if (empty($text)) {
                    return '';
                }

                // Don't duplicate URL if text already contains it
                if (strpos($text, $url) !== false) {
                    return $text;
                }

                return '[' . $text . '](' . $url . ')';
            },
            $content
        );

        // Convert headings to markdown before stripping tags
        // Using ** bold ** as heading marker since newlines get collapsed later
        $content = preg_replace('/<h1[^>]*>(.*?)<\/h1>/is', ' **$1** ', $content);
        $content = preg_replace('/<h2[^>]*>(.*?)<\/h2>/is', ' **$1** ', $content);
        $content = preg_replace('/<h3[^>]*>(.*?)<\/h3>/is', ' **$1** ', $content);
        $content = preg_replace('/<h4[^>]*>(.*?)<\/h4>/is', ' **$1** ', $content);
        $content = preg_replace('/<h5[^>]*>(.*?)<\/h5>/is', ' **$1** ', $content);
        $content = preg_replace('/<h6[^>]*>(.*?)<\/h6>/is', ' **$1** ', $content);

        // Now strip remaining HTML tags
        $content = strip_tags($content);

        // Normalize whitespace
        $content = preg_replace('/\s+/', ' ', $content);

        return trim($content);
    }

    /**
     * Format meta key into human-readable label
     *
     * @param string $key Meta key
     * @return string Formatted label
     */
    public static function format_field_label($key) {
        // Remove leading underscore for display
        $key = ltrim($key, '_');

        // Handle camelCase
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);

        // Replace underscores and hyphens with spaces
        $key = str_replace(array('_', '-'), ' ', $key);

        // Capitalize words
        $key = ucwords(strtolower($key));

        return $key;
    }

    /**
     * Check if a post uses a page builder that stores content as shortcodes or in meta
     *
     * @param int $post_id Post ID
     * @return bool True if this post needs rendered content fetching
     */
    public static function content_needs_rendering($post_id) {
        // 1. Divi
        $divi_builder = get_post_meta($post_id, '_et_pb_use_builder', true);
        if ($divi_builder === 'on') {
            return true;
        }

        // 2. GoodLayers
        $gdlr_builder = get_post_meta($post_id, '_gdlr_core_page_builder', true);
        if (!empty($gdlr_builder)) {
            return true;
        }

        // 3. Breakdance
        $breakdance_data = get_post_meta($post_id, '_breakdance_data', true);
        if (!empty($breakdance_data)) {
            return true;
        }

        // 4. WPBakery
        $wpb_status = get_post_meta($post_id, '_wpb_vc_js_status', true);
        if ($wpb_status === 'true') {
            return true;
        }

        // 5. Oxygen
        $oxygen_data = get_post_meta($post_id, 'ct_builder_shortcodes', true);
        if (!empty($oxygen_data)) {
            return true;
        }

        // 6. Bricks
        $bricks_data = get_post_meta($post_id, '_bricks_page_content_2', true);
        if (!empty($bricks_data)) {
            return true;
        }

        return false;
    }

    /**
     * Fetch rendered HTML content from a post URL
     *
     * @param int $post_id Post ID
     * @return string|false Rendered HTML content or false on failure
     */
    public static function fetch_rendered_content($post_id) {
        $post = get_post($post_id);

        if (!$post || !empty($post->post_password) || $post->post_status !== 'publish') {
            return false;
        }

        $url = get_permalink($post_id);
        if (!$url) {
            return false;
        }

        $response = wp_remote_get($url, array(
            'timeout'     => 15,
            'user-agent'  => 'AI-Chat-WP-Embeddings/1.0',
            'redirection' => 3,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Extract main content from rendered HTML
     *
     * @param string $html Full page HTML
     * @return string Extracted text content
     */
    public static function extract_from_rendered_html($html) {
        if (empty($html)) {
            return '';
        }

        $content = $html;

        // Try to find main content area (avoid headers/footers/sidebars)
        $selectors = array(
            '/<div[^>]*id=["\']main-content["\'][^>]*>(.*?)<\/div>\s*(?:<footer|<div[^>]*id=["\']footer)/is',
            '/<article[^>]*>(.*?)<\/article>/is',
            '/<div[^>]*class=["\'][^"\']*entry-content[^"\']*["\'][^>]*>(.*?)<\/div>/is',
            '/<main[^>]*>(.*?)<\/main>/is',
            '/<body[^>]*>(.*?)<\/body>/is',
        );

        foreach ($selectors as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $content = $matches[1];
                break;
            }
        }

        return self::preserve_links_and_strip_tags($content);
    }
}
