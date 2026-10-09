<?php
/**
 * External Page Content Extractor
 *
 * Extracts content from external web pages stored as ai_external_page CPT.
 * Used for embedding generation of scraped external website content.
 *
 * @package AICWP_Plugin
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_External_Page {

    /**
     * Extract content from external page for embedding generation
     *
     * @param int $post_id Post ID
     * @return string Structured content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'ai_external_page') {
            return '';
        }

        $structured_content = "";

        // Title
        $structured_content .= "TITLE: " . get_the_title($post_id) . ". ";

        // External URL (important for context)
        $external_url = get_post_meta($post_id, '_external_url', true);
        if (!empty($external_url)) {
            $structured_content .= "SOURCE_URL: " . $external_url . ". ";
        }

        // Source name (optional grouping label)
        $source_name = get_post_meta($post_id, '_external_source_name', true);
        if (!empty($source_name)) {
            $structured_content .= "SOURCE: " . $source_name . ". ";
        }

        // Content - already extracted text stored in post_content
        if (!empty($post->post_content)) {
            // Content is already plain text from the scraper, just clean whitespace
            $content = preg_replace('/\s+/', ' ', $post->post_content);
            $content = trim($content);
            $structured_content .= "CONTENT: " . $content . ". ";
        }

        // Limit total length to prevent excessive embedding size
        if (strlen($structured_content) > 8000) {
            $structured_content = substr($structured_content, 0, 8000);
        }

        return trim($structured_content);
    }
}
