<?php

/**

 * Utility Helper Class

 * 

 * Contains mathematical calculations and utility functions

 * 

 * @package AICWP_Plugin

 * @since 1.0.5

 */



// Prevent direct access

if (!defined('ABSPATH')) {

    exit;

}



class AICWP_Utility_Helper {

    /**
     * A price as plain text, formatted the way the store shows prices
     * (WooCommerce's currency symbol, its position, decimal and thousand
     * separators, number of decimals): "R$ 39,90", not "R$39.90".
     *
     * @param float|int|string $amount
     * @return string Empty for an empty amount.
     */
    public static function format_price($amount) {
        if ($amount === '' || $amount === null) {
            return '';
        }
        if (!function_exists('wc_price')) {
            return number_format((float) $amount, 2);
        }
        $text = html_entity_decode(wp_strip_all_tags(wc_price((float) $amount)), ENT_QUOTES, 'UTF-8');
        return trim(str_replace("\xc2\xa0", ' ', $text));
    }

    /**
     * The price a product card shows: a variable product whose variations
     * cost different amounts reads "A partir de: R$ 39,90" (WooCommerce's own,
     * translated "From:" text), anything else just its price.
     *
     * @param WC_Product $product
     * @param float|int|string $amount The price to show (already tax-adjusted for display).
     * @return string
     */
    public static function format_product_price($product, $amount) {
        $price = self::format_price($amount);
        if ($price === '' || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
            return $price;
        }
        $min = (float) $product->get_variation_price('min', true);
        $max = (float) $product->get_variation_price('max', true);
        if ($min === $max || !function_exists('wc_get_price_html_from_text')) {
            return $price;
        }
        $from = trim(html_entity_decode(wp_strip_all_tags(wc_get_price_html_from_text()), ENT_QUOTES, 'UTF-8'));
        return $from !== '' ? $from . ' ' . $price : $price;
    }



    /**

     * Resolve the effective embedding provider from the stored embedding model.

     * OpenRouter routes models from multiple vendors, so we detect the underlying

     * provider from the model slug to apply correct similarity thresholds.

     *

     * @param string|null $embedding_model Override model slug, or null to read from options

     * @return string 'openai', 'gemini', or 'mistral'

     */

    public static function get_embedding_provider($embedding_model = null) {

        if ($embedding_model === null) {

            $embedding_model = get_option('aicwp_embedding_model', '');

        }



        if (empty($embedding_model)) {

            return get_option('aicwp_provider', 'openai');

        }



        if (strpos($embedding_model, 'gemini') !== false) {

            return 'gemini';

        }

        if (strpos($embedding_model, 'mistral') !== false) {

            return 'mistral';

        }

        return 'openai';

    }

    

    /**

     * Transform similarity score to user-friendly percentage

     *

     * Applies provider-specific normalization to account for different

     * similarity distributions between OpenAI and Gemini embeddings.

     *

     * MAPPING TABLES:

     *

     * Gemini - Linear:

     * | Raw   | Display |

     * |-------|---------|

     * | 0.50  | 50%     |

     * | 0.70  | 70%     |

     * | 0.85  | 85%     |

     * | 1.00  | 100%    |

     *

     * Mistral - Compressed below 0.85 (high noise floor):

     * | Raw   | Display |

     * |-------|---------|

     * | 0.98  | 98%     | (linear zone)

     * | 0.85  | 85%     | (threshold)

     * | 0.80  | 76%     |

     * | 0.70  | 57%     |

     * | 0.60  | 39%     |

     * | 0.50  | 20%     |

     * | 0.40  | 16%     |

     *

     * OpenAI - Threshold-based:

     * | Raw   | Display |

     * |-------|---------|

     * | 0.30  | 39%     |

     * | 0.40  | 52%     |

     * | 0.49  | 64%     |

     * | 0.50  | 70%     |

     * | 0.60  | 75%     |

     * | 0.70  | 80%     |

     * | 0.80  | 85%     |

     * | 0.90  | 90%     |

     * | 1.00  | 95%     |

     *

     * @param float $raw_similarity Raw cosine similarity score

     * @param string|null $provider Optional provider override ('openai' or 'gemini')

     * @param string|null $post_type Optional post type (kept for backwards compatibility)

     * @return int User-friendly percentage

     */

    public static function transform_similarity_to_percentage($raw_similarity, $provider = null, $post_type = null) {

        // Resolve provider from embedding model, not AI provider

        if ($provider === null) {

            $provider = self::get_embedding_provider();

        }



        if ($provider === 'gemini') {

            // Gemini: curved mapping (0.50 raw → 75% display)

            if ($raw_similarity >= 0.50) {

                // 0.50 → 75%, scales up to 100% at 1.0

                $result = 75 + (($raw_similarity - 0.50) / 0.50) * 25;

            } else {

                // Below 0.50 → max 75%, linear scale from 0

                $result = ($raw_similarity / 0.50) * 75;

            }

        } elseif ($provider === 'mistral') {

            // Mistral: aggressive compression below 0.85

            // Mistral has a high "noise floor" (~0.50-0.60 for unrelated items)

            // so we compress lower scores significantly

            if ($raw_similarity >= 0.85) {

                // Excellent matches stay linear

                $result = $raw_similarity * 100;

            } elseif ($raw_similarity >= 0.50) {

                // 0.50-0.85 maps to 20-85% (compressed)

                $result = 20 + (($raw_similarity - 0.50) / 0.35) * 65;

            } else {

                // Below 0.50 maps to 0-20%

                $result = ($raw_similarity / 0.50) * 20;

            }

        } else {

            // OpenAI (all post types): threshold-based mapping

            // Below 0.50 = max 65%, 0.50+ = 70%+

            if ($raw_similarity >= 0.50) {

                // 0.50 → 70%, scales up to 95% at 1.0

                $result = 70 + (($raw_similarity - 0.50) / 0.50) * 25;

            } else {

                // Below 0.50 → max 65%, linear scale from 0

                $result = ($raw_similarity / 0.50) * 65;

            }

        }



        return $result;

    }



    /**

     * Convert user-friendly percentage to raw cosine similarity

     * Reverse of transform_similarity_to_percentage()

     *

     * @param int $percentage User-friendly percentage (0-100)

     * @param bool $strict Strict mode for listings/products

     * @param string|null $provider Optional provider override

     * @param bool $is_rag RAG mode - uses even more lenient thresholds for context retrieval

     * @return float Raw cosine similarity threshold

     */

    public static function percentage_to_similarity($percentage, $strict = true, $provider = null, $is_rag = false) {

        // Resolve provider from embedding model, not AI provider

        if ($provider === null) {

            $provider = self::get_embedding_provider();

        }



        // RAG mode: divide percentage by 2 for more lenient context retrieval

        // This only applies to non-strict (content) mode - listings/products stay strict

        if ($is_rag && !$strict) {

            $percentage = $percentage / 2;

        }



        // Two modes based on content type:

        // strict=true  → for listings/products

        // strict=false → for posts/pages/content (lenient)



        if ($strict) {

            // Listings/products mapping - provider-specific offsets

            if ($provider === 'gemini') {

                // Gemini: 50% → 0.35 raw (offset -0.15)

                $threshold = max(0, ($percentage / 100) - 0.15);

            } elseif ($provider === 'mistral') {

                // Mistral: high noise floor (~0.50-0.60), needs higher thresholds

                // 50% → 0.70 raw (offset +0.20), capped at 0.95

                $threshold = min(0.95, ($percentage / 100) + 0.20);

            } else {

                // OpenAI: 50% → 0.24 raw (offset -0.20, then /1.25)

                $threshold = max(0, ($percentage / 100) - 0.20);

                $threshold = $threshold / 1.25;

            }



            return $threshold;

        }



        // Lenient mapping for posts/pages/content

        // 90-100% → 0.60-0.75 raw

        // 75-90%  → 0.45-0.60 raw

        // 60-75%  → 0.35-0.45 raw

        // 40-60%  → 0.25-0.35 raw

        // 0-40%   → 0.00-0.25 raw

        if ($percentage >= 90) {

            $threshold = 0.60 + (($percentage - 90) / 10) * 0.15;

        } elseif ($percentage >= 75) {

            $threshold = 0.45 + (($percentage - 75) / 15) * 0.15;

        } elseif ($percentage >= 60) {

            $threshold = 0.35 + (($percentage - 60) / 15) * 0.10;

        } elseif ($percentage >= 40) {

            $threshold = 0.25 + (($percentage - 40) / 20) * 0.10;

        } else {

            $threshold = ($percentage / 40) * 0.25;

        }



        // OpenAI embedding models produce significantly lower similarity scores than Gemini.

        // Applies to any OpenAI-family embedding model (text-embedding-3-small/large),

        // whether used directly or via OpenRouter.

        if ($provider === 'openai') {

            $threshold = $threshold / 1.5;

        }



        return $threshold;

    }

    

    /**

     * Calculate cosine similarity between two vectors

     *

     * Optimized: Uses dot product only since OpenAI/Gemini embeddings are pre-normalized

     * to unit vectors (magnitude = 1). This skips magnitude calculations entirely.

     *

     * @param array $vector1 First vector

     * @param array $vector2 Second vector

     * @return float Cosine similarity score (-1 to 1)

     */

    public static function calculate_cosine_similarity($vector1, $vector2) {

        $len = count($vector1);

        if ($len !== count($vector2)) {

            return 0;

        }



        $dot_product = 0;

        for ($i = 0; $i < $len; $i++) {

            $dot_product += $vector1[$i] * $vector2[$i];

        }



        return $dot_product;

    }



    /**

     * Compute dot product directly on compressed embedding data

     *

     * Skips float decompression - multiplies query floats against packed 16-bit ints

     * and divides once at the end. Equivalent to calculate_cosine_similarity() but

     * avoids allocating an intermediate float array.

     *

     * unpack('s*') returns 1-indexed array, so stored values use offset $i + 1.

     *

     * @param array $query_vector Float array from the embedding API

     * @param string $compressed_data Base64 + gzcompress packed 16-bit data

     * @return float|false Cosine similarity, or false on decompression failure

     */

    public static function dot_product_packed($query_vector, $compressed_data) {

        if (empty($compressed_data)) {

            return false;

        }



        // Legacy JSON format - fall back to float path

        if (substr(trim($compressed_data), 0, 1) === '[') {

            $decoded = json_decode($compressed_data, true);

            if (!$decoded) {

                return false;

            }

            return self::calculate_cosine_similarity($query_vector, $decoded);

        }



        $packed = @gzuncompress(base64_decode($compressed_data));

        if ($packed === false) {

            // Try JSON fallback

            $decoded = json_decode($compressed_data, true);

            if ($decoded) {

                return self::calculate_cosine_similarity($query_vector, $decoded);

            }

            return false;

        }



        $quantized = unpack('s*', $packed);

        if ($quantized === false || count($quantized) !== count($query_vector)) {

            return false;

        }



        $dot = 0.0;

        $len = count($query_vector);

        for ($i = 0; $i < $len; $i++) {

            $dot += $query_vector[$i] * $quantized[$i + 1];

        }



        return $dot / 32767.0;

    }

    

    /**

     * Generate search explanation based on query and results count

     * 

     * @param string $query Search query

     * @param int $count Number of results found

     * @return string Search explanation

     */

    public static function generate_search_explanation($query, $count) {

        if ($count === 0) {

            return sprintf(__('No listings found matching "%s"', 'ai-chat-wp'), $query);

        } elseif ($count === 1) {

            return sprintf(__('Top 1 listing matching "%s"', 'ai-chat-wp'), $query);

        } else {

            return sprintf(__('Top %d listings matching "%s"', 'ai-chat-wp'), $count, $query);

        }

    }

    

    /**

     * Get the real client IP address securely.

     *

     * Three-step logic:

     * 1. Cloudflare: Trust CF-Connecting-IP only if REMOTE_ADDR is a verified Cloudflare edge IP.

     * 2. Custom proxy: Trust X-Forwarded-For / X-Real-IP only if REMOTE_ADDR matches

     *    IPs returned by the 'aicwp_trusted_proxies' filter.

     * 3. Direct: Use REMOTE_ADDR (cannot be spoofed at the TCP level).

     *

     * @since 1.8.4

     * @return string Valid IP address, or '127.0.0.1' if unresolvable.

     */

    public static function get_client_ip_secure() {

        $remote_addr = isset($_SERVER['REMOTE_ADDR'])

            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))

            : '';



        if (empty($remote_addr) || !filter_var($remote_addr, FILTER_VALIDATE_IP)) {

            return '127.0.0.1';

        }



        // Step 1: Cloudflare — auto-detected, zero config

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {

            $cf_ranges = self::get_cloudflare_ip_ranges();

            foreach ($cf_ranges as $range) {

                if (self::ip_in_range($remote_addr, $range)) {

                    $cf_ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));

                    if (filter_var($cf_ip, FILTER_VALIDATE_IP)) {

                        return $cf_ip;

                    }

                    break; // REMOTE_ADDR matched CF but header was invalid — fall through

                }

            }

        }



        // Step 2: Custom proxy — requires one-line filter hook

        $trusted_proxies = apply_filters('aicwp_trusted_proxies', array());

        if (!empty($trusted_proxies) && is_array($trusted_proxies)) {

            foreach ($trusted_proxies as $trusted_range) {

                if (self::ip_in_range($remote_addr, $trusted_range)) {

                    // Trust X-Forwarded-For first, then X-Real-IP

                    $forwarded_headers = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP');

                    foreach ($forwarded_headers as $header) {

                        if (!empty($_SERVER[$header])) {

                            $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));

                            // X-Forwarded-For can be comma-separated; take the first (client) IP

                            if (strpos($ip, ',') !== false) {

                                $ip = trim(explode(',', $ip)[0]);

                            }

                            if (filter_var($ip, FILTER_VALIDATE_IP)) {

                                return $ip;

                            }

                        }

                    }

                }

            }

        }



        // Step 3: Direct connection

        return $remote_addr;

    }



    /**

     * Check if an IP falls within a CIDR range.

     *

     * Supports both IPv4 (e.g. 103.21.244.0/22) and IPv6 (e.g. 2400:cb00::/32).

     *

     * @since 1.8.4

     * @param string $ip    IP address to check.

     * @param string $range CIDR range or single IP.

     * @return bool

     */

    public static function ip_in_range($ip, $range) {

        // Handle single IP (no CIDR) — use inet_pton for canonical comparison

        if (strpos($range, '/') === false) {

            $ip_bin    = @inet_pton($ip);

            $range_bin = @inet_pton($range);

            // Both must be valid IPs — false === false would incorrectly match

            if ($ip_bin === false || $range_bin === false) {

                return false;

            }

            return $ip_bin === $range_bin;

        }



        list($subnet, $bits_str) = explode('/', $range, 2);



        // Validate CIDR prefix length — non-numeric or empty means malformed range

        if (!is_numeric($bits_str)) {

            return false;

        }

        $bits = (int) $bits_str;



        $ip_bin     = @inet_pton($ip);

        $subnet_bin = @inet_pton($subnet);



        if ($ip_bin === false || $subnet_bin === false) {

            return false;

        }



        // Both must be the same address family

        if (strlen($ip_bin) !== strlen($subnet_bin)) {

            return false;

        }



        // Validate prefix length against address family (IPv4: 1-32, IPv6: 1-128)

        $max_bits = strlen($ip_bin) * 8;

        if ($bits < 1 || $bits > $max_bits) {

            return false;

        }



        // Build bitmask and compare (byte-based to avoid nibble overflow)

        $mask_bin = str_repeat("\xff", (int) ($bits / 8));

        $remainder = $bits % 8;

        if ($remainder > 0) {

            $mask_bin .= chr(0xFF << (8 - $remainder));

        }

        $mask_bin = str_pad($mask_bin, strlen($ip_bin), "\x00");



        return ($ip_bin & $mask_bin) === ($subnet_bin & $mask_bin);

    }



    /**

     * Get Cloudflare IP ranges.

     *

     * Hardcoded list — Cloudflare changes these extremely rarely (~2-3 times per decade).

     * Update this list in plugin releases when needed.

     * Source: https://www.cloudflare.com/ips/

     *

     * @since 1.8.4

     * @return string[] Array of CIDR ranges.

     */

    public static function get_cloudflare_ip_ranges() {

        return array(

            // IPv4 (last verified: January 2026)

            '173.245.48.0/20',

            '103.21.244.0/22',

            '103.22.200.0/22',

            '103.31.4.0/22',

            '141.101.64.0/18',

            '108.162.192.0/18',

            '190.93.240.0/20',

            '188.114.96.0/20',

            '197.234.240.0/22',

            '198.41.128.0/17',

            '162.158.0.0/15',

            '104.16.0.0/13',

            '104.24.0.0/14',

            '172.64.0.0/13',

            '131.0.72.0/22',

            // IPv6 (last verified: January 2026)

            '2400:cb00::/32',

            '2606:4700::/32',

            '2803:f800::/32',

            '2405:b500::/32',

            '2405:8100::/32',

            '2a06:98c0::/29',

            '2c0f:f248::/32',

        );

    }



    /**

     * Custom debug logging to debug_search.log

     * 

     * @param string $message Log message

     * @param string $level Log level (info, error, warning, debug)

     */

    public static function debug_log($message, $level = 'info') {

        // Only log if debug mode is explicitly enabled (must be truthy value like 1 or '1')

        $debug_mode = get_option('aicwp_debug_mode', 0);

        if (empty($debug_mode) || $debug_mode === '0') {

            return;

        }

        

        $log_file = WP_CONTENT_DIR . '/debug_search.log';

        $timestamp = date('Y-m-d H:i:s');

        $formatted_message = "[{$timestamp}] [{$level}] AI Chat for WordPress: {$message}" . PHP_EOL;

        

        // Ensure the log file is writable

        if (!file_exists($log_file)) {

            touch($log_file);

        }

        

        error_log($formatted_message, 3, $log_file);

    }
}

