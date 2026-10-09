<?php
/**
 * Search Analytics Class
 * 
 * Tracks search queries and performance for optimization insights
 * 
 * @package AICWP_Plugin
 * @since 1.1.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Analytics {
    
    /**
     * Log search query
     *
     * @param string $query Search query
     * @param int $results_count Number of results found
     * @param string $search_type 'ai' or 'traditional'
     * @param float $response_time Response time in milliseconds
     * @param string $source Source of search: 'search_bar', 'rest_api_universal', 'rest_api_rag', 'rest_api_products'
     */
    public static function log_search($query, $results_count, $search_type, $response_time, $source = 'search_bar') {
        // Only log if analytics are enabled
        if (!get_option('aicwp_enable_analytics', false)) {
            return;
        }

        // Deduplication: Skip if same query was logged within 60 seconds
        // Prevents double-logging of repeated identical searches
        $normalized = strtolower(trim($query));
        $dedupe_key = 'aicwp_search_dup_' . md5($normalized);

        if (get_transient($dedupe_key)) {
            return;
        }
        set_transient($dedupe_key, 1, 20);

        $log_entry = array(
            'query' => sanitize_text_field($query),
            'results_count' => intval($results_count),
            'search_type' => sanitize_text_field($search_type),
            'response_time' => floatval($response_time),
            'source' => sanitize_text_field($source),
            'timestamp' => current_time('timestamp'),
            'user_ip' => self::get_anonymized_ip(),
        );
        
        // Store in transient (keep for 30 days)
        $current_logs = get_option('aicwp_logs', array());
        $current_logs[] = $log_entry;
        
        // Keep only last 10000 entries to prevent database bloat
        if (count($current_logs) > 10000) {
            $current_logs = array_slice($current_logs, -10000);
        }
        
        update_option('aicwp_logs', $current_logs);
    }
    
    /**
     * Get search analytics data
     *
     * @param int $days Number of days to analyze (default 7)
     * @return array Analytics data
     */
    public static function get_analytics($days = 7) {
        $logs = get_option('aicwp_logs', array());
        $cutoff_time = current_time('timestamp') - ($days * DAY_IN_SECONDS);

        // Filter logs to specified time period
        $recent_logs = array_filter($logs, function($log) use ($cutoff_time) {
            return $log['timestamp'] > $cutoff_time;
        });

        if (empty($recent_logs)) {
            return array(
                'total_searches' => 0,
                'avg_response_time' => 0,
                'ai_usage_percentage' => 0,
                'popular_queries' => array(),
                'avg_results_per_search' => 0,
                'by_source' => array(),
            );
        }

        // Calculate analytics
        $total_searches = count($recent_logs);
        $ai_searches = array_filter($recent_logs, function($log) {
            return $log['search_type'] === 'ai';
        });

        $avg_response_time = array_sum(array_column($recent_logs, 'response_time')) / $total_searches;
        $ai_usage_percentage = (count($ai_searches) / $total_searches) * 100;
        $avg_results = array_sum(array_column($recent_logs, 'results_count')) / $total_searches;

        // Get popular queries
        $query_counts = array();
        foreach ($recent_logs as $log) {
            $query = strtolower(trim($log['query']));
            if (strlen($query) > 2) { // Ignore very short queries
                $query_counts[$query] = ($query_counts[$query] ?? 0) + 1;
            }
        }
        arsort($query_counts);
        $popular_queries = array_slice($query_counts, 0, 50, true);

        // Calculate breakdown by source
        $source_stats = array();
        foreach ($recent_logs as $log) {
            $source = isset($log['source']) ? $log['source'] : 'search_bar';

            if (!isset($source_stats[$source])) {
                $source_stats[$source] = array(
                    'count' => 0,
                    'total_results' => 0,
                    'total_response_time' => 0,
                    'ai_searches' => 0
                );
            }

            $source_stats[$source]['count']++;
            $source_stats[$source]['total_results'] += intval($log['results_count']);
            $source_stats[$source]['total_response_time'] += floatval($log['response_time']);

            if ($log['search_type'] === 'ai') {
                $source_stats[$source]['ai_searches']++;
            }
        }

        // Calculate averages and percentages for each source
        $by_source = array();
        foreach ($source_stats as $source => $stats) {
            $by_source[$source] = array(
                'count' => $stats['count'],
                'percentage' => round(($stats['count'] / $total_searches) * 100, 1),
                'avg_results' => round($stats['total_results'] / $stats['count'], 1),
                'avg_response_time' => round($stats['total_response_time'] / $stats['count'], 2),
                'ai_usage_percentage' => $stats['count'] > 0 ? round(($stats['ai_searches'] / $stats['count']) * 100, 1) : 0
            );
        }

        return array(
            'total_searches' => $total_searches,
            'avg_response_time' => round($avg_response_time, 2),
            'ai_usage_percentage' => round($ai_usage_percentage, 1),
            'popular_queries' => $popular_queries,
            'avg_results_per_search' => round($avg_results, 1),
            'period_days' => $days,
            'by_source' => $by_source,
        );
    }
    
    /**
     * Get anonymized IP address for privacy
     * 
     * @return string Anonymized IP
     */
    private static function get_anonymized_ip() {
        $ip = AICWP_Utility_Helper::get_client_ip_secure();
        
        // Anonymize IPv4 (remove last octet)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0'; // Replace last octet
            return implode('.', $parts);
        }
        
        // Anonymize IPv6 (keep first 64 bits)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $ip);
            $parts = array_slice($parts, 0, 4); // Keep first 4 groups
            return implode(':', $parts) . '::';
        }
        
        return 'anonymized';
    }
    
    /**
     * Clear analytics data
     */
    public static function clear_analytics() {
        delete_option('aicwp_logs');
    }

    /**
     * Get all raw logs for export
     *
     * @return array All log entries
     */
    public static function get_all_logs() {
        return get_option('aicwp_logs', array());
    }

    /**
     * Get popular queries with counts for export
     *
     * @param int $days Number of days to analyze (default: all)
     * @return array Array of queries with counts and stats
     */
    public static function get_popular_queries_for_export($days = null) {
        $logs = get_option('aicwp_logs', array());

        // Filter by days if specified
        if ($days !== null) {
            $cutoff_time = current_time('timestamp') - ($days * DAY_IN_SECONDS);
            $logs = array_filter($logs, function($log) use ($cutoff_time) {
                return isset($log['timestamp']) && $log['timestamp'] > $cutoff_time;
            });
        }

        // Aggregate queries
        $query_stats = array();
        foreach ($logs as $log) {
            $query = strtolower(trim($log['query'] ?? ''));
            if (strlen($query) < 2) continue;

            if (!isset($query_stats[$query])) {
                $query_stats[$query] = array(
                    'query' => $log['query'] ?? '',
                    'count' => 0,
                    'total_results' => 0,
                    'total_response_time' => 0,
                    'first_searched' => $log['timestamp'] ?? 0,
                    'last_searched' => $log['timestamp'] ?? 0,
                );
            }

            $query_stats[$query]['count']++;
            $query_stats[$query]['total_results'] += intval($log['results_count'] ?? 0);
            $query_stats[$query]['total_response_time'] += floatval($log['response_time'] ?? 0);

            $timestamp = $log['timestamp'] ?? 0;
            if ($timestamp < $query_stats[$query]['first_searched']) {
                $query_stats[$query]['first_searched'] = $timestamp;
            }
            if ($timestamp > $query_stats[$query]['last_searched']) {
                $query_stats[$query]['last_searched'] = $timestamp;
            }
        }

        // Sort by count descending
        uasort($query_stats, function($a, $b) {
            return $b['count'] - $a['count'];
        });

        return $query_stats;
    }

    /**
     * Export popular search queries as CSV
     *
     * Outputs CSV directly to browser for download
     *
     * @param int|null $days Number of days to include (null = all time)
     */
    public static function export_popular_queries_csv($days = null) {
        $query_stats = self::get_popular_queries_for_export($days);

        // Set headers for CSV download
        $filename = 'popular-search-queries-' . date('Y-m-d');
        if ($days !== null) {
            $filename .= '-last-' . $days . '-days';
        }
        $filename .= '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Write CSV header
        fputcsv($output, array(
            __('Search Query', 'ai-chat-wp'),
            __('Search Count', 'ai-chat-wp'),
            __('Avg Results', 'ai-chat-wp'),
            __('Avg Response Time (ms)', 'ai-chat-wp'),
            __('First Searched', 'ai-chat-wp'),
            __('Last Searched', 'ai-chat-wp')
        ));

        // Write data rows
        foreach ($query_stats as $stats) {
            $avg_results = $stats['count'] > 0 ? round($stats['total_results'] / $stats['count'], 1) : 0;
            $avg_response = $stats['count'] > 0 ? round($stats['total_response_time'] / $stats['count'], 2) : 0;

            fputcsv($output, array(
                $stats['query'],
                $stats['count'],
                $avg_results,
                $avg_response,
                $stats['first_searched'] ? date('Y-m-d H:i:s', $stats['first_searched']) : '',
                $stats['last_searched'] ? date('Y-m-d H:i:s', $stats['last_searched']) : ''
            ));
        }

        fclose($output);
    }
}
