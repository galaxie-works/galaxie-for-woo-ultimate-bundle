<?php
/**
 * AI Provider Abstraction Layer
 *
 * Handles differences between OpenAI and Google Gemini APIs
 * Provides unified interface for API calls
 *
 * @package AICWP_Plugin
 * @since 2.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Provider {

    /**
     * Current provider
     *
     * @var string 'openai', 'gemini', 'mistral', or 'openrouter'
     */
    private $provider;

    /**
     * API key for the selected provider
     *
     * @var string
     */
    private $api_key;

    /**
     * Constructor
     *
     * @param string $provider Optional provider override (defaults to settings)
     * @param string $api_key Optional API key override (defaults to settings)
     */
    public function __construct($provider = null, $api_key = null) {
        $this->provider = $provider ?: get_option('aicwp_provider', 'openai');

        if ($api_key) {
            $this->api_key = $api_key;
        } else {
            // Get API key based on provider
            if ($this->provider === 'gemini') {
                $this->api_key = get_option('aicwp_gemini_api_key', '');
            } elseif ($this->provider === 'mistral') {
                $this->api_key = get_option('aicwp_mistral_api_key', '');
            } elseif ($this->provider === 'openrouter') {
                $this->api_key = get_option('aicwp_openrouter_api_key', '');
            } else {
                $this->api_key = get_option('aicwp_api_key', '');
            }
        }
    }

    /**
     * Check if user has configured their own API key.
     *
     * @return bool True if any provider API key is set.
     */
    public function has_own_api_key() {
        return (
            get_option('aicwp_api_key', '') !== ''
            || get_option('aicwp_gemini_api_key', '') !== ''
            || get_option('aicwp_mistral_api_key', '') !== ''
            || get_option('aicwp_openrouter_api_key', '') !== ''
        );
    }

    /**
     * Get current provider.
     *
     * @return string 'openai', 'gemini', 'mistral', or 'openrouter'.
     */
    public function get_provider() {
        return $this->provider;
    }

    /**
     * Get API key for current provider
     *
     * @return string
     */
    public function get_api_key() {
        return $this->api_key;
    }

    /**
     * Get API endpoint URL
     *
     * @param string $type 'embeddings' or 'chat'
     * @return string Full API endpoint URL
     */
    public function get_endpoint($type = 'embeddings') {
        if ($this->get_provider() === 'gemini') {
            // Use OpenAI compatibility mode for Gemini
            // Base URL: https://generativelanguage.googleapis.com/v1beta/openai/
            if ($type === 'embeddings') {
                return 'https://generativelanguage.googleapis.com/v1beta/openai/embeddings';
            } elseif ($type === 'chat') {
                return 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions';
            }
        } elseif ($this->get_provider() === 'mistral') {
            // Mistral uses OpenAI-compatible API format
            // Base URL: https://api.mistral.ai/v1
            if ($type === 'embeddings') {
                return 'https://api.mistral.ai/v1/embeddings';
            } elseif ($type === 'chat') {
                return 'https://api.mistral.ai/v1/chat/completions';
            }
        } elseif ($this->get_provider() === 'openrouter') {
            // OpenRouter uses OpenAI-compatible API format
            // Base URL: https://openrouter.ai/api/v1
            if ($type === 'embeddings') {
                return 'https://openrouter.ai/api/v1/embeddings';
            } elseif ($type === 'chat') {
                return 'https://openrouter.ai/api/v1/chat/completions';
            }
        } else {
            // OpenAI endpoints
            if ($type === 'embeddings') {
                return 'https://api.openai.com/v1/embeddings';
            } elseif ($type === 'chat') {
                return 'https://api.openai.com/v1/chat/completions';
            }
        }

        return '';
    }

    /**
     * Get HTTP headers for API requests
     *
     * @return array Headers array
     */
    public function get_headers() {
        $headers = array(
            'Authorization' => 'Bearer ' . $this->get_api_key(),
            'Content-Type' => 'application/json',
        );

        return $headers;
    }

    /**
     * Parse the stored embedding option into model slug and optional dimensions.
     *
     * Composite values use a colon suffix: text-embedding-3-large:1024
     *
     * @return array { 'model' => string, 'dimensions' => int|null }
     */
    private function parse_embedding_option() {
        $stored = get_option('aicwp_embedding_model', '');
        if (empty($stored)) {
            return array('model' => '', 'dimensions' => null);
        }
        $parts  = explode(':', $stored, 2);
        $model  = $parts[0];
        $dims   = isset($parts[1]) ? intval($parts[1]) : null;
        return array('model' => $model, 'dimensions' => $dims);
    }

    /**
     * Get the default embedding model for the current provider.
     *
     * @return string Model name
     */
    private function get_default_embedding_model() {
        if ($this->get_provider() === 'gemini') {
            return 'gemini-embedding-001';
        } elseif ($this->get_provider() === 'mistral') {
            return 'mistral-embed';
        } elseif ($this->get_provider() === 'openrouter') {
            return 'openai/text-embedding-3-small';
        } else {
            return 'text-embedding-3-small';
        }
    }

    /**
     * Get embedding model name
     *
     * @return string Model name
     */
    public function get_embedding_model() {
        $parsed = $this->parse_embedding_option();
        if (!empty($parsed['model'])) {
            return $parsed['model'];
        }
        return $this->get_default_embedding_model();
    }

    /**
     * Get chat/completion model name
     *
     * @return string Model name
     */
    public function get_chat_model() {
        $stored = get_option('aicwp_chat_model', '');
        if ($this->get_provider() === 'gemini') {
            return $this->model_matches_provider($stored, 'gemini') ? $stored : 'gemini-3-flash-preview';
        } elseif ($this->get_provider() === 'mistral') {
            return $this->model_matches_provider($stored, 'mistral') ? $stored : 'mistral-large-latest';
        } elseif ($this->get_provider() === 'openrouter') {
            return $this->model_matches_provider($stored, 'openrouter') ? $stored : 'openai/gpt-5.4-mini';
        } else {
            return $this->model_matches_provider($stored, 'openai') ? $stored : 'gpt-5.4-mini';
        }
    }

    /**
     * Check if a model ID belongs to the given provider.
     *
     * @param string $model Model ID to check.
     * @param string $provider Provider name.
     * @return bool
     */
    private function model_matches_provider($model, $provider) {
        if (empty($model)) {
            return false;
        }
        if ($provider === 'openrouter') {
            return strpos($model, '/') !== false;
        }
        if ($provider === 'openai') {
            return strpos($model, '/') === false && strpos($model, 'gpt-') === 0;
        }
        if ($provider === 'gemini') {
            return strpos($model, 'gemini') === 0;
        }
        if ($provider === 'mistral') {
            return strpos($model, 'mistral') === 0;
        }
        return false;
    }

    /**
     * Prepare embedding request payload
     *
     * @param string|array $input Text to embed (single string or array of strings)
     * @return array Request payload
     */
    public function prepare_embedding_payload($input) {
        $parsed = $this->parse_embedding_option();
        $model  = $this->get_embedding_model();
        $dims   = $parsed['dimensions'];

        $payload = array(
            'model' => $model,
            'input' => $input,
        );

        if ($this->get_provider() === 'openrouter') {
            $payload['encoding_format'] = 'float';
        }

        // Add dimensions when explicitly configured via composite value
        if ($dims !== null && $dims > 0) {
            $payload['dimensions'] = $dims;
        } elseif ($this->get_provider() === 'gemini' && empty($parsed['model'])) {
            // Legacy fallback: gemini direct without stored option got hardcoded 1536
            $payload['dimensions'] = 1536;
        }

        return $payload;
    }

    /**
     * Prepare chat completion request payload
     *
     * @param array $messages Array of message objects
     * @param array $tools Optional tools for function calling
     * @param string $tool_choice Optional tool choice strategy
     * @return array Request payload
     */
    public function prepare_chat_payload($messages, $tools = null, $tool_choice = null) {
        $model = $this->get_chat_model();

        $payload = array(
            'model' => $model,
            'messages' => $messages,
        );

        // Only include tools if array is not empty
        // Empty tools array causes API errors in both OpenAI and Gemini
        if ($tools && is_array($tools) && count($tools) > 0) {
            $payload['tools'] = $tools;

            // Only include tool_choice if tools are present
            if ($tool_choice) {
                $payload['tool_choice'] = $tool_choice;
            }
        }

        return $payload;
    }

    /**
     * Strip OpenRouter namespace prefix from a model slug.
     *
     * OpenRouter uses namespaced slugs like 'openai/gpt-5.1', 'google/gemini-3-flash-preview'.
     * This helper returns the bare model ID without the vendor prefix.
     *
     * @param string $model Full model slug.
     * @return string Bare model ID (e.g. 'gpt-5.1').
     */
    public function get_bare_model( $model ) {
        if ( ! is_string( $model ) || $model === '' ) {
            return '';
        }
        return strpos( $model, 'openai/' ) === 0 ? substr( $model, 7 ) : $model;
    }

    /**
     * Check if a model belongs to the GPT-5 family.
     *
     * @param string $model Full or bare model slug.
     * @return bool
     */
    public function is_gpt5( $model ) {
        $bare = $this->get_bare_model( $model );
        return strpos( $bare, 'gpt-5' ) === 0;
    }

    /**
     * Apply model ID mappings (e.g. broken model remaps).
     *
     * @param string $model Full model slug (may include openai/ prefix).
     * @return string Mapped model slug.
     */
    public function normalize_model( $model ) {
        if ( ! is_string( $model ) || $model === '' ) {
            return $model;
        }
        $has_prefix = strpos( $model, 'openai/' ) === 0;
        $bare = $this->get_bare_model( $model );

        // GPT-5.2 has broken tool calling - map to 5.1
        if ( $bare === 'gpt-5.2' ) {
            return $has_prefix ? 'openai/gpt-5.1' : 'gpt-5.1';
        }

        return $model;
    }

    /**
     * Normalize a chat completion payload for the current provider and model.
     *
     * Centralizes all model-specific parameter differences into one method:
     *   - max_tokens vs max_completion_tokens (GPT-5 vs others)
     *   - temperature inclusion/exclusion (GPT-5 ignores it)
     *   - reasoning_effort per model (GPT-5.x, Gemini 3.x)
     *   - OpenRouter reasoning override (object form: reasoning: {effort: ...})
     *   - Model ID remaps (GPT-5.2 -> 5.1)
     *
     * @param array $payload Base payload with at minimum 'model' and 'messages'.
     * @param array $options {
     *     Optional. Normalization overrides.
     *     @type int    $max_tokens   Max tokens for the response. Default 3000.
     *     @type float  $temperature  Temperature for non-GPT-5 models. Default 0.6.
     *     @type string|null $reasoning Force a reasoning level ('none','low','medium','high').
     *                                   null = auto per model. Default null.
     * }
     * @return array Normalized payload ready for wp_remote_post.
     */
    public function normalize_chat_payload( array $payload, array $options = array() ) {
        $max_tokens   = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : 3000;
        $temperature  = isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.6;
        $force_reasoning = isset( $options['reasoning'] ) ? $options['reasoning'] : null;

        // Step 1: Apply model ID remaps
        $model = isset( $payload['model'] ) ? $payload['model'] : '';
        $model = $this->normalize_model( $model );
        $payload['model'] = $model;
        $bare = $this->get_bare_model( $model );

        // Step 2: max_tokens key - GPT-5 uses max_completion_tokens, others use max_tokens
        if ( $this->is_gpt5( $model ) ) {
            $payload['max_completion_tokens'] = $max_tokens;
            unset( $payload['max_tokens'] );
        } else {
            $payload['max_tokens'] = $max_tokens;
            unset( $payload['max_completion_tokens'] );
        }

        // Step 3: Temperature - GPT-5 models don't support it
        if ( $this->is_gpt5( $model ) ) {
            unset( $payload['temperature'] );
        } else {
            $payload['temperature'] = $temperature;
        }

        // Step 4: Reasoning - native providers (not OpenRouter)
        // OpenRouter is handled separately in step 5
        // Mistral only accepts 'none' or 'high' — map 'low'/'medium' to 'none'.
        if ( $this->get_provider() !== 'openrouter' ) {
            if ( $force_reasoning !== null ) {
                $effective_reasoning = $force_reasoning;
                if ( $this->get_provider() === 'mistral' && in_array( $effective_reasoning, array( 'low', 'medium' ), true ) ) {
                    $effective_reasoning = 'none';
                }
                $payload['reasoning_effort'] = $effective_reasoning;
            } elseif ( $bare === 'gpt-5.1' ) {
                $payload['reasoning_effort'] = 'none';
            } elseif ( $bare === 'gpt-5-mini' ) {
                $payload['reasoning_effort'] = 'low';
            } elseif ( strpos( $model, 'gemini-3.1-pro' ) !== false || strpos( $model, 'gemini-3-pro' ) !== false ) {
                $payload['reasoning_effort'] = 'low';
            } elseif ( strpos( $model, 'gemini-3-flash' ) !== false ) {
                $payload['reasoning_effort'] = 'low';
            }
        }

        // Step 5: OpenRouter reasoning override
        // Uses object form `reasoning: {effort: ...}` per OpenRouter docs.
        // Applied last so it replaces any native-provider reasoning_effort.
        if ( $this->get_provider() === 'openrouter' && isset( $payload['model'] ) ) {
            unset( $payload['reasoning_effort'] );

            if ( $force_reasoning !== null ) {
                // Explicit reasoning override takes precedence over toggle logic.
                $payload['reasoning'] = array( 'effort' => $force_reasoning );
            } elseif ( ! get_option( 'aicwp_openrouter_reasoning', 0 ) ) {
                // Some models reject 'none' with HTTP 400 (openai/*, google/gemini-3.1-pro*)
                $reasoning_mandatory = ( strpos( $payload['model'], 'openai/' ) === 0 )
                    || ( strpos( $payload['model'], 'google/gemini-3.1-pro' ) !== false );
                $effort = $reasoning_mandatory ? 'minimal' : 'none';
                $payload['reasoning'] = array( 'effort' => $effort );
            } else {
                // Reasoning toggle ON - let model use its default
                unset( $payload['reasoning'] );
            }
        }

        return $payload;
    }

    /**
     * Parse embedding response
     *
     * @param array $response_data Decoded JSON response
     * @return array|false Embedding array or false on failure
     */
    public function parse_embedding_response($response_data) {
        // Both OpenAI and Gemini (in compatibility mode) use the same response format
        return $response_data['data'][0]['embedding'] ?? false;
    }

    /**
     * Parse chat response
     *
     * @param array $response_data Decoded JSON response
     * @return array|false Response data or false on failure
     */
    public function parse_chat_response($response_data) {
        // Both providers use the same response format in compatibility mode
        return $response_data;
    }

    /**
     * Get provider display name
     *
     * @return string
     */
    public function get_provider_name() {
        if ($this->get_provider() === 'gemini') {
            return 'Google Gemini';
        } elseif ($this->get_provider() === 'mistral') {
            return 'Mistral AI';
        } elseif ($this->get_provider() === 'openrouter') {
            return 'OpenRouter';
        } else {
            return 'OpenAI';
        }
    }

    /**
     * Validate API key format
     *
     * @param string $api_key API key to validate
     * @return bool True if format appears valid
     */
    public function validate_api_key_format($api_key = null) {
        $key = $api_key ?: $this->get_api_key();

        if (empty($key)) {
            return false;
        }

        if ($this->get_provider() === 'gemini') {
            // Gemini keys start with AIzaSy
            return strpos($key, 'AIzaSy') === 0;
        } elseif ($this->get_provider() === 'mistral') {
            // Mistral keys are alphanumeric strings (no standard prefix)
            return strlen($key) >= 32;
        } elseif ($this->get_provider() === 'openrouter') {
            // OpenRouter keys start with sk-or-
            return strpos($key, 'sk-or-') === 0;
        } else {
            // OpenAI keys start with sk- but NOT sk-or- (that's OpenRouter)
            return strpos($key, 'sk-') === 0 && strpos($key, 'sk-or-') !== 0;
        }
    }

    /**
     * Get embedding dimensions for current provider
     *
     * @return int Number of dimensions
     */
    public function get_embedding_dimensions() {
        $parsed = $this->parse_embedding_option();
        if ($parsed['dimensions'] !== null && $parsed['dimensions'] > 0) {
            return $parsed['dimensions'];
        }

        $model = $this->get_embedding_model();

        // Known defaults for specific models
        if ($model === 'mistral-embed') {
            return 1024;
        }

        if ($model === 'gemini-embedding-001') {
            return 1536;
        }

        if ($model === 'text-embedding-3-small' || $model === 'openai/text-embedding-3-small') {
            return 1536;
        }

        if ($model === 'text-embedding-3-large' || $model === 'openai/text-embedding-3-large') {
            return 1536;
        }

        if ($model === 'google/gemini-embedding-2-preview') {
            return 1536;
        }

        // Fallback
        return 1536;
    }

    /**
     * Check if current provider supports vision/image input
     *
     * @return bool True if vision is supported
     */
    public function supports_vision() {
        return true;
    }

    /**
     * Check if current provider supports speech-to-text transcription
     *
     * @return bool True if transcription is supported
     */
    public function supports_transcription() {
        return in_array($this->get_provider(), array('openai', 'mistral', 'openrouter'), true);
    }

    /**
     * Get transcription API endpoint URL
     *
     * @return string Endpoint URL or empty string if not supported
     */
    public function get_transcription_endpoint() {
        if ($this->get_provider() === 'mistral') {
            return 'https://api.mistral.ai/v1/audio/transcriptions';
        } elseif ($this->get_provider() === 'openai') {
            return 'https://api.openai.com/v1/audio/transcriptions';
        }
        return '';
    }

    /**
     * Get transcription model name
     *
     * @return string Model name or empty string if not supported
     */
    public function get_transcription_model() {
        if ($this->get_provider() === 'mistral') {
            return 'voxtral-mini-latest';
        } elseif ($this->get_provider() === 'openai') {
            return 'whisper-1';
        }
        return '';
    }

    /**
     * Get HTTP headers for transcription/audio API requests
     *
     * @return array Headers array for audio transcription requests
     */
    public function get_transcription_headers() {
        if ($this->get_provider() === 'mistral') {
            return array(
                'x-api-key' => $this->get_api_key(),
            );
        } else {
            return array(
                'Authorization' => 'Bearer ' . $this->get_api_key(),
            );
        }
    }

    /**
     * Format image_url content for the current provider
     *
     * @param string $url The image URL (data: URI or https:// URL)
     * @param string $detail Detail level for OpenAI ('auto', 'low', 'high')
     * @return array Formatted image_url content block
     */
    public function format_image_content($url, $detail = 'auto') {
        if ($this->get_provider() === 'mistral') {
            return array(
                'type' => 'image_url',
                'image_url' => $url,
            );
        } else {
            return array(
                'type' => 'image_url',
                'image_url' => array(
                    'url' => $url,
                    'detail' => $detail,
                ),
            );
        }
    }
}
