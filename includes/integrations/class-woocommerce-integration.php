<?php
/**
 * WooCommerce Integration
 *
 * Provides WooCommerce-specific product search functionality
 * Only active when WooCommerce plugin is detected
 *
 * @package AICWP
 * @since 1.5.0
 */

if (!defined('ABSPATH')) exit;

class AICWP_WooCommerce_Integration {

    /**
     * Whether third-party pricing filters have already been registered.
     * Prevents duplicate callbacks when the class is instantiated more than once.
     *
     * @var bool
     */
    private static $pricing_filters_registered = false;

    /**
     * Constructor - Registers REST API routes
     */
    public function __construct() {
        // Only register routes if WooCommerce is available
        if (class_exists('WooCommerce')) {
            add_action('rest_api_init', array($this, 'register_woocommerce_routes'));
        }

        // WPC Price by Quantity — append quantity tiers to AI pricing context.
        // Guarded: this class is new'd in several places; register the filters only once.
        if (!self::$pricing_filters_registered) {
            add_filter('aicwp_product_extra_pricing', array($this, 'wpcpq_extra_pricing_text'), 10, 3);
            add_filter('aicwp_product_extra_pricing_data', array($this, 'wpcpq_extra_pricing_data'), 10, 3);
            self::$pricing_filters_registered = true;
        }
    }

    /**
     * Register WooCommerce-specific REST API routes
     */
    public function register_woocommerce_routes() {

        // Product search endpoint (AI semantic search + WooCommerce filters)
        register_rest_route('aicwp/v1', '/woocommerce-product-search', array(
            'methods' => 'POST',
            'callback' => array($this, 'search_products'),
            'permission_callback' => '__return_true',
            'args' => array(
                'query' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Natural language search query'
                ),
                'price_min' => array(
                    'type' => 'number',
                    'description' => 'Minimum price filter'
                ),
                'price_max' => array(
                    'type' => 'number',
                    'description' => 'Maximum price filter'
                ),
                'in_stock' => array(
                    'type' => 'boolean',
                    'description' => 'Only show in-stock products'
                ),
                'on_sale' => array(
                    'type' => 'boolean',
                    'description' => 'Only show products on sale'
                ),
                'category' => array(
                    'type' => 'string',
                    'description' => 'Product category slug or ID'
                ),
                'rating' => array(
                    'type' => 'number',
                    'description' => 'Minimum rating (1-5)'
                ),
                'sku' => array(
                    'type' => 'string',
                    'description' => 'Product SKU for direct lookup (pinned to top of results)'
                ),
                'per_page' => array(
                    'type' => 'integer',
                    'default' => 10,
                    'description' => 'Results per page'
                )
            )
        ));

        // Product details endpoint - get comprehensive information about a specific product
        register_rest_route('aicwp/v1', '/woocommerce-product-details', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_product_details'),
            'permission_callback' => '__return_true',
            'args' => array(
                'product_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'description' => 'Product post ID'
                )
            )
        ));

        // Order status endpoint - check WooCommerce order status
        register_rest_route('aicwp/v1', '/woocommerce-order-status', array(
            'methods' => 'POST',
            'callback' => array($this, 'get_order_status'),
            'permission_callback' => '__return_true',
            'args' => array(
                'order_number' => array(
                    'required' => true,
                    'description' => 'Order number or order ID'
                ),
                'billing_email' => array(
                    'type' => 'string',
                    'description' => 'Billing email for verification (required if user not logged in)'
                )
            )
        ));
    }

    /**
     * Product search endpoint - combines AI search with WooCommerce filters
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function search_products($request) {
        // Read-only pre-filter; actual atomic quota is consumed deeper in AI_Engine → generate_embedding() / expand_query_if_enabled()
        if (!AICWP_Embedding_Manager::check_rate_limit()) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Rate limit exceeded. Please try again later.',
                'type' => 'rate_limit_error'
            ), 429);
        }

        $start_time = microtime(true);

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'WooCommerce is required but not active.',
                'results' => array()
            ), 503);
        }

        $query = $request->get_param('query');
        $source = $request->get_param('source');
        $is_chatbot = ($source === 'chatbot');
        $has_ai = class_exists('AICWP_AI_Engine');

        $debug = get_option('aicwp_debug_mode', false);

        if ($debug) {
            error_log('=== WOOCOMMERCE PRODUCT SEARCH ===');
            error_log('Query: ' . ($query ?: 'NOT SET'));
            error_log('AI Available: ' . ($has_ai ? 'YES' : 'NO'));
        }

        // Build initial query args
        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $request->get_param('per_page') ?: 10,
            'paged' => 1,
            'ignore_sticky_posts' => 1,
        );

        // Get filters from request
        $price_min = $request->get_param('price_min');
        $price_max = $request->get_param('price_max');
        $in_stock = $request->get_param('in_stock');
        $on_sale = $request->get_param('on_sale');
        $category = $request->get_param('category');
        $rating = $request->get_param('rating');

        // Normalize "GPT-over-fills-every-param" defaults to null.
        // OpenAI GPT models (unlike Claude) eagerly set every optional schema
        // parameter to its type default — price_max: 0, price_min: 0, rating: 0,
        // category: "" — even when the user didn't mention them. Those literal
        // defaults would otherwise apply as real filters (e.g. price_max=0
        // rejects every paid product). Treat 0 / "" / <0 as "not filtering".
        if ($price_min === null || $price_min === '' || (float) $price_min <= 0) {
            $price_min = null;
        }
        if ($price_max === null || $price_max === '' || (float) $price_max <= 0) {
            $price_max = null;
        }
        if ($rating === null || $rating === '' || (float) $rating <= 0) {
            $rating = null;
        }
        if ($category === '' || $category === null) {
            $category = null;
        }

        // Execute search
        $results = array();

        // Track notice from AI engine (e.g., no embeddings)
        $ai_notice = null;
        $ai_notice_type = null;

        if ($has_ai && !empty($query)) {
            // AI search path - use embeddings
            if ($debug) {
                error_log('Using AI search for products');
            }

            $ai_engine = new AICWP_AI_Engine();

            // When chatbot: skip threshold (LLM will re-rank), use chatbot max results limit
            $search_limit = $is_chatbot ? intval(get_option('aicwp_chat_max_results', 10)) : 50;

            // Auto-detect batch processing to avoid OOM on large catalogs
            $total_embeddings = AICWP_Database_Manager::count_embeddings_for_search('product');
            if ($total_embeddings > 5000) {
                $ai_results = $ai_engine->search_with_batching($query, $search_limit, 0, 'product', $debug, 3000, $is_chatbot);
            } else {
                $ai_results = $ai_engine->search($query, $search_limit, 0, 'product', $debug, array(), false, $is_chatbot);
            }

            // Capture notice from AI engine
            if (!empty($ai_results['notice'])) {
                $ai_notice = $ai_results['notice'];
                $ai_notice_type = $ai_results['notice_type'] ?? 'info';
            }

            if ($debug) {
                error_log('AI search returned: ' . (is_array($ai_results['listings']) ? count($ai_results['listings']) : 0) . ' results');
            }

            if (!empty($ai_results['listings'])) {
                // Get product IDs from AI results
                $product_ids = array();
                foreach ($ai_results['listings'] as $result) {
                    $product_ids[] = isset($result['id']) ? $result['id'] : $result;
                }

                // Query products with AI ordering
                $custom_query_args = array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => count($product_ids),
                    'paged' => 1,
                    'post__in' => $product_ids,
                    'orderby' => 'post__in',
                    'ignore_sticky_posts' => 1,
                );

                $products = new WP_Query($custom_query_args);

                if ($products->have_posts()) {
                    while ($products->have_posts()) {
                        $products->the_post();
                        $product_data = $this->format_product_data(get_the_ID());

                        // Apply filters
                        if ($this->passes_filters($product_data, $price_min, $price_max, $in_stock, $on_sale, $category, $rating)) {
                            $results[] = $product_data;
                        }
                    }
                    wp_reset_postdata();
                }
            }
        } else {
            // Traditional search path (no AI)
            if ($debug) {
                error_log('Using traditional search for products');
            }

            // Add text search to query args
            if (!empty($query)) {
                $query_args['s'] = $query;
            }

            // Add category filter
            if (!empty($category)) {
                if (is_numeric($category)) {
                    $query_args['tax_query'] = array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field' => 'term_id',
                            'terms' => intval($category)
                        )
                    );
                } else {
                    $query_args['tax_query'] = array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field' => 'slug',
                            'terms' => $category
                        )
                    );
                }
            }

            $products = new WP_Query($query_args);

            if ($products->have_posts()) {
                while ($products->have_posts()) {
                    $products->the_post();
                    $product_data = $this->format_product_data(get_the_ID());

                    // Apply filters
                    if ($this->passes_filters($product_data, $price_min, $price_max, $in_stock, $on_sale, $category, $rating)) {
                        $results[] = $product_data;
                    }
                }
                wp_reset_postdata();
            }
        }

        // SKU direct lookup — if matched, return ONLY that product (keyword results ignored)
        $sku = $request->get_param('sku');
        if (!empty($sku)) {
            $sku_clean = trim(sanitize_text_field($sku));

            // 1. Try exact match first (fast, uses WC index)
            $sku_product_id = wc_get_product_id_by_sku($sku_clean);

            // 2. Fallback: LIKE match so "123" finds "sku-123", "sku 123" finds "sku-123", etc.
            //    Requires 2+ chars to avoid matching everything. Shortest match wins.
            if (!$sku_product_id && strlen($sku_clean) >= 2) {
                global $wpdb;
                $like = '%' . $wpdb->esc_like($sku_clean) . '%';
                $sku_product_id = (int) $wpdb->get_var($wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE pm.meta_key = '_sku'
                      AND pm.meta_value LIKE %s
                      AND p.post_type IN ('product', 'product_variation')
                      AND p.post_status = 'publish'
                    ORDER BY CHAR_LENGTH(pm.meta_value) ASC
                    LIMIT 1
                ", $like));
            }

            if ($sku_product_id) {
                // If matched a variation, resolve to parent product
                $sku_product = wc_get_product($sku_product_id);
                if ($sku_product && $sku_product->is_type('variation')) {
                    $sku_product_id = $sku_product->get_parent_id();
                }

                // Replace entire result set with just the SKU match — definitive lookup
                $results = array($this->format_product_data($sku_product_id));

                if ($debug) {
                    error_log('SKU match returned as sole result: ' . $sku_clean . ' → product #' . $sku_product_id);
                }
            } elseif ($debug) {
                error_log('SKU lookup no match, falling back to keyword results: ' . $sku_clean);
            }
        }

        // Limit results to display limit
        $display_limit = intval(get_option('aicwp_chat_max_results', 10));
        $actual_total = count($results);
        $displayed_results = array_slice($results, 0, $display_limit);

        if ($debug) {
            error_log('Product search complete: ' . $actual_total . ' results found, ' . count($displayed_results) . ' displayed');
        }

        // Track analytics
        if (class_exists('AICWP_Analytics')) {
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            $search_type = ($has_ai && !empty($query)) ? 'ai' : 'traditional';
            AICWP_Analytics::log_search($query, $actual_total, $search_type, $processing_time, 'rest_api_products');
        }

        return new WP_REST_Response(array(
            'success' => true,
            'search_type' => ($has_ai && !empty($query)) ? 'ai_semantic' : 'traditional',
            'total' => $actual_total,
            'total_displayed' => count($displayed_results),
            'display_limit' => $display_limit,
            'results' => $displayed_results
        ), 200);
    }

    /**
     * Get product details endpoint - returns comprehensive product information
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_product_details($request) {
        $product_id = intval($request->get_param('product_id'));

        // Verify product exists
        $post = get_post($product_id);
        if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Product not found or not published.'
            ), 404);
        }

        // Get WooCommerce product object
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Could not load product data.'
            ), 500);
        }

        $debug = get_option('aicwp_debug_mode', false);

        if ($debug) {
            error_log('=== PRODUCT DETAILS REQUEST ===');
            error_log('Product ID: ' . $product_id);
            error_log('Product Type: ' . $product->get_type());
        }

        // Build comprehensive structured content
        $structured_content = $this->build_product_structured_content($product, $product_id);

        return new WP_REST_Response(array(
            'success' => true,
            'product_id' => $product_id,
            'title' => $product->get_name(),
            'url' => get_permalink($product_id),
            'structured_content' => $structured_content
        ), 200);
    }

    /**
     * Build comprehensive structured content for a product
     *
     * Used by get_product_details() tool and "Talk about this product" feature.
     * Includes attributes which are intentionally NOT in embeddings.
     *
     * @param WC_Product $product WooCommerce product object
     * @param int $product_id Product post ID
     * @return string Structured content for AI
     */
    public function build_product_structured_content($product, $product_id) {
        $content = "";

        // === BASIC INFORMATION ===
        $content .= "PRODUCT NAME: " . $product->get_name() . "\n";
        $content .= "SKU: " . ($product->get_sku() ?: 'N/A') . "\n";
        $content .= "URL: " . get_permalink($product_id) . "\n\n";

        // === DESCRIPTIONS ===
        $short_desc = $product->get_short_description();
        if (!empty($short_desc)) {
            $content .= "SHORT DESCRIPTION:\n" . wp_strip_all_tags($short_desc) . "\n\n";
        }

        $long_desc = $product->get_description();
        if (!empty($long_desc)) {
            $content .= "FULL DESCRIPTION:\n" . wp_strip_all_tags($long_desc) . "\n\n";
        }

        // === PRICING (tax-aware using WooCommerce display settings) ===
        $currency_symbol = get_woocommerce_currency_symbol();
        $display_price = wc_get_price_to_display($product);
        $display_regular = wc_get_price_to_display($product, array('price' => $product->get_regular_price()));
        $display_sale = $product->get_sale_price() ? wc_get_price_to_display($product, array('price' => $product->get_sale_price())) : 0;

        $content .= "PRICING:\n";
        if ($product->is_on_sale() && $display_sale) {
            $savings = $display_regular - $display_sale;
            $savings_percent = $display_regular > 0 ? round(($savings / $display_regular) * 100) : 0;
            $content .= "- Regular Price: " . AICWP_Utility_Helper::format_price($display_regular) . "\n";
            $content .= "- Sale Price: " . AICWP_Utility_Helper::format_price($display_sale) . " (SAVE {$savings_percent}%)\n";
            $content .= "- Current Price: " . AICWP_Utility_Helper::format_price($display_price) . " - ON SALE!\n";
        } else {
            $content .= "- Price: " . AICWP_Utility_Helper::format_price($display_price) . "\n";
        }
        /**
         * Extra pricing info from third-party plugins (e.g. quantity tiers, bulk discounts).
         *
         * Hooked text is appended to the PRICING section of structured content
         * sent to the LLM via the get_product_details tool.
         * Return a string with newline-separated lines, each prefixed with "- ".
         *
         * @param string      $extra_pricing  Default empty string.
         * @param WC_Product  $product        WooCommerce product object.
         * @param int         $product_id     Product post ID.
         */
        $extra_pricing = apply_filters('aicwp_product_extra_pricing', '', $product, $product_id);
        if (!empty($extra_pricing)) {
            $content .= wp_strip_all_tags(trim($extra_pricing)) . "\n";
        }
        $content .= "\n";

        // === STOCK STATUS ===
        $content .= "AVAILABILITY:\n";
        $stock_status = $product->get_stock_status();
        if ($stock_status === 'instock') {
            $stock_quantity = $product->get_stock_quantity();
            if ($stock_quantity !== null) {
                $content .= "- Status: IN STOCK ({$stock_quantity} available)\n";
            } else {
                $content .= "- Status: IN STOCK\n";
            }
        } elseif ($stock_status === 'outofstock') {
            $content .= "- Status: OUT OF STOCK\n";
        } elseif ($stock_status === 'onbackorder') {
            $content .= "- Status: AVAILABLE ON BACKORDER\n";
        }
        $content .= "\n";

        // === CATEGORIES & TAGS ===
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
        if (!is_wp_error($categories) && !empty($categories)) {
            $content .= "CATEGORIES: " . implode(', ', $categories) . "\n";
        }

        $tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names'));
        if (!is_wp_error($tags) && !empty($tags)) {
            $content .= "TAGS: " . implode(', ', $tags) . "\n";
        }
        $content .= "\n";

        // === RATINGS & REVIEWS ===
        $average_rating = $product->get_average_rating();
        $rating_count = $product->get_rating_count();
        $review_count = $product->get_review_count();

        if ($rating_count > 0) {
            $content .= "CUSTOMER RATINGS:\n";
            $content .= "- Average Rating: " . number_format($average_rating, 1) . " out of 5 stars\n";
            $content .= "- Total Ratings: {$rating_count}\n";
            $content .= "- Total Reviews: {$review_count}\n\n";
        }

        // === ATTRIBUTES (Size, Color, etc.) ===
        $attributes = $product->get_attributes();
        if (!empty($attributes)) {
            $content .= "PRODUCT ATTRIBUTES:\n";
            foreach ($attributes as $attribute) {
                $name = wc_attribute_label($attribute->get_name());
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($product_id, $attribute->get_name(), array('fields' => 'names'));
                    if (!is_wp_error($terms) && !empty($terms)) {
                        $content .= "- {$name}: " . implode(', ', $terms) . "\n";
                    }
                } else {
                    $options = $attribute->get_options();
                    if (!empty($options)) {
                        $content .= "- {$name}: " . implode(', ', $options) . "\n";
                    }
                }
            }
            $content .= "\n";
        }

        // === VARIATIONS (for variable products) ===
        if ($product->is_type('variable')) {
            $variations = $product->get_available_variations();
            if (!empty($variations)) {
                $content .= "AVAILABLE VARIATIONS:\n";
                foreach ($variations as $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if ($variation) {
                        $var_desc = array();
                        foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                            $clean_name = str_replace('attribute_', '', $attr_name);
                            $clean_name = wc_attribute_label($clean_name);
                            $var_desc[] = "{$clean_name}: {$attr_value}";
                        }
                        $var_sku = $variation->get_sku();
                        $var_price = wc_get_price_to_display($variation);
                        $content .= "- " . implode(', ', $var_desc);
                        if ($var_sku) {
                            $content .= " (SKU: {$var_sku})";
                        }
                        $content .= " - " . AICWP_Utility_Helper::format_price($var_price);
                        if ($variation->is_in_stock()) {
                            $content .= " (In Stock)";
                        } else {
                            $content .= " (Out of Stock)";
                        }
                        $content .= "\n";
                    }
                }
                $content .= "\n";
            }
        }

        // === SHIPPING & DIMENSIONS ===
        $weight = $product->get_weight();
        $length = $product->get_length();
        $width = $product->get_width();
        $height = $product->get_height();

        if ($weight || $length || $width || $height) {
            $content .= "SHIPPING INFORMATION:\n";
            if ($weight) {
                $content .= "- Weight: {$weight} " . get_option('woocommerce_weight_unit') . "\n";
            }
            if ($length && $width && $height) {
                $dim_unit = get_option('woocommerce_dimension_unit');
                $content .= "- Dimensions: {$length} × {$width} × {$height} {$dim_unit}\n";
            }
            $content .= "\n";
        }

        // === ADDITIONAL INFORMATION ===
        if ($product->is_virtual()) {
            $content .= "PRODUCT TYPE: Digital/Virtual Product (No shipping required)\n\n";
        }
        if ($product->is_downloadable()) {
            $content .= "DOWNLOADABLE: Yes (Digital download available after purchase)\n\n";
        }

        return $content;
    }

    /**
     * Format product data for API response
     *
     * @param int $product_id Product ID
     * @return array Formatted product data
     */
    private function format_product_data($product_id) {
        $product = wc_get_product($product_id);

        if (!$product) {
            return array();
        }

        // Get price information (tax-aware using WooCommerce display settings)
        $currency_symbol = get_woocommerce_currency_symbol();
        $display_price = wc_get_price_to_display($product);
        $display_regular = wc_get_price_to_display($product, array('price' => $product->get_regular_price()));
        $display_sale = $product->get_sale_price() ? wc_get_price_to_display($product, array('price' => $product->get_sale_price())) : 0;

        // Format price with currency
        $formatted_price = $display_price ? AICWP_Utility_Helper::format_product_price($product, $display_price) : '';
        $formatted_regular_price = $display_regular ? AICWP_Utility_Helper::format_price($display_regular) : '';

        // Get product image (use WooCommerce placeholder if no image)
        $featured_image = get_the_post_thumbnail_url($product_id, 'medium');
        if (!$featured_image) {
            $featured_image = wc_placeholder_img_src('medium');
        }

        // Basic product data
        $data = array(
            'id' => $product_id,
            'title' => $product->get_name(),
            'url' => get_permalink($product_id),
            // Use product description stripped and trimmed (short_description often contains styled marketing HTML)
            'excerpt' => wp_trim_words(wp_strip_all_tags($product->get_description()), 25),
            'featured_image' => $featured_image,
        );

        // Price data
        $data['price'] = array(
            'regular' => $formatted_regular_price,
            'sale' => $display_sale ? AICWP_Utility_Helper::format_price($display_sale) : null,
            'formatted' => $formatted_price,
            'currency' => get_woocommerce_currency(),
            'raw' => $display_price,
            'raw_regular' => $display_regular
        );

        /**
         * Extra pricing data from third-party plugins (e.g. quantity tiers, bulk discounts).
         *
         * Hooked into the structured array returned by the product search tool.
         * Return an associative array (e.g. quantity tiers) or empty array to skip.
         *
         * @param array       $extra_pricing  Default empty array.
         * @param WC_Product  $product        WooCommerce product object.
         * @param int         $product_id     Product post ID.
         */
        $extra_pricing_data = apply_filters('aicwp_product_extra_pricing_data', array(), $product, $product_id);
        if (!empty($extra_pricing_data)) {
            $data['extra_pricing'] = $extra_pricing_data;
        }

        // SKU and product type
        $data['sku'] = $product->get_sku() ?: null;
        $data['product_type'] = $product->get_type();

        // Stock status
        $data['stock_status'] = $product->get_stock_status();
        $data['on_sale'] = $product->is_on_sale();

        // Variation data for variable products (so LLM can match SKU to correct price)
        if ($product->is_type('variable')) {
            $available_variations = $product->get_available_variations();
            if (!empty($available_variations)) {
                $variations_data = array();
                foreach (array_slice($available_variations, 0, 30) as $variation_data) {
                    $variation = wc_get_product($variation_data['variation_id']);
                    if (!$variation) {
                        continue;
                    }
                    $var_sku = $variation->get_sku();
                    if (!$var_sku) {
                        continue;
                    }
                    $var_display_price = wc_get_price_to_display($variation);
                    $var_attrs = array();
                    foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                        $clean_name = str_replace('attribute_', '', $attr_name);
                        $var_attrs[wc_attribute_label($clean_name)] = $attr_value;
                    }
                    $variations_data[] = array(
                        'sku' => $var_sku,
                        'price' => AICWP_Utility_Helper::format_price($var_display_price),
                        'attributes' => $var_attrs,
                        'in_stock' => $variation->is_in_stock(),
                    );
                }
                if (!empty($variations_data)) {
                    $data['variations'] = $variations_data;
                }
            }
        }

        // Rating
        $average_rating = $product->get_average_rating();
        $rating_count = $product->get_rating_count();
        $data['rating'] = array(
            'average' => $average_rating ? floatval($average_rating) : 0,
            'count' => intval($rating_count)
        );

        // Categories
        $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
        $data['categories'] = !is_wp_error($categories) ? $categories : array();

        // Tags
        $tags = wp_get_post_terms($product_id, 'product_tag', array('fields' => 'names'));
        $data['tags'] = !is_wp_error($tags) ? $tags : array();

        return $data;
    }

    /**
     * Check if product passes filters
     *
     * @param array $product Product data
     * @param float|null $price_min Minimum price
     * @param float|null $price_max Maximum price
     * @param bool|null $in_stock Stock filter
     * @param bool|null $on_sale Sale filter
     * @param string|null $category Category filter
     * @param float|null $rating Minimum rating
     * @return bool True if product passes all filters
     */
    private function passes_filters($product, $price_min, $price_max, $in_stock, $on_sale, $category, $rating) {
        if (empty($product)) {
            return false;
        }

        // Price filter
        if ($price_min !== null || $price_max !== null) {
            $product_price = isset($product['price']['raw']) ? $product['price']['raw'] : 0;

            if ($price_min !== null && $product_price < floatval($price_min)) {
                return false;
            }

            if ($price_max !== null && $product_price > floatval($price_max)) {
                return false;
            }
        }

        // Stock status filter - always exclude out-of-stock products
        if (!isset($product['stock_status']) || $product['stock_status'] !== 'instock') {
            return false;
        }

        // On sale filter
        if ($on_sale === true) {
            if (!isset($product['on_sale']) || $product['on_sale'] !== true) {
                return false;
            }
        }

        // Category filter (only for AI search - traditional search handles this in query)
        if (!empty($category) && isset($product['categories'])) {
            $category_match = false;

            foreach ($product['categories'] as $product_category) {
                if (stripos($product_category, $category) !== false) {
                    $category_match = true;
                    break;
                }
            }

            if (!$category_match) {
                return false;
            }
        }

        // Rating filter
        if ($rating !== null) {
            $product_rating = isset($product['rating']['average']) ? $product['rating']['average'] : 0;

            if ($product_rating < floatval($rating)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get order status endpoint - returns comprehensive order information
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_order_status($request) {
        // Rate limiting - 5 per minute, 100 per day per IP
        $client_ip = AICWP_Utility_Helper::get_client_ip_secure();
        $ip_hash = md5($client_ip . 'order_status_salt');

        // Per-minute limit (5 requests)
        $minute_key = 'order_status_min_' . $ip_hash;
        $minute_count = (int) get_transient($minute_key);
        if ($minute_count >= 5) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => __('Too many requests. Please wait a minute before trying again.', 'ai-chat-wp'),
                'type' => 'rate_limit_error'
            ), 429);
        }

        // Per-day limit (100 requests)
        $day_key = 'order_status_day_' . $ip_hash . '_' . date('Y-m-d');
        $day_count = (int) get_transient($day_key);
        if ($day_count >= 100) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => __('Daily limit exceeded. Please try again tomorrow.', 'ai-chat-wp'),
                'type' => 'rate_limit_error'
            ), 429);
        }

        // Increment counters
        set_transient($minute_key, $minute_count + 1, MINUTE_IN_SECONDS);
        set_transient($day_key, $day_count + 1, DAY_IN_SECONDS);

        $order_number = sanitize_text_field($request->get_param('order_number'));
        $billing_email = sanitize_email($request->get_param('billing_email'));

        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => __('WooCommerce is required but not active.', 'ai-chat-wp')
            ), 503);
        }

        $debug = get_option('aicwp_debug_mode', false);

        if ($debug) {
            error_log('=== ORDER STATUS REQUEST ===');
            error_log('Order Number: ' . $order_number);
            error_log('User Logged In: ' . (is_user_logged_in() ? 'Yes' : 'No'));
        }

        // Try to get order by order number or order ID
        $order = wc_get_order($order_number);

        // If not found by ID, try to search by order number
        if (!$order) {
            $orders = wc_get_orders(array(
                'limit' => 1,
                'order_number' => $order_number,
                'return' => 'ids'
            ));

            if (!empty($orders)) {
                $order = wc_get_order($orders[0]);
            }
        }

        // Security: Verify user has permission to view this order
        // Use uniform error response to prevent order ID enumeration
        $has_permission = false;
        $denial_reason = 'not_found';

        if ($order) {
            $current_user_id = get_current_user_id();
            $order_user_id = $order->get_user_id();
            $order_billing_email = $order->get_billing_email();

            // Check if user is logged in and owns the order
            if ($current_user_id > 0 && $current_user_id === $order_user_id) {
                $has_permission = true;
                if ($debug) {
                    error_log('Permission granted: User owns order');
                }
            }
            // Check if user is admin
            elseif (current_user_can('manage_woocommerce')) {
                $has_permission = true;
                if ($debug) {
                    error_log('Permission granted: User is admin');
                }
            }
            // Check if billing email matches (for guest orders or verification)
            elseif (!empty($billing_email) && strtolower($billing_email) === strtolower($order_billing_email)) {
                $has_permission = true;
                if ($debug) {
                    error_log('Permission granted: Email verification successful');
                }
            } else {
                $denial_reason = 'permission_denied';
            }
        }

        // Uniform error response - prevents order enumeration attacks
        // Attacker cannot distinguish between "order doesn't exist" and "no permission"
        if (!$has_permission) {
            if ($debug) {
                error_log('Order access denied: ' . $order_number . ' (reason: ' . $denial_reason . ')');
            }

            return new WP_REST_Response(array(
                'success' => false,
                'error' => __('Order not found or access denied. Please verify your order number and billing email.', 'ai-chat-wp')
            ), 404);
        }

        // Build comprehensive structured content for the order
        $structured_content = $this->build_order_structured_content($order);

        return new WP_REST_Response(array(
            'success' => true,
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'structured_content' => $structured_content,
            'view_order_url' => $order->get_view_order_url()
        ), 200);
    }

    /**
     * Build SANITIZED order content for AI (NO PII)
     *
     * SECURITY: Strips all personally identifiable information before sending to OpenAI.
     *
     * REMOVED (NOT sent to OpenAI):
     * - Customer names
     * - Addresses (street, city, state, zip, country)
     * - Email addresses
     * - Phone numbers
     * - Payment method details
     * - Customer notes
     *
     * KEPT (Safe to send):
     * - Order number & status
     * - Product names, quantities, prices
     * - Shipping method (not address)
     * - Tracking number
     * - Dates
     *
     * @param WC_Order $order WooCommerce order object
     * @return string Sanitized content for AI (no PII)
     */
    private function build_order_structured_content($order) {
        $content = "";

        // === ORDER INFORMATION ===
        $content .= "ORDER NUMBER: #" . $order->get_order_number() . "\n";

        // Order status with user-friendly labels
        $status = $order->get_status();
        $status_labels = array(
            'pending' => __('PENDING PAYMENT', 'ai-chat-wp'),
            'processing' => __('PROCESSING', 'ai-chat-wp'),
            'on-hold' => __('ON HOLD', 'ai-chat-wp'),
            'completed' => __('COMPLETED', 'ai-chat-wp'),
            'cancelled' => __('CANCELLED', 'ai-chat-wp'),
            'refunded' => __('REFUNDED', 'ai-chat-wp'),
            'failed' => __('FAILED', 'ai-chat-wp'),
            'shipped' => __('SHIPPED', 'ai-chat-wp')
        );
        $status_label = isset($status_labels[$status]) ? $status_labels[$status] : strtoupper($status);
        $content .= __('STATUS', 'ai-chat-wp') . ": " . $status_label . "\n";

        $content .= __('ORDER DATE', 'ai-chat-wp') . ": " . $order->get_date_created()->date_i18n('F j, Y g:i A') . "\n";

        // Payment status (generic - no method details)
        if ($order->is_paid()) {
            $content .= __('PAYMENT STATUS', 'ai-chat-wp') . ": " . __('PAID', 'ai-chat-wp');
            $date_paid = $order->get_date_paid();
            if ($date_paid) {
                $content .= " (" . __('paid on', 'ai-chat-wp') . " " . $date_paid->date_i18n('F j, Y g:i A') . ")";
            }
            $content .= "\n";
        } else {
            $content .= __('PAYMENT STATUS', 'ai-chat-wp') . ": " . __('NOT PAID', 'ai-chat-wp') . "\n";
        }

        $content .= "\n";

        // === ORDER ITEMS ===
        $items = $order->get_items();
        if (!empty($items)) {
            $item_count = count($items);
            /* translators: %d: number of items */
            $content .= sprintf(__('ORDER ITEMS (%d item)', 'ai-chat-wp'), $item_count) . ($item_count > 1 ? 's' : '') . ":\n";

            foreach ($items as $item) {
                $product_name = $item->get_name();
                $quantity = $item->get_quantity();
                $total = $order->get_formatted_line_subtotal($item);

                $content .= "- {$product_name} × {$quantity} - {$total}\n";

                // Add variation details (e.g., Size: Large, Color: Blue)
                $metadata = $item->get_formatted_meta_data();
                if (!empty($metadata)) {
                    foreach ($metadata as $meta) {
                        $content .= "  • {$meta->display_key}: {$meta->display_value}\n";
                    }
                }
            }
            $content .= "\n";
        }

        // === ORDER TOTALS ===
        $content .= __('ORDER TOTALS', 'ai-chat-wp') . ":\n";
        $content .= "- " . __('Subtotal', 'ai-chat-wp') . ": " . $order->get_subtotal_to_display() . "\n";

        // Shipping method only (no address)
        if ($order->get_shipping_total() > 0) {
            $shipping_method = $order->get_shipping_method();
            $content .= "- " . __('Shipping', 'ai-chat-wp') . ": {$shipping_method} - " . wc_price($order->get_shipping_total()) . "\n";
        }

        // Tax
        if ($order->get_total_tax() > 0) {
            $content .= "- " . __('Tax', 'ai-chat-wp') . ": " . wc_price($order->get_total_tax()) . "\n";
        }

        // Discounts
        if ($order->get_total_discount() > 0) {
            $content .= "- " . __('Discount', 'ai-chat-wp') . ": -" . wc_price($order->get_total_discount()) . "\n";
        }

        $content .= "- " . __('TOTAL', 'ai-chat-wp') . ": " . $order->get_formatted_order_total() . "\n\n";

        // === TRACKING INFORMATION ===
        // Agnostic: check multiple common tracking meta keys used by various plugins
        // First non-empty value wins for each field; if none found, section is skipped
        $tracking_number_keys = array(
            '_tracking_number',
            '_shipping_tracking_number',
        );
        $tracking_provider_keys = array(
            '_tracking_provider',
            '_webexpert_order_tracking_carrier',
        );
        $tracking_url_keys = array(
            '_tracking_url',
            '_wing_order_tracking_url',
        );
        $parcel_id_keys = array(
            '_boxnow_parcel_ids',
        );

        $tracking_number = '';
        foreach ($tracking_number_keys as $meta_key) {
            $value = $order->get_meta($meta_key);
            if (!empty($value)) {
                $tracking_number = sanitize_text_field($value);
                break;
            }
        }

        $tracking_provider = '';
        foreach ($tracking_provider_keys as $meta_key) {
            $value = $order->get_meta($meta_key);
            if (!empty($value)) {
                $tracking_provider = sanitize_text_field($value);
                break;
            }
        }

        $tracking_url = '';
        foreach ($tracking_url_keys as $meta_key) {
            $value = $order->get_meta($meta_key);
            if (!empty($value)) {
                $tracking_url = esc_url_raw($value);
                break;
            }
        }

        // Parcel IDs (may be serialized arrays)
        $parcel_ids = '';
        foreach ($parcel_id_keys as $meta_key) {
            $value = $order->get_meta($meta_key);
            if (!empty($value)) {
                if (is_array($value)) {
                    $parcel_ids = implode(', ', array_map('sanitize_text_field', $value));
                } else {
                    $parcel_ids = sanitize_text_field($value);
                }
                break;
            }
        }

        // WooCommerce Germanized / Shiptastic integration
        // Shipments are stored in a custom table, not as order meta — query them via the API
        if (empty($tracking_number) && empty($tracking_url) && function_exists('wc_stc_get_shipments_by_order')) {
            $shipments = wc_stc_get_shipments_by_order($order);
            foreach ($shipments as $shipment) {
                if ($shipment->get_tracking_id()) {
                    $tracking_number = sanitize_text_field($shipment->get_tracking_id());
                    $tracking_url    = esc_url_raw($shipment->get_tracking_url());
                    $provider        = $shipment->get_shipping_provider();
                    if ($provider && is_callable(array($provider, 'get_title'))) {
                        $tracking_provider = sanitize_text_field($provider->get_title());
                    }
                    break; // Use the first shipment with tracking
                }
            }
        }

        /**
         * Filter tracking meta keys to support additional plugins.
         *
         * Return associative array with resolved tracking data:
         * 'tracking_number', 'tracking_provider', 'tracking_url', 'parcel_ids'
         *
         * @param array    $tracking_data Current resolved tracking data.
         * @param WC_Order $order         The WooCommerce order object.
         */
        $tracking_data = apply_filters('aicwp_order_tracking_data', array(
            'tracking_number'   => $tracking_number,
            'tracking_provider' => $tracking_provider,
            'tracking_url'      => $tracking_url,
            'parcel_ids'        => $parcel_ids,
        ), $order);

        $tracking_number   = $tracking_data['tracking_number'];
        $tracking_provider = $tracking_data['tracking_provider'];
        $tracking_url      = $tracking_data['tracking_url'];
        $parcel_ids        = $tracking_data['parcel_ids'];

        if ($tracking_number || $tracking_url || $parcel_ids) {
            $content .= __('TRACKING INFORMATION', 'ai-chat-wp') . ":\n";
            if ($tracking_provider) {
                $content .= "- " . __('Carrier', 'ai-chat-wp') . ": {$tracking_provider}\n";
            }
            if ($tracking_number) {
                $content .= "- " . __('Tracking Number', 'ai-chat-wp') . ": {$tracking_number}\n";
            }
            if ($parcel_ids) {
                $content .= "- " . __('Parcel ID', 'ai-chat-wp') . ": {$parcel_ids}\n";
            }
            if ($tracking_url) {
                $content .= "- " . __('Track Package', 'ai-chat-wp') . ": {$tracking_url}\n";
            }
            $content .= "\n";
        }

        // === ORDER STATUS DETAILS ===
        if ($status === 'completed') {
            $content .= "✅ " . __('Order completed and delivered.', 'ai-chat-wp') . "\n";
            if ($order->get_date_completed()) {
                $content .= __('Completed on', 'ai-chat-wp') . ": " . $order->get_date_completed()->date_i18n('F j, Y g:i A') . "\n";
            }
        } elseif ($status === 'processing') {
            $content .= "🔄 " . __('Order is being processed and will be shipped soon.', 'ai-chat-wp') . "\n";
        } elseif ($status === 'shipped') {
            $content .= "📦 " . __('Order has been shipped and is on its way.', 'ai-chat-wp') . "\n";
        } elseif ($status === 'pending') {
            $content .= "⏳ " . __('Order is pending payment.', 'ai-chat-wp') . "\n";
        } elseif ($status === 'on-hold') {
            $content .= "⏸️ " . __('Order is on hold.', 'ai-chat-wp') . "\n";
        } elseif ($status === 'cancelled') {
            $content .= "❌ " . __('Order has been cancelled.', 'ai-chat-wp') . "\n";
        } elseif ($status === 'refunded') {
            $content .= "💰 " . __('Order has been refunded.', 'ai-chat-wp') . "\n";
        }

        /**
         * Filter to inject arbitrary extra content into the order context sent to AI.
         *
         * @param string   $content Built order content (no PII).
         * @param WC_Order $order   The WooCommerce order object.
         */
        return apply_filters('aicwp_order_extra_content', $content, $order);
    }

    // =========================================================================
    // WPC Price by Quantity integration
    //
    // Feeds quantity-based pricing tiers into the AI so the chatbot can answer
    // "how much for 50 units?" accurately. Only runs when WPC Price by Quantity
    // for WooCommerce (by WPClever) is active — otherwise returns early.
    //
    // Plugin: https://wordpress.org/plugins/wpc-price-by-quantity/
    // Helper class: Wpcpq_Helper (provided by WPC plugin)
    // =========================================================================

    /**
     * Append human-readable quantity tiers to the PRICING section sent to the LLM.
     *
     * Hooked into: aicwp_product_extra_pricing
     * Used by: embeddings content extractor, get_product_details tool, base chat API.
     *
     * @param string     $extra      Existing extra pricing text.
     * @param WC_Product $product    WooCommerce product object.
     * @param int        $product_id Product post ID.
     * @return string
     */
    public function wpcpq_extra_pricing_text($extra, $product, $product_id) {
        if (!class_exists('Wpcpq_Helper')) {
            return $extra;
        }

        $pricing = Wpcpq_Helper::get_pricing($product_id);
        if (empty($pricing) || empty($pricing['tiers'])) {
            return $extra;
        }

        $tiers      = Wpcpq_Helper::sort_tiers($pricing['tiers']);
        $method     = !empty($pricing['method']) ? $pricing['method'] : 'volume';
        $base_price = (float) $product->get_price();

        if ($base_price <= 0 || empty($tiers)) {
            return $extra;
        }

        $currency = get_woocommerce_currency_symbol();
        $lines    = array();

        foreach ($tiers as $i => $tier) {
            if (empty($tier['quantity']) || !isset($tier['price'])) {
                continue;
            }

            $qty        = (int) $tier['quantity'];
            $tier_price = Wpcpq_Helper::calculate_price($tier['price'], $base_price);

            if ($tier_price <= 0) {
                continue;
            }

            // Quantity range label: "1-4", "5-9", "10+"
            if (isset($tiers[$i + 1])) {
                $next_qty = (int) $tiers[$i + 1]['quantity'] - 1;
                $range    = ($qty === $next_qty) ? "{$qty}" : "{$qty}-{$next_qty}";
            } else {
                $range = "{$qty}+";
            }

            $formatted = AICWP_Utility_Helper::format_price($tier_price);

            $discount = Wpcpq_Helper::get_discount($base_price, $tier_price, 'percentage');
            if ($discount > 0) {
                $formatted .= " (" . round($discount) . "% off)";
            }

            $lines[] = "- {$range} units: {$formatted}/each";
        }

        $method_label = ($method === 'tiered')
            ? 'tiered (each range priced separately)'
            : 'volume (single price for entire order)';

        $extra .= "QUANTITY-BASED PRICING ({$method_label}):\n";
        $extra .= implode("\n", $lines) . "\n";

        return $extra;
    }

    /**
     * Append structured quantity-tier array to the product search API response.
     *
     * Hooked into: aicwp_product_extra_pricing_data
     * Used by: format_product_data() in WooCommerce product search results.
     *
     * @param array      $data       Existing extra data.
     * @param WC_Product $product    WooCommerce product object.
     * @param int        $product_id Product post ID.
     * @return array
     */
    public function wpcpq_extra_pricing_data($data, $product, $product_id) {
        if (!class_exists('Wpcpq_Helper')) {
            return $data;
        }

        $pricing = Wpcpq_Helper::get_pricing($product_id);
        if (empty($pricing) || empty($pricing['tiers'])) {
            return $data;
        }

        $tiers      = Wpcpq_Helper::sort_tiers($pricing['tiers']);
        $method     = !empty($pricing['method']) ? $pricing['method'] : 'volume';
        $base_price = (float) $product->get_price();

        if ($base_price <= 0 || empty($tiers)) {
            return $data;
        }

        $structured_tiers = array();

        foreach ($tiers as $i => $tier) {
            if (empty($tier['quantity']) || !isset($tier['price'])) {
                continue;
            }

            $qty        = (int) $tier['quantity'];
            $tier_price = Wpcpq_Helper::calculate_price($tier['price'], $base_price);

            if ($tier_price <= 0) {
                continue;
            }

            $max_qty = isset($tiers[$i + 1]) ? (int) $tiers[$i + 1]['quantity'] - 1 : null;

            $structured_tiers[] = array(
                'min_qty'          => $qty,
                'max_qty'          => $max_qty,
                'price'            => round($tier_price, 2),
                'discount_percent' => round(Wpcpq_Helper::get_discount($base_price, $tier_price, 'percentage')),
            );
        }

        $data['quantity_pricing'] = array(
            'method' => $method,
            'tiers'  => $structured_tiers,
        );

        return $data;
    }
}
