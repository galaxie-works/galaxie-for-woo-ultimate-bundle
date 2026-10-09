<?php
/**
 * Document Content Extractor
 *
 * Extracts content from document posts (PDF, TXT, MD, XML, CSV) for embedding generation
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractor_PDF {

    /**
     * Extract content from document post
     *
     * @param int $post_id Post ID
     * @return string Formatted content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'ai_pdf_document') {
            return '';
        }

        $content = '';

        // Get document metadata
        $original_filename = get_post_meta($post_id, '_pdf_original_filename', true);
        $chunk_number = get_post_meta($post_id, '_pdf_chunk_number', true);
        $total_chunks = get_post_meta($post_id, '_pdf_total_chunks', true);

        // Header section
        $content .= "DOCUMENT\n";
        $content .= str_repeat('━', 40) . "\n";

        // Filename
        if ($original_filename) {
            $content .= sprintf(__('File: %s', 'ai-chat-wp'), $original_filename) . "\n";
        }

        // Chunk information (if document is chunked)
        if ($total_chunks > 1) {
            $content .= sprintf(
                __('Part %d of %d', 'ai-chat-wp'),
                $chunk_number,
                $total_chunks
            ) . "\n";
        }

        $content .= "\n";

        // Title section (chunk title or document title)
        if ($post->post_title) {
            $content .= "TITLE\n";
            $content .= str_repeat('━', 40) . "\n";
            $content .= $post->post_title . "\n\n";
        }

        // Main content section
        $content .= "CONTENT\n";
        $content .= str_repeat('━', 40) . "\n";

        // Extract and clean the content
        $text_content = $post->post_content;

        // Remove excessive whitespace while preserving structure
        $text_content = preg_replace('/[ \t]+/', ' ', $text_content);
        $text_content = preg_replace('/\n{3,}/', "\n\n", $text_content);
        $text_content = trim($text_content);

        $content .= $text_content . "\n";

        // Apply filter to allow customization
        $content = apply_filters('aicwp_pdf_extracted_content', $content, $post_id, $post);

        return $content;
    }

    /**
     * Get preview of document content
     *
     * @param int $post_id Post ID
     * @param int $length Maximum length in characters
     * @return string Preview text
     */
    public function get_preview($post_id, $length = 200) {
        $content = $this->extract_content($post_id);

        // Remove headers/formatting for preview
        $content = preg_replace('/^[A-Z\s]+\n━+\n/m', '', $content);
        $content = trim($content);

        if (mb_strlen($content) > $length) {
            $content = mb_substr($content, 0, $length) . '...';
        }

        return $content;
    }
}
