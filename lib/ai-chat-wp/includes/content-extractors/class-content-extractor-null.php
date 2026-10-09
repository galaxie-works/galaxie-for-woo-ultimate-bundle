<?php
/**
 * Null Content Extractor
 *
 * Returns empty content for Pro-only post types.
 * Used when someone tries to use page/product without Pro license.
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_Null {

    /**
     * Extract content - returns empty string
     * This ensures no embeddings are generated for Pro-only post types
     *
     * @param int $post_id Post ID
     * @return string Empty string
     */
    public function extract_content($post_id) {
        // Pro feature - return empty so no embedding is generated
        return '';
    }
}
