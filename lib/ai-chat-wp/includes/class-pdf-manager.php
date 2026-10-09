<?php
/**
 * Document Manager - PRO Feature
 *
 * Handles document upload, text extraction, and chunking
 * Supports PDF (via Smalot PdfParser), TXT, MD, XML, CSV files
 *
 * @package AICWP
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_PDF_Manager {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Upload directory name
     */
    const UPLOAD_DIR = 'ai-pdf-documents';

    /**
     * Supported file extensions
     */
    const SUPPORTED_EXTENSIONS = array('pdf', 'txt', 'md', 'xml', 'csv');

    /**
     * Text file extensions (read directly without parsing)
     */
    const TEXT_EXTENSIONS = array('txt', 'md', 'xml', 'csv');

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
        // Load Composer autoload for PdfParser
        $this->load_pdf_parser();

        // AJAX handlers
        add_action('wp_ajax_aicwp_upload_pdf', array($this, 'ajax_upload_pdf'));
        add_action('wp_ajax_aicwp_delete_pdf', array($this, 'ajax_delete_pdf'));
        add_action('wp_ajax_aicwp_get_pdf_list', array($this, 'ajax_get_pdf_list'));
        add_action('wp_ajax_aicwp_train_pdf', array($this, 'ajax_train_pdf'));
    }

    /**
     * Load PDF parser library
     */
    private function load_pdf_parser() {
        $autoload_path = AICWP_PLUGIN_PATH . 'vendor/autoload.php';
        if (file_exists($autoload_path)) {
            require_once $autoload_path;
        }
    }

    /**
     * Check if PDF parser is available
     *
     * @return bool
     */
    public function is_pdf_parser_available() {
        return class_exists('Smalot\PdfParser\Parser');
    }

    /**
     * Check if file extension is a text file (not PDF)
     *
     * @param string $extension File extension
     * @return bool
     */
    private function is_text_file($extension) {
        return in_array(strtolower($extension), self::TEXT_EXTENSIONS);
    }

    /**
     * Check if file extension is supported
     *
     * @param string $extension File extension
     * @return bool
     */
    private function is_supported_extension($extension) {
        return in_array(strtolower($extension), self::SUPPORTED_EXTENSIONS);
    }

    /**
     * Extract text from a text file (TXT, MD, XML, CSV)
     *
     * @param string $file_path Path to file
     * @param string $extension File extension
     * @return string|WP_Error Extracted text or error
     */
    private function extract_text_from_text_file($file_path, $extension) {
        if (!file_exists($file_path)) {
            return new WP_Error('file_not_found', __('File not found', 'ai-chat-wp'));
        }

        $content = file_get_contents($file_path);

        if ($content === false) {
            return new WP_Error('read_failed', __('Failed to read file', 'ai-chat-wp'));
        }

        // Handle CSV files specially - convert to readable text
        if (strtolower($extension) === 'csv') {
            $content = $this->csv_to_text($content);
        }

        // Handle XML files - strip tags but preserve text content
        if (strtolower($extension) === 'xml') {
            $content = $this->xml_to_text($content);
        }

        return $content;
    }

    /**
     * Convert CSV content to readable text
     *
     * @param string $csv_content Raw CSV content
     * @return string Readable text
     */
    private function csv_to_text($csv_content) {
        $lines = array();
        $rows = str_getcsv($csv_content, "\n");

        // Get headers from first row
        $headers = array();
        if (!empty($rows)) {
            $headers = str_getcsv($rows[0]);
        }

        // Process each row
        foreach ($rows as $index => $row) {
            if ($index === 0) {
                // Skip header row but include as section title
                $lines[] = "Headers: " . implode(', ', $headers);
                $lines[] = "";
                continue;
            }

            $columns = str_getcsv($row);
            $row_text = array();

            foreach ($columns as $col_index => $value) {
                if (!empty(trim($value))) {
                    $header = isset($headers[$col_index]) ? $headers[$col_index] : "Column " . ($col_index + 1);
                    $row_text[] = $header . ": " . trim($value);
                }
            }

            if (!empty($row_text)) {
                $lines[] = "Row " . $index . ":";
                $lines[] = implode("\n", $row_text);
                $lines[] = "";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Convert XML content to readable text
     *
     * @param string $xml_content Raw XML content
     * @return string Readable text
     */
    private function xml_to_text($xml_content) {
        // Try to parse as XML and extract text
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);

        if ($xml === false) {
            // If XML parsing fails, strip tags and return plain text
            return strip_tags($xml_content);
        }

        // Recursively extract text from XML
        return $this->extract_xml_text($xml);
    }

    /**
     * Recursively extract text from SimpleXMLElement
     *
     * @param SimpleXMLElement $element
     * @param int $depth Current depth for indentation
     * @return string
     */
    private function extract_xml_text($element, $depth = 0) {
        $text = array();
        $indent = str_repeat("  ", $depth);

        foreach ($element->children() as $name => $child) {
            $child_text = trim((string)$child);
            $has_children = count($child->children()) > 0;

            if ($has_children) {
                $text[] = $indent . $name . ":";
                $text[] = $this->extract_xml_text($child, $depth + 1);
            } elseif (!empty($child_text)) {
                $text[] = $indent . $name . ": " . $child_text;
            }
        }

        // Also get direct text content
        $direct_text = trim((string)$element);
        if (!empty($direct_text) && count($element->children()) === 0) {
            $text[] = $indent . $direct_text;
        }

        return implode("\n", $text);
    }

    /**
     * Get upload directory
     *
     * @return array Upload directory info
     */
    private function get_upload_dir() {
        $upload_dir = wp_upload_dir();
        $pdf_dir = $upload_dir['basedir'] . '/' . self::UPLOAD_DIR;
        $pdf_url = $upload_dir['baseurl'] . '/' . self::UPLOAD_DIR;

        // Create directory if it doesn't exist
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);

            // Add .htaccess to prevent direct access
            $htaccess = $pdf_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "deny from all\n");
            }
        }

        return array(
            'path' => $pdf_dir,
            'url' => $pdf_url,
        );
    }

    /**
     * Upload and process document file (PDF, TXT, MD, XML, CSV)
     *
     * @param array $file $_FILES array element
     * @return array|WP_Error Result array or error
     */
    public function upload_and_process_pdf($file) {
        if (empty($file['tmp_name'])) {
            return new WP_Error('no_file', __('No file uploaded', 'ai-chat-wp'));
        }

        // Get file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // Validate file type
        if (!$this->is_supported_extension($extension)) {
            return new WP_Error('invalid_type', sprintf(
                __('Unsupported file type. Allowed: %s', 'ai-chat-wp'),
                implode(', ', self::SUPPORTED_EXTENSIONS)
            ));
        }

        // For PDF files, check if parser is available
        if ($extension === 'pdf' && !$this->is_pdf_parser_available()) {
            return new WP_Error('no_parser', __('PDF parser library not available', 'ai-chat-wp'));
        }

        // Check file size (max 50MB)
        $max_size = 50 * 1024 * 1024; // 50MB
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', __('File size must be less than 50MB', 'ai-chat-wp'));
        }

        // Extract text based on file type
        if ($this->is_text_file($extension)) {
            // Text files: read directly
            $text = $this->extract_text_from_text_file($file['tmp_name'], $extension);
        } else {
            // PDF files: use Smalot parser
            $text = $this->extract_text_from_pdf($file['tmp_name']);
        }

        if (is_wp_error($text)) {
            return $text;
        }

        // Check if text is empty
        if (empty(trim($text))) {
            $error_message = ($extension === 'pdf')
                ? __('Could not extract text from PDF. The file may be image-based or encrypted.', 'ai-chat-wp')
                : __('The file appears to be empty or contains no readable text.', 'ai-chat-wp');
            return new WP_Error('no_text', $error_message);
        }

        // Move file to upload directory
        $upload_dir = $this->get_upload_dir();
        $filename = sanitize_file_name($file['name']);
        $unique_filename = wp_unique_filename($upload_dir['path'], $filename);
        $file_path = $upload_dir['path'] . '/' . $unique_filename;

        // Clean filename for display (remove file extension)
        $display_filename = preg_replace('/\.(' . implode('|', self::SUPPORTED_EXTENSIONS) . ')$/i', '', $filename);

        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            return new WP_Error('upload_failed', __('Failed to move uploaded file', 'ai-chat-wp'));
        }

        // Chunk the text
        $chunks = $this->chunk_text($text);

        // Create posts for each chunk (use display filename without extension)
        $post_ids = $this->create_pdf_posts($display_filename, $chunks, $file_path, $extension);

        if (is_wp_error($post_ids)) {
            // Cleanup file on error
            @unlink($file_path);
            return $post_ids;
        }

        // Embeddings are generated via the separate batch training AJAX endpoint.
        // This avoids PHP timeout fatals when processing many chunks in one request.

        return array(
            'success' => true,
            'filename' => $filename,
            'chunks' => count($chunks),
            'post_ids' => $post_ids,
            'message' => sprintf(
                __('Document uploaded successfully. Created %d chunk(s).', 'ai-chat-wp'),
                count($chunks)
            ),
        );
    }

    /**
     * Extract text from PDF file
     *
     * @param string $file_path Path to PDF file
     * @return string|WP_Error Extracted text or error
     */
    private function extract_text_from_pdf($file_path) {
        if (!$this->is_pdf_parser_available()) {
            return new WP_Error('no_parser', __('PDF parser not available', 'ai-chat-wp'));
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file_path);
            $text = $pdf->getText();

            // Sanitize invalid UTF-8 sequences that Smalot may produce
            // This prevents preg_replace('/u') and mb_strlen() failures downstream
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

            return $text;
        } catch (Exception $e) {
            return new WP_Error('extraction_failed', sprintf(
                __('Failed to extract text from PDF: %s', 'ai-chat-wp'),
                $e->getMessage()
            ));
        }
    }

    /**
     * Chunk text into smaller pieces with overlap
     *
     * Uses character-based calculation (via Content Chunker if available)
     * for reliable cross-language support, then word-based splitting
     * WITH overlap for context continuity between chunks.
     *
     * @param string $text Full text
     * @return array Array of text chunks
     */
    private function chunk_text($text) {
        // Clean up text first
        $text = $this->clean_text($text);

        if (empty($text)) {
            return array($text);
        }

        // Calculate character count (UTF-8 aware)
        // Always use Content Chunker for consistent thresholds across posts and documents
        if (class_exists('AICWP_Content_Chunker')) {
            $char_count = AICWP_Content_Chunker::count_chars_utf8($text);
            $threshold = AICWP_Content_Chunker::get_threshold();
            $chunk_size = AICWP_Content_Chunker::get_chunk_size();
        } else {
            // Fallback (should never happen - base plugin required)
            // Keep in sync with AICWP_Content_Chunker::DEFAULT_THRESHOLD/DEFAULT_CHUNK_SIZE
            $cleaned = preg_replace('/\s+/u', ' ', trim($text));
            $char_count = mb_strlen($cleaned ?? '', 'UTF-8');
            $threshold = 7000;
            $chunk_size = 3500;
        }

        // Don't chunk if below threshold
        if ($char_count <= $threshold) {
            return array($text);
        }

        // Calculate number of chunks
        $num_chunks = (int) ceil($char_count / $chunk_size);

        if ($num_chunks <= 1) {
            return array($text);
        }

        // Always use word-based splitting WITH overlap for documents
        return $this->split_with_overlap($text, $num_chunks);
    }

    /**
     * Split text into chunks with word overlap
     *
     * Each chunk (except the first) includes overlap words from the end
     * of the previous chunk to maintain context continuity.
     *
     * @param string $text Text to split
     * @param int $num_chunks Target number of chunks
     * @return array Array of text chunks
     */
    private function split_with_overlap($text, $num_chunks) {
        // Split into words (UTF-8 aware)
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $total_words = count($words);

        if ($total_words === 0) {
            return array($text);
        }

        $overlap = AICWP_Content_Chunker::DEFAULT_OVERLAP;
        $words_per_chunk = (int) ceil($total_words / $num_chunks);
        $chunks = array();

        for ($i = 0; $i < $num_chunks; $i++) {
            // Calculate offset - subtract overlap for chunks after the first
            if ($i === 0) {
                $offset = 0;
            } else {
                $offset = ($i * $words_per_chunk) - $overlap;
                $offset = max(0, $offset);
            }

            // Calculate length - add overlap for all chunks except the last
            $length = $words_per_chunk;
            if ($i < $num_chunks - 1) {
                $length += $overlap;
            }

            $chunk_words = array_slice($words, $offset, $length);

            if (!empty($chunk_words)) {
                $chunks[] = implode(' ', $chunk_words);
            }
        }

        return $chunks;
    }

    /**
     * Clean extracted text
     *
     * @param string $text Raw text
     * @return string Cleaned text
     */
    private function clean_text($text) {
        // Remove excessive whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        // Remove excessive blank lines (keep double for paragraph breaks)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Create WordPress posts for document chunks
     *
     * @param string $filename Original filename (without extension)
     * @param array $chunks Text chunks
     * @param string $file_path Path to original file
     * @param string $file_type File extension (pdf, txt, md, xml, csv)
     * @return array|WP_Error Array of post IDs or error
     */
    private function create_pdf_posts($filename, $chunks, $file_path, $file_type = 'pdf') {
        $total_chunks = count($chunks);
        $post_ids = array();

        foreach ($chunks as $index => $chunk_content) {
            $chunk_number = $index + 1;

            // Create post title
            $title = sprintf(
                __('%s (Part %d of %d)', 'ai-chat-wp'),
                $filename,
                $chunk_number,
                $total_chunks
            );

            // Create post
            $post_data = array(
                'post_title' => $title,
                'post_content' => $chunk_content,
                'post_type' => 'ai_pdf_document',
                'post_status' => 'publish', // Safe: post type has public=>false, publicly_queryable=>false
                'post_author' => get_current_user_id(),
            );

            $post_id = wp_insert_post($post_data);

            if (is_wp_error($post_id) || $post_id <= 0) {
                // Cleanup previously created posts
                foreach ($post_ids as $created_id) {
                    $created_id = (int) $created_id;
                    if ($created_id > 0) {
                        wp_delete_post($created_id, true);
                    }
                }
                return is_wp_error($post_id) ? $post_id : new WP_Error('insert_failed', 'Failed to create document chunk');
            }

            // Add metadata
            update_post_meta($post_id, '_pdf_original_filename', $filename);
            update_post_meta($post_id, '_pdf_chunk_number', $chunk_number);
            update_post_meta($post_id, '_pdf_total_chunks', $total_chunks);
            update_post_meta($post_id, '_pdf_file_path', $file_path);
            update_post_meta($post_id, '_document_file_type', strtolower($file_type));

            $post_ids[] = $post_id;
        }

        return $post_ids;
    }

    /**
     * AJAX: Upload document file
     */
    public function ajax_upload_pdf() {
        check_ajax_referer('aicwp_pdf_upload', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'ai-chat-wp')));
        }

        if (empty($_FILES['pdf_file'])) {
            wp_send_json_error(array('message' => __('No file uploaded', 'ai-chat-wp')));
        }

        $result = $this->upload_and_process_pdf($_FILES['pdf_file']);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Delete document
     */
    public function ajax_delete_pdf() {
        check_ajax_referer('aicwp_pdf_upload', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'ai-chat-wp')));
        }

        $filename = isset($_POST['filename']) ? sanitize_text_field($_POST['filename']) : '';

        if (empty($filename)) {
            wp_send_json_error(array('message' => __('No filename provided', 'ai-chat-wp')));
        }

        // Find all posts with this filename.
        //
        // The list view in get_all_pdf_documents() groups any ai_pdf_document
        // post whose _pdf_original_filename meta is missing or empty under the
        // sentinel label 'Unknown'. The original lookup below could not find
        // those orphans because it required an exact meta_value match — the
        // user would see "Unknown" in the UI but get "Document not found" on
        // delete. When the incoming filename is the sentinel, fall back to a
        // LEFT JOIN that catches missing / empty meta as well.
        global $wpdb;

        if ($filename === 'Unknown') {
            $post_ids = $wpdb->get_col(
                "SELECT p.ID FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm
                     ON p.ID = pm.post_id AND pm.meta_key = '_pdf_original_filename'
                 WHERE p.post_type = 'ai_pdf_document'
                 AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = 'Unknown')"
            );
        } else {
            $post_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'ai_pdf_document'
                 AND pm.meta_key = '_pdf_original_filename'
                 AND pm.meta_value = %s",
                $filename
            ));
        }

        if (empty($post_ids)) {
            wp_send_json_error(array('message' => __('Document not found', 'ai-chat-wp')));
        }

        // Delete all chunks
        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            if ($post_id > 0) {
                wp_delete_post($post_id, true);
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Document "%s" deleted successfully', 'ai-chat-wp'), $filename),
        ));
    }

    /**
     * AJAX: Get list of documents
     */
    public function ajax_get_pdf_list() {
        check_ajax_referer('aicwp_pdf_upload', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'ai-chat-wp')));
        }

        if (!class_exists('AICWP_PDF_Post_Type')) {
            wp_send_json_error(array('message' => __('Document post type not available', 'ai-chat-wp')));
        }

        $pdf_documents = AICWP_PDF_Post_Type::get_all_pdf_documents();

        // Add embedding stats for each document
        if (class_exists('AICWP_Database_Manager')) {
            global $wpdb;
            $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();

            foreach ($pdf_documents as &$doc) {
                // Get chunk IDs for this PDF
                $chunk_ids = array_column($doc['chunks'], 'id');

                if (!empty($chunk_ids)) {
                    $placeholders = implode(',', array_fill(0, count($chunk_ids), '%d'));
                    $indexed_count = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(DISTINCT listing_id) FROM {$embeddings_table}
                         WHERE listing_id IN ($placeholders)",
                        ...$chunk_ids
                    ));
                } else {
                    $indexed_count = 0;
                }

                $doc['indexed_chunks'] = $indexed_count;
                $doc['is_fully_indexed'] = ($indexed_count === $doc['total_chunks']);
            }
        }

        wp_send_json_success(array(
            'documents' => $pdf_documents,
        ));
    }

    /**
     * AJAX: Train document manually (generate embeddings for all chunks)
     */
    public function ajax_train_pdf() {
        check_ajax_referer('aicwp_pdf_upload', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'ai-chat-wp')));
        }

        $filename = isset($_POST['filename']) ? sanitize_text_field($_POST['filename']) : '';
        $offset   = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

        if (empty($filename)) {
            wp_send_json_error(array('message' => __('No filename provided', 'ai-chat-wp')));
        }

        $batch_size = 10;

        // Get total chunk count
        global $wpdb;
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'ai_pdf_document'
             AND p.post_status = 'publish'
             AND pm.meta_key = '_pdf_original_filename'
             AND pm.meta_value = %s",
            $filename
        ));

        if ($total === 0) {
            wp_send_json_error(array('message' => __('Document not found', 'ai-chat-wp')));
        }

        if ($offset >= $total) {
            wp_send_json_success(array(
                'done'      => true,
                'processed' => $total,
                'total'     => $total,
                'has_more'  => false,
            ));
            return;
        }

        // Fetch batch of post IDs
        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'ai_pdf_document'
             AND p.post_status = 'publish'
             AND pm.meta_key = '_pdf_original_filename'
             AND pm.meta_value = %s
             ORDER BY p.ID ASC
             LIMIT %d OFFSET %d",
            $filename,
            $batch_size,
            $offset
        ));

        if (empty($post_ids)) {
            wp_send_json_error(array('message' => __('Chunks not found', 'ai-chat-wp')));
        }

        // Collect content for all chunks in this batch
        $table_name = $wpdb->prefix . 'aicwp_embeddings';
        $chunks_to_embed = array(); // post_id => array('content' => ..., 'hash' => ...)

        foreach ($post_ids as $pid) {
            $content = AICWP_Background_Processor::collect_content($pid);
            if (empty($content)) {
                continue;
            }
            $content_hash = md5($content);

            // Skip if embedding already exists with same hash
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT content_hash FROM {$table_name} WHERE listing_id = %d",
                $pid
            ));
            if ($existing === $content_hash) {
                continue;
            }

            $chunks_to_embed[$pid] = array(
                'content' => $content,
                'hash'    => $content_hash,
            );
        }

        $succeeded = count($post_ids) - count($chunks_to_embed); // already-indexed count as successes

        // Generate embeddings for chunks that need them
        if (!empty($chunks_to_embed)) {
            // --- HTTP TIMEOUT SAFETY ---
            $php_max = (int) ini_get('max_execution_time');
            $http_cap_filter = null;

            if ($php_max > 0) {
                $elapsed = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
                $remaining = $php_max - $elapsed;
                $safe_http = max(5, (int) floor($remaining) - 1);

                $http_cap_filter = function ($args) use ($safe_http) {
                    if (isset($args['timeout']) && $args['timeout'] > $safe_http) {
                        $args['timeout'] = $safe_http;
                    }
                    return $args;
                };
                add_filter('http_request_args', $http_cap_filter, 9999);
            }

            try {
                $provider = new AICWP_Provider();
                $endpoint = $provider->get_endpoint('embeddings');
                $headers  = $provider->get_headers();
                $supports_batch = ($provider->get_provider_name() !== 'Google Gemini');

                // Build ordered arrays of texts and post IDs
                $texts = array();
                $pid_order = array();
                foreach ($chunks_to_embed as $pid => $data) {
                    $texts[] = AICWP_Embedding_Manager::sanitize_utf8($data['content']);
                    $pid_order[] = $pid;
                }

                if ($supports_batch) {
                    // OpenAI / Mistral — single API call with array input
                    $payload = $provider->prepare_embedding_payload($texts);
                    $json_body = json_encode($payload);

                    $response = wp_remote_post($endpoint, array(
                        'headers' => $headers,
                        'body'    => $json_body,
                        'timeout' => 60,
                    ));

                    if (is_wp_error($response)) {
                        throw new Exception($response->get_error_message());
                    }

                    $http_code = wp_remote_retrieve_response_code($response);
                    $body = json_decode(wp_remote_retrieve_body($response), true);

                    if ($http_code !== 200 || isset($body['error'])) {
                        $err = isset($body['error']['message']) ? $body['error']['message'] : "HTTP {$http_code}";
                        throw new Exception($err);
                    }

                    if (isset($body['data']) && is_array($body['data'])) {
                        foreach ($body['data'] as $item) {
                            $idx = $item['index'] ?? null;
                            $embedding = $item['embedding'] ?? null;
                            if ($idx === null || !$embedding || !isset($pid_order[$idx])) {
                                continue;
                            }
                            $pid = $pid_order[$idx];
                            $wpdb->replace($table_name, array(
                                'listing_id'   => $pid,
                                'embedding'    => AICWP_Database_Manager::compress_embedding_for_storage($embedding),
                                'content_hash' => $chunks_to_embed[$pid]['hash'],
                                'updated_at'   => current_time('mysql'),
                            ));
                            $succeeded++;
                        }
                    }
                } else {
                    // Gemini — one API call per chunk (no array input support)
                    foreach ($texts as $i => $text) {
                        $payload = $provider->prepare_embedding_payload($text);
                        $json_body = json_encode($payload);

                        $response = wp_remote_post($endpoint, array(
                            'headers' => $headers,
                            'body'    => $json_body,
                            'timeout' => 60,
                        ));

                        if (is_wp_error($response)) {
                            continue;
                        }

                        $http_code = wp_remote_retrieve_response_code($response);
                        $body = json_decode(wp_remote_retrieve_body($response), true);

                        if ($http_code !== 200 || isset($body['error'])) {
                            continue;
                        }

                        $embedding = $body['data'][0]['embedding'] ?? null;
                        if (!$embedding) {
                            continue;
                        }

                        $pid = $pid_order[$i];
                        $wpdb->replace($table_name, array(
                            'listing_id'   => $pid,
                            'embedding'    => AICWP_Database_Manager::compress_embedding_for_storage($embedding),
                            'content_hash' => $chunks_to_embed[$pid]['hash'],
                            'updated_at'   => current_time('mysql'),
                        ));
                        $succeeded++;
                    }
                }
            } catch (Exception $e) {
                // Remove filter if still active
                if ($http_cap_filter) {
                    remove_filter('http_request_args', $http_cap_filter, 9999);
                }

                // API call failed — return partial progress so JS retries
                wp_send_json_success(array(
                    'processed' => $offset,
                    'total'     => $total,
                    'has_more'  => $offset < $total,
                    'success'   => false,
                ));
                return;
            }

            // Remove filter after all API calls
            if ($http_cap_filter) {
                remove_filter('http_request_args', $http_cap_filter, 9999);
            }
        }

        $new_offset = $offset + count($post_ids);

        wp_send_json_success(array(
            'processed' => $new_offset,
            'total'     => $total,
            'has_more'  => $new_offset < $total,
            'success'   => true,
        ));
    }
}

// Initialize
AICWP_PDF_Manager::get_instance();
