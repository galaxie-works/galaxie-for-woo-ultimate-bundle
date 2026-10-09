<?php
/**
 * Post Content Extractor
 *
 * Extracts content from WordPress standard 'post' post type for AI embeddings.
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_Post {

    /**
     * Extract content from blog post for embedding generation
     *
     * @param int $post_id Post ID
     * @return string Structured content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'post') {
            return '';
        }

        $structured_content = "";

        // Title
        $structured_content .= "TITLE: " . get_the_title($post_id) . ". ";

        // Content - preserve links for LLM context
        if (!empty($post->post_content)) {
            $content = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($post->post_content);
            $structured_content .= "CONTENT: " . $content . ". ";
        }

        // Excerpt
        if (!empty($post->post_excerpt)) {
            $excerpt = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($post->post_excerpt);
            $structured_content .= "EXCERPT: " . $excerpt . ". ";
        }

        // Categories
        $categories = get_the_category($post_id);
        if (!empty($categories)) {
            $cat_names = array_map(function($cat) {
                return $cat->name;
            }, $categories);
            $structured_content .= "CATEGORIES: " . implode(', ', $cat_names) . ". ";
        }

        // Tags
        $tags = get_the_tags($post_id);
        if (!empty($tags)) {
            $tag_names = array_map(function($tag) {
                return $tag->name;
            }, $tags);
            $structured_content .= "TAGS: " . implode(', ', $tag_names) . ". ";
        }

        // Author
        $author_id = $post->post_author;
        $author = get_the_author_meta('display_name', $author_id);
        if ($author) {
            $structured_content .= "AUTHOR: " . $author . ". ";
        }

        // Featured image alt text (useful for context)
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $alt_text = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
            if (!empty($alt_text)) {
                $structured_content .= "IMAGE_CONTEXT: " . $alt_text . ". ";
            }
        }

        // Auto-detect additional custom fields
        $custom_fields_content = AICWP_Content_Extractor_Factory::extract_custom_fields($post_id);
        if (!empty($custom_fields_content)) {
            $structured_content .= "CUSTOM_FIELDS: " . $custom_fields_content . ". ";
        }

        // Limit total length
        if (strlen($structured_content) > 8000) {
            $structured_content = substr($structured_content, 0, 8000);
        }

        return trim($structured_content);
    }
}
