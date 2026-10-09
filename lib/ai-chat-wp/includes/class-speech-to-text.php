<?php
/**
 * Speech-to-Text Feature (PRO)
 *
 * Provides voice input functionality for the AI chat widget.
 * Supports both OpenAI Whisper API and Gemini (via OpenAI compatibility).
 * Records audio from user's microphone, transcribes it, and fills the chat input.
 *
 * @package AICWP
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Speech_To_Text {

    /**
     * Constructor - sets up hooks
     */
    public function __construct() {
        add_action('aicwp_chat_mic_button', array($this, 'render_mic_button'));
        add_action('aicwp_chat_transcribe_endpoint', array($this, 'register_endpoint'));
        add_action('aicwp_chat_enqueue_speech_assets', array($this, 'enqueue_assets'));
    }

    /**
     * Register the transcribe REST endpoint
     *
     * @param AICWP_Chat_API $chat_api The chat API instance
     */
    public function register_endpoint($chat_api) {
        register_rest_route('aicwp/v1', '/transcribe', array(
            'methods' => 'POST',
            'callback' => array($this, 'transcribe_audio'),
            'permission_callback' => array($chat_api, 'check_chat_permission'),
        ));
    }

    /**
     * Handle audio transcription request
     *
     * @param WP_REST_Request $request The request object
     * @return WP_REST_Response
     */
    public function transcribe_audio($request) {
        $request_id = substr(md5(uniqid('transcribe_', true)), 0, 8);
        $client_ip = AICWP_Utility_Helper::get_client_ip_secure();

        // Rate limit check (reuse chat API method)
        if (class_exists('AICWP_Chat_API')) {
            $rate_check = AICWP_Chat_API::check_ip_rate_limit($client_ip);
            if (!$rate_check['allowed']) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => array(
                        'message' => $rate_check['error'],
                        'type' => 'rate_limit_error',
                        'request_id' => $request_id
                    )
                ), 429);
            }
        }

        // Validate file upload
        if (empty($_FILES['audio'])) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('No audio file provided.', 'ai-chat-wp'),
                    'type' => 'validation_error',
                    'request_id' => $request_id
                )
            ), 400);
        }

        $file = $_FILES['audio'];

        // Validate MIME type
        $allowed_types = array(
            'audio/webm',
            'audio/mp4',
            'audio/mpeg',
            'audio/ogg',
            'audio/wav',
            'audio/x-wav',
            'audio/m4a',
            'audio/x-m4a',
            'video/webm', // Some browsers report webm as video
        );

        if (!in_array($file['type'], $allowed_types, true)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Invalid audio format. Supported formats: webm, mp4, mp3, ogg, wav.', 'ai-chat-wp'),
                    'type' => 'validation_error',
                    'request_id' => $request_id
                )
            ), 400);
        }

        // Validate file size (max 25MB)
        $max_size = 25 * 1024 * 1024;
        if ($file['size'] > $max_size) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Audio file too large (max 25MB).', 'ai-chat-wp'),
                    'type' => 'validation_error',
                    'request_id' => $request_id
                )
            ), 400);
        }

        // Get provider and API key
        $provider_name = get_option('aicwp_provider', 'openai');

        // Get API key based on provider
        if ($provider_name === 'gemini') {
            $api_key = get_option('aicwp_gemini_api_key');
        } elseif ($provider_name === 'mistral') {
            $api_key = get_option('aicwp_mistral_api_key');
        } elseif ($provider_name === 'openrouter') {
            $api_key = get_option('aicwp_openrouter_api_key');
        } else {
            $api_key = get_option('aicwp_api_key');
        }

        if (empty($api_key)) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('API key not configured.', 'ai-chat-wp'),
                    'type' => 'configuration_error',
                    'request_id' => $request_id
                )
            ), 500);
        }

        // Route to appropriate provider
        if ($provider_name === 'gemini') {
            return $this->transcribe_with_gemini($file, $api_key, $request_id);
        } elseif ($provider_name === 'mistral') {
            return $this->transcribe_with_mistral($file, $api_key, $request_id);
        } elseif ($provider_name === 'openrouter') {
            return $this->transcribe_with_openrouter($file, $api_key, $request_id);
        } else {
            return $this->transcribe_with_openai($file, $api_key, $request_id);
        }
    }

    /**
     * Transcribe audio using OpenAI Whisper API
     *
     * @param array $file The uploaded file from $_FILES
     * @param string $api_key OpenAI API key
     * @param string $request_id Request ID for logging
     * @return WP_REST_Response
     */
    private function transcribe_with_openai($file, $api_key, $request_id) {
        // Build multipart request for OpenAI
        $boundary = wp_generate_password(24, false);
        $body = $this->build_whisper_multipart_body($file, $boundary);

        if ($body === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Failed to read audio file.', 'ai-chat-wp'),
                    'type' => 'file_error',
                    'request_id' => $request_id
                )
            ), 500);
        }

        // Call OpenAI Whisper API
        $response = wp_remote_post('https://api.openai.com/v1/audio/transcriptions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE OpenAI error: %s', $request_id, $response->get_error_message()));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Transcription service unavailable. Please try again.', 'ai-chat-wp'),
                    'type' => 'api_error',
                    'request_id' => $request_id
                )
            ), 502);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            if (get_option('aicwp_debug_mode', false)) {
                $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
                error_log(sprintf('AI Chat [%s] TRANSCRIBE OpenAI API error (%d): %s', $request_id, $status_code, $error_msg));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Could not transcribe audio. Please try again.', 'ai-chat-wp'),
                    'type' => 'transcription_error',
                    'request_id' => $request_id
                )
            ), $status_code >= 400 && $status_code < 600 ? $status_code : 500);
        }

        if (isset($result['text'])) {
            $transcribed_text = sanitize_text_field(trim($result['text']));
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE OpenAI success: %d chars', $request_id, strlen($transcribed_text)));
            }
            return new WP_REST_Response(array(
                'success' => true,
                'text' => $transcribed_text
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => array(
                'message' => __('Could not transcribe audio.', 'ai-chat-wp'),
                'type' => 'transcription_error',
                'request_id' => $request_id
            )
        ), 500);
    }

    /**
     * Transcribe audio using Mistral Voxtral API
     *
     * @param array $file The uploaded file from $_FILES
     * @param string $api_key Mistral API key
     * @param string $request_id Request ID for logging
     * @return WP_REST_Response
     */
    private function transcribe_with_mistral($file, $api_key, $request_id) {
        // Build multipart request (same format as OpenAI but different model)
        $boundary = wp_generate_password(24, false);
        $body = $this->build_mistral_multipart_body($file, $boundary);

        if ($body === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Failed to read audio file.', 'ai-chat-wp'),
                    'type' => 'file_error',
                    'request_id' => $request_id
                )
            ), 500);
        }

        // Call Mistral Voxtral API
        $response = wp_remote_post('https://api.mistral.ai/v1/audio/transcriptions', array(
            'headers' => array(
                'x-api-key' => $api_key,
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $body,
            'timeout' => 60,
        ));

        if (is_wp_error($response)) {
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE Mistral error: %s', $request_id, $response->get_error_message()));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Transcription service unavailable. Please try again.', 'ai-chat-wp'),
                    'type' => 'api_error',
                    'request_id' => $request_id
                )
            ), 502);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            if (get_option('aicwp_debug_mode', false)) {
                $error_msg = isset($result['error']['message']) ? $result['error']['message'] : 'Unknown error';
                error_log(sprintf('AI Chat [%s] TRANSCRIBE Mistral API error (%d): %s', $request_id, $status_code, $error_msg));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Could not transcribe audio. Please try again.', 'ai-chat-wp'),
                    'type' => 'transcription_error',
                    'request_id' => $request_id
                )
            ), $status_code >= 400 && $status_code < 600 ? $status_code : 500);
        }

        if (isset($result['text'])) {
            $transcribed_text = sanitize_text_field(trim($result['text']));
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE Mistral success: %d chars', $request_id, strlen($transcribed_text)));
            }
            return new WP_REST_Response(array(
                'success' => true,
                'text' => $transcribed_text
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => array(
                'message' => __('Could not transcribe audio.', 'ai-chat-wp'),
                'type' => 'transcription_error',
                'request_id' => $request_id
            )
        ), 500);
    }

    /**
     * Transcribe audio using Gemini Native API (supports webm, mp3, wav, ogg, etc.)
     *
     * @param array $file The uploaded file from $_FILES
     * @param string $api_key Gemini API key
     * @param string $request_id Request ID for logging
     * @return WP_REST_Response
     */
    private function transcribe_with_gemini($file, $api_key, $request_id) {
        // Read and encode audio file as base64
        $file_content = @file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Failed to read audio file.', 'ai-chat-wp'),
                    'type' => 'file_error',
                    'request_id' => $request_id
                )
            ), 500);
        }

        $base64_audio = base64_encode($file_content);

        // Map MIME type for Gemini
        $mime_type = $file['type'];
        if ($mime_type === 'video/webm') {
            $mime_type = 'audio/webm';
        }

        // Build request using Gemini's native generateContent API
        // This supports more audio formats than OpenAI compatibility
        $request_body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'inline_data' => array(
                                'mime_type' => $mime_type,
                                'data' => $base64_audio
                            )
                        ),
                        array(
                            'text' => 'Transcribe this audio exactly as spoken. Output only the transcription text, nothing else. If the audio is empty or unclear, output an empty string.'
                        )
                    )
                )
            ),
            'generationConfig' => array(
                'maxOutputTokens' => 4096,
                'temperature' => 0
            )
        );

        // Call Gemini native API (supports webm, mp3, wav, ogg, flac, aac)
        // Using gemini-3-flash for fast, accurate transcription
        $response = wp_remote_post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent',
            array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $api_key,
                ),
                'body' => wp_json_encode($request_body),
                'timeout' => 60,
            )
        );

        if (is_wp_error($response)) {
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE Gemini error: %s', $request_id, $response->get_error_message()));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Transcription service unavailable. Please try again.', 'ai-chat-wp'),
                    'type' => 'api_error',
                    'request_id' => $request_id
                )
            ), 502);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            if (get_option('aicwp_debug_mode', false)) {
                $error_msg = isset($result['error']['message']) ? $result['error']['message'] : wp_remote_retrieve_body($response);
                error_log(sprintf('AI Chat [%s] TRANSCRIBE Gemini API error (%d): %s', $request_id, $status_code, $error_msg));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Could not transcribe audio. Please try again.', 'ai-chat-wp'),
                    'type' => 'transcription_error',
                    'request_id' => $request_id
                )
            ), $status_code >= 400 && $status_code < 600 ? $status_code : 500);
        }

        // Extract text from Gemini native response
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            $transcribed_text = sanitize_text_field(trim($result['candidates'][0]['content']['parts'][0]['text']));
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE Gemini success: %d chars', $request_id, strlen($transcribed_text)));
            }
            return new WP_REST_Response(array(
                'success' => true,
                'text' => $transcribed_text
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => array(
                'message' => __('Could not transcribe audio.', 'ai-chat-wp'),
                'type' => 'transcription_error',
                'request_id' => $request_id
            )
        ), 500);
    }

    /**
     * Transcribe audio using OpenRouter (via chat/completions with input_audio content type).
     *
     * OpenRouter does not expose a dedicated /v1/audio/transcriptions endpoint, so we POST to
     * the standard chat/completions endpoint with a multimodal audio content block. Model is
     * hardcoded to google/gemini-3-flash-preview — the same Gemini 3 Flash Preview model used
     * by the native-Gemini transcription path above, routed through OpenRouter.
     *
     * @param array $file The uploaded file from $_FILES
     * @param string $api_key OpenRouter API key (sk-or-v1-...)
     * @param string $request_id Request ID for logging
     * @return WP_REST_Response
     */
    private function transcribe_with_openrouter($file, $api_key, $request_id) {
        // Read and encode audio file as base64
        $file_content = @file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Failed to read audio file.', 'ai-chat-wp'),
                    'type' => 'file_error',
                    'request_id' => $request_id
                )
            ), 500);
        }

        $base64_audio = base64_encode($file_content);

        // Map MIME type to the format string used by OpenRouter's input_audio schema.
        // OpenRouter forwards raw bytes to the upstream provider which auto-detects from
        // magic bytes, so the format string is effectively advisory.
        $mime_type = $file['type'];
        if ($mime_type === 'video/webm') {
            $mime_type = 'audio/webm';
        }
        $format = $this->get_audio_format($mime_type);

        // Build OpenAI-compatible chat/completions request with input_audio content block
        $request_body = array(
            'model' => 'google/gemini-3-flash-preview',
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => 'Transcribe this audio exactly as spoken. Output only the transcription text, nothing else. If the audio is empty or unclear, output an empty string.'
                        ),
                        array(
                            'type' => 'input_audio',
                            'input_audio' => array(
                                'data' => $base64_audio,
                                'format' => $format
                            )
                        )
                    )
                )
            ),
            'max_tokens' => 2048,
            'temperature' => 0
        );

        // Call OpenRouter chat/completions endpoint
        $response = wp_remote_post(
            'https://openrouter.ai/api/v1/chat/completions',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($request_body),
                'timeout' => 60,
            )
        );

        if (is_wp_error($response)) {
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE OpenRouter error: %s', $request_id, $response->get_error_message()));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Transcription service unavailable. Please try again.', 'ai-chat-wp'),
                    'type' => 'api_error',
                    'request_id' => $request_id
                )
            ), 502);
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $result = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            if (get_option('aicwp_debug_mode', false)) {
                $error_msg = isset($result['error']['message']) ? $result['error']['message'] : wp_remote_retrieve_body($response);
                error_log(sprintf('AI Chat [%s] TRANSCRIBE OpenRouter API error (%d): %s', $request_id, $status_code, $error_msg));
            }
            return new WP_REST_Response(array(
                'success' => false,
                'error' => array(
                    'message' => __('Could not transcribe audio. Please try again.', 'ai-chat-wp'),
                    'type' => 'transcription_error',
                    'request_id' => $request_id
                )
            ), $status_code >= 400 && $status_code < 600 ? $status_code : 500);
        }

        // Extract text from OpenAI-compatible chat/completions response
        if (isset($result['choices'][0]['message']['content'])) {
            $transcribed_text = sanitize_text_field(trim($result['choices'][0]['message']['content']));
            if (get_option('aicwp_debug_mode', false)) {
                error_log(sprintf('AI Chat [%s] TRANSCRIBE OpenRouter success: %d chars', $request_id, strlen($transcribed_text)));
            }
            return new WP_REST_Response(array(
                'success' => true,
                'text' => $transcribed_text
            ), 200);
        }

        return new WP_REST_Response(array(
            'success' => false,
            'error' => array(
                'message' => __('Could not transcribe audio.', 'ai-chat-wp'),
                'type' => 'transcription_error',
                'request_id' => $request_id
            )
        ), 500);
    }

    /**
     * Get audio format string for Gemini from MIME type
     *
     * @param string $mime_type The MIME type
     * @return string The format string (wav, mp3, etc.)
     */
    private function get_audio_format($mime_type) {
        $formats = array(
            'audio/webm' => 'webm',
            'audio/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/m4a' => 'm4a',
            'audio/x-m4a' => 'm4a',
        );
        return isset($formats[$mime_type]) ? $formats[$mime_type] : 'webm';
    }

    /**
     * Build multipart form body for OpenAI Whisper API
     *
     * @param array $file The uploaded file array from $_FILES
     * @param string $boundary The multipart boundary string
     * @return string|false The multipart body or false on error
     */
    private function build_whisper_multipart_body($file, $boundary) {
        $file_content = @file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            return false;
        }

        $extensions = array(
            'audio/webm' => 'webm',
            'video/webm' => 'webm',
            'audio/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/m4a' => 'm4a',
            'audio/x-m4a' => 'm4a',
        );
        $ext = isset($extensions[$file['type']]) ? $extensions[$file['type']] : 'webm';

        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="recording.' . $ext . '"' . "\r\n";
        $body .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
        $body .= $file_content . "\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n";
        $body .= 'whisper-1' . "\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="response_format"' . "\r\n\r\n";
        $body .= 'json' . "\r\n";

        $body .= '--' . $boundary . '--';

        return $body;
    }

    /**
     * Build multipart form body for Mistral Voxtral API
     *
     * @param array $file The uploaded file array from $_FILES
     * @param string $boundary The multipart boundary string
     * @return string|false The multipart body or false on error
     */
    private function build_mistral_multipart_body($file, $boundary) {
        $file_content = @file_get_contents($file['tmp_name']);
        if ($file_content === false) {
            return false;
        }

        $extensions = array(
            'audio/webm' => 'webm',
            'video/webm' => 'webm',
            'audio/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/m4a' => 'm4a',
            'audio/x-m4a' => 'm4a',
        );
        $ext = isset($extensions[$file['type']]) ? $extensions[$file['type']] : 'webm';

        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="file"; filename="recording.' . $ext . '"' . "\r\n";
        $body .= 'Content-Type: ' . $file['type'] . "\r\n\r\n";
        $body .= $file_content . "\r\n";

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="model"' . "\r\n\r\n";
        $body .= 'voxtral-mini-latest' . "\r\n";

        $body .= '--' . $boundary . '--';

        return $body;
    }

    /**
     * Render the microphone button HTML
     */
    public function render_mic_button() {
        ?>
        <div
            class="aicwp-chat-mic-btn"
            data-chat-tooltip="<?php esc_attr_e('Voice Input', 'ai-chat-wp'); ?>"
            aria-label="<?php esc_attr_e('Record voice message', 'ai-chat-wp'); ?>"
            role="button"
            tabindex="0"
        >
            <!-- Default state: mic icon -->
            <svg class="mic-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"></path>
                <path d="M19 10v2a7 7 0 0 1-14 0v-2"></path>
                <line x1="12" y1="19" x2="12" y2="23"></line>
                <line x1="8" y1="23" x2="16" y2="23"></line>
            </svg>
            <!-- Recording state: red dot + timer + stop button -->
            <span class="mic-recording-ui" style="display:none">
                <span class="mic-recording-dot"></span>
                <span class="mic-recording-timer">0:00</span>
                <svg class="stop-icon" viewBox="0 0 24 24" width="14" height="14">
                    <rect fill="currentColor" x="4" y="4" width="16" height="16" rx="2"/>
                </svg>
            </span>
            <!-- Transcribing state: spinner -->
            <span class="mic-transcribing-loader" style="display:none"></span>
        </div>
        <?php
    }

    /**
     * Enqueue speech-to-text CSS and JavaScript assets
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'aicwp-speech',
            AICWP_PLUGIN_URL . 'assets/css/speech-to-text.css',
            array('aicwp-chat'),
            AICWP_VERSION
        );

        wp_enqueue_script(
            'aicwp-speech',
            AICWP_PLUGIN_URL . 'assets/js/speech-to-text.js',
            array('aicwp-chat', 'jquery'),
            AICWP_VERSION,
            true
        );
    }
}
