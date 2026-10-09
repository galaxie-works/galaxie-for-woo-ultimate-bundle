<?php
/**
 * AI Chat for WordPress - Content Extractors
 *
 * Provides content extractors for premium post types (page, product).
 * Without Pro, these post types use the default basic extractor.
 *
 * @package AICWP
 * @since 1.7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Extractors {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into extractor factory to provide Pro extractors
        add_filter('aicwp_content_extractor', array($this, 'get_extractor'), 10, 2);
    }

    /**
     * Get extractor for premium post types
     *
     * @param object|null $extractor Current extractor (null if not set)
     * @param string $post_type Post type slug
     * @return object|null Extractor instance or null to use default
     */
    public function get_extractor($extractor, $post_type) {
        switch ($post_type) {
            case 'page':
                return new AICWP_Content_Extractor_Page();

            case 'product':
                return new AICWP_Content_Extractor_Product();

            default:
                return null;
        }
    }
}

/**
 * Page Content Extractor (Pro)
 */
class AICWP_Content_Extractor_Page {

    /**
     * Clean page content by removing scripts, styles, and page builder artifacts
     *
     * Pages often contain inline CSS/JS from page builders (Elementor, WPBakery, etc.)
     * This method strips all that before extracting meaningful text.
     *
     * @param string $content Raw post_content
     * @return string Clean text content
     */
    private function clean_page_content($content) {
        if (empty($content)) {
            return '';
        }

        // Remove <script> tags and their content
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content);

        // Remove <style> tags and their content
        $content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $content);

        // Remove <noscript> tags and their content
        $content = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $content);

        // Remove HTML comments (including conditional comments)
        $content = preg_replace('/<!--.*?-->/s', '', $content);

        // Remove SVG tags and their content (paths, not useful text)
        $content = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $content);

        // Remove common page builder shortcodes
        $content = preg_replace('/\[elementor[^\]]*\]/', '', $content);
        $content = preg_replace('/\[vc_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/vc_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[et_pb_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/et_pb_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[gdlr_core_[^\]]*\]/', '', $content);
        $content = preg_replace('/\[\/gdlr_core_[^\]]*\]/', '', $content);

        // Use Factory method to preserve links and strip remaining tags
        if (class_exists('AICWP_Content_Extractor_Factory')) {
            return AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($content);
        }

        // Fallback if Factory not available
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }

    /**
     * Extract content from page for embedding generation
     *
     * @param int $post_id Page ID
     * @return string Structured content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'page') {
            return '';
        }

        $structured_content = "";

        // Title
        $structured_content .= "TITLE: " . get_the_title($post_id) . ". ";

        // Content extraction
        $content = '';

        if (AICWP_Content_Extractor_Factory::content_needs_rendering($post_id)) {
            // Page builder detected - fetch rendered HTML instead of parsing shortcodes
            $rendered_html = AICWP_Content_Extractor_Factory::fetch_rendered_content($post_id);
            if ($rendered_html) {
                $content = AICWP_Content_Extractor_Factory::extract_from_rendered_html($rendered_html);
            }
            // Fallback to post_content if fetch failed
            if (empty($content) && !empty($post->post_content)) {
                $content = $this->clean_page_content($post->post_content);
            }
        } else {
            // Normal extraction from post_content
            if (!empty($post->post_content)) {
                $content = $this->clean_page_content($post->post_content);
            }
        }

        if (!empty($content)) {
            $structured_content .= "CONTENT: " . $content . ". ";
        }

        // Excerpt
        if (!empty($post->post_excerpt)) {
            $excerpt = strip_tags($post->post_excerpt);
            $structured_content .= "EXCERPT: " . trim($excerpt) . ". ";
        }

        // Parent page hierarchy (useful for context)
        if ($post->post_parent) {
            $parent_titles = array();
            $parent_id = $post->post_parent;

            while ($parent_id) {
                $parent = get_post($parent_id);
                if ($parent) {
                    $parent_titles[] = $parent->post_title;
                    $parent_id = $parent->post_parent;
                } else {
                    break;
                }
            }

            if (!empty($parent_titles)) {
                $structured_content .= "PARENT_PAGES: " . implode(' > ', array_reverse($parent_titles)) . ". ";
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

        // Page template (can provide context about page purpose)
        $template = get_page_template_slug($post_id);
        if ($template) {
            $template_name = basename($template, '.php');
            $structured_content .= "PAGE_TYPE: " . str_replace('-', ' ', $template_name) . ". ";
        }

        // Auto-detect additional custom fields
        if (class_exists('AICWP_Content_Extractor_Factory')) {
            $custom_fields_content = AICWP_Content_Extractor_Factory::extract_custom_fields($post_id);
            if (!empty($custom_fields_content)) {
                $structured_content .= "CUSTOM_FIELDS: " . $custom_fields_content . ". ";
            }
        }

        // Limit total length
        if (strlen($structured_content) > 8000) {
            $structured_content = substr($structured_content, 0, 8000);
        }

        return trim($structured_content);
    }
}

/**
 * WooCommerce Product Content Extractor (Pro)
 */
class AICWP_Content_Extractor_Product {

    /**
     * Clean content by removing scripts, styles, and page builder artifacts
     *
     * @param string $content Raw content
     * @return string Clean text content
     */
    private function clean_content($content) {
        if (empty($content)) {
            return '';
        }

        // Remove <script> and <style> tags with their content
        $content = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $content);
        $content = preg_replace('/<noscript\b[^>]*>.*?<\/noscript>/is', '', $content);
        $content = preg_replace('/<!--.*?-->/s', '', $content);
        $content = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $content);

        // Use Factory method if available
        if (class_exists('AICWP_Content_Extractor_Factory')) {
            return AICWP_Content_Extractor_Factory::preserve_links_and_strip_tags($content);
        }

        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }

    /**
     * Extract content from WooCommerce product for embedding generation
     *
     * @param int $post_id Product ID
     * @return string Structured content for embedding
     */
    public function extract_content($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type !== 'product') {
            return '';
        }

        // Check if WooCommerce is active
        if (!function_exists('wc_get_product')) {
            return $this->extract_basic_content($post);
        }

        $product = wc_get_product($post_id);
        if (!$product) {
            return $this->extract_basic_content($post);
        }

        $structured_content = "";

        // === PRODUCT IDENTIFIERS FIRST (SKU, EAN, GTIN - critical for search) ===

        // SKU - at the very beginning for searchability
        $sku = $product->get_sku();
        if ($sku) {
            $structured_content .= "SKU: " . $sku . ". ";
        }

        // EAN/GTIN/Barcode - check common meta field names used by various plugins
        $ean_fields = array(
            '_ean',
            '_gtin',
            '_barcode',
            '_global_unique_id',  // WooCommerce native GTIN field (WC 9.2+)
            '_hwp_product_gtin',  // Product GTIN for WooCommerce plugin
            'hwp_product_gtin',
            '_wpm_gtin_code',     // WPM GTIN plugin
            'wpm_gtin_code',
            '_alg_ean',           // EAN for WooCommerce plugin
            'alg_ean',
            '_ywbc_barcode_display_value',  // YITH Barcodes
            '_wepos_barcode',     // wePOS Barcode
            'ean',                // Generic
            'gtin',
            'barcode',
            'upc',                // Universal Product Code
            '_upc',
            'isbn',               // Books
            '_isbn',
            'mpn',                // Manufacturer Part Number
            '_mpn',
        );

        $ean_value = '';
        foreach ($ean_fields as $field) {
            $value = get_post_meta($post_id, $field, true);
            if (!empty($value)) {
                $ean_value = $value;
                break;
            }
        }

        if (!empty($ean_value)) {
            $structured_content .= "EAN/GTIN: " . $ean_value . ". ";
        }

        // === STRUCTURED DATA (important metadata) ===

        // Product name
        $structured_content .= "PRODUCT_NAME: " . $product->get_name() . ". ";

        // Product type
        $structured_content .= "PRODUCT_TYPE: " . $product->get_type() . ". ";

        // Price information
        if ($product->get_price()) {
            $regular_price = $product->get_regular_price();
            $sale_price = $product->get_sale_price();

            if ($sale_price) {
                $structured_content .= "PRICE: Regular {$regular_price}, Sale {$sale_price}. ";
            } else {
                $structured_content .= "PRICE: {$regular_price}. ";
            }
        }

        /**
         * Extra pricing info from third-party plugins (e.g. quantity tiers, bulk discounts).
         *
         * Hooked text is appended after the PRICE line in embedding training content.
         * Keep it concise — the total content is capped at 8k chars.
         *
         * @param string      $extra_pricing  Default empty string.
         * @param WC_Product  $product        WooCommerce product object.
         * @param int         $product_id     Product post ID.
         */
        $extra_pricing = apply_filters('aicwp_product_extra_pricing', '', $product, $post_id);
        if (!empty($extra_pricing)) {
            $structured_content .= "QUANTITY_PRICING: " . wp_strip_all_tags(trim($extra_pricing)) . ". ";
        }

        // Categories
        $categories = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'names'));
        if (!is_wp_error($categories) && !empty($categories)) {
            $structured_content .= "CATEGORIES: " . implode(', ', $categories) . ". ";
        }

        // Tags
        $tags = wp_get_post_terms($post_id, 'product_tag', array('fields' => 'names'));
        if (!is_wp_error($tags) && !empty($tags)) {
            $structured_content .= "TAGS: " . implode(', ', $tags) . ". ";
        }

        // Attributes
        $attributes = $product->get_attributes();
        if (!empty($attributes)) {
            $attr_strings = array();
            foreach ($attributes as $attribute) {
                if (is_a($attribute, 'WC_Product_Attribute')) {
                    $name = wc_attribute_label($attribute->get_name());
                    $options = $attribute->get_options();
                    if (!empty($options)) {
                        if (is_array($options)) {
                            $attr_strings[] = $name . ': ' . implode(', ', $options);
                        } else {
                            $attr_strings[] = $name . ': ' . $options;
                        }
                    }
                }
            }
            if (!empty($attr_strings)) {
                $structured_content .= "ATTRIBUTES: " . implode('; ', $attr_strings) . ". ";
            }
        }

        // Variation SKUs and prices (for variable products - critical for SKU-based searches)
        if ($product->is_type('variable')) {
            $available_variations = $product->get_available_variations();
            if (!empty($available_variations)) {
                $var_parts = array();
                // Cap at 30 variations to avoid dominating the 8k embedding budget
                foreach (array_slice($available_variations, 0, 30) as $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if (!$variation) {
                        continue;
                    }
                    $var_sku = $variation->get_sku();
                    if ($var_sku) {
                        $var_price = $variation->get_price();
                        $var_attrs = array();
                        foreach ($variation->get_attributes() as $attr_name => $attr_value) {
                            if (!empty($attr_value)) {
                                $var_attrs[] = wc_attribute_label($attr_name) . ': ' . $attr_value;
                            }
                        }
                        $var_desc = !empty($var_attrs) ? implode(', ', $var_attrs) : '';
                        $var_parts[] = "SKU {$var_sku}" . ($var_desc ? " ({$var_desc})" : '') . " - {$var_price}";
                    }
                }
                if (!empty($var_parts)) {
                    $structured_content .= "VARIATION_SKUS: " . implode('; ', $var_parts) . ". ";
                }
            }
        }

        // Stock status
        if ($product->is_in_stock()) {
            $structured_content .= "AVAILABILITY: In Stock. ";
        } else {
            $structured_content .= "AVAILABILITY: Out of Stock. ";
        }

        // Auto-detect additional custom fields (before descriptions)
        if (class_exists('AICWP_Content_Extractor_Factory')) {
            $already_extracted = array(
                '_price', '_regular_price', '_sale_price', '_sku', '_stock',
                '_stock_status', '_weight', '_length', '_width', '_height',
                '_product_attributes', '_downloadable', '_virtual',
                '_manage_stock', '_backorders', '_sold_individually',
                '_tax_status', '_tax_class', '_purchase_note',
                '_product_image_gallery', '_product_version',
                '_per_product_admin_commission_type', 'per_product_admin_commission_type',
                '_per_product_admin_commission', 'per_product_admin_commission',
                '_per_product_admin_additional_fee', 'per_product_admin_additional_fee',
                'pageview', '_pageview', 'pageviews', '_pageviews',
                'total_sales', '_total_sales',
                '_wc_average_rating', '_wc_rating_count', '_wc_review_count',
                '_upsell_ids', '_crosssell_ids', '_children',
                '_variation_description', '_default_attributes',
                // EAN/GTIN/Barcode fields (already extracted at the beginning)
                '_ean', '_gtin', '_barcode', '_global_unique_id',
                '_hwp_product_gtin', 'hwp_product_gtin',
                '_wpm_gtin_code', 'wpm_gtin_code',
                '_alg_ean', 'alg_ean',
                '_ywbc_barcode_display_value', '_wepos_barcode',
                'ean', 'gtin', 'barcode', 'upc', '_upc',
                'isbn', '_isbn', 'mpn', '_mpn',
                // WPC Price by Quantity (serialized data, handled via aicwp_product_extra_pricing filter)
                'wpcpq_enable', 'wpcpq_prices',
            );
            $custom_fields_content = AICWP_Content_Extractor_Factory::extract_custom_fields($post_id, $already_extracted);
            if (!empty($custom_fields_content)) {
                $structured_content .= "CUSTOM_FIELDS: " . $custom_fields_content . ". ";
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

        // === DESCRIPTIONS LAST (can be cut if 8k limit hit) ===

        // Short description
        $short_description = $product->get_short_description();
        if (!empty($short_description)) {
            $short_description = $this->clean_content($short_description);
            if (!empty($short_description)) {
                if (mb_strlen($short_description, 'UTF-8') > 2000) {
                    $short_description = mb_substr($short_description, 0, 2000, 'UTF-8');
                }
                $structured_content .= "SHORT_DESCRIPTION: " . $short_description . ". ";
            }
        }

        // Description (full) - cap at 5000 chars
        $description = $product->get_description();
        if (!empty($description)) {
            $description = $this->clean_content($description);
            if (!empty($description)) {
                if (mb_strlen($description, 'UTF-8') > 5000) {
                    $description = mb_substr($description, 0, 5000, 'UTF-8');
                }
                $structured_content .= "DESCRIPTION: " . $description . ". ";
            }
        }

        // Limit total length
        if (strlen($structured_content) > 8000) {
            $structured_content = substr($structured_content, 0, 8000);
        }

        return trim($structured_content);
    }

    /**
     * Extract basic content if WooCommerce functions aren't available
     *
     * @param WP_Post $post Post object
     * @return string Basic structured content
     */
    private function extract_basic_content($post) {
        $structured_content = "";

        $structured_content .= "TITLE: " . get_the_title($post->ID) . ". ";

        if (!empty($post->post_content)) {
            $content = $this->clean_content($post->post_content);
            if (!empty($content)) {
                $structured_content .= "CONTENT: " . $content . ". ";
            }
        }

        if (!empty($post->post_excerpt)) {
            $excerpt = $this->clean_content($post->post_excerpt);
            if (!empty($excerpt)) {
                $structured_content .= "EXCERPT: " . $excerpt . ". ";
            }
        }

        return trim($structured_content);
    }
}
