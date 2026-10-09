<?php
/**
 * Fallback Search Engine Class
 * 
 * Handles traditional WordPress search functionality
 * 
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Fallback_Engine {
    
    /**
     * Perform fallback search using WordPress default search
     * 
     * @param string $query Search query
     * @param int $limit Number of results to return
     * @param int $offset Results offset for pagination
     * @param string $listing_types Comma-separated post types or 'all' (all enabled post types)
     * @return array Search results
     */
    public static function search($query, $limit, $offset, $listing_types) {
        // 'all' = every post type enabled in admin settings
        $post_types = ($listing_types === 'all')
            ? AICWP_Database_Manager::get_enabled_post_types()
            : array_filter(array_map('trim', explode(',', $listing_types)));

        if (empty($post_types)) {
            $post_types = array('post', 'page');
        }

        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'offset' => $offset,
            's' => $query,
        );
        
        $search_query = new WP_Query($args);
        
        return array(
            'listings' => AICWP_Result_Formatter::format_search_results($search_query->posts, false),
            'total_found' => $search_query->found_posts,
            'search_type' => 'traditional',
            'query' => $query,
            'explanation' => sprintf(__('Here are results matching "%s"', 'ai-chat-wp'), $query),
            'has_more' => $search_query->found_posts > ($offset + $limit)
        );
    }
}
