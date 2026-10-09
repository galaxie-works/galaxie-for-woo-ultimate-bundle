<?php
/**
 * Content Extractor for Content Chunks
 *
 * Simple extractor that returns the chunk's stored content.
 * Chunks are pre-processed fragments of larger posts.
 *
 * @package AICWP_Plugin
 * @since 2.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_Chunk {

    /**
     * Extract content from a chunk post
     *
     * Chunks store pre-processed content, so we just return it
     * with minimal additional context.
     *
     * @param int $post_id Chunk post ID
     * @return string Content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== AICWP_Content_Chunker::CHUNK_POST_TYPE) {
            return '';
        }

        $content_parts = array();

        // Get parent post info for context
        $parent_id = get_post_meta($post_id, '_chunk_parent_id', true);
        $chunk_number = get_post_meta($post_id, '_chunk_number', true);
        $chunk_total = get_post_meta($post_id, '_chunk_total', true);
        $source_type = get_post_meta($post_id, '_chunk_source_type', true);

        if ($parent_id) {
            $parent = get_post($parent_id);
            if ($parent) {
                // Add parent context
                $content_parts[] = sprintf(
                    "SOURCE: %s (Part %d of %d)",
                    $parent->post_title,
                    $chunk_number,
                    $chunk_total
                );

                // Add parent post type context
                if ($source_type) {
                    $post_type_obj = get_post_type_object($source_type);
                    if ($post_type_obj) {
                        $content_parts[] = sprintf("TYPE: %s", $post_type_obj->labels->singular_name);
                    }
                }

                // For external pages, include the actual external URL
                if ($source_type === 'ai_external_page') {
                    $external_url = get_post_meta($parent_id, '_external_url', true);
                    if (!empty($external_url)) {
                        $content_parts[] = sprintf("PAGE URL: %s", $external_url);
                    }
                }

                // Add parent categories if available
                $categories = wp_get_post_categories($parent_id, array('fields' => 'names'));
                if (!empty($categories) && !is_wp_error($categories)) {
                    $content_parts[] = sprintf("CATEGORIES: %s", implode(', ', array_slice($categories, 0, 3)));
                }

                // Add parent tags if available
                $tags = wp_get_post_tags($parent_id, array('fields' => 'names'));
                if (!empty($tags) && !is_wp_error($tags)) {
                    $content_parts[] = sprintf("TAGS: %s", implode(', ', array_slice($tags, 0, 5)));
                }
            }
        }

        // Add the chunk content (already cleaned)
        $chunk_content = $post->post_content;
        if (!empty($chunk_content)) {
            $content_parts[] = "CONTENT: " . $chunk_content;
        }

        return implode(". ", $content_parts);
    }
}
