<?php
/**
 * Result Formatter Class
 * 
 * Handles formatting and transforming search results for display
 * 
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Result_Formatter {
    
    /**
     * Format search results for display
     * 
     * @param array $results Raw search results
     * @param bool $include_score Whether to include similarity scores
     * @param array $similarities Similarity scores indexed by post ID
     * @return array Formatted results
     */
    public static function format_search_results($results, $include_score = false, $similarities = array(), $apply_filtering = true) {
        $formatted = array();
        $min_match_percentage = intval(get_option('aicwp_min_match_percentage', 50));
        $filtered_count = 0;
        
        foreach ($results as $result) {
            if (is_object($result) && isset($result->ID)) {
                $post_id = $result->ID;
            } else {
                $post_id = is_array($result) ? $result : $result;
            }
            
            // Get post object for type detection
            $post = get_post($post_id);
            $post_type = $post ? $post->post_type : 'post';

            // Get excerpt and ensure it's plain text (some plugins inject HTML)
            $raw_excerpt = get_the_excerpt($post_id);
            $clean_excerpt = wp_strip_all_tags($raw_excerpt);

            // For external pages, use actual external URL instead of WordPress permalink
            $result_permalink = ($post_type === 'ai_external_page')
                ? get_post_meta($post_id, '_external_url', true)
                : get_permalink($post_id);

            $listing = array(
                'id' => $post_id,
                'title' => get_the_title($post_id),
                'excerpt' => $clean_excerpt,
                'permalink' => $result_permalink,
                'thumbnail' => self::get_listing_thumbnail($post_id),
                'post_type' => $post_type,
            );

            // Add WooCommerce product-specific data if applicable
            if ($post_type === 'product' && function_exists('wc_get_product')) {
                $product = wc_get_product($post_id);
                if ($product) {
                    $listing['price'] = $product->get_price();
                    $listing['price_html'] = $product->get_price_html();
                    $listing['product_type'] = $product->get_type();
                    $listing['in_stock'] = $product->is_in_stock();
                    $listing['stock_status'] = $product->get_stock_status(); // 'instock', 'outofstock', 'onbackorder'
                    $listing['on_sale'] = $product->is_on_sale();
                    $listing['regular_price'] = $product->get_regular_price();
                    $listing['sale_price'] = $product->get_sale_price();

                    // Formatted prices with currency symbol (plain text, no HTML)
                    $currency_symbol = get_woocommerce_currency_symbol();
                    $listing['regular_price_formatted'] = $product->get_regular_price() ? AICWP_Utility_Helper::format_price((float)$product->get_regular_price()) : '';
                    $listing['sale_price_formatted'] = $product->get_sale_price() ? AICWP_Utility_Helper::format_price((float)$product->get_sale_price()) : '';
                    $listing['price_formatted'] = $product->get_price() ? AICWP_Utility_Helper::format_product_price($product, (float)$product->get_price()) : '';
                }
            }
            
            if ($include_score && isset($similarities[$post_id])) {
                $raw_similarity = $similarities[$post_id];

                // Transform cosine similarity to a more intuitive user-friendly score
                // Cosine similarity typically ranges 0.2-0.8 for text, we'll map this to 0-100%
                $user_friendly_score = AICWP_Utility_Helper::transform_similarity_to_percentage($raw_similarity, null, $post_type);
                
                // Filter out results below minimum match percentage (only if filtering is enabled)
                if ($apply_filtering && $user_friendly_score < $min_match_percentage) {
                    $filtered_count++;
                    continue; // Skip this result
                }
                
                // Get configurable thresholds
                $best_match_threshold = intval(get_option('aicwp_best_match_threshold', 75));

                $listing['similarity_score'] = round($raw_similarity, 4); // Keep raw for debugging
                $listing['match_percentage'] = round($user_friendly_score, 1); // User-friendly percentage
                $listing['percentage'] = round($user_friendly_score, 1); // Alias for frontend compatibility
                $listing['best_match'] = ($user_friendly_score >= $best_match_threshold); // Best Match badge for high-quality results

                // Simplified match types for internal use
                if ($user_friendly_score >= $best_match_threshold) {
                    $listing['match_type'] = 'best';
                } elseif ($user_friendly_score >= 60) {
                    $listing['match_type'] = 'good';
                } else {
                    $listing['match_type'] = 'relevant';
                }
            } elseif ($include_score) {
                // Default values for AI search results
                $listing['similarity_score'] = 0.0;
                $listing['match_percentage'] = 0;
                $listing['percentage'] = 0;
                $listing['best_match'] = false;
                $listing['match_type'] = 'relevant';
            }
            
            $formatted[] = $listing;
        }
        
        // Add debug info about filtering
        if ($include_score && $filtered_count > 0 && get_option('aicwp_debug_mode', false)) {
            error_log("AI Chat for WordPress - Filtered out {$filtered_count} results below {$min_match_percentage}% match threshold");
        }
        
        return $formatted;
    }
    
    /**
     * Get listing thumbnail with fallback to placeholder
     *
     * @param int $post_id Listing post ID
     * @return string Thumbnail URL or placeholder URL
     */
    private static function get_listing_thumbnail($post_id) {
        // Try to get the featured image first
        // Use WordPress default 'thumbnail' size (typically 150x150)
        $thumbnail_url = get_the_post_thumbnail_url($post_id, 'thumbnail');
        
        if ($thumbnail_url) {
            return $thumbnail_url;
        }
        
        // No featured image found: return empty string (no image)
        return '';
    }
}
