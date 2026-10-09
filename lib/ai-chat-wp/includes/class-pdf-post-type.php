<?php
/**
 * Document Custom Post Type
 *
 * Registers the ai_pdf_document post type for storing document content
 * Supports PDF, TXT, MD, XML, CSV files
 * Posts are private and only accessible to admins
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_PDF_Post_Type {

    /**
     * Post type slug
     */
    const POST_TYPE = 'ai_pdf_document';

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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Cleanup on post deletion
        add_action('before_delete_post', array($this, 'cleanup_on_delete'));
    }

    /**
     * Register the PDF document post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => __('Documents', 'ai-chat-wp'),
            'singular_name'         => __('Document', 'ai-chat-wp'),
            'menu_name'             => __('Documents', 'ai-chat-wp'),
            'all_items'             => __('All Documents', 'ai-chat-wp'),
            'add_new'               => __('Add New', 'ai-chat-wp'),
            'add_new_item'          => __('Add New Document', 'ai-chat-wp'),
            'edit_item'             => __('Edit Document', 'ai-chat-wp'),
            'new_item'              => __('New Document', 'ai-chat-wp'),
            'view_item'             => __('View Document', 'ai-chat-wp'),
            'search_items'          => __('Search Documents', 'ai-chat-wp'),
            'not_found'             => __('No documents found', 'ai-chat-wp'),
            'not_found_in_trash'    => __('No documents found in trash', 'ai-chat-wp'),
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
     * Enqueue admin assets (for future admin columns)
     */
    public function enqueue_admin_assets($hook) {
        // Reserved for future use
    }

    /**
     * Cleanup when PDF document is deleted
     * Removes associated embeddings and original file
     */
    public function cleanup_on_delete($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $post = get_post($post_id);

        if (!$post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        // Delete embedding if exists
        if (class_exists('AICWP_Database_Manager')) {
            global $wpdb;
            $table = AICWP_Database_Manager::get_embeddings_table_name();
            $wpdb->delete($table, array('listing_id' => $post_id), array('%d'));
        }

        // Get parent PDF file path (if this is a chunk)
        $pdf_file = get_post_meta($post_id, '_pdf_file_path', true);
        $chunk_number = get_post_meta($post_id, '_pdf_chunk_number', true);
        $total_chunks = get_post_meta($post_id, '_pdf_total_chunks', true);

        // If this is the last chunk or only chunk, delete the original file
        if ($pdf_file && file_exists($pdf_file)) {
            // Check if there are other chunks
            global $wpdb;
            $remaining_chunks = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = '_pdf_file_path'
                 AND pm.meta_value = %s
                 AND pm.post_id != %d
                 AND p.post_type = %s",
                $pdf_file,
                $post_id,
                self::POST_TYPE
            ));

            // Delete file only if no other chunks reference it
            if ($remaining_chunks == 0) {
                @unlink($pdf_file);

                // Trigger action for PRO plugin to cleanup
                do_action('aicwp_pdf_file_deleted', $pdf_file);
            }
        }
    }

    /**
     * Get all documents (chunks grouped by parent file)
     *
     * @return array Array of documents grouped by original filename
     */
    public static function get_all_pdf_documents() {
        global $wpdb;

        $query = "
            SELECT p.ID, p.post_title, p.post_date,
                   pm1.meta_value as filename,
                   pm2.meta_value as chunk_number,
                   pm3.meta_value as total_chunks,
                   pm4.meta_value as file_path,
                   pm5.meta_value as file_type
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_pdf_original_filename'
            LEFT JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_pdf_chunk_number'
            LEFT JOIN {$wpdb->postmeta} pm3 ON p.ID = pm3.post_id AND pm3.meta_key = '_pdf_total_chunks'
            LEFT JOIN {$wpdb->postmeta} pm4 ON p.ID = pm4.post_id AND pm4.meta_key = '_pdf_file_path'
            LEFT JOIN {$wpdb->postmeta} pm5 ON p.ID = pm5.post_id AND pm5.meta_key = '_document_file_type'
            WHERE p.post_type = %s
            ORDER BY pm1.meta_value, pm2.meta_value
        ";

        $results = $wpdb->get_results($wpdb->prepare($query, self::POST_TYPE), ARRAY_A);

        // Group by filename
        $grouped = array();
        foreach ($results as $row) {
            $filename = $row['filename'] ?: 'Unknown';
            if (!isset($grouped[$filename])) {
                // Determine file type - from meta or fallback to extracting from file_path
                $file_type = $row['file_type'];
                if (empty($file_type) && !empty($row['file_path'])) {
                    $file_type = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
                }
                if (empty($file_type)) {
                    $file_type = 'pdf'; // Default for legacy documents
                }

                $grouped[$filename] = array(
                    'filename' => $filename,
                    'file_path' => $row['file_path'],
                    'file_type' => $file_type,
                    'upload_date' => $row['post_date'],
                    'chunks' => array(),
                    'total_chunks' => (int) $row['total_chunks'],
                );
            }
            $grouped[$filename]['chunks'][] = array(
                'id' => $row['ID'],
                'title' => $row['post_title'],
                'chunk_number' => (int) $row['chunk_number'],
            );
        }

        return array_values($grouped);
    }

    /**
     * Check if PDF documents are enabled for training
     *
     * @return bool
     */
    public static function is_enabled_for_training() {
        $enabled_types = get_option('aicwp_enabled_post_types', array());
        return in_array(self::POST_TYPE, $enabled_types);
    }
}

// Initialize
AICWP_PDF_Post_Type::get_instance();
