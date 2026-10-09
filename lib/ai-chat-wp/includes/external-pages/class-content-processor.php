<?php
/**
 * Content Processor - HTML to Text Extraction
 *
 * Extracts readable text content from HTML pages.
 * Removes scripts, styles, navigation, and other non-content elements.
 *
 * @package AICWP
 * @since 1.8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Processor {

    /**
     * Extract text content from HTML
     *
     * @param string $html Raw HTML content
     * @return array Array with 'title' and 'text' keys
     */
    public function extract($html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);

        // Ensure UTF-8 encoding
        $html = $this->ensure_utf8($html);
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Get title
        $title = '';
        $titles = $doc->getElementsByTagName('title');
        if ($titles->length > 0) {
            $title = trim($titles->item(0)->textContent);
        }

        // Remove non-content tags
        $remove_tags = array(
            'script', 'style', 'noscript', 'nav', 'header', 'footer',
            'aside', 'iframe', 'svg', 'canvas', 'video', 'audio',
            'form', 'button', 'select', 'input', 'textarea',
        );

        foreach ($remove_tags as $tag) {
            $elements = $doc->getElementsByTagName($tag);
            // Remove elements in reverse order to avoid index issues
            for ($i = $elements->length - 1; $i >= 0; $i--) {
                $el = $elements->item($i);
                if ($el && $el->parentNode) {
                    $el->parentNode->removeChild($el);
                }
            }
        }

        // Find main content using common selectors
        $xpath = new DOMXPath($doc);
        $main_queries = array(
            '//main',
            '//article',
            '//*[@role="main"]',
            '//*[@id="content"]',
            '//*[@id="main-content"]',
            '//*[@id="main"]',
            '//*[contains(@class, "post-content")]',
            '//*[contains(@class, "entry-content")]',
            '//*[contains(@class, "article-content")]',
            '//*[contains(@class, "docs-content")]',
            '//*[contains(@class, "markdown-body")]',
            '//*[contains(@class, "content-area")]',
            '//*[contains(@class, "page-content")]',
        );

        $text = '';
        foreach ($main_queries as $query) {
            $elements = $xpath->query($query);
            if ($elements && $elements->length > 0) {
                $text = $elements->item(0)->textContent;
                break;
            }
        }

        // Fallback to body if no main content found
        if (empty(trim($text))) {
            $body = $doc->getElementsByTagName('body');
            if ($body->length > 0) {
                $text = $body->item(0)->textContent;
            }
        }

        // Clean whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Limit text length to prevent excessive content
        if (strlen($text) > 50000) {
            $text = substr($text, 0, 50000);
        }

        return array(
            'title' => $title,
            'text' => $text,
        );
    }

    /**
     * Ensure string is valid UTF-8
     *
     * @param string $string Input string
     * @return string UTF-8 encoded string
     */
    private function ensure_utf8($string) {
        // Detect encoding
        $encoding = mb_detect_encoding($string, array('UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'), true);

        // Convert to UTF-8 if needed
        if ($encoding && $encoding !== 'UTF-8') {
            $string = mb_convert_encoding($string, 'UTF-8', $encoding);
        }

        // Remove invalid UTF-8 sequences
        return mb_convert_encoding($string, 'UTF-8', 'UTF-8');
    }
}
