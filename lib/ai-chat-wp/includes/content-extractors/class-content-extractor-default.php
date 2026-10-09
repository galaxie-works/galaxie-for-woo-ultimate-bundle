<?php
/**
 * Default Content Extractor
 *
 * Fallback extractor for any post type not explicitly handled.
 * Provides basic content extraction using WordPress core fields.
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_Default {

    /**
     * Extract content from any post type using generic approach
     *
     * @param int $post_id Post ID
     * @return string Structured content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post) {
            return '';
        }

        $structured_content = "";

        // Title
        $structured_content .= "TITLE: " . get_the_title($post_id) . ". ";

        // Post type (helps AI understand context)
        $post_type_obj = get_post_type_object($post->post_type);
        if ($post_type_obj) {
            $structured_content .= "TYPE: " . $post_type_obj->labels->singular_name . ". ";
        }

        // Content extraction - check if page builder needs rendered HTML
        $content = '';

        if (AICWP_Content_Extractor_Factory::content_needs_rendering($post_id)) {
            $rendered_html = AICWP_Content_Extractor_Factory::fetch_rendered_content($post_id);
            if ($rendered_html) {
                $content = AICWP_Content_Extractor_Factory::extract_from_rendered_html($rendered_html);
            }
            // Fallback to post_content if fetch failed
            if (empty($content) && !empty($post->post_content)) {
                $content = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($post->post_content);
            }
        } elseif (!empty($post->post_content)) {
            $content = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($post->post_content);
        }

        if (!empty($content)) {
            $structured_content .= "CONTENT: " . $content . ". ";
        }

        // Excerpt
        if (!empty($post->post_excerpt)) {
            $excerpt = AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($post->post_excerpt);
            $structured_content .= "EXCERPT: " . $excerpt . ". ";
        }

        // Get all taxonomies for this post type
        $taxonomies = get_object_taxonomies($post->post_type, 'objects');
        if (!empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy) {
                // Skip built-in non-relevant taxonomies
                if (in_array($taxonomy->name, array('post_format', 'nav_menu', 'link_category'))) {
                    continue;
                }

                $terms = wp_get_post_terms($post_id, $taxonomy->name, array('fields' => 'names'));
                if (!is_wp_error($terms) && !empty($terms)) {
                    $label = strtoupper(str_replace(' ', '_', $taxonomy->label));
                    $structured_content .= $label . ": " . implode(', ', $terms) . ". ";
                }
            }
        }

        // Featured image alt text
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $alt_text = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
            if (!empty($alt_text)) {
                $structured_content .= "IMAGE_CONTEXT: " . $alt_text . ". ";
            }
        }

        // Author
        $author = get_the_author_meta('display_name', $post->post_author);
        if ($author) {
            $structured_content .= "AUTHOR: " . $author . ". ";
        }

        // Auto-detect and extract public custom fields (using shared Factory method)
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
