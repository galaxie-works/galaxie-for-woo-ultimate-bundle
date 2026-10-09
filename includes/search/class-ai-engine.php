<?php
/**
 * AI Search Engine Class
 * 
 * Handles all AI-powered search functionality including embeddings
 * 
 * @package AICWP_Plugin
 * @since 1.0.5
 * @version 1.0.6
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_AI_Engine {

    /**
     * Embedding manager instance
     *
     * @var AICWP_Embedding_Manager
     */
    private $embedding_manager;

    /**
     * AI Provider instance
     *
     * @var AICWP_Provider
     */
    private $provider;

    /**
     * API key (for backward compatibility)
     *
     * @var string
     */
    private $api_key;

    /**
     * Constructor
     *
     * @param string $api_key Optional API key (deprecated - use provider settings instead)
     */
    public function __construct($api_key = '') {
        // IMPORTANT: Don't assume OpenAI when API key is provided
        // Always use configured provider from settings for consistency
        $this->provider = new AICWP_Provider();
        $this->api_key = $this->provider->get_api_key();

        // Backward compatibility: warn if deprecated parameter is used
        if ($api_key && get_option('aicwp_debug_mode', false)) {
            AICWP_Utility_Helper::debug_log(
                'AI_Engine: API key passed to constructor is deprecated. Using provider settings instead.',
                'warning'
            );
        }

        $this->embedding_manager = new AICWP_Embedding_Manager();
    }
    
    /**
     * Test API connection health (deprecated - kept for backward compatibility)
     *
     * @deprecated No longer used internally. Embedding generation will fail fast if API is down.
     * @return bool True if API key is configured
     */
    public function test_api_health() {
        // Simplified: just check if API key exists
        // Actual API health is tested when we try to generate embeddings
        return !empty($this->api_key);
    }
    
    /**
     * Perform AI-powered search
     *
     * @param string $query Search query
     * @param int $limit Number of results to return
     * @param int $offset Results offset for pagination
     * @param string $listing_types Comma-separated post types or 'all' (all enabled post types)
     * @param bool $debug Enable debug logging
     * @param array $location_filtered_ids Optional pre-filtered listing IDs from SQL location search
     * @param bool $is_rag RAG mode - uses more lenient thresholds for context retrieval
     * @param bool $skip_threshold Skip similarity threshold filtering (return top-K raw results for LLM re-ranking)
     * @return array Search results
     */
    public function search($query, $limit, $offset, $listing_types, $debug = false, $location_filtered_ids = array(), $is_rag = false, $skip_threshold = false) {
        global $wpdb;
        
        $debug_info = array();
        $search_start = microtime(true);

        // Check if API key is configured
        if (empty($this->api_key)) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('No API key configured, falling back to traditional search', 'warning');
            }
            $provider_name = $this->provider->get_provider_name();
            throw new Exception($provider_name . ' API key not configured - using fallback search');
        }

        // Generate embedding for search query
        if ($debug) {
            AICWP_Utility_Helper::debug_log('EMBEDDING: Generating embedding for query: "' . $query . '"');
        }

        // Apply query expansion if enabled
        $expanded_query = $this->expand_query_if_enabled($query, $debug);

        // Generate embedding for query
        $query_embedding = $this->embedding_manager->generate_embedding($expanded_query);

        if ($debug) {
            AICWP_Utility_Helper::debug_log('QUERY ANALYSIS: Original="' . $query . '", Expanded="' . $expanded_query . '"');
        }
        if (!$query_embedding) {
            if ($debug) {
                $debug_info['embedding_generation'] = 'failed';
                $debug_info['error'] = 'Could not generate embedding for query';
                AICWP_Utility_Helper::debug_log('EMBEDDING ERROR: Could not generate embedding for query', 'error');
            }
            $provider_name = $this->provider->get_provider_name();
            throw new Exception($provider_name . ' API failed to generate embedding - using fallback search');
        }
        
        if ($debug) {
            $debug_info['embedding_generation'] = 'success';
            $debug_info['embedding_dimensions'] = count($query_embedding);
            AICWP_Utility_Helper::debug_log('EMBEDDING SUCCESS: Generated embedding with ' . count($query_embedding) . ' dimensions');
        }
        
        // Get embeddings from database
        // LOCATION PRE-FILTER: Use SQL-filtered IDs if provided (from chatbot location parameter)
        $embeddings = AICWP_Database_Manager::get_embeddings_for_search($listing_types, array(), $location_filtered_ids);

        if ($debug) {
            $debug_info['embeddings_found'] = count($embeddings);
            $debug_info['location_pre_filtered'] = !empty($location_filtered_ids);
            $debug_info['pre_filter_count'] = count($location_filtered_ids);

            if (!empty($location_filtered_ids)) {
                AICWP_Utility_Helper::debug_log('DATABASE: Using SQL location pre-filter (' . count($location_filtered_ids) . ' IDs) - Found ' . count($embeddings) . ' embeddings');
            } else {
                AICWP_Utility_Helper::debug_log('DATABASE: Found ' . count($embeddings) . ' embeddings (no location filtering)');
            }
        }

        if (empty($embeddings)) {
            if ($debug) {
                $debug_info['error'] = 'No embeddings found in database';
                AICWP_Utility_Helper::debug_log('DATABASE ERROR: No embeddings found in database', 'error');
            }
            // Return friendly empty response instead of throwing exception
            return array(
                'listings' => array(),
                'total' => 0,
                'query' => $query,
                'search_type' => 'ai_semantic',
                'is_fallback' => false,
                'chunk_mapping' => array(),
                'notice' => __('No data available yet. Please train the AI search data first.', 'ai-chat-wp'),
                'notice_type' => 'no_embeddings',
                'debug' => $debug ? $debug_info : null
            );
        }

        // Calculate similarities with admin-configured threshold
        $similarities = array();
        $similarity_scores = array();

        // Determine if searching products (strict) or content (lenient)
        // 'all' = every post type enabled in admin settings
        $strict_types = array('product');
        $use_strict = false;
        $types_array = ($listing_types === 'all')
            ? AICWP_Database_Manager::get_enabled_post_types()
            : array_map('trim', explode(',', $listing_types));
        foreach ($types_array as $type) {
            if (in_array($type, $strict_types)) {
                $use_strict = true;
                break;
            }
        }

        // Use admin setting for minimum match percentage, convert to raw similarity
        // Pass $is_rag to use more lenient thresholds for RAG context retrieval
        $min_match_percentage = intval(get_option('aicwp_min_match_percentage', 50));
        $min_similarity_threshold = AICWP_Utility_Helper::percentage_to_similarity($min_match_percentage, $use_strict, null, $is_rag);

        if ($skip_threshold) {
            $min_similarity_threshold = 0;
        }

        if ($debug) {
            $mode = $use_strict ? 'strict (products)' : ($is_rag ? 'RAG (extra lenient)' : 'lenient (content)');
            AICWP_Utility_Helper::debug_log('THRESHOLD: Using admin setting ' . $min_match_percentage . '% = ' . round($min_similarity_threshold, 3) . ' raw similarity [' . $mode . ']');
        }
        
        $failed_decompressions = 0;

        // Pass 1: raw similarity only (fast - no DB calls)
        $raw_similarities = array();
        foreach ($embeddings as $embedding_row) {
            $similarity = AICWP_Utility_Helper::dot_product_packed($query_embedding, $embedding_row->embedding);
            if ($similarity !== false) {
                $raw_similarities[$embedding_row->listing_id] = $similarity;
            } else {
                $failed_decompressions++;
                if ($debug && $failed_decompressions <= 3) {
                    AICWP_Utility_Helper::debug_log(
                        sprintf(
                            'DECOMPRESSION FAILED for listing %d - Embedding data length: %d bytes, First 100 chars: %s',
                            $embedding_row->listing_id,
                            strlen($embedding_row->embedding),
                            substr($embedding_row->embedding, 0, 100)
                        ),
                        'error'
                    );
                }
            }
        }

        if ($debug && $failed_decompressions > 0) {
            AICWP_Utility_Helper::debug_log(
                "DECOMPRESSION: Failed to decompress {$failed_decompressions} out of " . count($embeddings) . " embeddings",
                'error'
            );
        }

        // Keyword boost is first-page focused: only top 25 candidates get the expensive
        // DB/term/meta lookups. Results 26+ keep their raw semantic score. This is
        // intentional even when limit > 25 (RAG mode uses up to 150) because results
        // ranked that low are already weak semantic matches.
        arsort($raw_similarities);
        $boost_candidates = array_slice($raw_similarities, 0, 25, true);

        // Pass 2: keyword boost only on top candidates
        $similarities = array();
        foreach ($boost_candidates as $listing_id => $similarity) {
            $keyword_boost = $this->calculate_keyword_boost($query, $listing_id);
            $adjusted_similarity = $similarity + $keyword_boost;

            if ($adjusted_similarity >= $min_similarity_threshold) {
                $similarities[$listing_id] = $adjusted_similarity;
                $similarity_scores[] = round($adjusted_similarity, 4);
            }
        }

        // Add remaining raw results that didn't get boosted (below top 25 but above threshold)
        // These have no keyword boost applied
        $remaining = array_slice($raw_similarities, 25, null, true);
        foreach ($remaining as $listing_id => $similarity) {
            if ($similarity >= $min_similarity_threshold) {
                $similarities[$listing_id] = $similarity;
            }
        }

        // CHUNKING: Map chunk IDs back to parent post IDs
        // This ensures search results show the original post, not the chunk
        // Use extended mapping to preserve chunk IDs for RAG context
        $chunk_result = AICWP_Database_Manager::map_chunks_to_parents($similarities, true);
        $similarities = $chunk_result['similarities'];
        $chunk_mapping = $chunk_result['chunk_mapping'];

        if ($debug) {
            AICWP_Utility_Helper::debug_log('CHUNKING: Mapped ' . count($similarities) . ' results (chunks consolidated to parents)');
            AICWP_Utility_Helper::debug_log('CHUNKING: ' . count($chunk_mapping) . ' posts have matching chunks');
        }

        if ($debug) {
            $debug_info['similarities_calculated'] = count($similarities);
            $debug_info['min_threshold'] = $min_similarity_threshold;
            $debug_info['filtered_out'] = count($embeddings) - count($similarities);
            
            AICWP_Utility_Helper::debug_log('SIMILARITY: Calculated similarities for ' . count($similarities) . ' listings');
            AICWP_Utility_Helper::debug_log('SIMILARITY: Minimum threshold: ' . $min_similarity_threshold);
            AICWP_Utility_Helper::debug_log('SIMILARITY: Filtered out ' . (count($embeddings) - count($similarities)) . ' results below threshold');
            
            if (!empty($similarity_scores)) {
                $debug_info['similarity_range'] = array(
                    'min' => min($similarity_scores),
                    'max' => max($similarity_scores),
                    'avg' => round(array_sum($similarity_scores) / count($similarity_scores), 4)
                );
                $debug_info['top_5_scores'] = array_slice(array_reverse($similarity_scores), 0, 5);
                
                AICWP_Utility_Helper::debug_log('SIMILARITY RANGE: Min=' . min($similarity_scores) . ', Max=' . max($similarity_scores) . ', Avg=' . round(array_sum($similarity_scores) / count($similarity_scores), 4));
                AICWP_Utility_Helper::debug_log('TOP 5 SCORES: ' . implode(', ', array_slice(array_reverse($similarity_scores), 0, 5)));
            } else {
                $debug_info['similarity_range'] = 'No results above threshold';
                AICWP_Utility_Helper::debug_log('SIMILARITY: No results above threshold');
            }
        }
        
        // Check if we have any relevant results
        if (empty($similarities)) {
            if ($debug) {
                $debug_info['error'] = "No results found above similarity threshold of {$min_similarity_threshold}";
                AICWP_Utility_Helper::debug_log('RESULTS ERROR: No results found above similarity threshold of ' . $min_similarity_threshold, 'warning');
            }
            
            // Return empty results instead of throwing exception
            return array(
                'listings' => array(),
                'total' => 0,
                'query' => $query,
                'search_type' => 'ai_semantic',
                'is_fallback' => false,
                'chunk_mapping' => array(),
                'debug' => $debug ? $debug_info : null
            );
        }
        
        // Sort by similarity and get top results
        arsort($similarities);
        $top_listing_ids = array_keys($similarities);

        if ($debug) {
            AICWP_Utility_Helper::debug_log('SORTING: Found ' . count($top_listing_ids) . ' results above threshold');
            AICWP_Utility_Helper::debug_log('TOP 10 LISTING IDS: ' . implode(', ', array_slice($top_listing_ids, 0, 10)));
        }

        // Raw similarity threshold filtering already applied above (line 212)
        // No redundant display % filtering - raw threshold is authoritative
        $filtered_listing_ids = $top_listing_ids;

        // Apply pagination
        $paginated_ids = array_slice($filtered_listing_ids, $offset, $limit);
        
        // DEBUG: Log pagination details
        if ($debug) {
            AICWP_Utility_Helper::debug_log('MAIN PAGINATION: Total filtered results=' . count($filtered_listing_ids) . ', Offset=' . $offset . ', Limit=' . $limit . ', Paginated=' . count($paginated_ids));
            AICWP_Utility_Helper::debug_log('MAIN PAGINATION: Paginated listing IDs: [' . implode(', ', $paginated_ids) . ']');
        }
        
        // Format results - filtering already applied, so pass false for filtering in formatter
        $formatted_results = AICWP_Result_Formatter::format_search_results($paginated_ids, true, $similarities, false);
        
        if ($debug) {
            AICWP_Utility_Helper::debug_log('RESULTS: Using direct semantic search with structured embeddings');
        }
        
        $search_time = round((microtime(true) - $search_start) * 1000, 2);
        
        if ($debug) {
            $debug_info['search_time'] = $search_time . 'ms';
            $debug_info['total_processed'] = count($top_listing_ids);
            $debug_info['returned_results'] = count($formatted_results);
            
            AICWP_Utility_Helper::debug_log('TIMING: Search completed in ' . $search_time . 'ms');
            AICWP_Utility_Helper::debug_log('RESULTS: Total processed=' . count($top_listing_ids) . ', Returned results=' . count($formatted_results));
            
            // Add details about top results for debugging
            $debug_results = array();
            foreach (array_slice($formatted_results, 0, 10) as $result) { // Top 10 for debug
                $debug_results[] = array(
                    'title' => $result['title'],
                    'score' => $result['match_percentage'] ?? $result['similarity_score'],
                    'match_type' => $result['match_type'],
                    'post_type' => $result['post_type']
                );
                AICWP_Utility_Helper::debug_log('TOP RESULT: "' . $result['title'] . '" (Raw: ' . ($result['similarity_score'] ?? 'N/A') . ', Display: ' . ($result['match_percentage'] ?? 'N/A') . '%, Type: ' . $result['post_type'] . ')');
            }
            $debug_info['top_results'] = $debug_results;
            
            AICWP_Utility_Helper::debug_log('=== AI CHAT SEARCH DEBUG END ===');
        }
        
        $result = array(
            'listings' => $formatted_results,
            'total_found' => count($filtered_listing_ids),
            'search_type' => 'ai',
            'query' => $query,
            'explanation' => AICWP_Utility_Helper::generate_search_explanation($query, count($filtered_listing_ids)),
            'has_more' => ($offset + $limit) < count($filtered_listing_ids),
            'chunk_mapping' => $chunk_mapping // For RAG: which chunks matched for each parent
        );

        if ($debug) {
            $result['debug'] = $debug_info;
        }

        return $result;
    }
    

    /**
     * Perform AI-powered search with batch processing for memory efficiency
     *
     * @param string $query Search query
     * @param int $limit Number of results to return
     * @param int $offset Results offset for pagination
     * @param string $listing_types Comma-separated post types or 'all' (all enabled post types)
     * @param bool $debug Enable debug logging
     * @param int $batch_size Number of embeddings per batch (default 3000, safe for 256MB PHP)
     * @param bool $skip_threshold Skip similarity threshold filtering (return top-K raw results for LLM re-ranking)
     * @return array Search results
     */
    public function search_with_batching($query, $limit, $offset, $listing_types, $debug = false, $batch_size = 3000, $skip_threshold = false) {
        global $wpdb;
        
        $debug_info = array();
        $search_start = microtime(true);

        // Check if API key is configured
        if (empty($this->api_key)) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('AI SEARCH (BATCH): No API key configured, falling back to traditional search', 'warning');
            }
            $provider_name = $this->provider->get_provider_name();
            throw new Exception($provider_name . ' API key not configured - using fallback search');
        }

        if ($debug) {
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Starting batch processing for query: "' . $query . '"');
        }
        
        $detected_locations = array();
        $has_location_intent = false;

        // Apply query expansion if enabled
        $business_query = $query;
        $expanded_query = $this->expand_query_if_enabled($business_query, $debug);
        
        // Generate embedding for business query
        $query_embedding = $this->embedding_manager->generate_embedding($expanded_query);
        
        if (!$query_embedding) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('BATCH SEARCH: Could not generate embedding for query', 'error');
            }
            $provider_name = $this->provider->get_provider_name();
            throw new Exception($provider_name . ' API failed to generate embedding - using fallback search');
        }
        
        if ($debug) {
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Generated embedding with ' . count($query_embedding) . ' dimensions');
        }
        
        // BATCH PROCESSING: Get total count first
        $apply_location_filtering = false;
        $total_embeddings = AICWP_Database_Manager::count_embeddings_for_search($listing_types, $apply_location_filtering ? $detected_locations : array());
        
        if ($debug) {
            $debug_info['total_embeddings'] = $total_embeddings;
            $debug_info['location_filtering'] = $apply_location_filtering;
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Found ' . $total_embeddings . ' total embeddings to process');
        }
        
        if ($total_embeddings === 0) {
            throw new Exception('No embeddings found in database');
        }
        
        // BATCH PROCESSING: Process in chunks to save memory
        // $batch_size is now passed as parameter (auto-detected by caller)
        $similarities = array();
        $processed_count = 0;

        // Determine if searching products (strict) or content (lenient)
        // 'all' = every post type enabled in admin settings
        $strict_types = array('product');
        $use_strict = false;
        $types_array = ($listing_types === 'all')
            ? AICWP_Database_Manager::get_enabled_post_types()
            : array_map('trim', explode(',', $listing_types));
        foreach ($types_array as $type) {
            if (in_array($type, $strict_types)) {
                $use_strict = true;
                break;
            }
        }

        // Use admin setting for minimum match percentage, convert to raw similarity
        $min_match_percentage = intval(get_option('aicwp_min_match_percentage', 50));
        $min_similarity_threshold = AICWP_Utility_Helper::percentage_to_similarity($min_match_percentage, $use_strict);

        if ($skip_threshold) {
            $min_similarity_threshold = 0;
        }

        if ($debug) {
            $mode = $use_strict ? 'strict (products)' : 'lenient (content)';
            $debug_info['batch_size'] = $batch_size;
            $debug_info['min_threshold'] = $min_similarity_threshold;
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Using admin setting ' . $min_match_percentage . '% = ' . round($min_similarity_threshold, 3) . ' raw similarity [' . $mode . ']');
        }
        
        // Process embeddings in batches - raw similarity only (no keyword boost)
        $raw_similarities = array();
        $batch_offset = 0;
        while ($batch_offset < $total_embeddings) {
            $batch_embeddings = AICWP_Database_Manager::get_embeddings_batch(
                $listing_types,
                $apply_location_filtering ? $detected_locations : array(),
                $batch_size,
                $batch_offset
            );

            if ($debug) {
                AICWP_Utility_Helper::debug_log('BATCH SEARCH: Processing batch ' . ($batch_offset / $batch_size + 1) . ' with ' . count($batch_embeddings) . ' embeddings');
            }

            foreach ($batch_embeddings as $embedding_row) {
                $similarity = AICWP_Utility_Helper::dot_product_packed($query_embedding, $embedding_row->embedding);
                if ($similarity !== false) {
                    $raw_similarities[$embedding_row->listing_id] = $similarity;
                    $processed_count++;
                }
            }

            unset($batch_embeddings);

            $batch_offset += $batch_size;

            if ($debug) {
                AICWP_Utility_Helper::debug_log('BATCH SEARCH: Processed ' . $processed_count . ' embeddings');
            }
        }

        // Keyword boost is first-page focused: only top 25 candidates get the expensive
        // DB/term/meta lookups. Results 26+ keep their raw semantic score.
        arsort($raw_similarities);
        $boost_candidates = array_slice($raw_similarities, 0, 25, true);

        $similarities = array();
        foreach ($boost_candidates as $listing_id => $similarity) {
            $keyword_boost = $this->calculate_keyword_boost($query, $listing_id);
            $adjusted_similarity = $similarity + $keyword_boost;

            if ($adjusted_similarity >= $min_similarity_threshold) {
                $similarities[$listing_id] = $adjusted_similarity;
            }
        }

        // Add remaining raw results (no keyword boost)
        $remaining = array_slice($raw_similarities, 25, null, true);
        foreach ($remaining as $listing_id => $similarity) {
            if ($similarity >= $min_similarity_threshold) {
                $similarities[$listing_id] = $similarity;
            }
        }

        // CHUNKING: Map chunk IDs back to parent post IDs
        // This ensures search results show the original post, not the chunk
        // Use extended mapping to preserve chunk IDs for RAG context
        $chunk_result = AICWP_Database_Manager::map_chunks_to_parents($similarities, true);
        $similarities = $chunk_result['similarities'];
        $chunk_mapping = $chunk_result['chunk_mapping'];

        if ($debug) {
            AICWP_Utility_Helper::debug_log('BATCH SEARCH CHUNKING: Mapped ' . count($similarities) . ' results (chunks consolidated to parents)');
            AICWP_Utility_Helper::debug_log('BATCH SEARCH CHUNKING: ' . count($chunk_mapping) . ' posts have matching chunks');
        }

        if ($debug) {
            $debug_info['processed_count'] = $processed_count;
            $debug_info['similarities_found'] = count($similarities);
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Completed processing. Total similarities found: ' . count($similarities));
        }

        // Check if we have any relevant results
        if (empty($similarities)) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('BATCH SEARCH: No results found above similarity threshold of ' . $min_similarity_threshold, 'warning');
            }
            throw new Exception('No relevant results found');
        }

        // Sort by similarity and get results
        arsort($similarities);
        $top_listing_ids = array_keys($similarities);

        // Apply pagination
        $paginated_ids = array_slice($top_listing_ids, $offset, $limit);
        // Pass $apply_filtering = false - raw threshold filtering already done above
        $formatted_results = AICWP_Result_Formatter::format_search_results($paginated_ids, true, $similarities, false);

        $search_time = round((microtime(true) - $search_start) * 1000, 2);

        if ($debug) {
            $debug_info['search_time'] = $search_time . 'ms';
            $debug_info['total_results'] = count($top_listing_ids);
            $debug_info['returned_results'] = count($formatted_results);
            $final_ids = array_map(function($listing) { return $listing['id']; }, $formatted_results);
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Completed in ' . $search_time . 'ms, returning ' . count($formatted_results) . ' results');
            AICWP_Utility_Helper::debug_log('BATCH SEARCH: Final listing IDs: [' . implode(', ', $final_ids) . ']');
        }

        return array(
            'listings' => $formatted_results,
            'total_found' => count($top_listing_ids),
            'search_type' => 'ai_batch',
            'query' => $query,
            'explanation' => AICWP_Utility_Helper::generate_search_explanation($query, count($top_listing_ids)),
            'has_more' => ($offset + $limit) < count($top_listing_ids),
            'chunk_mapping' => $chunk_mapping, // For RAG: which chunks matched for each parent
            'debug' => $debug ? $debug_info : null
        );
    }
    
    /**
     * Expand query with related keywords if enabled
     * 
     * @param string $query Original business query (without location)
     * @param bool $debug Enable debug logging
     * @return string Expanded query or original query
     */
    private function expand_query_if_enabled($query, $debug = false) {
        // Check if query expansion is enabled
        if (!get_option('aicwp_query_expansion')) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Disabled - returning original query');
            }
            return $query;
        }

        // Atomically acquire rate limit slot before making API call
        if (!AICWP_Embedding_Manager::try_acquire_rate_limit()) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Skipped - global rate limit exceeded', 'warning');
            }
            return $query;
        }

        if ($debug) {
            AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Enabled - expanding query: "' . $query . '"');
        }

        try {
            $prompt = "Expand this search query with 3-5 related keywords only. Focus on business types and services, NOT locations.

Original query: \"{$query}\"

CRITICAL: Expand keywords in the SAME LANGUAGE as the original query. If the query is in Polish, respond in Polish. If in English, respond in English. In French respond in French etc.

Rules:
1. Return ONLY keywords separated by commas
2. Maximum 5 additional keywords
3. NO quotes, NO explanations
4. NO location names
5. Focus on business categories and synonyms
6. MAINTAIN THE ORIGINAL LANGUAGE

Examples:
- \"car broken down\" → \"auto repair, mechanic, garage, vehicle service\"
- \"place to sleep\" → \"hotel, accommodation, lodging, hostel\"
- \"kanapki\" → \"delikatesy, sklep mięsny, catering, fast food\"
- \"hotel\" → \"nocleg, zakwaterowanie, pensjonat, hostel\" (if query was Polish)

Keywords:";

            // Use lightweight models for query expansion
            $model = ($this->provider->get_provider() === 'gemini') ? 'gemini-2.5-flash' : 'gpt-4o-mini';
            $max_tokens = ($this->provider->get_provider() === 'gemini') ? 500 : 150;

            $payload = $this->provider->normalize_chat_payload(array(
                'model' => $model,
                'messages' => array(array('role' => 'user', 'content' => $prompt)),
            ), array('max_tokens' => $max_tokens));

            $response = wp_remote_post($this->provider->get_endpoint('chat'), array(
                'headers' => $this->provider->get_headers(),
                'body' => json_encode($payload),
                'timeout' => 10,
            ));

            if (is_wp_error($response)) {
                if ($debug) {
                    AICWP_Utility_Helper::debug_log('QUERY EXPANSION ERROR: ' . $response->get_error_message(), 'error');
                }
                return $query; // Return original query on error
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($debug) {
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Response HTTP Code: ' . wp_remote_retrieve_response_code($response));
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Raw response body: ' . substr(wp_remote_retrieve_body($response), 0, 500));
            }

            if (isset($body['error'])) {
                if ($debug) {
                    $provider_name = $this->provider->get_provider_name();
                    AICWP_Utility_Helper::debug_log($provider_name . ' QUERY EXPANSION API ERROR: ' . $body['error']['message'], 'error');
                }
                return $query;
            }

            $expanded_keywords = $body['choices'][0]['message']['content'] ?? '';
            $expanded_keywords = trim($expanded_keywords);

            if ($debug) {
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Extracted content: "' . $expanded_keywords . '"');
            }

            // Clean up the response - remove quotes and extra formatting
            $expanded_keywords = str_replace(array('"', "'", '  '), array('', '', ' '), $expanded_keywords);

            if (empty($expanded_keywords)) {
                if ($debug) {
                    AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Empty response - returning original query', 'warning');
                    AICWP_Utility_Helper::debug_log('QUERY EXPANSION: Full response body: ' . print_r($body, true));
                }
                return $query;
            }
            
            // Combine original query with expanded keywords (space-separated for better embedding)
            $final_query = $query . ' ' . $expanded_keywords;
            
            if ($debug) {
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION SUCCESS: "' . $query . '" → "' . $final_query . '"');
            }
            
            return $final_query;

        } catch (Exception $e) {
            if ($debug) {
                AICWP_Utility_Helper::debug_log('QUERY EXPANSION EXCEPTION: ' . $e->getMessage(), 'error');
            }
            return $query; // Return original query on exception
        }
    }

    /**
     * Calculate keyword boost for exact matches using semantic analysis
     * 
     * @param string $query Search query
     * @param int $listing_id Listing ID
     * @return float Boost value (0.0 to 0.2)
     */
    private function calculate_keyword_boost($query, $listing_id) {
        $boost = 0.0;
        $query_lower = strtolower($query);
        
        // Get listing content for keyword matching
        $post = get_post($listing_id);
        if (!$post) {
            return $boost;
        }
        
        // Combine title, content, and meta for keyword matching
        $content_lower = strtolower($post->post_title . ' ' . $post->post_content);
        
        // Extract meaningful words from query (3+ characters, not common words)
        $query_words = preg_split('/\s+/', $query_lower);
        $meaningful_words = array();
        $common_words = array('the', 'and', 'for', 'with', 'that', 'this', 'from', 'they', 'have', 'was', 'are', 'but', 'not', 'all', 'can', 'had', 'her', 'you', 'one', 'our', 'out', 'day', 'get', 'use', 'man', 'new', 'now', 'way', 'may', 'say');
        
        foreach ($query_words as $word) {
            $word = trim($word, '.,!?;:"()[]{}');
            if (strlen($word) >= 3 && !in_array($word, $common_words)) {
                $meaningful_words[] = $word;
            }
        }
        
        // Check for exact matches of meaningful words
        $matches = 0;
        foreach ($meaningful_words as $word) {
            if (strpos($content_lower, $word) !== false) {
                $matches++;
            }
        }
        
        // Calculate boost based on match ratio (stronger boost for keyword matches)
        if (!empty($meaningful_words)) {
            $match_ratio = $matches / count($meaningful_words);
            if ($match_ratio >= 0.7) { // 70% of words match
                $boost = 0.25;
            } elseif ($match_ratio >= 0.5) { // 50% of words match
                $boost = 0.18;
            } elseif ($match_ratio >= 0.3) { // 30% of words match
                $boost = 0.10;
            }
        }

        // Cap the total boost
        return min($boost, 0.30);
    }
    
}
