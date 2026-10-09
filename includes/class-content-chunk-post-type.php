<?php
/**
 * Content Chunk Custom Post Type
 *
 * Registers the ai_content_chunk post type for storing chunked content
 * from long posts/pages. Posts are private and only accessible to admins.
 *
 * @package AICWP_Plugin
 * @since 2.1.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Content_Chunk_Post_Type {

    /**
     * Post type slug
     */
    const POST_TYPE = 'ai_content_chunk';

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('init', array($this, 'register_post_type'));

        // Cleanup chunks when parent post is deleted
        add_action('before_delete_post', array($this, 'cleanup_on_parent_delete'));

        // Cleanup chunk when it's deleted
        add_action('before_delete_post', array($this, 'cleanup_on_chunk_delete'));

        // When parent post is trashed, also trash chunks
        add_action('wp_trash_post', array($this, 'trash_chunks_with_parent'));

        // When parent post is untrashed, also untrash chunks
        add_action('untrash_post', array($this, 'untrash_chunks_with_parent'));
    }

    /**
     * Register the content chunk post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => __('Content Chunks', 'ai-chat-wp'),
            'singular_name'         => __('Content Chunk', 'ai-chat-wp'),
            'menu_name'             => __('Content Chunks', 'ai-chat-wp'),
            'all_items'             => __('All Content Chunks', 'ai-chat-wp'),
            'add_new'               => __('Add New', 'ai-chat-wp'),
            'add_new_item'          => __('Add New Content Chunk', 'ai-chat-wp'),
            'edit_item'             => __('Edit Content Chunk', 'ai-chat-wp'),
            'new_item'              => __('New Content Chunk', 'ai-chat-wp'),
            'view_item'             => __('View Content Chunk', 'ai-chat-wp'),
            'search_items'          => __('Search Content Chunks', 'ai-chat-wp'),
            'not_found'             => __('No content chunks found', 'ai-chat-wp'),
            'not_found_in_trash'    => __('No content chunks found in trash', 'ai-chat-wp'),
        );

        $args = array(
            'labels'                => $labels,
            'public'                => false,
            'publicly_queryable'    => false,
            'show_ui'               => false, // Hidden from admin menu
            'show_in_menu'          => false,
            'show_in_nav_menus'     => false,
            'show_in_admin_bar'     => false,
            'query_var'             => false,
            'rewrite'               => false,
            'capability_type'       => 'post',
            'capabilities'          => array(
                'edit_post'          => 'manage_options',
                'read_post'          => 'manage_options',
                'delete_post'        => 'manage_options',
                'edit_posts'         => 'manage_options',
                'edit_others_posts'  => 'manage_options',
                'delete_posts'       => 'manage_options',
                'publish_posts'      => 'manage_options',
                'read_private_posts' => 'manage_options',
            ),
            'has_archive'           => false,
            'hierarchical'          => false,
            'menu_position'         => null,
            'supports'              => array('title', 'editor', 'custom-fields'),
            'exclude_from_search'   => true,
            'show_in_rest'          => false,
        );

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Cleanup chunks when parent post is deleted
     *
     * @param int $post_id Post ID being deleted
     */
    public function cleanup_on_parent_delete($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);

        if (!$post) {
            return;
        }

        // Skip if this is a chunk itself
        if ($post->post_type === self::POST_TYPE) {
            return;
        }

        // Check if this post has chunks
        if (!AICWP_Content_Chunker::supports_chunking($post->post_type)) {
            return;
        }

        // Delete all chunks for this parent
        AICWP_Content_Chunker::delete_chunks_for_post($post_id);
    }

    /**
     * Cleanup embedding when chunk is deleted
     *
     * @param int $post_id Post ID being deleted
     */
    public function cleanup_on_chunk_delete($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        // Delete embedding
        if (class_exists('AICWP_Database_Manager')) {
            AICWP_Database_Manager::delete_embedding($post_id);
        }
    }

    /**
     * Trash chunks when parent is trashed
     *
     * @param int $post_id Post ID being trashed
     */
    public function trash_chunks_with_parent($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type === self::POST_TYPE) {
            return;
        }

        if (!AICWP_Content_Chunker::supports_chunking($post->post_type)) {
            return;
        }

        $chunks = AICWP_Content_Chunker::get_chunks_for_post($post_id);
        foreach ($chunks as $chunk) {
            wp_trash_post($chunk->ID);
        }
    }

    /**
     * Untrash chunks when parent is untrashed
     *
     * @param int $post_id Post ID being untrashed
     */
    public function untrash_chunks_with_parent($post_id) {
        $post = get_post($post_id);

        if (!$post || $post->post_type === self::POST_TYPE) {
            return;
        }

        if (!AICWP_Content_Chunker::supports_chunking($post->post_type)) {
            return;
        }

        // Find trashed chunks for this parent
        $chunks = get_posts(array(
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => -1,
            'post_status'    => 'trash',
            'meta_key'       => '_chunk_parent_id',
            'meta_value'     => $post_id,
        ));

        foreach ($chunks as $chunk) {
            wp_untrash_post($chunk->ID);
        }
    }

    /**
     * Get statistics about content chunks
     *
     * @return array Statistics array
     */
    public static function get_stats() {
        global $wpdb;

        $stats = array(
            'total_chunks' => 0,
            'chunked_posts' => 0,
            'chunked_pages' => 0,
            'chunks_with_embeddings' => 0,
        );

        // Total chunks
        $stats['total_chunks'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
            self::POST_TYPE
        ));

        // Unique parent posts by type
        $parent_counts = $wpdb->get_results($wpdb->prepare(
            "SELECT pm2.meta_value as source_type, COUNT(DISTINCT pm1.meta_value) as count
             FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm1.post_id = pm2.post_id
             INNER JOIN {$wpdb->posts} p ON pm1.post_id = p.ID
             WHERE pm1.meta_key = '_chunk_parent_id'
             AND pm2.meta_key = '_chunk_source_type'
             AND p.post_type = %s
             AND p.post_status = 'publish'
             GROUP BY pm2.meta_value",
            self::POST_TYPE
        ), ARRAY_A);

        foreach ($parent_counts as $row) {
            if ($row['source_type'] === 'post') {
                $stats['chunked_posts'] = (int) $row['count'];
            } elseif ($row['source_type'] === 'page') {
                $stats['chunked_pages'] = (int) $row['count'];
            }
        }

        // Chunks with embeddings
        if (class_exists('AICWP_Database_Manager')) {
            $table = AICWP_Database_Manager::get_embeddings_table_name();
            $stats['chunks_with_embeddings'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table} e
                 INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                 WHERE p.post_type = %s",
                self::POST_TYPE
            ));
        }

        return $stats;
    }
}

// Initialize
AICWP_Content_Chunk_Post_Type::get_instance();
