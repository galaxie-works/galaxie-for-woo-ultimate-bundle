<?php
/**
 * Admin Interface Class
 *
 * Handles WordPress admin settings and management interface
 *
 * @package AICWP_Plugin
 * @since 1.0.5
 */

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

// Include admin handlers
require_once plugin_dir_path(__FILE__) . "class-admin-chat-history.php";
require_once plugin_dir_path(__FILE__) . "class-admin-contact-messages.php";
require_once plugin_dir_path(__FILE__) . "class-admin-search-analytics.php";

class AICWP_Admin_Interface
{
    /**
     * Chat history admin handler instance
     * @var AICWP_Admin_Chat_History
     */
    private $chat_history;

    /**
     * Contact messages admin handler instance
     * @var AICWP_Admin_Contact_Messages
     */
    private $contact_messages;

    /**
     * Search analytics admin handler instance
     * @var AICWP_Admin_Search_Analytics
     */
    private $search_analytics;

    /**
     * Centralized Settings Registry - Single Source of Truth
     *
     * This registry eliminates duplication across 10+ locations in the codebase.
     * All settings metadata is defined here once and derived everywhere else.
     *
     * @return array Comprehensive settings configuration
     */
    private function get_settings_registry()
    {
        $settings = [
            // ============================================
            // API Configuration Settings
            // ============================================
            "aicwp_provider" => [
                "type" => "select",
                "section" => "api-config",
                "sanitize" => "sanitize_text_field",
                "default" => "openai",
                "description" => "AI Provider",
                "options" => [
                    "openai" => "OpenAI",
                    "gemini" => "Google Gemini",
                    "openrouter" => "OpenRouter",
                ],
            ],
            "aicwp_embedding_model" => [
                "type" => "select",
                "section" => "api-config",
                "sanitize" => "sanitize_text_field",
                "default" => "",
                "description" => "Embedding Model",
            ],
            "aicwp_api_key" => [
                "type" => "text",
                "section" => "api-config",
                "sanitize" => "sanitize_text_field",
                "default" => "",
                "description" => "OpenAI API Key",
            ],
            "aicwp_gemini_api_key" => [
                "type" => "text",
                "section" => "api-config",
                "sanitize" => "sanitize_text_field",
                "default" => "",
                "description" => "Google Gemini API Key",
            ],
            "aicwp_mistral_api_key" => [
                "type" => "text",
                "section" => "api-config",
                "sanitize" => "sanitize_text_field",
                "default" => "",
                "description" => "Mistral AI API Key",
            ],
            "aicwp_openrouter_api_key" => [
                "type" => "text",
                "section" => "api-config",
                "sanitize" => "sanitize_text_field",
                "default" => "",
                "description" => "OpenRouter API Key",
            ],
            "aicwp_openrouter_reasoning" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Enable model reasoning for OpenRouter (off = faster, on = better answers for complex questions)",
            ],
            "aicwp_debug_mode" => [
                "type" => "checkbox",
                "section" => "developer-debug",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable debug logging",
            ],
            "aicwp_chat_lazy_load" => [
                "type" => "checkbox",
                "section" => "developer-debug",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Lazy load chatbot scripts",
            ],
            "aicwp_chat_custom_css" => [
                "type" => "textarea",
                "section" => "developer-debug",
                "sanitize" => "sanitize_textarea_field",
                "default" => "",
                "description" => "Custom CSS for chat widget",
            ],
            // ============================================
            // Quality & Threshold Settings
            // ============================================
            "aicwp_query_expansion" => [
                "type" => "checkbox",
                "section" => "quality-thresholds",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable query expansion",
            ],
            "aicwp_min_match_percentage" => [
                "type" => "number",
                "section" => "quality-thresholds",
                "sanitize" => "intval",
                "default" => 50,
                "description" => "Minimum match percentage",
            ],
            "aicwp_best_match_threshold" => [
                "type" => "number",
                "section" => "quality-thresholds",
                "sanitize" => "intval",
                "default" => 75,
                "description" => "Best match threshold",
            ],
            "aicwp_max_results" => [
                "type" => "number",
                "section" => "quality-thresholds",
                "sanitize" => "intval",
                "default" => 10,
                "description" => "Maximum search results",
            ],

            // ============================================
            // Search Suggestions Settings
            // ============================================
            "aicwp_suggestions_enabled" => [
                "type" => "checkbox",
                "section" => "search-suggestions",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable search suggestions",
            ],
            "aicwp_suggestions_source" => [
                "type" => "select",
                "section" => "search-suggestions",
                "sanitize" => "sanitize_text_field",
                "default" => "ai",
                "description" => "Suggestions source",
            ],
            "aicwp_custom_suggestions" => [
                "type" => "textarea",
                "section" => "search-suggestions",
                "sanitize" => "sanitize_textarea_field",
                "default" => "",
                "description" => "Custom suggestions (one per line)",
            ],

            // ============================================
            // Processing Settings
            // ============================================
            "aicwp_rate_limit_per_hour" => [
                "type" => "number",
                "section" => "processing",
                "sanitize" => "intval",
                "default" => 200,
                "min" => 10,
                "max" => 10000,
                "description" => "API rate limit per hour",
            ],

            // ============================================
            // Analytics Settings
            // ============================================
            "aicwp_enable_analytics" => [
                "type" => "checkbox",
                "section" => "internal",
                "sanitize" => "intval",
                "default" => 1,
                "description" => "Enable analytics tracking",
            ],

            // ============================================
            // AI Chat Configuration Settings
            // ============================================
            "aicwp_chat_force_language" => [
                "type" => "text",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "",
                "description" =>
                    "Force AI to respond in this language. Use [English][browser_lang] for restricted choice.",
            ],
            "aicwp_chat_system_prompt" => [
                "type" => "textarea",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_textarea_field",
                "default" => "",
                "description" => "System prompt for AI chat",
            ],
            "aicwp_chat_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 1,
                "description" => "Enable AI chat",
            ],
            "aicwp_chat_avatar" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Chat avatar image",
            ],
            "aicwp_chat_name" => [
                "type" => "text",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => __("AI Assistant", "ai-chat-wp"),
                "description" => "Chat assistant name",
            ],
            "aicwp_chat_welcome_message" => [
                "type" => "wysiwyg",
                "section" => "ai-chat-config",
                "sanitize" => "wp_kses_post",
                "default" => __(
                    "Hello! How can I help you today?",
                    "ai-chat-wp",
                ),
                "description" => "Welcome message (supports HTML)",
            ],
            "aicwp_chat_model" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "gpt-5.4-mini",
                "description" => "OpenAI model to use",
            ],
            "aicwp_chat_max_results" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 10,
                "description" => "Maximum results to include in context",
            ],
            "aicwp_chat_woo_cart_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable WooCommerce cart in chatbot",
            ],
            "aicwp_chat_woo_order_checking_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 1,
                "description" => "Enable order checking in chatbot",
            ],
            "aicwp_chat_rag_sources_limit" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 5,
                "min" => 2,
                "max" => 10,
                "description" => "Maximum RAG sources to send to LLM",
            ],
            "aicwp_chat_hide_images" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Hide images in chat results",
            ],
            "aicwp_chat_loading_style" => [
                "type" => "radio",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "spinner",
                "description" => "Loading animation style",
                "options" => [
                    "spinner" => "Spinner + Text",
                    "dots" => "Dots Only",
                ],
            ],
            "aicwp_chat_typing_animation" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Enable typing animation effect for AI responses",
            ],
            "aicwp_chat_require_login" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Require login to use chat",
            ],
            "aicwp_chat_history_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 1,
                "description" => "Enable chat history",
            ],
            "aicwp_chat_retention_days" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 30,
                "description" => "Chat history retention (days)",
            ],
            "aicwp_chat_terms_notice_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Show terms notice",
            ],
            "aicwp_chat_terms_notice_text" => [
                "type" => "wysiwyg",
                "section" => "ai-chat-config",
                "sanitize" => "wp_kses_post",
                "default" => "",
                "description" => "Terms notice text (supports HTML)",
            ],
            "aicwp_chat_whitelabel_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Hide the \"Powered by Community\" badge",
            ],
            "aicwp_contact_form_allow_ai_send" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Allow AI to send emails to you when explicitly asked by user when chatting with AI",
            ],
            "aicwp_contact_form_examples" => [
                "type" => "textarea",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_textarea_field",
                "default" =>
                    "EXAMPLES OF WHEN TO USE:\n- \"Can you send a message to the site owner for me?\"\n- \"I want to contact support about X\"\n- \"Please send them my inquiry about Y\"\n\nEXAMPLES OF WHEN NOT TO USE:\n- \"How can I contact you?\" (just provide contact info)\n- \"What's your email?\" (just provide info, don't send)",
                "description" =>
                    "Examples for AI when to use or not use the contact form tool",
            ],
            "aicwp_whatsapp_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable WhatsApp integration via Twilio",
            ],
            "aicwp_telegram_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable Telegram bot integration",
            ],
            "aicwp_chat_rate_limit_tier1" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 10,
                "description" => "Rate limit tier 1 (logged out users)",
            ],
            "aicwp_chat_rate_limit_tier2" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 30,
                "description" => "Rate limit tier 2 (logged in users)",
            ],
            "aicwp_chat_rate_limit_tier3" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 100,
                "description" => "Rate limit tier 3 (premium users)",
            ],
            "aicwp_chat_context_length" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "normal",
                "description" => "Conversation context length preset",
            ],

            // ============================================
            // Floating Chat Widget Settings (now part of ai-chat-config section)
            // ============================================
            "aicwp_floating_chat_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable floating chat widget",
            ],
            "aicwp_floating_keep_chat_opened" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Keep chat opened when navigating between pages",
            ],
            "aicwp_floating_position" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "right",
                "description" => "Floating widget position (left or right)",
            ],
            "aicwp_floating_button_icon" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "default",
                "description" => "Button icon style",
            ],
            "aicwp_floating_custom_icon" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Custom icon attachment ID",
            ],
            "aicwp_floating_custom_icon_size" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "absint",
                "default" => 32,
                "description" =>
                    "Custom icon size in pixels (overrides width/height/max-width/max-height)",
            ],
            "aicwp_floating_welcome_bubble" => [
                "type" => "wysiwyg",
                "section" => "ai-chat-config",
                "sanitize" => "wp_kses_post",
                "default" => __("Hi! How can I help you?", "ai-chat-wp"),
                "description" => "Welcome bubble text (supports HTML)",
            ],
            "aicwp_floating_popup_width" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 390,
                "description" => "Popup width (pixels)",
            ],
            "aicwp_floating_popup_height" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 600,
                "description" => "Popup height (pixels)",
            ],
            "aicwp_floating_button_color" => [
                "type" => "color",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_hex_color",
                "default" => "#222222",
                "description" => "Button background color",
            ],
            "aicwp_primary_color" => [
                "type" => "color",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_hex_color",
                "default" => "#0073ee",
                "description" => "Primary color for UI elements",
            ],
            "aicwp_color_scheme" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "light",
                "description" =>
                    "Color scheme for chat widget (light, dark, auto)",
            ],
            "aicwp_color_scheme_switcher" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Show color scheme switcher toggle in chat window",
            ],
            "aicwp_floating_header_style" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "simple",
                "description" =>
                    "Header style for floating chat popup (simple, image, or animated)",
            ],
            "aicwp_floating_header_bg" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Header background image attachment ID",
            ],
            "aicwp_floating_header_overlay" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable overlay on header background image",
            ],
            "aicwp_animated_bg_color" => [
                "type" => "color",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_hex_color",
                "default" => "#1560d0",
                "description" => "Base color for animated wave background",
            ],
            "aicwp_floating_excluded_pages" => [
                "type" => "array",
                "section" => "ai-chat-config",
                "sanitize" => "array_map_intval",
                "default" => [],
                "description" => "Pages where floating chat should be hidden",
            ],
            "aicwp_floating_offset_desktop_h" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 20,
                "description" => "Desktop horizontal offset (px)",
            ],
            "aicwp_floating_offset_desktop_v" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 20,
                "description" => "Desktop vertical offset (px)",
            ],
            "aicwp_floating_offset_mobile_h" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 20,
                "description" => "Mobile horizontal offset (px)",
            ],
            "aicwp_floating_offset_mobile_v" => [
                "type" => "number",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 20,
                "description" => "Mobile vertical offset (px)",
            ],
            "aicwp_chat_quick_buttons_enabled" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable quick action buttons in chat",
            ],
            "aicwp_chat_quick_buttons_visibility" => [
                "type" => "select",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_text_field",
                "default" => "always",
                "description" => "Quick buttons visibility mode",
            ],
            "aicwp_chat_quick_buttons" => [
                "type" => "array",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_quick_buttons",
                "default" => [],
                "description" => "Quick action buttons configuration",
            ],
            "aicwp_chat_blocked_ips" => [
                "type" => "array",
                "section" => "ai-chat-config",
                "sanitize" => "sanitize_blocked_ips",
                "default" => [],
                "description" =>
                    "IP addresses blocked from using the chat widget",
            ],
            "aicwp_chat_enable_speech" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" =>
                    "Enable speech-to-text input in chat",
            ],
            "aicwp_chat_enable_image_input" => [
                "type" => "checkbox",
                "section" => "ai-chat-config",
                "sanitize" => "intval",
                "default" => 0,
                "description" => "Enable image input in chat (vision models)",
            ],

            // ============================================
            // Special/Internal Settings (not in standard forms)
            // ============================================
            "aicwp_enabled_types" => [
                "type" => "array",
                "section" => "internal",
                "sanitize" => "sanitize_text_field",
                "default" => [],
                "description" => "Enabled post types for AI search",
            ],
        ];

        // Allow extensions to register additional settings
        return apply_filters("aicwp_settings_registry", $settings);
    }

    /**
     * Get all setting keys from registry
     *
     * @return array Array of setting keys
     */
    private function get_all_setting_keys()
    {
        return array_keys($this->get_settings_registry());
    }

    /**
     * Get secret setting keys.
     *
     * @return array
     */
    private function get_secret_setting_keys()
    {
        return [
            "aicwp_api_key",
            "aicwp_gemini_api_key",
            "aicwp_mistral_api_key",
            "aicwp_openrouter_api_key",
        ];
    }

    /**
     * Get the local vendor icon URL for an OpenRouter model slug.
     *
     * Maps the vendor prefix (e.g. 'openai/', 'anthropic/') to its local icon
     * file in assets/provider-icons/. Icons are rendered inside the AI chat
     * model <option> elements, progressively enhanced via CSS
     * appearance:base-select (Chrome 135+) — on older browsers the <img> is
     * stripped by the native select renderer and only the text label shows.
     *
     * @param string $model_slug OpenRouter model slug (e.g. 'openai/gpt-5-mini')
     * @return string Icon URL, or empty string if vendor is unknown.
     */
    private function get_openrouter_vendor_icon_url($model_slug)
    {
        // Order matters: more-specific prefixes must come before generic ones
        // (e.g. 'google/gemini-' before 'google/', so Gemini gets the star
        // logo instead of the generic Google G).
        static $map = [
            // OpenRouter namespaced prefixes
            "openai/" => "openai.png",
            "anthropic/" => "anthropic.png",
            "google/gemini-" => "gemini.svg",
            "google/" => "google.png", // Gemma and other Google models
            "meta-llama/" => "meta.png",
            "mistralai/" => "mistral.png",
            "deepseek/" => "deepseek.png",
            "z-ai/" => "z-ai.png",
            "moonshotai/" => "moonshot.png",
            "qwen/" => "qwen.png",
            "x-ai/" => "x-ai.png",
            "minimax/" => "minimax.png",
            // Native (bare) prefixes for OpenAI / Gemini / Mistral optgroups
            "gpt-" => "openai.png",
            "text-embedding-" => "openai.png",
            "gemini-" => "gemini.svg",
            "mistral-" => "mistral.png",
        ];
        foreach ($map as $prefix => $filename) {
            if (strpos($model_slug, $prefix) === 0) {
                return AICWP_PLUGIN_URL .
                    "assets/provider-icons/" .
                    $filename;
            }
        }
        return "";
    }

    /**
     * Get settings grouped by section
     *
     * @return array Settings organized by section
     */
    private function get_section_settings()
    {
        $registry = $this->get_settings_registry();
        $sections = [];

        foreach ($registry as $key => $config) {
            if ($config["section"] !== "internal") {
                $sections[$config["section"]][] = $key;
            }
        }

        return $sections;
    }

    /**
     * Get checkbox fields, optionally filtered by section
     *
     * @param string|null $section Optional section filter
     * @return array Array of checkbox field keys
     */
    private function get_checkbox_fields($section = null)
    {
        $registry = $this->get_settings_registry();
        $checkboxes = [];

        foreach ($registry as $key => $config) {
            if ($config["type"] === "checkbox") {
                if ($section === null || $config["section"] === $section) {
                    $checkboxes[] = $key;
                }
            }
        }

        return $checkboxes;
    }

    /**
     * Get section checkboxes mapping
     *
     * @return array Checkboxes organized by section
     */
    private function get_section_checkboxes()
    {
        $registry = $this->get_settings_registry();
        $sections = [];

        foreach ($registry as $key => $config) {
            if (
                $config["type"] === "checkbox" &&
                $config["section"] !== "internal"
            ) {
                $sections[$config["section"]][] = $key;
            }
        }

        return $sections;
    }

    /**
     * Sanitize a setting value based on registry configuration
     *
     * @param string $key Setting key
     * @param mixed $value Value to sanitize
     * @return mixed Sanitized value
     */
    private function sanitize_setting($key, $value)
    {
        $registry = $this->get_settings_registry();

        if (!isset($registry[$key])) {
            // Unknown setting, use basic sanitization
            return sanitize_text_field($value);
        }

        $config = $registry[$key];

        // Allow extensions to handle sanitization for their own settings
        $filtered_value = apply_filters(
            "aicwp_sanitize_setting",
            $value,
            $key,
            $value,
        );
        if ($filtered_value !== $value) {
            return $filtered_value;
        }

        // Handle arrays
        if (is_array($value)) {
            // Special handling for page IDs (integers)
            if ($key === "aicwp_floating_excluded_pages") {
                return array_map("intval", $value);
            }
            // Special handling for quick buttons
            if ($key === "aicwp_chat_quick_buttons") {
                $sanitized_buttons = [];
                foreach ($value as $button) {
                    if (!empty($button["text"])) {
                        $btn_type = isset($button["type"])
                            ? $button["type"]
                            : "chat";
                        $btn_color = isset($button["color"])
                            ? sanitize_hex_color($button["color"])
                            : "";
                        $sanitized_buttons[] = [
                            "text" => sanitize_text_field($button["text"]),
                            "type" => in_array($btn_type, [
                                "chat",
                                "url",
                                "contact",
                            ])
                                ? $btn_type
                                : "chat",
                            "value" =>
                                $btn_type === "url"
                                    ? esc_url_raw($button["value"])
                                    : sanitize_text_field($button["value"]),
                            "color" => $btn_color ? $btn_color : "",
                        ];
                    }
                }
                return $sanitized_buttons;
            }
            // Special handling for blocked IPs
            if ($key === "aicwp_chat_blocked_ips") {
                $sanitized_ips = [];
                foreach ($value as $entry) {
                    if (!empty($entry["ip"])) {
                        $ip = sanitize_text_field(trim($entry["ip"]));
                        // Basic validation - allow IPv4, IPv6, and CIDR notation
                        if (
                            filter_var($ip, FILTER_VALIDATE_IP) ||
                            (strpos($ip, "/") !== false &&
                                filter_var(
                                    explode("/", $ip)[0],
                                    FILTER_VALIDATE_IP,
                                ))
                        ) {
                            $sanitized_ips[] = ["ip" => $ip];
                        }
                    }
                }
                return $sanitized_ips;
            }
            // Special handling for pre-chat fields
            if ($key === "aicwp_chat_pre_chat_fields") {
                $sanitized_fields = [];
                foreach ($value as $entry) {
                    if (!empty($entry["label"])) {
                        $sanitized_fields[] = [
                            "label" => sanitize_text_field(
                                trim($entry["label"]),
                            ),
                        ];
                    }
                }
                return $sanitized_fields;
            }
            return array_map("sanitize_text_field", $value);
        }

        // Handle special cases with min/max bounds
        if ($key === "aicwp_rate_limit_per_hour") {
            $value = intval($value);
            return max($config["min"], min($config["max"], $value));
        }

        // RAG sources limit - ensure minimum of 2
        if ($key === "aicwp_chat_rag_sources_limit") {
            $value = intval($value);
            return max($config["min"], min($config["max"], $value));
        }

        // Context length - only allow valid presets
        if ($key === "aicwp_chat_context_length") {
            return in_array($value, ["short", "normal", "long"])
                ? $value
                : "normal";
        }

        if (
            $key === "aicwp_floating_button_color" ||
            $key === "aicwp_primary_color"
        ) {
            $sanitized = sanitize_hex_color($value);
            return empty($sanitized) ? $config["default"] : $sanitized;
        }

        // Custom system prompt - enforce length limit on save (prevent devtools bypass of maxlength)
        if ($key === "aicwp_chat_system_prompt") {
            $sanitized = sanitize_textarea_field(wp_unslash($value));
            $max_length = AICWP_MAX_PROMPT_LENGTH;
            return mb_substr($sanitized, 0, $max_length);
        }

        // Apply sanitization callback
        $sanitize_callback = $config["sanitize"];

        // Handle special sanitization for WYSIWYG fields (allow HTML)
        if ($sanitize_callback === "wp_kses_post") {
            return wp_kses_post(wp_unslash($value));
        }

        // Handle textarea fields (strip slashes)
        if ($sanitize_callback === "sanitize_textarea_field") {
            return sanitize_textarea_field(wp_unslash($value));
        }

        // Standard sanitization
        return call_user_func($sanitize_callback, $value);
    }

    /**
     * Get default value for a setting
     *
     * @param string $key Setting key
     * @return mixed Default value
     */
    private function get_default_value($key)
    {
        $registry = $this->get_settings_registry();
        return isset($registry[$key]) ? $registry[$key]["default"] : null;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize admin handlers (they register their own AJAX handlers)
        $this->chat_history = new AICWP_Admin_Chat_History();
        $this->contact_messages = new AICWP_Admin_Contact_Messages();
        $this->search_analytics = new AICWP_Admin_Search_Analytics();

        // Ensure default settings exist (for existing installations)
        add_action("admin_init", [$this, "ensure_default_settings"], 5);

        // Admin menu
        add_action("admin_menu", [$this, "admin_menu"]);
        add_filter("parent_file", [$this, "set_active_parent_menu"]);
        add_filter("submenu_file", [$this, "set_active_submenu"], 10, 2);
        add_filter("admin_title", [$this, "set_admin_page_title"], 10, 2);

        // Settings
        add_action("admin_init", [$this, "admin_init"]);

        // Enqueue admin scripts
        add_action("admin_enqueue_scripts", [$this, "enqueue_admin_scripts"]);

        // AJAX handlers for settings
        add_action("wp_ajax_aicwp_save_settings", [
            $this,
            "ajax_save_settings",
        ]);
        add_action("wp_ajax_aicwp_save_embedding_model", [
            $this,
            "ajax_save_embedding_model",
        ]);
        add_action("wp_ajax_aicwp_test_api_key", [
            $this,
            "ajax_test_api_key",
        ]);
        add_action("wp_ajax_aicwp_test_gemini_api_key", [
            $this,
            "ajax_test_gemini_api_key",
        ]);
        add_action("wp_ajax_aicwp_test_mistral_api_key", [
            $this,
            "ajax_test_mistral_api_key",
        ]);
        add_action("wp_ajax_aicwp_test_openrouter_api_key", [
            $this,
            "ajax_test_openrouter_api_key",
        ]);
        add_action("wp_ajax_aicwp_clear_cache", [
            $this,
            "ajax_clear_cache",
        ]);
        add_action("wp_ajax_aicwp_regenerate_embedding", [
            $this,
            "ajax_regenerate_embedding",
        ]);
        add_action("wp_ajax_aicwp_clear_embeddings_for_provider_switch", [
            $this,
            "ajax_clear_embeddings_for_provider_switch",
        ]);
        add_action("wp_ajax_aicwp_create_missing_tables", [
            $this,
            "ajax_create_missing_tables",
        ]);
        add_action("wp_ajax_aicwp_clear_ip_rate_limits", [
            $this,
            "ajax_clear_ip_rate_limits",
        ]);
        add_action("wp_ajax_aicwp_toggle_auto_training", [
            $this,
            "ajax_toggle_auto_training",
        ]);
        add_action("wp_ajax_aicwp_toggle_search_analytics", [
            $this,
            "ajax_toggle_search_analytics",
        ]);
        add_action("wp_ajax_aicwp_toggle_custom_fields", [
            $this,
            "ajax_toggle_custom_fields",
        ]);

        // Embedding search handler
        add_action("wp_ajax_aicwp_search_embeddings", [
            $this,
            "ajax_search_embeddings",
        ]);

        // Knowledge sources management
        add_action("wp_ajax_aicwp_search_posts_for_reference", [
            $this,
            "ajax_search_posts_for_reference",
        ]);
        add_action("wp_ajax_aicwp_add_knowledge_source", [
            $this,
            "ajax_add_knowledge_source",
        ]);
        add_action("wp_ajax_aicwp_delete_knowledge_source", [
            $this,
            "ajax_delete_knowledge_source",
        ]);
    }

    /**
     * Ensure default settings exist in database
     * This handles existing installations that were activated before defaults were added
     */
    public function ensure_default_settings()
    {
        // Check if defaults have been initialized (use a flag to avoid running every page load)
        $defaults_initialized = get_option(
            "aicwp_defaults_initialized",
            false,
        );

        if ($defaults_initialized) {
            return; // Defaults already set
        }

        // Get defaults from main plugin class (single source of truth)
        $defaults = AICWP_Plugin::get_default_settings();

        foreach ($defaults as $option_name => $default_value) {
            // Only add if option doesn't exist
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }

        // Set flag so this doesn't run again
        update_option("aicwp_defaults_initialized", true);
    }

    /**
     * Get the admin menu icon (robot head as chat bubble with tail)
     *
     * @return string Base64-encoded SVG data URI
     */
    private function get_menu_icon()
    {
        // Robot icon - white fill, viewBox 24x28 adds bottom whitespace to raise icon
        return "data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyOCIgZmlsbD0iI0ZGRiI+PHBhdGggZD0iTTkgMTRhMS41IDEuNSAwIDEwMCAzIDEuNSAxLjUgMCAwMDAtM3oiLz48cGF0aCBkPSJNMTMuNSAxNS41YTEuNSAxLjUgMCAxMTMgMCAxLjUgMS41IDAgMDEtMyAweiIvPjxwYXRoIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTEyIDFhMiAyIDAgMDAtMiAyYzAgLjc0LjQwMiAxLjM4NyAxIDEuNzMyVjdINmEzIDMgMCAwMC0zIDN2MTBhMyAzIDAgMDAzIDNoMTJhMyAzIDAgMDAzLTNWMTBhMyAzIDAgMDAtMy0zaC01VjQuNzMyQTIgMiAwIDAwMTIgMXpNNSAxMGExIDEgMCAwMTEtMWgxMmExIDEgMCAwMTEgMXYxMGExIDEgMCAwMS0xIDFINmExIDEgMCAwMS0xLTFWMTB6Ii8+PHBhdGggZD0iTTEgMTRhMSAxIDAgMDAtMSAxdjJhMSAxIDAgMTAyIDB2LTJhMSAxIDAgMDAtMS0xeiIvPjxwYXRoIGQ9Ik0yMiAxNWExIDEgMCAxMTIgMHYyYTEgMSAwIDExLTIgMHYtMnoiLz48L3N2Zz4=";
    }

    /**
     * Add admin menu
     */
    public function admin_menu()
    {
        add_menu_page(
            "AI Chat for WordPress", // Page title
            "AI Chat", // Menu title
            "manage_options", // Capability
            "ai-chat-wp", // Menu slug
            [$this, "admin_page"], // Callback function
            $this->get_menu_icon(), // Robot icon
            30, // Position (after Comments)
        );

        $this->add_admin_sidebar_tabs();
    }

    /**
     * Get admin URL for a specific plugin tab.
     *
     * @param string $tab Tab slug.
     * @return string Admin URL.
     */
    private function get_admin_tab_url($tab)
    {
        return admin_url($this->get_admin_tab_menu_slug($tab));
    }

    /**
     * Get WordPress menu slug for a specific plugin tab.
     *
     * @param string $tab Tab slug.
     * @return string Menu slug.
     */
    private function get_admin_tab_menu_slug($tab)
    {
        return "admin.php?page=ai-chat-wp&tab=" . rawurlencode($tab);
    }

    /**
     * Get the active plugin tab from the current request.
     *
     * @return string Active tab slug.
     */
    private function get_active_admin_tab()
    {
        $active_tab = isset($_GET["tab"])
            ? sanitize_key(wp_unslash($_GET["tab"]))
            : "stats";

        return $active_tab === "" ? "stats" : $active_tab;
    }

    /**
     * Add direct sidebar submenu entries for each tab.
     *
     * WordPress top-level menu links do not preserve extra query args for the
     * page slug, so we provide explicit submenu URLs and let the top-level item
     * use the first submenu entry as its target.
     */
    private function add_admin_sidebar_tabs()
    {
        global $submenu;

        $tabs = [
            [
                "slug" => "stats",
                "label" => __("Dashboard", "ai-chat-wp"),
            ],
            [
                "slug" => "ai-chat",
                "label" => __("Settings", "ai-chat-wp"),
            ],
        ];

        $tabs[] = [
            "slug" => "database",
            "label" => __("Data Training", "ai-chat-wp"),
        ];

        $tabs = apply_filters("aicwp_admin_sidebar_tabs", $tabs);
        $submenu["ai-chat-wp"] = [];

        foreach ($tabs as $tab) {
            if (empty($tab["slug"]) || empty($tab["label"])) {
                continue;
            }

            $tab_slug = sanitize_key($tab["slug"]);
            $is_our_page = isset($_GET["page"]) && $_GET["page"] === "ai-chat-wp";
            $classes =
                ($is_our_page && $this->get_active_admin_tab() === $tab_slug) ? "current" : "";

            $submenu["ai-chat-wp"][] = [
                $tab["label"],
                "manage_options",
                $this->get_admin_tab_menu_slug($tab_slug),
                $tab["label"],
                $classes,
            ];
        }
    }

    /**
     * Keep the plugin parent menu open while viewing any tab.
     *
     * @param string $parent_file Current parent file.
     * @return string Active parent file.
     */
    public function set_active_parent_menu($parent_file)
    {
        if (
            isset($_GET["page"]) &&
            $_GET["page"] === "ai-chat-wp"
        ) {
            return "ai-chat-wp";
        }

        return $parent_file;
    }

    /**
     * Highlight the correct sidebar submenu item for the active tab.
     *
     * @param string $submenu_file Current submenu file.
     * @param string $parent_file Current parent file.
     * @return string Active submenu file.
     */
    public function set_active_submenu($submenu_file, $parent_file)
    {
        if (
            !isset($_GET["page"]) ||
            $_GET["page"] !== "ai-chat-wp"
        ) {
            return $submenu_file;
        }

        return $this->get_admin_tab_menu_slug($this->get_active_admin_tab());
    }

    /**
     * Set the browser tab title to include the current plugin tab name.
     *
     * @param string $admin_title The current admin title.
     * @param string $title       The page title.
     * @return string Modified admin title.
     */
    public function set_admin_page_title($admin_title, $title)
    {
        if (!isset($_GET["page"]) || $_GET["page"] !== "ai-chat-wp") {
            return $admin_title;
        }

        $active_tab = $this->get_active_admin_tab();
        $tab_labels = [
            "stats"      => __("Dashboard", "ai-chat-wp"),
            "ai-chat"    => __("Settings", "ai-chat-wp"),
            "database"   => __("Data Training", "ai-chat-wp"),
        ];

        $tab_label = isset($tab_labels[$active_tab])
            ? $tab_labels[$active_tab]
            : $active_tab;

        // Allow extensions to override the label.
        $tab_label = apply_filters(
            "aicwp_admin_tab_title",
            $tab_label,
            $active_tab
        );

        $page_title = "AI Chat for WordPress";
        if ($tab_label && $tab_label !== $active_tab) {
            $page_title .= " \u{2039} " . $tab_label;
        }

        // Only replace $title when it appears at the very start of $admin_title.
        // This avoids accidentally replacing text inside the site name.
        if (strpos($admin_title, $title) === 0) {
            return $page_title . substr($admin_title, strlen($title));
        }

        return $admin_title;
    }

    /**
     * Initialize admin settings
     *
     * REFACTORED: Now uses centralized settings registry
     */
    public function admin_init()
    {
        // Register settings with explicit capability for multisite compatibility
        $settings_args = [
            "type" => "string",
            "sanitize_callback" => null, // We handle sanitization in AJAX handler
            "default" => null,
        ];

        // Register all settings from the central registry
        foreach ($this->get_all_setting_keys() as $setting_key) {
            register_setting(
                "aicwp_settings",
                $setting_key,
                $settings_args,
            );
        }

        // Add allowed_options filter for multisite compatibility
        add_filter("allowed_options", [$this, "add_allowed_options"]);
    }

    /**
     * Add plugin options to allowed options list for multisite compatibility
     *
     * REFACTORED: Now uses centralized settings registry
     *
     * @param array $allowed_options Array of allowed options
     * @return array Modified array of allowed options
     */
    public function add_allowed_options($allowed_options)
    {
        $allowed_options[
            "aicwp_settings"
        ] = $this->get_all_setting_keys();
        return $allowed_options;
    }

    /**
     * Add hidden fields to preserve other settings when submitting a form
     *
     * REFACTORED: Now uses centralized settings registry
     *
     * @param array $exclude_fields Array of field names to exclude from hidden fields
     */
    private function add_hidden_fields_except($exclude_fields = [])
    {
        // Allow extensions to add their settings to the exclude list
        $exclude_fields = apply_filters(
            "aicwp_hidden_fields_except",
            $exclude_fields,
        );

        $all_settings = $this->get_all_setting_keys();

        foreach ($all_settings as $setting) {
            if (!in_array($setting, $exclude_fields)) {
                $value = get_option($setting);
                if ($value !== false && $value !== "") {
                    // Handle different input types
                    if (is_array($value)) {
                        foreach ($value as $key => $sub_value) {
                            // Handle nested arrays (like quick buttons)
                            if (is_array($sub_value)) {
                                foreach (
                                    $sub_value
                                    as $sub_key => $sub_sub_value
                                ) {
                                    if (!is_array($sub_sub_value)) {
                                        echo '<input type="hidden" name="' .
                                            esc_attr($setting) .
                                            "[" .
                                            esc_attr($key) .
                                            "][" .
                                            esc_attr($sub_key) .
                                            ']" value="' .
                                            esc_attr($sub_sub_value) .
                                            '">';
                                    }
                                }
                            } else {
                                echo '<input type="hidden" name="' .
                                    esc_attr($setting) .
                                    "[" .
                                    esc_attr($key) .
                                    ']" value="' .
                                    esc_attr($sub_value) .
                                    '">';
                            }
                        }
                    } else {
                        echo '<input type="hidden" name="' .
                            esc_attr($setting) .
                            '" value="' .
                            esc_attr($value) .
                            '">';
                    }
                }
            }
        }
    }

    /**
     * AJAX handler for saving settings
     */
    public function ajax_save_settings()
    {
        // Debug logging for multisite
        if (
            is_multisite() &&
            get_option("aicwp_debug_mode", false)
        ) {
            error_log("[AI Chat Multisite Debug] AJAX save settings called");
            error_log(
                "[AI Chat Multisite Debug] Blog ID: " . get_current_blog_id(),
            );
            error_log(
                "[AI Chat Multisite Debug] User ID: " . get_current_user_id(),
            );
            error_log(
                "[AI Chat Multisite Debug] User can manage_options: " .
                    (current_user_can("manage_options") ? "yes" : "no"),
            );
        }

        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get section being saved (to properly handle unchecked checkboxes)
        $section = isset($_POST["section"])
            ? sanitize_text_field($_POST["section"])
            : "";

        // REFACTORED: Get settings mapping from central registry
        $section_settings = $this->get_section_settings();
        $section_checkboxes = $this->get_section_checkboxes();
        $all_settings = $this->get_all_setting_keys();

        $updated_settings = [];

        // Handle unchecked checkboxes for current section
        // Special handling for combined sections
        $sections_to_check = [];
        if ($section === "settings-config") {
            // Settings tab combines all these sections
            $sections_to_check = [
                "api-config",
                "developer-debug",
                "processing",
            ];
        } elseif ($section === "ai-chat-config") {
            // AI Chat tab now includes former Settings sections
            $sections_to_check = [
                "ai-chat-config",
                "api-config",
                "developer-debug",
                "processing",
                "quality-thresholds",
                "search-suggestions",
            ];
        } elseif ($section && isset($section_checkboxes[$section])) {
            $sections_to_check = [$section];
        }

        foreach ($sections_to_check as $check_section) {
            if (isset($section_checkboxes[$check_section])) {
                foreach (
                    $section_checkboxes[$check_section]
                    as $checkbox_field
                ) {
                    if (!isset($_POST[$checkbox_field])) {
                        // Checkbox not in POST = unchecked, set to 0
                        update_option($checkbox_field, 0);
                        $updated_settings[$checkbox_field] = 0;
                    }
                }
            }
        }

        // Handle array checkbox fields (like page exclusion lists)
        // These are type='array' but behave like checkboxes and need special handling
        if ($section === "ai-chat-config") {
            if (!isset($_POST["aicwp_floating_excluded_pages"])) {
                // No checkboxes selected = empty array
                update_option("aicwp_floating_excluded_pages", []);
                $updated_settings["aicwp_floating_excluded_pages"] = [];
            }
            // Handle blocked IPs - if all IPs removed, save empty array
            if (!isset($_POST["aicwp_chat_blocked_ips"])) {
                update_option("aicwp_chat_blocked_ips", []);
                $updated_settings["aicwp_chat_blocked_ips"] = [];
            }
            // Handle pre-chat fields - if all fields removed, save empty array
            if (!isset($_POST["aicwp_chat_pre_chat_fields"])) {
                update_option("aicwp_chat_pre_chat_fields", []);
                $updated_settings["aicwp_chat_pre_chat_fields"] = [];
            }
        }

        foreach ($this->get_secret_setting_keys() as $secret_setting) {
            $remove_flag = $secret_setting . "_remove";
            $posted_value = isset($_POST[$secret_setting])
                ? $this->sanitize_setting(
                    $secret_setting,
                    $_POST[$secret_setting],
                )
                : "";

            if (!empty($_POST[$remove_flag]) && $posted_value === "") {
                update_option($secret_setting, "");
                $updated_settings[$secret_setting] = "";
            }
        }

        // Process each setting
        foreach ($all_settings as $setting) {
            if (isset($_POST[$setting])) {
                // REFACTORED: Use centralized sanitization from registry
                $value = $this->sanitize_setting($setting, $_POST[$setting]);

                if (
                    in_array($setting, $this->get_secret_setting_keys(), true)
                ) {
                    $remove_flag = $setting . "_remove";

                    if (
                        $value === "" ||
                        (!empty($_POST[$remove_flag]) && $value === "")
                    ) {
                        continue;
                    }
                }

                // Update the option
                $update_result = update_option($setting, $value);

                // Debug logging for multisite
                if (
                    is_multisite() &&
                    get_option("aicwp_debug_mode", false)
                ) {
                    error_log(
                        sprintf(
                            "[AI Chat Multisite Debug] update_option(%s) result: %s",
                            $setting,
                            $update_result ? "success" : "failed",
                        ),
                    );
                }

                $updated_settings[$setting] = $value;

                // Auto-update model when provider changes
                if ($setting === "aicwp_provider") {
                    $current_model = get_option("aicwp_chat_model", "");
                    $openai_models = [
                        "gpt-4o-mini",
                        "gpt-4o",
                        "gpt-4.1-nano",
                        "gpt-4.1-mini",
                        "gpt-4.1",
                        "gpt-5-mini",
                        "gpt-5-chat-latest",
                        "gpt-5.1",
                        "gpt-5.2",
                        "gpt-5.3-chat-latest",
                        "gpt-5.4",
                        "gpt-5.4-mini",
                        "gpt-5.4-nano",
                        "gpt-5.5",
                    ];
                    $gemini_models = [
                        "gemini-2.5-flash",
                        "gemini-2.5-pro",
                        "gemini-3.1-pro-preview",
                        "gemini-3-flash-preview",
                        "gemini-3.1-flash-lite-preview",
                    ];
                    $mistral_models = [
                        "mistral-small-latest",
                        "mistral-medium-latest",
                        "mistral-medium-3.5",
                        "mistral-large-latest",
                    ];
                    // OpenRouter models use a vendor/model namespaced format. Any slug with a '/' is considered
                    // valid so we don't need to maintain an allowlist of 300+ models.
                    $is_openrouter_model =
                        is_string($current_model) &&
                        strpos($current_model, "/") !== false;

                    // If switching to OpenAI and current model is not an OpenAI model
                    if (
                        $value === "openai" &&
                        !in_array($current_model, $openai_models)
                    ) {
                        update_option("aicwp_chat_model", "gpt-5.4-mini");
                        $updated_settings["aicwp_chat_model"] =
                            "gpt-5.4-mini";
                    }
                    // If switching to Gemini and current model is not a Gemini model
                    elseif (
                        $value === "gemini" &&
                        !in_array($current_model, $gemini_models)
                    ) {
                        update_option(
                            "aicwp_chat_model",
                            "gemini-3-flash-preview",
                        );
                        $updated_settings["aicwp_chat_model"] =
                            "gemini-3-flash-preview";
                    }
                    // If switching to Mistral and current model is not a Mistral model
                    elseif (
                        $value === "mistral" &&
                        !in_array($current_model, $mistral_models)
                    ) {
                        update_option(
                            "aicwp_chat_model",
                            "mistral-large-latest",
                        );
                        $updated_settings["aicwp_chat_model"] =
                            "mistral-large-latest";
                    }
                    // If switching to OpenRouter and current model is not an OpenRouter (namespaced) model
                    elseif ($value === "openrouter" && !$is_openrouter_model) {
                        update_option(
                            "aicwp_chat_model",
                            "openai/gpt-5.4-mini",
                        );
                        $updated_settings["aicwp_chat_model"] =
                            "openai/gpt-5.4-mini";
                    }
                }
            }
            // NOTE: Do NOT set missing settings to 0
            // If a setting is not in POST, it means it's from a different tab/form
            // Leave those settings unchanged in the database
        }

        // Debug logging for multisite
        if (
            is_multisite() &&
            get_option("aicwp_debug_mode", false)
        ) {
            error_log(
                "[AI Chat Multisite Debug] Total settings updated: " .
                    count($updated_settings),
            );
        }

        // Cleanup: Ensure all checkbox values are proper integers (not strings)
        $this->cleanup_checkbox_values();

        // Ensure default contact form examples are stored if not set
        if (get_option("aicwp_contact_form_examples") === false) {
            $default_examples =
                "EXAMPLES OF WHEN TO USE:\n- \"Can you send a message to the site owner for me?\"\n- \"I want to contact support about X\"\n- \"Please send them my inquiry about Y\"\n\nEXAMPLES OF WHEN NOT TO USE:\n- \"How can I contact you?\" (just provide contact info)\n- \"What's your email?\" (just provide info, don't send)";
            add_option("aicwp_contact_form_examples", $default_examples);
        }

        // Handle chat history table creation/deletion based on setting
        $history_enabled = get_option("aicwp_chat_history_enabled", 0);
        if ($history_enabled && class_exists("AICWP_Chat_History")) {
            // Create table if it doesn't exist
            AICWP_Chat_History::create_table();
        }

        wp_send_json_success([
            "message" => __("Settings saved successfully!", "ai-chat-wp"),
            "updated_settings" => $updated_settings,
        ]);
    }

    /**
     * Cleanup corrupted checkbox values (convert string '0' to integer 0)
     *
     * REFACTORED: Now uses centralized settings registry
     */
    private function cleanup_checkbox_values()
    {
        // Get all checkbox fields from the central registry
        $checkbox_fields = $this->get_checkbox_fields();

        foreach ($checkbox_fields as $field) {
            $value = get_option($field);
            // Convert string '0' or '1' to proper integers
            if (
                $value === "0" ||
                $value === 0 ||
                $value === false ||
                $value === "false"
            ) {
                update_option($field, 0);
            } elseif (
                $value === "1" ||
                $value === 1 ||
                $value === true ||
                $value === "true"
            ) {
                update_option($field, 1);
            }
        }
    }

    /**
     * AJAX handler for testing API key
     */
    public function ajax_test_api_key()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get API key from POST data or current settings
        $api_key = isset($_POST["api_key"])
            ? sanitize_text_field(wp_unslash($_POST["api_key"]))
            : "";
        if ($api_key === "") {
            $api_key = get_option("aicwp_api_key", "");
        }

        if (empty($api_key)) {
            wp_send_json_error([
                "message" => __(
                    "Please enter an API key first.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        try {
            // Test the API key by making a simple request
            $response = wp_remote_get("https://api.openai.com/v1/models", [
                "headers" => [
                    "Authorization" => "Bearer " . $api_key,
                    "Content-Type" => "application/json",
                ],
                "timeout" => 15,
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    "message" =>
                        __("Connection failed: ", "ai-chat-wp") .
                        $response->get_error_message(),
                ]);
                return;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                wp_send_json_success([
                    "message" => __("✅ API key is valid!", "ai-chat-wp"),
                ]);
            } elseif ($response_code === 401) {
                wp_send_json_error([
                    "message" => __(
                        "❌ Invalid API key. Please check your key and try again.",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 429) {
                wp_send_json_error([
                    "message" => __(
                        "⚠️ API key valid but rate limit exceeded. Try again in a moment.",
                        "ai-chat-wp",
                    ),
                ]);
            } else {
                $error_body = json_decode($response_body, true);
                $error_message = isset($error_body["error"]["message"])
                    ? $error_body["error"]["message"]
                    : __("Unknown error", "ai-chat-wp");
                wp_send_json_error([
                    "message" => sprintf(
                        __("❌ API Error (%d): %s", "ai-chat-wp"),
                        $response_code,
                        $error_message,
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                "message" =>
                    __("❌ Test failed: ", "ai-chat-wp") . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for testing Gemini API key
     */
    public function ajax_test_gemini_api_key()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get API key from POST data or current settings
        $api_key = isset($_POST["api_key"])
            ? sanitize_text_field(wp_unslash($_POST["api_key"]))
            : "";
        if ($api_key === "") {
            $api_key = get_option("aicwp_gemini_api_key", "");
        }

        if (empty($api_key)) {
            wp_send_json_error([
                "message" => __(
                    "Please enter a Gemini API key first.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        try {
            // Test the API key by making a simple embedding request (smallest possible test)
            $test_endpoint =
                "https://generativelanguage.googleapis.com/v1beta/openai/embeddings";

            $response = wp_remote_post($test_endpoint, [
                "headers" => [
                    "Authorization" => "Bearer " . $api_key,
                    "Content-Type" => "application/json",
                ],
                "body" => json_encode([
                    "model" => "gemini-embedding-001",
                    "input" => "test",
                    "dimensions" => 1536,
                ]),
                "timeout" => 15,
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    "message" =>
                        __("Connection failed: ", "ai-chat-wp") .
                        $response->get_error_message(),
                ]);
                return;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                wp_send_json_success([
                    "message" => __(
                        "✅ Gemini API key is valid!",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 401 || $response_code === 403) {
                wp_send_json_error([
                    "message" => __(
                        "❌ Invalid Gemini API key. Please check your key and try again.",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 429) {
                wp_send_json_error([
                    "message" => __(
                        "⚠️ API key valid but rate limit exceeded. Try again in a moment.",
                        "ai-chat-wp",
                    ),
                ]);
            } else {
                $error_body = json_decode($response_body, true);
                $error_message = isset($error_body["error"]["message"])
                    ? $error_body["error"]["message"]
                    : __("Unknown error", "ai-chat-wp");
                wp_send_json_error([
                    "message" => sprintf(
                        __("❌ Gemini API Error (%d): %s", "ai-chat-wp"),
                        $response_code,
                        $error_message,
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                "message" =>
                    __("❌ Test failed: ", "ai-chat-wp") . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for testing Mistral API key
     */
    public function ajax_test_mistral_api_key()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get API key from POST data or current settings
        $api_key = isset($_POST["api_key"])
            ? sanitize_text_field(wp_unslash($_POST["api_key"]))
            : "";
        if ($api_key === "") {
            $api_key = get_option("aicwp_mistral_api_key", "");
        }

        if (empty($api_key)) {
            wp_send_json_error([
                "message" => __(
                    "Please enter a Mistral API key first.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        try {
            // Test the API key by making a simple models list request
            $test_endpoint = "https://api.mistral.ai/v1/models";

            $response = wp_remote_get($test_endpoint, [
                "headers" => [
                    "Authorization" => "Bearer " . $api_key,
                    "Content-Type" => "application/json",
                ],
                "timeout" => 15,
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    "message" =>
                        __("Connection failed: ", "ai-chat-wp") .
                        $response->get_error_message(),
                ]);
                return;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                wp_send_json_success([
                    "message" => __(
                        "✅ Mistral API key is valid!",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 401 || $response_code === 403) {
                wp_send_json_error([
                    "message" => __(
                        "❌ Invalid Mistral API key. Please check your key and try again.",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 429) {
                wp_send_json_error([
                    "message" => __(
                        "⚠️ API key valid but rate limit exceeded. Try again in a moment.",
                        "ai-chat-wp",
                    ),
                ]);
            } else {
                $error_body = json_decode($response_body, true);
                $error_message = isset($error_body["message"])
                    ? $error_body["message"]
                    : __("Unknown error", "ai-chat-wp");
                wp_send_json_error([
                    "message" => sprintf(
                        __("❌ Mistral API Error (%d): %s", "ai-chat-wp"),
                        $response_code,
                        $error_message,
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                "message" =>
                    __("❌ Test failed: ", "ai-chat-wp") . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for testing OpenRouter API key
     */
    public function ajax_test_openrouter_api_key()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get API key from POST data or current settings
        $api_key = isset($_POST["api_key"])
            ? sanitize_text_field(wp_unslash($_POST["api_key"]))
            : "";
        if ($api_key === "") {
            $api_key = get_option("aicwp_openrouter_api_key", "");
        }

        if (empty($api_key)) {
            wp_send_json_error([
                "message" => __(
                    "Please enter an OpenRouter API key first.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        try {
            // Test the API key by making a simple models list request
            $test_endpoint = "https://openrouter.ai/api/v1/models";

            $response = wp_remote_get($test_endpoint, [
                "headers" => [
                    "Authorization" => "Bearer " . $api_key,
                    "Content-Type" => "application/json",
                ],
                "timeout" => 15,
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error([
                    "message" =>
                        __("Connection failed: ", "ai-chat-wp") .
                        $response->get_error_message(),
                ]);
                return;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                wp_send_json_success([
                    "message" => __(
                        "✅ OpenRouter API key is valid!",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 401 || $response_code === 403) {
                wp_send_json_error([
                    "message" => __(
                        "❌ Invalid OpenRouter API key. Please check your key and try again.",
                        "ai-chat-wp",
                    ),
                ]);
            } elseif ($response_code === 429) {
                wp_send_json_error([
                    "message" => __(
                        "⚠️ API key valid but rate limit exceeded. Try again in a moment.",
                        "ai-chat-wp",
                    ),
                ]);
            } else {
                $error_body = json_decode($response_body, true);
                $error_message = isset($error_body["error"]["message"])
                    ? $error_body["error"]["message"]
                    : (isset($error_body["message"])
                        ? $error_body["message"]
                        : __("Unknown error", "ai-chat-wp"));
                wp_send_json_error([
                    "message" => sprintf(
                        __(
                            "❌ OpenRouter API Error (%d): %s",
                            "ai-chat-wp",
                        ),
                        $response_code,
                        $error_message,
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                "message" =>
                    __("❌ Test failed: ", "ai-chat-wp") . $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for clearing cache
     */
    public function ajax_clear_cache()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        try {
            $cleared_count = 0;
            $cleared_types = [];

            // Clear API health cache
            if (delete_transient("aicwp_api_health")) {
                $cleared_count++;
                $cleared_types[] = __("API health status", "ai-chat-wp");
            }

            // Clear global rate limit (current hour)
            $rate_limit_key = "aicwp_rate_limit_" . date("Y-m-d-H");
            if (delete_option($rate_limit_key)) {
                $cleared_count++;
                $cleared_types[] = __("global rate limit", "ai-chat-wp");
            }

            // Clean up old global rate limit option rows
            global $wpdb;
            $old_rate_limits = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name < %s AND option_name LIKE 'aicwp_rate_limit_%%'",
                    $rate_limit_key,
                ),
            );
            if ($old_rate_limits) {
                $cleared_count += $old_rate_limits;
            }

            // Clear usage tracking cache (current hour)
            $usage_key = "aicwp_usage_" . date("Y-m-d-H");
            if (delete_transient($usage_key)) {
                $cleared_count++;
                $cleared_types[] = __("usage tracking", "ai-chat-wp");
            }

            // Clear processing delay cache
            $processing_keys = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_aicwp_processing_delay_%'",
            );
            foreach ($processing_keys as $transient_name) {
                $key = str_replace("_transient_", "", $transient_name);
                if (delete_transient($key)) {
                    $cleared_count++;
                }
            }
            if (count($processing_keys) > 0) {
                $cleared_types[] = sprintf(
                    __("processing delays (%d)", "ai-chat-wp"),
                    count($processing_keys),
                );
            }

            $message =
                $cleared_count > 0
                    ? sprintf(
                        __("✅ Cleared %d cache entries: %s", "ai-chat-wp"),
                        $cleared_count,
                        implode(", ", $cleared_types),
                    )
                    : __(
                        "ℹ️ No cache entries found to clear.",
                        "ai-chat-wp",
                    );

            wp_send_json_success(["message" => $message]);
        } catch (Exception $e) {
            wp_send_json_error([
                "message" =>
                    __("❌ Clear cache failed: ", "ai-chat-wp") .
                    $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for clearing IP-based rate limit transients
     */
    public function ajax_clear_ip_rate_limits()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        try {
            global $wpdb;

            // Find and delete all IP rate limit transients (pattern: ai_chat_ip_*)
            $ip_transients = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_ai_chat_ip_%'",
            );

            $cleared_count = 0;
            foreach ($ip_transients as $transient_name) {
                $key = str_replace("_transient_", "", $transient_name);
                if (delete_transient($key)) {
                    $cleared_count++;
                }
            }

            if ($cleared_count > 0) {
                wp_send_json_success([
                    "message" => sprintf(
                        __("Cleared %d IP rate limits.", "ai-chat-wp"),
                        $cleared_count,
                    ),
                ]);
            } else {
                wp_send_json_success([
                    "message" => __(
                        "No IP rate limits to clear.",
                        "ai-chat-wp",
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                "message" =>
                    __("Failed to clear IP rate limits: ", "ai-chat-wp") .
                    $e->getMessage(),
            ]);
        }
    }

    /**
     * AJAX handler for toggling auto-training on save
     */
    public function ajax_toggle_auto_training()
    {
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $disabled = filter_var(
            $_POST["disabled"] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        update_option("aicwp_disable_auto_training", $disabled);

        wp_send_json_success();
    }

    /**
     * AJAX handler for toggling search analytics
     */
    public function ajax_toggle_search_analytics()
    {
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $enabled = filter_var(
            $_POST["enabled"] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        update_option("aicwp_enable_analytics", $enabled ? 1 : 0);

        wp_send_json_success();
    }

    /**
     * AJAX handler for toggling custom fields inclusion in embeddings
     */
    public function ajax_toggle_custom_fields()
    {
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $disabled = filter_var(
            $_POST["disabled"] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        update_option("aicwp_disable_custom_fields", $disabled);

        wp_send_json_success();
    }

    /**
     * AJAX handler for creating missing database tables and columns
     */
    public function ajax_create_missing_tables()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        global $wpdb;
        $created_tables = [];
        $failed_tables = [];
        $upgraded_columns = [];

        // Define required tables and their creation methods
        $required_tables = [
            "aicwp_embeddings" => [
                "class" => "AICWP_Database_Manager",
                "method" => "create_table",
            ],
            "aicwp_chat_history" => [
                "class" => "AICWP_Chat_History",
                "method" => "create_table",
            ],
            "aicwp_contact_messages" => [
                "class" => "AICWP_Contact_Messages",
                "method" => "create_table",
            ],
        ];

        foreach ($required_tables as $table_suffix => $table_info) {
            $full_table_name = $wpdb->prefix . $table_suffix;

            // Check if table already exists
            $table_exists =
                $wpdb->get_var(
                    $wpdb->prepare("SHOW TABLES LIKE %s", $full_table_name),
                ) === $full_table_name;

            if (!$table_exists) {
                // Try to create the table
                if (
                    class_exists($table_info["class"]) &&
                    method_exists($table_info["class"], $table_info["method"])
                ) {
                    $result = call_user_func([
                        $table_info["class"],
                        $table_info["method"],
                    ]);

                    // Verify table was created
                    $table_now_exists =
                        $wpdb->get_var(
                            $wpdb->prepare(
                                "SHOW TABLES LIKE %s",
                                $full_table_name,
                            ),
                        ) === $full_table_name;

                    if ($table_now_exists) {
                        $created_tables[] = $table_suffix;
                    } else {
                        $failed_tables[] =
                            $table_suffix .
                            " (" .
                            __("creation failed", "ai-chat-wp") .
                            ")";
                    }
                } else {
                    $failed_tables[] =
                        $table_suffix .
                        " (" .
                        __("class/method not found", "ai-chat-wp") .
                        ")";
                }
            }
        }

        // Run column migrations for chat_history table
        $chat_history_table = $wpdb->prefix . "aicwp_chat_history";
        if (
            $wpdb->get_var("SHOW TABLES LIKE '{$chat_history_table}'") ===
            $chat_history_table
        ) {
            // Check and add ip_address column if missing
            $ip_column = $wpdb->get_results(
                "SHOW COLUMNS FROM {$chat_history_table} LIKE 'ip_address'",
            );
            if (empty($ip_column)) {
                $wpdb->query(
                    "ALTER TABLE {$chat_history_table} ADD COLUMN ip_address varchar(45) DEFAULT NULL AFTER user_id",
                );
                $wpdb->query(
                    "ALTER TABLE {$chat_history_table} ADD KEY ip_address (ip_address)",
                );
                $upgraded_columns[] = "ip_address";
            }
        }

        // Build response message
        $messages = [];
        if (!empty($created_tables)) {
            $messages[] = sprintf(
                __("Created tables: %s", "ai-chat-wp"),
                implode(", ", $created_tables),
            );
        }
        if (!empty($upgraded_columns)) {
            $messages[] = sprintf(
                __("Added columns: %s", "ai-chat-wp"),
                implode(", ", $upgraded_columns),
            );
        }
        if (!empty($failed_tables)) {
            $messages[] = sprintf(
                __("Failed: %s", "ai-chat-wp"),
                implode(", ", $failed_tables),
            );
        }

        if (!empty($failed_tables)) {
            wp_send_json_error([
                "message" => "❌ " . implode(". ", $messages),
                "created" => $created_tables,
                "upgraded" => $upgraded_columns,
                "failed" => $failed_tables,
            ]);
        } elseif (!empty($created_tables) || !empty($upgraded_columns)) {
            wp_send_json_success([
                "message" =>
                    "✅ " .
                    implode(". ", $messages) .
                    ". " .
                    __("Please refresh the page.", "ai-chat-wp"),
                "created" => $created_tables,
                "upgraded" => $upgraded_columns,
            ]);
        } else {
            wp_send_json_success([
                "message" => __(
                    "ℹ️ Database is already up to date.",
                    "ai-chat-wp",
                ),
                "created" => [],
                "upgraded" => [],
            ]);
        }
    }

    /**
     * AJAX handler for regenerating single embedding
     */
    public function ajax_regenerate_embedding()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get post ID
        $post_id = absint($_POST["listing_id"] ?? 0);
        if (!$post_id) {
            wp_send_json_error([
                "message" => __(
                    "Please enter a valid post ID.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error([
                "message" => sprintf(
                    __("Post ID %d not found.", "ai-chat-wp"),
                    $post_id,
                ),
            ]);
            return;
        }

        // Check if post type is enabled
        $enabled_post_types = AICWP_Database_Manager::get_enabled_post_types();
        if (!in_array($post->post_type, $enabled_post_types)) {
            wp_send_json_error([
                "message" => sprintf(
                    __(
                        'Post type "%s" is not enabled for AI search. Enable it in Universal Settings first.',
                        "ai-chat-wp",
                    ),
                    $post->post_type,
                ),
            ]);
            return;
        }

        try {
            // Check if API key is configured (provider-aware for OpenAI/Gemini)
            $provider = new AICWP_Provider();
            $api_key = $provider->get_api_key();
            if (empty($api_key)) {
                wp_send_json_error([
                    "message" => sprintf(
                        __(
                            "%s API key is not configured. Please configure it in Settings first.",
                            "ai-chat-wp",
                        ),
                        $provider->get_provider_name(),
                    ),
                ]);
                return;
            }

            // Regenerate the embedding
            $result = AICWP_Database_Manager::generate_single_embedding(
                $post_id,
            );

            if ($result["success"]) {
                $message = sprintf(
                    __(
                        '✅ Embedding regenerated successfully for "%s" (ID: %d, Type: %s). Processed %d characters.',
                        "ai-chat-wp",
                    ),
                    esc_html($post->post_title),
                    $post_id,
                    $post->post_type,
                    $result["chars_processed"] ?? 0,
                );

                wp_send_json_success([
                    "message" => $message,
                    "post_title" => $post->post_title,
                    "post_id" => $post_id,
                    "post_type" => $post->post_type,
                    "chars_processed" => $result["chars_processed"] ?? 0,
                    "embedding_dimensions" =>
                        $result["embedding_dimensions"] ?? 0,
                ]);
            } else {
                wp_send_json_error([
                    "message" => sprintf(
                        __(
                            '❌ Failed to regenerate embedding for "%s" (ID: %d): %s',
                            "ai-chat-wp",
                        ),
                        esc_html($post->post_title),
                        $post_id,
                        $result["error"] ??
                            __("Unknown error", "ai-chat-wp"),
                    ),
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error([
                "message" => sprintf(
                    __(
                        "❌ Error regenerating embedding for post ID %d: %s",
                        "ai-chat-wp",
                    ),
                    $post_id,
                    $e->getMessage(),
                ),
            ]);
        }
    }

    /**
     * AJAX handler for searching embeddings
     */
    public function ajax_search_embeddings()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Get search term
        $search_term = isset($_POST["search_term"])
            ? sanitize_text_field($_POST["search_term"])
            : "";
        if (empty($search_term)) {
            wp_send_json_error([
                "message" => __(
                    "Please enter a search term.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        try {
            $results = AICWP_Database_Manager::search_embeddings(
                $search_term,
                20,
            );

            wp_send_json_success([
                "results" => $results,
                "count" => count($results),
                "search_term" => $search_term,
            ]);
        } catch (Exception $e) {
            wp_send_json_error([
                "message" => sprintf(
                    __("Error searching embeddings: %s", "ai-chat-wp"),
                    $e->getMessage(),
                ),
            ]);
        }
    }

    /**
     * AJAX handler: Search posts for reference picker in system prompt
     */
    public function ajax_search_posts_for_reference()
    {
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $search = isset($_POST["search"])
            ? sanitize_text_field($_POST["search"])
            : "";
        if (mb_strlen($search) < 2) {
            wp_send_json_success(["results" => []]);
            return;
        }

        global $wpdb;

        // Include all enabled post types + document types, exclude products (they have dedicated tools)
        $enabled_types = [];
        if (class_exists("AICWP_Database_Manager")) {
            $enabled_types = AICWP_Database_Manager::get_enabled_post_types();
        }
        $enabled_types = array_diff($enabled_types, ["product"]);
        // Always include document types
        $all_types = array_unique(
            array_merge($enabled_types, [
                "ai_pdf_document",
                "ai_external_page",
            ]),
        );

        if (empty($all_types)) {
            wp_send_json_success(["results" => []]);
            return;
        }

        $types_placeholders = implode(
            ",",
            array_fill(0, count($all_types), "%s"),
        );
        $like = "%" . $wpdb->esc_like($search) . "%";

        $query = $wpdb->prepare(
            "SELECT ID, post_title, post_type FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ($types_placeholders)
             AND post_title LIKE %s
             ORDER BY post_title ASC
             LIMIT 10",
            array_merge($all_types, [$like]),
        );

        $posts = $wpdb->get_results($query);
        $results = [];

        foreach ($posts as $post) {
            $type_label = $post->post_type;
            if ($post->post_type === "ai_pdf_document") {
                $type_label = "PDF";
            } elseif ($post->post_type === "ai_external_page") {
                $type_label = "External Page";
            } else {
                $type_obj = get_post_type_object($post->post_type);
                $type_label =
                    $type_obj && isset($type_obj->labels->singular_name)
                        ? $type_obj->labels->singular_name
                        : ucfirst($post->post_type);
            }

            $results[] = [
                "id" => $post->ID,
                "title" => html_entity_decode(
                    wp_strip_all_tags($post->post_title),
                    ENT_QUOTES,
                    "UTF-8",
                ),
                "type" => $type_label,
            ];
        }

        wp_send_json_success(["results" => $results]);
    }

    /**
     * AJAX handler: Add a knowledge source
     */
    public function ajax_add_knowledge_source()
    {
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $topic = isset($_POST["topic"])
            ? sanitize_text_field($_POST["topic"])
            : "";
        $post_id = isset($_POST["post_id"]) ? intval($_POST["post_id"]) : 0;
        $post_title = isset($_POST["post_title"])
            ? sanitize_text_field($_POST["post_title"])
            : "";

        if (empty($topic) || $post_id <= 0) {
            wp_send_json_error([
                "message" => __(
                    "Topic and post are required.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        $sources = get_option("aicwp_knowledge_sources", []);
        if (!is_array($sources)) {
            $sources = [];
        }

        $sources[] = [
            "topic" => $topic,
            "post_id" => $post_id,
            "post_title" => $post_title,
        ];

        update_option("aicwp_knowledge_sources", $sources);

        wp_send_json_success(["sources" => $sources]);
    }

    /**
     * AJAX handler: Delete a knowledge source by index
     */
    public function ajax_delete_knowledge_source()
    {
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $index = isset($_POST["index"]) ? intval($_POST["index"]) : -1;

        $sources = get_option("aicwp_knowledge_sources", []);
        if (!is_array($sources) || !isset($sources[$index])) {
            wp_send_json_error([
                "message" => __("Source not found.", "ai-chat-wp"),
            ]);
            return;
        }

        array_splice($sources, $index, 1);
        update_option("aicwp_knowledge_sources", $sources);

        wp_send_json_success(["sources" => $sources]);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on our settings page (updated hook for top-level menu)
        if ($hook !== "toplevel_page_ai-chat-wp") {
            return;
        }

        // Enqueue Outfit font
        wp_enqueue_style(
            "outfit-font",
            "https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap",
            [],
            null,
        );

        // Enqueue CSS
        wp_enqueue_style(
            "ai-chat-wp-admin",
            AICWP_PLUGIN_URL . "assets/css/admin-ui-styles.css",
            ["outfit-font"],
            AICWP_VERSION,
        );

        // Enqueue jQuery (it should already be loaded, but just to be sure)
        wp_enqueue_script("jquery");

        // Enqueue WordPress media uploader (for custom icon upload)
        wp_enqueue_media();

        // Enqueue WordPress color picker
        wp_enqueue_style("wp-color-picker");
        wp_enqueue_script("wp-color-picker");

        // Get the admin AJAX URL (multisite compatible)
        $admin_ajax_url = get_admin_url(
            get_current_blog_id(),
            "admin-ajax.php",
        );

        // Get current embedding count for provider switch detection (lightweight query only)
        global $wpdb;
        $embeddings_table = AICWP_Database_Manager::get_embeddings_table_name();
        $total_embeddings = 0;
        if (
            $wpdb->get_var("SHOW TABLES LIKE '{$embeddings_table}'") ===
            $embeddings_table
        ) {
            $total_embeddings = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$embeddings_table}",
            );
        }

        // Enqueue modular admin JavaScript files
        $js_version = AICWP_VERSION;
        $js_base_url = AICWP_PLUGIN_URL . "assets/js/admin/";

        // Core module (must be loaded first)
        wp_enqueue_script(
            "aicwp-ui-admin-core",
            $js_base_url . "admin-core.js",
            ["jquery"],
            $js_version,
            true,
        );

        // Settings module (provider toggle, API tests, forms)
        wp_enqueue_script(
            "aicwp-ui-admin-settings",
            $js_base_url . "ai-admin-settings.js",
            ["jquery", "aicwp-ui-admin-core"],
            $js_version,
            true,
        );

        // Embeddings module (batch processing, viewer)
        wp_enqueue_script(
            "aicwp-ui-admin-embeddings",
            $js_base_url . "admin-ui-embeddings.js",
            ["jquery", "aicwp-ui-admin-core"],
            $js_version,
            true,
        );

        // Database module (status, actions, search)
        wp_enqueue_script(
            "aicwp-ui-admin-database",
            $js_base_url . "admin-database.js",
            ["jquery", "aicwp-ui-admin-core", "aicwp-ui-admin-embeddings"],
            $js_version,
            true,
        );

        // Media module (WordPress media uploader)
        wp_enqueue_script(
            "aicwp-ui-admin-media",
            $js_base_url . "admin-media.js",
            ["jquery", "aicwp-ui-admin-core"],
            $js_version,
            true,
        );

        // UI module (sticky footers, collapsibles, shortcode generator, etc.)
        wp_enqueue_script(
            "aicwp-ui-admin-ui",
            $js_base_url . "admin-ui-scripts.js",
            ["jquery", "aicwp-ui-admin-core"],
            $js_version,
            true,
        );

        // Silk wave renderer (shared with frontend, used for admin preview)
        wp_enqueue_script(
            "aicwp-silk-wave-bg",
            AICWP_PLUGIN_URL . "assets/js/silk-wave-bg.js",
            [],
            $js_version,
            true,
        );

        // Localize AJAX settings for the core module
        $provider = new AICWP_Provider();
        $has_api_key = !empty($provider->get_api_key());

        wp_localize_script("aicwp-ui-admin-core", "aicwp_ajax", [
            "ajax_url" => $admin_ajax_url,
            "nonce" => wp_create_nonce("aicwp_nonce"),
            "clear_embeddings_nonce" => wp_create_nonce(
                "aicwp_clear_embeddings",
            ),
            "test_email_nonce" => wp_create_nonce("aicwp_test_email"),
            "contact_form_nonce" => wp_create_nonce(
                "aicwp_contact_form_settings",
            ),
            "total_embeddings" => intval($total_embeddings),
            "has_api_key" => $has_api_key,
            "provider_name" => $provider->get_provider_name(),
            "current_provider" => $provider->get_provider(),
            "settings_url" => admin_url(
                "admin.php?page=ai-chat-wp&tab=ai-chat",
            ),
        ]);

        // Localize translations for all modules
        wp_localize_script("aicwp-ui-admin-core", "aicwp_i18n", [
            // General
            "success" => __("Success!", "ai-chat-wp"),
            "error" => __("Error!", "ai-chat-wp"),
            "loading" => __("Loading...", "ai-chat-wp"),
            "ajaxError" => __("AJAX error:", "ai-chat-wp"),
            "connectionFailed" => __("Connection failed:", "ai-chat-wp"),
            "ajaxErrorOccurred" => __("AJAX error occurred", "ai-chat-wp"),
            "ajaxConfigError" => __(
                "Error: AJAX configuration not loaded. Please refresh the page.",
                "ai-chat-wp",
            ),
            "noResults" => __("No results found.", "ai-chat-wp"),
            "whenUserAsksAbout" => __("When user asks about", "ai-chat-wp"),

            // Provider settings
            "providerRetrainNotice" => __(
                "Click Save and go to Data Training tab and start retraining after changing provider.",
                "ai-chat-wp",
            ),
            "errorClearingEmbeddings" => __(
                "Error clearing embeddings. Please try again.",
                "ai-chat-wp",
            ),
            "saved" => __("Saved", "ai-chat-wp"),
            "saveFailed" => __(
                "Failed to save embedding model.",
                "ai-chat-wp",
            ),
            "openaiModel" => __("OpenAI Model", "ai-chat-wp"),
            "openaiModelHelp" => __(
                "Select the OpenAI model for chat responses.",
                "ai-chat-wp",
            ),
            "geminiModel" => __("Gemini Model", "ai-chat-wp"),
            "geminiModelHelp" => __(
                "Select the Gemini model for chat responses.",
                "ai-chat-wp",
            ),
            "mistralModel" => __("Mistral Model", "ai-chat-wp"),
            "mistralModelHelp" => __(
                "Select the Mistral model for chat responses.",
                "ai-chat-wp",
            ),

            // API testing
            "enterApiKeyFirst" => __(
                "Please enter an API key first.",
                "ai-chat-wp",
            ),
            "testingConnection" => __(
                "Testing API connection...",
                "ai-chat-wp",
            ),
            "testFailed" => __("Test failed", "ai-chat-wp"),

            // Cache and rate limits
            "clearing" => __("Clearing...", "ai-chat-wp"),
            "clearCacheFailed" => __("Clear cache failed", "ai-chat-wp"),
            "failed" => __("Failed", "ai-chat-wp"),
            "creating" => __("Creating...", "ai-chat-wp"),
            "failedCreateTables" => __(
                "Failed to create tables",
                "ai-chat-wp",
            ),

            // Batch generation
            "startingGeneration" => __(
                "Starting structured embedding generation...",
                "ai-chat-wp",
            ),
            "stoppedByUser" => __(
                "Generation stopped by user.",
                "ai-chat-wp",
            ),
            "processingBatch" => __(
                "Processing batch starting at offset",
                "ai-chat-wp",
            ),
            "batchCompleted" => __("Batch completed:", "ai-chat-wp"),
            "itemsProcessed" => __("items processed.", "ai-chat-wp"),
            "progress" => __("Progress:", "ai-chat-wp"),
            "batchHadErrors" => __("Batch had", "ai-chat-wp"),
            "errors" => __("errors:", "ai-chat-wp"),
            "andMore" => __("and", "ai-chat-wp"),
            "moreErrors" => __("more errors", "ai-chat-wp"),
            "generationComplete" => __(
                "Generation completed successfully!",
                "ai-chat-wp",
            ),
            "batchFailed" => __("Batch failed:", "ai-chat-wp"),
            "generationFailed" => __("Generation failed.", "ai-chat-wp"),
            "connectionError" => __(
                "Generation failed due to connection error.",
                "ai-chat-wp",
            ),
            "apiKeyMissing" => __("API Key Not Configured", "ai-chat-wp"),
            "apiKeyMissingDesc" => __(
                "Please add your API key in the Settings tab before starting training.",
                "ai-chat-wp",
            ),
            "goToSettings" => __("Go to Settings", "ai-chat-wp"),

            // Single embedding
            "enterListingId" => __(
                "Please enter an item ID",
                "ai-chat-wp",
            ),
            "enterValidListingId" => __(
                "Please enter a valid item ID",
                "ai-chat-wp",
            ),
            "loadingEmbedding" => __(
                "Loading embedding data for item",
                "ai-chat-wp",
            ),
            "regenerationFailed" => __("Regeneration failed", "ai-chat-wp"),
            "generating" => __("Generating...", "ai-chat-wp"),
            "done" => __("Done", "ai-chat-wp"),
            "failedToGenerate" => __(
                "Failed to generate embedding:",
                "ai-chat-wp",
            ),

            // Embedding viewer
            "parent" => __("Parent", "ai-chat-wp"),
            "contentChunked" => __(
                "This content is chunked into",
                "ai-chat-wp",
            ),
            "partsForBetter" => __(
                "parts for better embedding quality",
                "ai-chat-wp",
            ),
            "words" => __("Words", "ai-chat-wp"),
            "characters" => __("Characters", "ai-chat-wp"),
            "embedding" => __("Embedding", "ai-chat-wp"),
            "created" => __("Created", "ai-chat-wp"),
            "yes" => __("Yes", "ai-chat-wp"),
            "no" => __("No", "ai-chat-wp"),
            "clickChunkId" => __(
                "Click on a chunk ID to view its embedding details.",
                "ai-chat-wp",
            ),
            "processedContent" => __("Processed Content", "ai-chat-wp"),
            "embeddingVector" => __(
                "Embedding Vector (first 10 dimensions)",
                "ai-chat-wp",
            ),
            "fullVector" => __(
                "Full embedding vector contains",
                "ai-chat-wp",
            ),
            "dimensions" => __("dimensions", "ai-chat-wp"),
            "deleteEmbedding" => __("Delete Embedding", "ai-chat-wp"),
            "deleteAllChunks" => __("Delete All Chunks", "ai-chat-wp"),
            "parentNoEmbedding" => __(
                "Parent post does not have its own embedding - content is stored in chunks above.",
                "ai-chat-wp",
            ),
            "noEmbeddingFound" => __("No embedding found", "ai-chat-wp"),
            "notProcessedYet" => __(
                "This item has not been processed for AI search yet.",
                "ai-chat-wp",
            ),
            "confirmDeleteEmbedding" => __(
                "Are you sure you want to delete the embedding for",
                "ai-chat-wp",
            ),
            "confirmDeleteChunks" => __(
                "Are you sure you want to delete all",
                "ai-chat-wp",
            ),
            "chunksFor" => __("chunks for", "ai-chat-wp"),
            "needToRegenerate" => __(
                "You will need to regenerate it to use AI search for this item.",
                "ai-chat-wp",
            ),
            "deletingEmbedding" => __(
                "Deleting embedding...",
                "ai-chat-wp",
            ),
            "deletingChunks" => __("Deleting chunks...", "ai-chat-wp"),
            "embeddingDeleted" => __(
                "Embedding deleted successfully.",
                "ai-chat-wp",
            ),
            "chunksDeleted" => __(
                "All chunks deleted successfully.",
                "ai-chat-wp",
            ),

            // Database status
            "processedEmbeddings" => __(
                "Processed Embeddings",
                "ai-chat-wp",
            ),
            "missingEmbeddings" => __("Missing Embeddings", "ai-chat-wp"),
            "recentActivity" => __("Recent Activity (24h)", "ai-chat-wp"),
            "recentEmbeddings" => __("Recent Embeddings", "ai-chat-wp"),
            "searchPlaceholder" => __(
                "Search by title or ID...",
                "ai-chat-wp",
            ),
            "search" => __("Search", "ai-chat-wp"),
            "title" => __("Title", "ai-chat-wp"),
            "type" => __("Type", "ai-chat-wp"),
            "clickIdToView" => __(
                "Click on any ID to view its embedding data.",
                "ai-chat-wp",
            ),
            "indicatesChunk" => __(
                "indicates that content was split into parts for better accuracy.",
                "ai-chat-wp",
            ),
            "noRecentEmbeddings" => __(
                "No recent embeddings found.",
                "ai-chat-wp",
            ),
            "selectAll" => __("Select All", "ai-chat-wp"),
            "deselectAll" => __("Deselect All", "ai-chat-wp"),
            "generateSelected" => __("Generate Selected", "ai-chat-wp"),
            "lastModified" => __("Last Modified", "ai-chat-wp"),
            "action" => __("Action", "ai-chat-wp"),
            "generate" => __("Generate", "ai-chat-wp"),
            "clickGenerateOrSelect" => __(
                'Click "Generate" for individual items or select multiple and use "Generate Selected".',
                "ai-chat-wp",
            ),
            "allMissing" => __("All", "ai-chat-wp"),
            "listingsAreMissing" => __(
                'items are missing embeddings. Use the "Generate Structured Embeddings" tool above to process them in bulk.',
                "ai-chat-wp",
            ),
            "errorLoadingInfo" => __(
                "Error loading item information",
                "ai-chat-wp",
            ),

            // Search
            "enterSearchTerm" => __(
                "Please enter a search term.",
                "ai-chat-wp",
            ),
            "searching" => __("Searching...", "ai-chat-wp"),
            "searchFailed" => __("Search failed.", "ai-chat-wp"),
            "searchRequestFailed" => __(
                "Search request failed.",
                "ai-chat-wp",
            ),
            "resultsFound" => __("result(s) found", "ai-chat-wp"),
            "noResultsFound" => __("No results found.", "ai-chat-wp"),
            "clearSearchResults" => __(
                "Clear search results",
                "ai-chat-wp",
            ),

            // Database actions
            "confirmClearAll" => __(
                "Are you sure? This will delete all embeddings and cannot be undone.",
                "ai-chat-wp",
            ),
            "clearingDatabase" => __("Clearing database...", "ai-chat-wp"),
            "selectPostType" => __(
                "Please select a post type",
                "ai-chat-wp",
            ),
            "confirmDeletePostType" => __(
                "Are you sure you want to delete all embeddings for",
                "ai-chat-wp",
            ),
            "cannotBeUndone" => __("This cannot be undone.", "ai-chat-wp"),
            "deletingEmbeddingsFor" => __(
                "Deleting embeddings for",
                "ai-chat-wp",
            ),
            "clearingAnalytics" => __(
                "Clearing analytics data...",
                "ai-chat-wp",
            ),
            "actionCompleted" => __(
                "Action completed successfully!",
                "ai-chat-wp",
            ),
            "successfullyDeleted" => __(
                "Successfully deleted",
                "ai-chat-wp",
            ),
            "embeddingsForPostType" => __(
                "embedding(s) for post type:",
                "ai-chat-wp",
            ),
            "analyticsCleared" => __(
                "Analytics data cleared successfully!",
                "ai-chat-wp",
            ),

            // Bulk generation
            "embeddings" => __("embeddings...", "ai-chat-wp"),
            "completed" => __("Completed:", "ai-chat-wp"),
            "processing" => __("Processing", "ai-chat-wp"),

            // Media uploader
            "selectCustomIcon" => __("Select Custom Icon", "ai-chat-wp"),
            "useThisIcon" => __("Use this icon", "ai-chat-wp"),
            "selectChatAvatar" => __("Select Chat Avatar", "ai-chat-wp"),
            "useThisImage" => __("Use this image", "ai-chat-wp"),
            "remove" => __("Remove", "ai-chat-wp"),
            "buttonColor" => get_option(
                "aicwp_floating_button_color",
                "#222222",
            ),
            "pluginUrl" => AICWP_PLUGIN_URL,

            // UI module - Shortcode generator
            "copied" => __("Copied!", "ai-chat-wp"),

            // UI module - Quality slider
            "qualityVeryLow" => __(
                "Loose - many results, lower relevance",
                "ai-chat-wp",
            ),
            "qualityLow" => __(
                "Broad - more results, some may be less relevant",
                "ai-chat-wp",
            ),
            "qualityBalanced" => __(
                "Balanced - good mix of quantity and quality",
                "ai-chat-wp",
            ),
            "qualityHigh" => sprintf(
                __(
                    "Quality focused - pay attention because %syou might start getting little results%s",
                    "ai-chat-wp",
                ),
                "<strong>",
                "</strong>",
            ),
            "qualityVeryHigh" => sprintf(
                __(
                    "Very strict — %syou might get little to no results%s",
                    "ai-chat-wp",
                ),
                "<strong>",
                "</strong>",
            ),

            // UI module - Quick buttons
            "placeholderUrl" => __("https://example.com", "ai-chat-wp"),
            "placeholderMessage" => __("Message to send", "ai-chat-wp"),
            "color" => __("Buttons Color", "ai-chat-wp"),
            "reset" => __("Reset", "ai-chat-wp"),
            "requestFailed" => __(
                "Request failed. Please try again.",
                "ai-chat-wp",
            ),
        ]);

        // Note: Color picker and toggleable cards are now handled by admin-ui-scripts.js

        // Add inline CSS for AJAX form styling
        wp_add_inline_style(
            "ai-chat-wp-admin",
            '
            .aicwp-ui-form-message {
                margin-top: 15px;
                padding: 10px 15px;
                border-radius: 5px;
                border-left: none;
                background: #fff;
            }
            .aicwp-ui-form-message.aicwp-ui-alert-success {
                background: #ecf7ed;
                color: #1e4620;
            }
            .aicwp-ui-form-message.aicwp-ui-alert-error {
                background: #fbeaea;
                color: #761919;
            }
            .button-spinner {
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .aicwp-ui-ajax-form button[type="submit"]:disabled {
                opacity: 0.7;
                cursor: not-allowed;
            }
            .aicwp-ui-api-test-result {
                padding: 10px 15px;
                border-radius: 4px;
                border: 1px solid;
                font-size: 14px;
                line-height: 1.4;
            }
            .aicwp-ui-api-test-result.aicwp-ui-api-test-success {
                border-color: #46b450;
                background: #ecf7ed;
                color: #1e4620;
            }
            .aicwp-ui-api-test-result.aicwp-ui-api-test-error {
                border-color: #dc3232;
                background: #fbeaea;
                color: #761919;
            }
            .aicwp-ui-button-secondary {
                background: #f7f7f7;
                color: #555;
                border: 1px solid #ccc;
            }
            .aicwp-ui-button-secondary:hover {
                background: #e9e9e9;
                border-color: #999;
            }
            .aicwp-ui-cache-actions {
                margin-top: 10px;
            }
            .aicwp-ui-cache-actions button {
                display: inline-flex;
                align-items: center;
                gap: 5px;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .spin {
                animation: spin 1s linear infinite;
            }
        ',
        );
    }

    /**
     * Admin page content
     */
    public function admin_page()
    {
        // Prevent caching plugins (LiteSpeed Cache, W3TC, WP Super Cache, etc.)
        // from caching this settings page
        if (!defined("DONOTCACHEPAGE")) {
            define("DONOTCACHEPAGE", true);
        }
        if (!headers_sent()) {
            header(
                "Cache-Control: no-store, no-cache, must-revalidate, max-age=0",
            );
            header("Pragma: no-cache");
            header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");
        }

        // Run table migrations if needed (only when admin visits this page)
        if (class_exists("AICWP_Contact_Messages")) {
            AICWP_Contact_Messages::maybe_upgrade_table();
        }

        $active_tab = isset($_GET["tab"])
            ? sanitize_key(wp_unslash($_GET["tab"]))
            : "stats";

        if ($active_tab === "") {
            $active_tab = "stats";
        }
        ?>
        <script>
        // Apply collapsed state immediately to prevent flash
        (function() {
            var STORAGE_KEY = "aicwp_ui_collapsed_cards";
            var DEFAULTS = { "database-management": true, "semantic-search-field": true, "developer-debug": true };
            try {
                var stored = localStorage.getItem(STORAGE_KEY);
                var state = stored ? JSON.parse(stored) : {};
                for (var id in DEFAULTS) {
                    if (!(id in state)) state[id] = DEFAULTS[id];
                }
                window.aicwpCollapsedCards = state;
                var style = document.createElement('style');
                style.id = 'aicwp-ui-early-collapse-styles';
                var css = '';
                for (var id in state) {
                    if (state[id] === true) {
                        css += '.aicwp-ui-card-toggleable[data-toggle-id="' + id + '"]:not(.js-ready) .aicwp-ui-card-body { display: none; }';
                        css += '.aicwp-ui-card-toggleable[data-toggle-id="' + id + '"]:not(.js-ready) .aicwp-ui-card-header { border-bottom: none; }';
                        css += '.aicwp-ui-card-toggleable[data-toggle-id="' + id + '"]:not(.js-ready) .aicwp-ui-card-toggle-icon { transform: translateY(-50%) rotate(0deg); }';
                    }
                }
                if (css) {
                    style.textContent = css;
                    document.head.appendChild(style);
                }
            } catch(e) {}
        })();
        </script>
        <div class="wrap aicwp-ui-admin-wrap">
            <div class="aicwp-ui-header">
                <?php // Show debug mode notice if enabled

        if (get_option("aicwp_debug_mode", false)) {
                    $this->show_debug_mode_notice();
                } ?>
                <div class="aicwp-ui-header-content">
                    <div class="aicwp-ui-header-text">
                        <h1 style="display: none;"></h1>
                        <div class="aicwp-ui-header-main">
                            <div class="aicwp-ui-logo-container"><img src="<?php echo esc_url(
                                AICWP_PLUGIN_URL .
                                    "assets/icons/logo.png",
                            ); ?>" alt="" class="aicwp-ui-header-logo"></div>
                            <div class="aicwp-ui-header-title-group">
                                <?php
                                $current_user = wp_get_current_user();
                                $greeting_name = $current_user->first_name
                                    ? $current_user->first_name
                                    : $current_user->user_login;
                                ?>
                                <div class="aicwp-ui-header-title"><?php printf(
                                    __("Hello, %s", "ai-chat-wp"),
                                    esc_html($greeting_name),
                                ); ?></div>
                                <p class="aicwp-ui-header-subtitle"><?php _e(
                                    "Manage your AI assistant, train your content, and track conversations with AI Chat for WordPress.",
                                    "ai-chat-wp",
                                ); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <nav class="aicwp-ui-nav-tab-wrapper nav-tab-wrapper">
                <a href="<?php echo esc_url($this->get_admin_tab_url("stats")); ?>"
                   class="nav-tab <?php echo $active_tab == "stats"
                       ? "nav-tab-active"
                       : ""; ?>">
                    <svg width="16" height="16" viewBox="2 3 19 19" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 5V19C4 19.5523 4.44772 20 5 20H19" stroke="#0060ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M18 9L13 13.9999L10.5 11.4998L7 14.9998" stroke="#0060ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <?php _e("Dashboard", "ai-chat-wp"); ?>
                </a>
                <a href="<?php echo esc_url($this->get_admin_tab_url("ai-chat")); ?>"
                   class="nav-tab <?php echo $active_tab == "ai-chat"
                       ? "nav-tab-active"
                       : ""; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 19.951 19.951">
                        <g transform="translate(-2.025 -2.025)">
                            <path d="M7.713,5.175a1.894,1.894,0,0,0,2.542-.987,1.89,1.89,0,0,1,3.49,0,1.894,1.894,0,0,0,2.542.987,1.914,1.914,0,0,1,2.538,2.538,1.894,1.894,0,0,0,.987,2.542,1.89,1.89,0,0,1,0,3.49,1.894,1.894,0,0,0-.987,2.542,1.914,1.914,0,0,1-2.538,2.538,1.894,1.894,0,0,0-2.542.987,1.89,1.89,0,0,1-3.49,0,1.9,1.9,0,0,0-2.542-.987,1.914,1.914,0,0,1-2.538-2.538,1.894,1.894,0,0,0-.987-2.542,1.89,1.89,0,0,1,0-3.49,1.894,1.894,0,0,0,.987-2.542A1.914,1.914,0,0,1,7.713,5.175ZM12,8.75A3.25,3.25,0,1,0,15.25,12,3.25,3.25,0,0,0,12,8.75Z" fill="#6aa9ff" fill-rule="evenodd" opacity="0.1"></path>
                            <path d="M10.255,4.188a1.894,1.894,0,0,1-2.542.987A1.914,1.914,0,0,0,5.175,7.713a1.894,1.894,0,0,1-.987,2.542,1.89,1.89,0,0,0,0,3.49,1.894,1.894,0,0,1,.987,2.542,1.914,1.914,0,0,0,2.538,2.538,1.9,1.9,0,0,1,2.542.987,1.89,1.89,0,0,0,3.49,0,1.894,1.894,0,0,1,2.542-.987,1.914,1.914,0,0,0,2.538-2.538,1.894,1.894,0,0,1,.987-2.542,1.89,1.89,0,0,0,0-3.49,1.9,1.9,0,0,1-.987-2.542,1.914,1.914,0,0,0-2.538-2.538,1.894,1.894,0,0,1-2.542-.987A1.89,1.89,0,0,0,10.255,4.188Z" fill="none" stroke="#006aff" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"></path>
                            <path d="M15,12a3,3,0,1,1-3-3A3,3,0,0,1,15,12Z" fill="none" stroke="#006aff" stroke-width="2"></path>
                        </g>
                    </svg>
                    <?php _e("Settings", "ai-chat-wp"); ?>
                </a>
                <a href="<?php echo esc_url($this->get_admin_tab_url("database")); ?>"
                   class="nav-tab <?php echo $active_tab == "database"
                       ? "nav-tab-active"
                       : ""; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14.4" height="16" viewBox="0 0 18 20">
                        <g transform="translate(-3 -2)">
                            <path d="M20,7c0,2.209-3.582,4-8,4S4,9.209,4,7s3.582-4,8-4S20,4.791,20,7Z" fill="#004dff" opacity="0.1"/>
                            <path d="M19.75,13.477a5.3,5.3,0,0,1-1.981,1.575A13.22,13.22,0,0,1,12,16.25a13.22,13.22,0,0,1-5.769-1.2A5.3,5.3,0,0,1,4.25,13.477V17c0,.959.784,1.894,2.2,2.6A12.726,12.726,0,0,0,12,20.75,12.726,12.726,0,0,0,17.545,19.6c1.421-.711,2.2-1.646,2.2-2.6Z" fill="#004dff" opacity="0.1"/>
                            <path d="M20,7c0,2.209-3.582,4-8,4S4,9.209,4,7s3.582-4,8-4S20,4.791,20,7Z" fill="none" stroke="#006aff" stroke-width="2"/>
                            <path d="M20,12c0,2.209-3.582,4-8,4s-8-1.791-8-4" fill="none" stroke="#006aff" stroke-width="2"/>
                            <path d="M4,7V17c0,2.209,3.582,4,8,4s8-1.791,8-4V7" fill="none" stroke="#006aff" stroke-width="2"/>
                        </g>
                    </svg>
                    <?php _e("Data Training", "ai-chat-wp"); ?>
                </a>
                <?php // Hook for add-ons to add additional tabs

        do_action("aicwp_admin_nav_tabs", $active_tab); ?>
            </nav>

            <?php if ($active_tab == "stats"): ?>
                <div class="aicwp-ui-tab-content aicwp-ui-stats-tab">
                    <?php $this->render_stats_tab(); ?>
                </div>
            <?php
                elseif ($active_tab == "ai-chat"): ?>
                <div class="aicwp-ui-tab-content aicwp-ui-ai-chat-tab">
                    <?php $this->render_ai_chat_tab(); ?>
                </div>
            <?php elseif ($active_tab == "database"): ?>
                <div class="aicwp-ui-tab-content aicwp-ui-database-tab">
                    <?php $this->render_database_tab(); ?>
                </div>
            <?php else: ?>
                <?php do_action(
                    "aicwp_admin_tab_content",
                    $active_tab,
                ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render settings tab
     */
    private function render_settings_tab()
    {
        ?>
        <?php
        $current_provider = get_option("aicwp_provider", "openai");
        $model = get_option("aicwp_chat_model", "gpt-5.4-mini");
        ?>

        <div class="aicwp-ui-card is-active" data-chat-section="api-config">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e("API Configuration", "ai-chat-wp"); ?></h3>
                    <p><?php _e(
                        "Configure your AI provider and rate limiting.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body" style="position: relative;">
                    <!-- AI Provider Selection -->
                    <div class="aicwp-ui-form-group">
                        <label for="aicwp_provider" class="aicwp-ui-label">
                            <?php _e("AI Provider", "ai-chat-wp"); ?>
                        </label>

                        <!-- Hidden select for data storage -->
                        <select id="aicwp_provider" name="aicwp_provider" class="aicwp-ui-input" style="display: none;">
                            <option value="openai" <?php selected(
                                get_option(
                                    "aicwp_provider",
                                    "openai",
                                ),
                                "openai",
                            ); ?>><?php _e(
    "OpenAI",
    "ai-chat-wp",
); ?></option>
                            <option value="gemini" <?php selected(
                                get_option(
                                    "aicwp_provider",
                                    "openai",
                                ),
                                "gemini",
                            ); ?>><?php _e(
    "Google Gemini",
    "ai-chat-wp",
); ?></option>
                            <option value="mistral" <?php selected(
                                get_option(
                                    "aicwp_provider",
                                    "openai",
                                ),
                                "mistral",
                            ); ?>><?php _e(
    "Mistral AI",
    "ai-chat-wp",
); ?></option>
                            <option value="openrouter" <?php selected(
                                get_option(
                                    "aicwp_provider",
                                    "openai",
                                ),
                                "openrouter",
                            ); ?>><?php _e(
    "OpenRouter",
    "ai-chat-wp",
); ?></option>
                        </select>

                        <!-- Custom Toggle Switch with Logos -->
                        <div class="ai-provider-toggle ai-provider-toggle-4" data-selected="<?php echo esc_attr(
                            get_option("aicwp_provider", "openai"),
                        ); ?>">
                            <div class="ai-provider-option" data-value="openai">
                                <div class="ai-provider-logo">
                                    <img src="<?php echo esc_url(
                                        AICWP_PLUGIN_URL .
                                            "assets/icons/gpt.svg",
                                    ); ?>" alt="ChatGPT">
                                </div>
                            </div>
                            <div class="ai-provider-option" data-value="gemini">
                                <div class="ai-provider-logo">
                                    <img src="<?php echo esc_url(
                                        AICWP_PLUGIN_URL .
                                            "assets/icons/gemini.svg",
                                    ); ?>" alt="Gemini">
                                </div>
                            </div>
                            <div class="ai-provider-option" data-value="mistral">
                                <div class="ai-provider-logo">
                                    <img src="<?php echo esc_url(
                                        AICWP_PLUGIN_URL .
                                            "assets/icons/mistral.svg",
                                    ); ?>" alt="Mistral">
                                </div>
                            </div>
                            <div class="ai-provider-option" data-value="openrouter">
                                <div class="ai-provider-logo">
                                    <img src="<?php echo esc_url(
                                        AICWP_PLUGIN_URL .
                                            "assets/icons/openrouter.svg",
                                    ); ?>" alt="OpenRouter">
                                </div>
                            </div>
                            <div class="ai-provider-slider"></div>
                        </div>

                        <div class="aicwp-ui-help-text">
                            <?php _e(
                                "Choose between OpenAI, Google Gemini, Mistral AI, or OpenRouter - a gateway to all top AI models.",
                                "ai-chat-wp",
                            ); ?>
                        </div>
                    </div>

                    <!-- OpenAI API Key (shown when provider is OpenAI) -->
                    <div class="aicwp-ui-form-group provider-field provider-openai" style="<?php echo get_option(
                        "aicwp_provider",
                        "openai",
                    ) !== "openai"
                        ? "display:none;"
                        : ""; ?>">
                        <label for="aicwp_api_key" class="aicwp-ui-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            <?php _e("OpenAI API Key", "ai-chat-wp"); ?>
                        </label>
                        <?php $has_openai_key =
                            get_option("aicwp_api_key", "") !==
                            ""; ?>
                        <div class="aicwp-ui-api-test-wrapper aicwp-ui-group-block" style="padding: 20px; padding-bottom: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 10px;">
                            <input type="password" id="aicwp_api_key" name="<?php echo $has_openai_key
                                ? ""
                                : "aicwp_api_key"; ?>" value="<?php echo $has_openai_key
    ? str_repeat("*", 36)
    : ""; ?>" class="aicwp-ui-input aicwp-ui-api-key-input" placeholder="<?php echo $has_openai_key
    ? str_repeat("*", 36)
    : "sk-..."; ?>" autocomplete="new-password" <?php disabled(
    $has_openai_key,
); ?> data-setting-name="aicwp_api_key" style="flex: 1 1 280px; margin-bottom: 10px;" />
                            <br>
                            <input type="hidden" name="aicwp_api_key_remove" value="0" class="aicwp-ui-api-key-remove-flag" data-setting-name="aicwp_api_key" />
                            <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary test-api-key-button" id="test-api-key" style="font-size: 13px; padding: 8px 16px;">
                                <span class="button-text">
                                    <?php _e(
                                        "Test API Key",
                                        "ai-chat-wp",
                                    ); ?>
                                </span>
                                <span class="button-spinner" style="display: none;">
                                    <span class="aicwp-ui-spinner"></span>
                                    <?php _e("Testing...", "ai-chat-wp"); ?>
                                </span>
                            </button>
                            <button type="button" class="aicwp-ui-button aicwp-ui-button-danger aicwp-ui-api-key-remove-button" data-target="aicwp_api_key" style="font-size: 13px !important; padding: 8px 16px !important; margin-left: 5px;">
                                <?php _e("Remove API Key", "ai-chat-wp"); ?>
                            </button>

                            <div class="aicwp-ui-help-text aicwp-ui-blue">
                                <?php _e(
                                    '<strong>OpenAI requires $5 minimum balance.</strong> Enter your OpenAI API key from the OpenAI Dashboard.',
                                    "ai-chat-wp",
                                ); ?>
                                <br><a href="https://platform.openai.com/api-keys" target="_blank"><?php _e(
                                    "How to create Open AI API key →",
                                    "ai-chat-wp",
                                ); ?></a>
                            </div>
                        </div>
                        <div id="api-test-result" class="aicwp-ui-api-test-result" style="margin-top: 8px; display: none;"></div>
                    </div>

                    <!-- Gemini API Key (shown when provider is Gemini) -->
                    <div class="aicwp-ui-form-group provider-field provider-gemini" style="<?php echo get_option(
                        "aicwp_provider",
                        "openai",
                    ) !== "gemini"
                        ? "display:none;"
                        : ""; ?>">
                        <label for="aicwp_gemini_api_key" class="aicwp-ui-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            <?php _e(
                                "Google Gemini API Key",
                                "ai-chat-wp",
                            ); ?>
                        </label>
                        <?php $has_gemini_key =
                            get_option(
                                "aicwp_gemini_api_key",
                                "",
                            ) !== ""; ?>
                        <div class="aicwp-ui-api-test-wrapper aicwp-ui-group-block" style="padding: 20px; padding-bottom: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 10px;">
                            <input type="password" id="aicwp_gemini_api_key" name="<?php echo $has_gemini_key
                                ? ""
                                : "aicwp_gemini_api_key"; ?>" value="<?php echo $has_gemini_key
    ? str_repeat("*", 36)
    : ""; ?>" class="aicwp-ui-input aicwp-ui-api-key-input" placeholder="<?php echo $has_gemini_key
    ? str_repeat("*", 36)
    : "AIzaSy..."; ?>" autocomplete="new-password" <?php disabled(
    $has_gemini_key,
); ?> data-setting-name="aicwp_gemini_api_key" style="flex: 1 1 280px; margin-bottom: 10px;" />
                            <br>
                            <input type="hidden" name="aicwp_gemini_api_key_remove" value="0" class="aicwp-ui-api-key-remove-flag" data-setting-name="aicwp_gemini_api_key" />
                            <button type="button" id="test-gemini-api-key" class="aicwp-ui-button aicwp-ui-button-secondary test-api-key-button" style="font-size: 13px; padding: 8px 16px;">
                                <span class="button-text">
                                    <?php _e(
                                        "Test API Key",
                                        "ai-chat-wp",
                                    ); ?>
                                </span>
                                <span class="button-spinner" style="display: none;">
                                    <span class="aicwp-ui-spinner"></span>
                                    <?php _e("Testing...", "ai-chat-wp"); ?>
                                </span>
                            </button>
                            <button type="button" class="aicwp-ui-button aicwp-ui-button-danger aicwp-ui-api-key-remove-button" data-target="aicwp_gemini_api_key" style="font-size: 13px !important; padding: 8px 16px !important;">
                                <?php _e("Remove API Key", "ai-chat-wp"); ?>
                            </button>

                            <div class="aicwp-ui-help-text aicwp-ui-blue">
                                <?php printf(
                                    __(
                                        'Enter your Google Gemini API key from %1$sGoogle AI Studio%2$s. Note: %3$sfree tier has very low rate limits%4$s — we recommend a paid plan.',
                                        "ai-chat-wp",
                                    ),
                                    '<a href="https://aistudio.google.com/app/apikey" target="_blank">',
                                    "</a>",
                                    "<strong>",
                                    "</strong>",
                                ); ?>
                            </div>
                        </div>
                        <div id="gemini-api-test-result" class="aicwp-ui-api-test-result" style="margin-top: 8px; display: none;"></div>
                    </div>

                    <!-- Mistral API Key (shown when provider is Mistral) -->
                    <div class="aicwp-ui-form-group provider-field provider-mistral" style="<?php echo get_option(
                        "aicwp_provider",
                        "openai",
                    ) !== "mistral"
                        ? "display:none;"
                        : ""; ?>">
                        <label for="aicwp_mistral_api_key" class="aicwp-ui-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            <?php _e("Mistral AI API Key", "ai-chat-wp"); ?>
                        </label>
                        <?php $has_mistral_key =
                            get_option(
                                "aicwp_mistral_api_key",
                                "",
                            ) !== ""; ?>
                        <div class="aicwp-ui-api-test-wrapper aicwp-ui-group-block" style="padding: 20px; padding-bottom: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 10px;">
                            <input type="password" id="aicwp_mistral_api_key" name="<?php echo $has_mistral_key
                                ? ""
                                : "aicwp_mistral_api_key"; ?>" value="<?php echo $has_mistral_key
    ? str_repeat("*", 36)
    : ""; ?>" class="aicwp-ui-input aicwp-ui-api-key-input" placeholder="<?php echo $has_mistral_key
    ? str_repeat("*", 36)
    : "..."; ?>" autocomplete="new-password" <?php disabled(
    $has_mistral_key,
); ?> data-setting-name="aicwp_mistral_api_key" style="flex: 1 1 280px; margin-bottom: 10px;" />
                            <br>
                            <input type="hidden" name="aicwp_mistral_api_key_remove" value="0" class="aicwp-ui-api-key-remove-flag" data-setting-name="aicwp_mistral_api_key" />
                            <button type="button" id="test-mistral-api-key" class="aicwp-ui-button aicwp-ui-button-secondary test-api-key-button" style="font-size: 13px; padding: 8px 16px;">
                                <span class="button-text">
                                    <?php _e(
                                        "Test API Key",
                                        "ai-chat-wp",
                                    ); ?>
                                </span>
                                <span class="button-spinner" style="display: none;">
                                    <span class="aicwp-ui-spinner"></span>
                                    <?php _e("Testing...", "ai-chat-wp"); ?>
                                </span>
                            </button>
                            <button type="button" class="aicwp-ui-button aicwp-ui-button-danger aicwp-ui-api-key-remove-button" data-target="aicwp_mistral_api_key" style="font-size: 13px !important; padding: 8px 16px !important;">
                                <?php _e("Remove API Key", "ai-chat-wp"); ?>
                            </button>

                            <div class="aicwp-ui-help-text aicwp-ui-blue">
                                <?php _e(
                                    "Enter your Mistral AI API key from the Mistral Console. Mistral is hosted in",
                                    "ai-chat-wp",
                                ); ?> <a href="https://help.mistral.ai/en/articles/347629-where-do-you-store-my-data-or-my-organization-s-data" target="_blank"><?php _e(
     "European Union",
     "ai-chat-wp",
 ); ?></a>.
                                <br><a href="https://console.mistral.ai/api-keys" target="_blank"><?php _e(
                                    "Get Mistral API Key →",
                                    "ai-chat-wp",
                                ); ?></a>
                            </div>
                        </div>
                        <div id="mistral-api-test-result" class="aicwp-ui-api-test-result" style="margin-top: 8px; display: none;"></div>
                    </div>

                    <!-- OpenRouter API Key (shown when provider is OpenRouter) -->
                    <div class="aicwp-ui-form-group provider-field provider-openrouter" style="<?php echo get_option(
                        "aicwp_provider",
                        "openai",
                    ) !== "openrouter"
                        ? "display:none;"
                        : ""; ?>">
                        <label for="aicwp_openrouter_api_key" class="aicwp-ui-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            <?php _e("OpenRouter API Key", "ai-chat-wp"); ?>
                        </label>
                        <?php $has_openrouter_key =
                            get_option(
                                "aicwp_openrouter_api_key",
                                "",
                            ) !== ""; ?>
                        <div class="aicwp-ui-api-test-wrapper aicwp-ui-group-block" style="padding: 20px; padding-bottom: 20px; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 10px;">
                            <input type="password" id="aicwp_openrouter_api_key" name="<?php echo $has_openrouter_key
                                ? ""
                                : "aicwp_openrouter_api_key"; ?>" value="<?php echo $has_openrouter_key
    ? str_repeat("*", 36)
    : ""; ?>" class="aicwp-ui-input aicwp-ui-api-key-input" placeholder="<?php echo $has_openrouter_key
    ? str_repeat("*", 36)
    : "sk-or-v1-..."; ?>" autocomplete="new-password" <?php disabled(
    $has_openrouter_key,
); ?> data-setting-name="aicwp_openrouter_api_key" style="flex: 1 1 280px; margin-bottom: 10px;" />
                            <br>
                            <input type="hidden" name="aicwp_openrouter_api_key_remove" value="0" class="aicwp-ui-api-key-remove-flag" data-setting-name="aicwp_openrouter_api_key" />
                            <button type="button" id="test-openrouter-api-key" class="aicwp-ui-button aicwp-ui-button-secondary test-api-key-button" style="font-size: 13px; padding: 8px 16px;">
                                <span class="button-text">
                                    <?php _e(
                                        "Test API Key",
                                        "ai-chat-wp",
                                    ); ?>
                                </span>
                                <span class="button-spinner" style="display: none;">
                                    <span class="aicwp-ui-spinner"></span>
                                    <?php _e("Testing...", "ai-chat-wp"); ?>
                                </span>
                            </button>
                            <button type="button" class="aicwp-ui-button aicwp-ui-button-danger aicwp-ui-api-key-remove-button" data-target="aicwp_openrouter_api_key" style="font-size: 13px !important; padding: 8px 16px !important;">
                                <?php _e("Remove API Key", "ai-chat-wp"); ?>
                            </button>

                            <div class="aicwp-ui-help-text aicwp-ui-blue">
                                <?php _e(
                                    '<strong>One API key for top AI providers</strong> (OpenAI, Anthropic, Google, Meta, Mistral, DeepSeek, Grok).<br><strong>OpenRouter requires $5 minimum balance.</strong>',
                                    "ai-chat-wp",
                                ); ?> <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>
                            </div>
                        </div>
                        <div id="openrouter-api-test-result" class="aicwp-ui-api-test-result" style="margin-top: 8px; display: none;"></div>
                    </div>

                    <!-- Model Selection -->
                    <div class="aicwp-ui-form-group">
                        <label for="aicwp_chat_model" class="aicwp-ui-label">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>
                            <span id="model-label-text">
                                <?php if ($current_provider === "gemini") {
                                    _e("Gemini Model", "ai-chat-wp");
                                } elseif ($current_provider === "mistral") {
                                    _e("Mistral Model", "ai-chat-wp");
                                } elseif ($current_provider === "openrouter") {
                                    _e("OpenRouter Model", "ai-chat-wp");
                                } else {
                                    _e("OpenAI Model", "ai-chat-wp");
                                } ?>
                            </span>
                        </label>
                        <div style="padding: 20px;  padding-bottom: 5px; border-radius: 8px; border: 1px solid #e0e0e0;" class="aicwp-ui-group-block">
                        <?php // Helper closure: emit a single <option> with its vendor icon prepended.

        $render_model_option = function ($slug, $label) use ($model) {
                            $icon_url = $this->get_openrouter_vendor_icon_url(
                                $slug,
                            );
                            $img_tag = $icon_url
                                ? '<img src="' .
                                    esc_url($icon_url) .
                                    '" alt="" class="aicwp-ui-model-icon">'
                                : "";
                            printf(
                                '<option value="%s"%s>%s%s</option>',
                                esc_attr($slug),
                                selected($model, $slug, false),
                                $img_tag,
                                esc_html($label),
                            );
                        }; ?>
                        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                        <select id="aicwp_chat_model" name="aicwp_chat_model" class="aicwp-ui-input" style="flex: 1; min-width: 260px;">
                            <!-- OpenAI Models -->
                            <optgroup label="OpenAI Models" class="model-group model-group-openai" style="<?php echo $current_provider !==
                            "openai"
                                ? "display:none;"
                                : ""; ?>">
                                <?php
                                $openai_models = [
                                    "gpt-4.1-mini" => "GPT-4.1 Mini (Fast & Good)",
                                    "gpt-4.1" => "GPT-4.1 (Smart, non-reasoning)",
                                    "gpt-5-mini" => "GPT-5 Mini (Fast & Good)",
                                    "gpt-5-chat-latest" => "GPT-5 (Smart)",
                                    "gpt-5.1" => "GPT-5.1 (High Intelligence)",
                                    "gpt-5.2" => "GPT-5.2 (High Intelligence)",
                                    "gpt-5.3-chat-latest" =>
                                        "GPT-5.3 (High Intelligence)",
                                    "gpt-5.4" =>
                                        "GPT-5.4 (High Intelligence - Latest)",
                                    "gpt-5.4-mini" => "GPT-5.4 Mini (Fast & Smart)",
                                    "gpt-5.4-nano" =>
                                        "GPT-5.4 Nano (Fastest & Cheapest)",
                                    "gpt-5.5" => "GPT-5.5 (Latest)",
                                ];
                                foreach ($openai_models as $slug => $label) {
                                    $render_model_option($slug, $label);
                                }
                                ?>
                            </optgroup>
                            <!-- Gemini Models -->
                            <optgroup label="Gemini Models" class="model-group model-group-gemini" style="<?php echo $current_provider !==
                            "gemini"
                                ? "display:none;"
                                : ""; ?>">
                                <?php
                                $gemini_models = [
                                    "gemini-2.5-flash" => "Gemini 2.5 Flash (Fast)",
                                    "gemini-2.5-pro" =>
                                        "Gemini 2.5 Pro (High Intellgence & Slow)",
                                    "gemini-3.1-pro-preview" =>
                                        "Gemini 3.1 Pro (High Intelligence & Slow)",
                                    "gemini-3-flash-preview" =>
                                        "Gemini 3 Flash (Intelligent & Fast - Recommended)",
                                    "gemini-3.1-flash-lite-preview" =>
                                        "Gemini 3.1 Flash Lite (Fastest)",
                                ];
                                foreach ($gemini_models as $slug => $label) {
                                    $render_model_option($slug, $label);
                                }
                                ?>
                            </optgroup>
                            <!-- Mistral Models -->
                            <optgroup label="Mistral Models" class="model-group model-group-mistral" style="<?php echo $current_provider !==
                            "mistral"
                                ? "display:none;"
                                : ""; ?>">
                                <?php
                                $mistral_models = [
                                    "mistral-small-latest" =>
                                        "Mistral Small 4 (Fast)",
                                    "mistral-medium-latest" =>
                                        "Mistral Medium 3.1 (Balanced - Recommended)",
                                    "mistral-medium-3.5" => "Mistral Medium 3.5",
                                    "mistral-large-latest" =>
                                        "Mistral Large 3 (High Intelligence)",
                                ];
                                foreach ($mistral_models as $slug => $label) {
                                    $render_model_option($slug, $label);
                                }
                                ?>
                            </optgroup>
                            <!-- OpenRouter Models -->
                            <optgroup label="OpenRouter Models" class="model-group model-group-openrouter" style="<?php echo $current_provider !==
                            "openrouter"
                                ? "display:none;"
                                : ""; ?>">
                                <?php
                                $openrouter_models = [
                                    // OpenAI
                                    "openai/gpt-5-mini" => "GPT-5 Mini",
                                    "openai/gpt-5.1" => "GPT-5.1",
                                    "openai/gpt-5.3-chat-latest" => "GPT-5.3",
                                    "openai/gpt-5.4" => "GPT-5.4",
                                    "openai/gpt-5.4-mini" => "GPT-5.4 Mini",
                                    "openai/gpt-5.4-nano" => "GPT-5.4 Nano",
                                    "openai/gpt-5.5" => "GPT-5.5",
                                    "openai/gpt-4.1" => "GPT-4.1",
                                    "openai/gpt-4.1-mini" => "GPT-4.1 Mini",
                                    // Anthropic
                                    "anthropic/claude-sonnet-4.6" =>
                                        "Claude Sonnet 4.6",
                                    "anthropic/claude-opus-4.6" =>
                                        "Claude Opus 4.6",
                                    "anthropic/claude-haiku-4.5" =>
                                        "Claude Haiku 4.5",
                                    // Google
                                    "google/gemini-3.1-pro-preview" =>
                                        "Gemini 3.1 Pro",
                                    "google/gemini-3-flash-preview" =>
                                        "Gemini 3 Flash",
                                    "google/gemini-3.1-flash-lite-preview" =>
                                        "Gemini 3.1 Flash Lite",
                                    "google/gemini-2.5-flash" => "Gemini 2.5 Flash",
                                    // Meta
                                    "meta-llama/llama-3.3-70b-instruct" =>
                                        "Llama 3.3 70B",
                                    // Mistral
                                    "mistralai/mistral-large-2512" =>
                                        "Mistral Large 3",
                                    "mistralai/mistral-medium-3.1" =>
                                        "Mistral Medium 3.1",
                                    // DeepSeek
                                    "deepseek/deepseek-chat-v3" =>
                                        "DeepSeek Chat v3",
                                    "deepseek/deepseek-chat-v3.1" =>
                                        "DeepSeek V3.1",
                                    "deepseek/deepseek-v3.2" => "DeepSeek V3.2",
                                    "deepseek/deepseek-v4-pro" => "DeepSeek V4 Pro",
                                    "deepseek/deepseek-v4-flash" => "DeepSeek V4 Flash",
                                    // Z-AI
                                    "z-ai/glm-5.1" => "GLM 5.1",
                                    "z-ai/glm-5-turbo" => "GLM 5 Turbo",
                                    // Moonshot
                                    "moonshotai/kimi-k2.5" => "Kimi K2.5",
                                    // Qwen
                                    "qwen/qwen3.5-flash-02-23" => "Qwen 3.5 Flash",
                                    "qwen/qwen3.6-plus" => "Qwen 3.6 Plus",
                                    // MiniMax
                                    "minimax/minimax-m2.7" => "MiniMax M2.7",
                                    // X-AI
                                    "x-ai/grok-4" => "Grok 4",
                                    "x-ai/grok-4.1-fast" => "Grok 4.1 Fast",
                                    "x-ai/grok-4.20" => "Grok 4.20",
                                ];
                                foreach ($openrouter_models as $slug => $label) {
                                    $render_model_option($slug, $label);
                                }
                                ?>
                            </optgroup>
                        </select>
                            <!-- OpenRouter reasoning toggle (same row as model dropdown, shown only when provider = openrouter) -->
                            <div class="provider-field provider-openrouter" style="<?php echo $current_provider !==
                            "openrouter"
                                ? "display:none;"
                                : ""; ?>">
                                <label class="aicwp-ui-checkbox-label" style="margin-top: 8px;">
                                    <input type="checkbox" name="aicwp_openrouter_reasoning" value="1" <?php checked(
                                        get_option(
                                            "aicwp_openrouter_reasoning",
                                            0,
                                        ),
                                        1,
                                    ); ?> />
                                    <span class="aicwp-ui-checkbox-custom"></span>
                                    <span class="aicwp-ui-checkbox-text"><?php _e(
                                        "Enable reasoning",
                                        "ai-chat-wp",
                                    ); ?> <span class="aicwp-ui-hint-icon" data-tooltip="<?php esc_attr_e(
     "Reasoning can produce better answers for complex questions but might be slower.",
     "ai-chat-wp",
 ); ?>" aria-label="<?php esc_attr_e(
    "More info",
    "ai-chat-wp",
); ?>" tabindex="0">?</span></span>
                                </label>
                            </div>
                        </div>
                        <p class="aicwp-ui-help-text" id="model-help-text">
                            <?php if ($current_provider === "gemini") {
                                _e(
                                    "Select the Gemini model for chat responses. Better models provide more accurate and context-aware responses.",
                                    "ai-chat-wp",
                                );
                            } elseif ($current_provider === "mistral") {
                                _e(
                                    "Select the Mistral model for chat responses. Better models provide more accurate and context-aware responses.",
                                    "ai-chat-wp",
                                );
                            } elseif ($current_provider === "openrouter") {
                                _e(
                                    "Select an OpenRouter model for chat responses. The better the model, the more accurate and context-aware the responses at a cost of time response.",
                                    "ai-chat-wp",
                                );
                            } else {
                                _e(
                                    "Select the OpenAI model for chat responses. The better the model, the more accurate and context-aware the responses at a cost of time response.",
                                    "ai-chat-wp",
                                );
                            } ?>
                        </p>
                        <p class="aicwp-ui-warning-text" id="gemini-3-warning" style="color: #dc3545; font-size: 13px; margin-top: -10px; display: <?php echo $current_provider ===
                            "gemini" && $model === "gemini-3.1-pro-preview"
                            ? "block"
                            : "none"; ?>;">
                            ⚠️ <?php _e(
                                'To use Gemini 3 you need to enable billing in Google. It doesn\'t work within Free Tier.',
                                "ai-chat-wp",
                            ); ?>
                        </p>
                        <p class="aicwp-ui-warning-text" id="openrouter-multimodal-warning" style="color: #dc3545; font-size: 13px; margin-top: -10px; display: none;">
                            ⚠️ <?php _e(
                                "This model does not support audio or image input.",
                                "ai-chat-wp",
                            ); ?>
                        </p>
                        </div>
                    </div>

                    <!-- Rate Limit Settings - Collapsible -->
                    <div class="aicwp-ui-collapsible-section">
                        <div class="aicwp-ui-collapsible-header" data-section="rate-limits">
                            <span class="aicwp-ui-collapsible-title">
                                <span class="dashicons dashicons-shield"></span>
                                <?php _e(
                                    "Rate Limit Settings",
                                    "ai-chat-wp",
                                ); ?>
                            </span>
                            <span class="aicwp-ui-collapsible-toggle">
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
                            </span>
                        </div>
                        <div class="aicwp-ui-collapsible-content">
                            <div class="aicwp-ui-form-group">
                                <label for="aicwp_rate_limit_per_hour" class="aicwp-ui-label">
                                    <?php _e(
                                        "Global API Rate Limit (per hour)",
                                        "ai-chat-wp",
                                    ); ?>
                                </label>
                                <input type="number" id="aicwp_rate_limit_per_hour" name="aicwp_rate_limit_per_hour" value="<?php echo esc_attr(
                                    get_option(
                                        "aicwp_rate_limit_per_hour",
                                        1000,
                                    ),
                                ); ?>" min="100" max="10000" step="1" class="aicwp-ui-input aicwp-ui-input-small" />
                                <span><?php _e(
                                    "API calls per hour",
                                    "ai-chat-wp",
                                ); ?></span>
                                <div class="aicwp-ui-help-text">
                                    <?php _e(
                                        "Maximum number of API calls allowed per hour (includes chat completions, and search operations).",
                                        "ai-chat-wp",
                                    ); ?>
                                    <?php
                                    // Show current usage if available
                                    $rate_limit_key =
                                        "aicwp_rate_limit_" .
                                        date("Y-m-d-H");
                                    $current_calls = (int) get_option(
                                        $rate_limit_key,
                                        0,
                                    );
                                    if ($current_calls > 0) {
                                        echo '<br><span style="background-color: rgba(0, 123, 255, 0.1); padding: 3px 7px; display: inline-block; margin-top: 5px; border-radius: 3px; color: rgba(0, 123, 255, 1);">Current hour usage: <strong style="color: rgba(0, 123, 255, 1)">' .
                                            $current_calls .
                                            "</strong> calls</span>";
                                    }
                                    ?>
                                </div>
                            </div>

                            <!-- User Rate Limiting - Per IP Address -->
                            <div class="aicwp-ui-form-group">
                                <label class="aicwp-ui-label"><?php _e(
                                    "User Rate Limit - Per IP Address",
                                    "ai-chat-wp",
                                ); ?> <a href="#" id="clear-ip-rate-limits" style="color: #dc3545; text-decoration: underline; font-weight: 400 !important;"><?php _e(
     "Clear all IP limits",
     "ai-chat-wp",
 ); ?></a></label>
                                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <input type="number" id="aicwp_chat_rate_limit_tier1" name="aicwp_chat_rate_limit_tier1" value="<?php echo esc_attr(
                                            get_option(
                                                "aicwp_chat_rate_limit_tier1",
                                                10,
                                            ),
                                        ); ?>" min="1" max="100" class="aicwp-ui-input" style="width: 80px;" />
                                        <span style="font-size: 12px; color: #666;"><?php _e(
                                            "/min",
                                            "ai-chat-wp",
                                        ); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <input type="number" id="aicwp_chat_rate_limit_tier2" name="aicwp_chat_rate_limit_tier2" value="<?php echo esc_attr(
                                            get_option(
                                                "aicwp_chat_rate_limit_tier2",
                                                30,
                                            ),
                                        ); ?>" min="1" max="500" class="aicwp-ui-input" style="width: 80px;" />
                                        <span style="font-size: 12px; color: #666;"><?php _e(
                                            "/15min",
                                            "ai-chat-wp",
                                        ); ?></span>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 5px;">
                                        <input type="number" id="aicwp_chat_rate_limit_tier3" name="aicwp_chat_rate_limit_tier3" value="<?php echo esc_attr(
                                            get_option(
                                                "aicwp_chat_rate_limit_tier3",
                                                100,
                                            ),
                                        ); ?>" min="1" max="10000" class="aicwp-ui-input" style="width: 80px;" />
                                        <span style="font-size: 12px; color: #666;"><?php _e(
                                            "/day",
                                            "ai-chat-wp",
                                        ); ?></span>
                                    </div>
                                </div>
                                <div class="aicwp-ui-help-text"><?php _e(
                                    "Max chat messages per IP address in each time window. Enforced server-side.",
                                    "ai-chat-wp",
                                ); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Provider Change Confirmation Modal -->
                    <div id="provider-change-modal" class="aicwp-ui-modal" style="display: none;">
                        <div class="aicwp-ui-modal-overlay"></div>
                        <div class="aicwp-ui-modal-content">
                            <div class="aicwp-ui-modal-header">
                                <h3><?php _e(
                                    "Switch AI Provider?",
                                    "ai-chat-wp",
                                ); ?></h3>
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #ff9800;">
                                    <path d="M12 2L1 21H23L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <path d="M12 9V13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    <circle cx="12" cy="17" r="1" fill="currentColor"/>
                                </svg>
                            </div>
                            <div class="aicwp-ui-modal-body">
                                <p><?php _e(
                                    "Switching to a different AI provider will:",
                                    "ai-chat-wp",
                                ); ?></p>
                                <ul class="aicwp-ui-modal-list">
                                    <li><?php _e(
                                        "Clear all existing embeddings",
                                        "ai-chat-wp",
                                    ); ?></li>
                                    <li><strong><?php _e(
                                        "Require retraining with the new provider",
                                        "ai-chat-wp",
                                    ); ?></strong></li>
                                </ul>
                            </div>
                            <div class="aicwp-ui-modal-footer">
                                <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="modal-cancel-btn">
                                    <?php _e("Cancel", "ai-chat-wp"); ?>
                                </button>
                                <button type="button" class="aicwp-ui-button aicwp-ui-button-danger" id="modal-confirm-btn">
                                    <span class="button-text"><?php _e(
                                        "Yes, Clear Embeddings",
                                        "ai-chat-wp",
                                    ); ?></span>
                                    <span class="button-spinner" style="display: none;">
                                        <span class="aicwp-ui-spinner"></span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>

            </div>
        </div>

        <!-- AI Semantic Search Field - Shortcode Builder -->
        <div class="aicwp-ui-card" data-chat-section="semantic-search">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path><path d="M11 8v6"></path><path d="M8 11h6"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e(
                        "AI Semantic Search Field",
                        "ai-chat-wp",
                    ); ?></h3>
                    <p><?php _e(
                        "Add AI-powered semantic search to any page using a shortcode.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">
                <?php $this->render_search_field_shortcode_builder(); ?>
                    <?php $this->render_min_match_slider(); ?>

                    <div class="aicwp-ui-form-group">
                        <label for="aicwp_max_results" class="aicwp-ui-label">
                            <?php _e(
                                "Maximum AI Top Picks Results",
                                "ai-chat-wp",
                            ); ?>
                        </label>
                        <input type="number" id="aicwp_max_results" name="aicwp_max_results" value="<?php echo esc_attr(
                            get_option("aicwp_max_results", 10),
                        ); ?>" min="3" max="50" step="1" class="aicwp-ui-input aicwp-ui-input-small" />
                        <span><?php _e("results", "ai-chat-wp"); ?></span>
                        <div class="aicwp-ui-help-text">
                            <?php _e(
                                'Maximum number of <strong>"Best Match" badge</strong> results to display in <strong>search field shortcode</strong> dropdown.',
                                "ai-chat-wp",
                            ); ?>
                            <br>
                            <span style="color: #27ae60;">5</span> = <?php _e(
                                "Balanced (recommended)",
                                "ai-chat-wp",
                            ); ?>,
                            <span style="color: #2980b9;">3</span> = <?php _e(
                                "Compact",
                                "ai-chat-wp",
                            ); ?>,
                            <span style="color: #f39c12;">20</span> = <?php _e(
                                "Comprehensive",
                                "ai-chat-wp",
                            ); ?>
                        </div>
                    </div>
            </div>
        </div>

        <div class="aicwp-ui-card" data-chat-section="developer-debug">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 16 4-4-4-4"></path><path d="m6 8-4 4 4 4"></path><path d="m14.5 4-5 16"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e(
                        "Developer & Debug Options",
                        "ai-chat-wp",
                    ); ?></h3>
                    <p><?php _e(
                        "Advanced options for development and troubleshooting.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">
                    <div class="aicwp-ui-form-group">
                        <label class="aicwp-ui-checkbox-label">
                            <input type="checkbox" name="aicwp_debug_mode" value="1" <?php checked(
                                get_option("aicwp_debug_mode"),
                                1,
                            ); ?> />
                            <span class="aicwp-ui-checkbox-custom"></span>
                            <span class="aicwp-ui-checkbox-text">
                                <?php _e("Debug Mode", "ai-chat-wp"); ?>
                                <small><?php _e(
                                    "Enable debug logging to wp-content/debug.log",
                                    "ai-chat-wp",
                                ); ?></small>
                            </span>
                        </label>
                        <div class="aicwp-ui-help-text"><?php _e(
                            "When enabled, detailed search information will be logged to help troubleshoot issues. Make sure WP_DEBUG_LOG is enabled in wp-config.php.",
                            "ai-chat-wp",
                        ); ?></div>
                    </div>

                    <div class="aicwp-ui-form-group">
                        <label class="aicwp-ui-checkbox-label">
                            <input type="checkbox" name="aicwp_chat_lazy_load" value="1" <?php checked(
                                get_option("aicwp_chat_lazy_load"),
                                1,
                            ); ?> />
                            <span class="aicwp-ui-checkbox-custom"></span>
                            <span class="aicwp-ui-checkbox-text">
                                Lazy Load Chatbot
                                <small><?php _e(
                                    "Load chatbot scripts only when the floating widget is opened",
                                    "ai-chat-wp",
                                ); ?></small>
                            </span>
                        </label>
                        <div class="aicwp-ui-help-text"><?php _e(
                            "Defers loading chatbot JavaScript until the user opens the chat. Improves page load performance. Only applies to the floating widget, not the shortcode.",
                            "ai-chat-wp",
                        ); ?></div>
                    </div>

                    <div class="aicwp-ui-form-group">
                        <label for="aicwp_chat_custom_css" class="aicwp-ui-label">
                            <?php _e("Custom CSS", "ai-chat-wp"); ?>
                        </label>
                        <textarea id="aicwp_chat_custom_css" name="aicwp_chat_custom_css" rows="8" class="aicwp-ui-input" style="font-family: Menlo, Consolas, Monaco, monospace; font-size: 12px;"><?php echo esc_textarea(
                            get_option("aicwp_chat_custom_css", ""),
                        ); ?></textarea>
                    </div>

                    <!-- Database Tables Status -->
                    <div class="aicwp-ui-form-group">
                        <label class="aicwp-ui-label"><?php _e(
                            "Database Tables Status",
                            "ai-chat-wp",
                        ); ?></label>
                        <?php
                        global $wpdb;
                        $tables = [
                            $wpdb->prefix . "aicwp_embeddings" => __(
                                "Embeddings",
                                "ai-chat-wp",
                            ),
                            $wpdb->prefix . "aicwp_chat_history" => __(
                                "Chat History",
                                "ai-chat-wp",
                            ),
                            $wpdb->prefix . "aicwp_contact_messages" => __(
                                "Contact Messages",
                                "ai-chat-wp",
                            ),
                        ];
                        $missing_tables = [];
                        $missing_columns = [];

                        foreach ($tables as $table_name => $label) {
                            if (
                                $wpdb->get_var(
                                    "SHOW TABLES LIKE '{$table_name}'",
                                ) !== $table_name
                            ) {
                                $missing_tables[] = $label;
                            }
                        }

                        // Check for ip_address column in chat_history table
                        $chat_history_table =
                            $wpdb->prefix . "aicwp_chat_history";
                        if (
                            $wpdb->get_var(
                                "SHOW TABLES LIKE '{$chat_history_table}'",
                            ) === $chat_history_table
                        ) {
                            $ip_column = $wpdb->get_results(
                                "SHOW COLUMNS FROM {$chat_history_table} LIKE 'ip_address'",
                            );
                            if (empty($ip_column)) {
                                $missing_columns[] = __(
                                    "Chat History: ip_address column",
                                    "ai-chat-wp",
                                );
                            }
                        }

                        $has_issues =
                            !empty($missing_tables) || !empty($missing_columns);
                        ?>
                        <?php if (!$has_issues): ?>
                        <div style="padding: 8px 10px; background: #def2e9; border-radius: 5px;  margin-top: 8px;">
                            <span style="color: #047857;">
                                <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span>
                                <?php _e(
                                    "All database tables are properly configured.",
                                    "ai-chat-wp",
                                ); ?>
                            </span>
                        </div>
                        <?php else: ?>
                        <div style="padding: 8px 10px; background: #fef2f2; border-radius: 5px; margin-top: 8px;">
                            <span style="color: #dc2626;">
                                <span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
                                <?php
                                $issues = [];
                                if (!empty($missing_tables)) {
                                    $issues[] = sprintf(
                                        __(
                                            "Missing tables: %s",
                                            "ai-chat-wp",
                                        ),
                                        implode(", ", $missing_tables),
                                    );
                                }
                                if (!empty($missing_columns)) {
                                    $issues[] = sprintf(
                                        __(
                                            "Missing columns: %s",
                                            "ai-chat-wp",
                                        ),
                                        implode(", ", $missing_columns),
                                    );
                                }
                                echo implode("<br>", $issues);
                                ?>
                            </span>
                            <div style="margin-top: 8px; font-size: 13px; color: #dc2626; font-weight: 500;">
                                <?php _e(
                                    "To fix: Deactivate and reactivate the plugin.",
                                    "ai-chat-wp",
                                ); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="aicwp-ui-form-group">
                        <label class="aicwp-ui-label"><?php _e(
                            "Server Info",
                            "ai-chat-wp",
                        ); ?></label>
                        <?php
                        $server_memory =
                            ini_get("memory_limit") ?:
                            __("Unknown", "ai-chat-wp");
                        $server_memory_int = wp_convert_hr_to_bytes(
                            $server_memory,
                        );
                        $memory_low =
                            $server_memory_int > 0 &&
                            $server_memory_int < 256 * 1024 * 1024;
                        $border_color = $memory_low ? "#ef4444" : "#94a3b8";
                        ?>
                        <div style="padding: 8px 10px; background: #f8fafc; border-radius: 4px; border-left: 3px solid <?php echo $border_color; ?>; margin-top: 8px; display: flex; gap: 5px 20px; flex-wrap: wrap;">
                            <span><?php _e(
                                "WP Memory Limit:",
                                "ai-chat-wp",
                            ); ?> <code><?php echo esc_html(
     defined("WP_MEMORY_LIMIT") ? WP_MEMORY_LIMIT : "40M",
 ); ?></code></span>
                            <span><?php _e(
                                "PHP Memory Limit:",
                                "ai-chat-wp",
                            ); ?> <code><?php echo esc_html(
     $server_memory,
 ); ?></code></span>
                            <span><?php _e(
                                "Max Execution Time:",
                                "ai-chat-wp",
                            ); ?> <code><?php echo esc_html(
     ini_get("max_execution_time") ?: "0",
 ); ?>s</code></span>
                        </div>
                        <?php if ($memory_low): ?>
                            <div class="aicwp-ui-help-text" style="margin-top: 6px; color: #ef4444;"><?php _e(
                                "PHP memory limit is below 256 MB. This may cause issues with embedding generation and AI search. Please increase it in your hosting panel or php.ini.",
                                "ai-chat-wp",
                            ); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="aicwp-ui-form-group">
                        <label style="font-weight: 500;"><?php _e(
                            "Cache Management",
                            "ai-chat-wp",
                        ); ?></label>
                        <div class="aicwp-ui-help-text"><?php _e(
                            "Clear rate limiting and usage tracking data. Useful for testing or troubleshooting.",
                            "ai-chat-wp",
                        ); ?></div>
                        <div class="aicwp-ui-cache-actions" style="margin-top: 10px;">
                            <button type="button" id="aicwp-clear-cache-btn" class="aicwp-ui-button aicwp-ui-button-secondary">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><path d="M3 6h18M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                                <?php _e("Clear Cache", "ai-chat-wp"); ?>
                            </button>
                            <span id="aicwp-clear-cache-status" style="margin-left: 10px; font-weight: bold;"></span>
                        </div>
                    </div>

            </div>
        </div>
        <?php
    }

    /**
     * Render database management tab
     */
    private function render_database_tab()
    {
        ?>
        <!-- Generate Embeddings Section -->
        <div class="aicwp-ui-card aicwp-ui-card-full-width">
            <div class="aicwp-ui-card-header">
                <h3><?php _e(
                    "Select Content for Training",
                    "ai-chat-wp",
                ); ?></h3>
                <p>
                    <?php printf(
                        /* translators: %s: "New content is automatically trained" (bold text) */
                        __(
                            "Disabling a post type will exclude it from training and search results. %s after adding/editing, no need to run this in future.",
                            "ai-chat-wp",
                        ),
                        '<strong style="font-weight: 500; color: #111;">' .
                            __(
                                "New content is automatically trained",
                                "ai-chat-wp",
                            ) .
                            "</strong>",
                    ); ?>
                </p>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- STEP 1: Content Sources Selection -->
                <?php
                $universal_settings = new AICWP_Universal_Settings();
                $universal_settings->render_content_sources_cards();
                ?>

                <!-- STEP 2: Generation Controls -->

                <!-- Simple Training Interface -->
                <?php $this->render_simple_batch_interface(); ?>
            </div>
        </div>

        <!-- Database Management Section (Actions + Status) -->
        <div class="aicwp-ui-card aicwp-ui-card-toggleable aicwp-ui-card-full-width" data-toggle-id="database-management">
            <div class="aicwp-ui-card-header">
                <h3><?php _e("Database Management", "ai-chat-wp"); ?></h3>
                <p><?php _e(
                    "Manage your AI search database and monitor embedding statistics.",
                    "ai-chat-wp",
                ); ?></p>
                <span class="dashicons dashicons-arrow-down-alt2 aicwp-ui-card-toggle-icon"></span>
            </div>
            <div class="aicwp-ui-card-body">
                <div class="aicwp-ui-db-management-grid">
                    <!-- Left Column: Database Actions -->
                    <div class="aicwp-ui-db-actions-column">
                        <h4><?php _e(
                            "Database Actions",
                            "ai-chat-wp",
                        ); ?></h4>

                        <div class="aicwp-ui-form-group" style="margin-bottom:5px">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" id="disable-auto-training" value="1" <?php checked(
                                    get_option(
                                        "aicwp_disable_auto_training",
                                        false,
                                    ),
                                ); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text" style="font-weight:500;"><?php _e(
                                    "Disable auto-training for new/edited content",
                                    "ai-chat-wp",
                                ); ?></span>
                            </label>
                            <script>
                            jQuery(function($){
                                $('#disable-auto-training').on('change', function(){
                                    var $cb = $(this),
                                        $custom = $cb.next('.aicwp-ui-checkbox-custom'),
                                        $spinner = $('<span class="aicwp-ui-spinner aicwp-ui-spinner--small" style="margin-left:0;top:4px"></span>');
                                    $custom.hide().after($spinner);
                                    AicwpAdmin.ajax({
                                        action: 'aicwp_toggle_auto_training',
                                        data: { disabled: $cb.is(':checked') },
                                        success: function(r){ if(!r.success) $cb.prop('checked', !$cb.is(':checked')); },
                                        error: function(){ $cb.prop('checked', !$cb.is(':checked')); },
                                        complete: function(){ $spinner.remove(); $custom.show(); }
                                    });
                                });
                            });
                            </script>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" id="disable-custom-fields" value="1" <?php checked(
                                    get_option(
                                        "aicwp_disable_custom_fields",
                                        false,
                                    ),
                                ); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text" style="font-weight:500;"><?php _e(
                                    "Disable custom fields in embeddings",
                                    "ai-chat-wp",
                                ); ?>
                                    <span class="aicwp-ui-hint-icon" data-tooltip="<?php echo esc_attr(
                                        __(
                                            "When enabled, auto-detected custom fields will be excluded from embedding content. Requires re-training after changing.",
                                            "ai-chat-wp",
                                        ),
                                    ); ?>" tabindex="0" aria-label="<?php echo esc_attr(
    __("More info about disabling custom fields", "ai-chat-wp"),
); ?>">?</span>
                                </span>
                            </label>
                            <script>
                            jQuery(function($){
                                $('#disable-custom-fields').on('change', function(){
                                    var $cb = $(this),
                                        $custom = $cb.next('.aicwp-ui-checkbox-custom'),
                                        $spinner = $('<span class="aicwp-ui-spinner aicwp-ui-spinner--small" style="margin-left:0;top:4px"></span>');
                                    $custom.hide().after($spinner);
                                    AicwpAdmin.ajax({
                                        action: 'aicwp_toggle_custom_fields',
                                        data: { disabled: $cb.is(':checked') },
                                        success: function(r){ if(!r.success) $cb.prop('checked', !$cb.is(':checked')); },
                                        error: function(){ $cb.prop('checked', !$cb.is(':checked')); },
                                        complete: function(){ $spinner.remove(); $custom.show(); }
                                    });
                                });
                            });
                            </script>
                        </div>

                        <?php
                        $current_provider = get_option(
                            "aicwp_provider",
                            "openai",
                        );
                        $current_embedding_model = get_option(
                            "aicwp_embedding_model",
                            "",
                        );
                        $provider_obj = new AICWP_Provider();
                        $default_model = $provider_obj->get_embedding_model();
                        $selected_model =
                            $current_embedding_model ?: $default_model;

                        $render_embedding_option = function (
                            $slug,
                            $label,
                        ) use ($selected_model) {
                            $icon_url = $this->get_openrouter_vendor_icon_url(
                                $slug,
                            );
                            $img_tag = $icon_url
                                ? '<img src="' .
                                    esc_url($icon_url) .
                                    '" alt="" class="aicwp-ui-model-icon">'
                                : "";
                            printf(
                                '<option value="%s"%s>%s%s</option>',
                                esc_attr($slug),
                                selected($selected_model, $slug, false),
                                $img_tag,
                                esc_html($label),
                            );
                        };
                        ?>
                        <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                            <label for="aicwp_embedding_model" class="aicwp-ui-label">
                                <?php _e(
                                    "Embedding Model",
                                    "ai-chat-wp",
                                ); ?>
                                <span class="aicwp-ui-hint-icon" data-tooltip="<?php echo esc_attr(
                                    __(
                                        "Embedding models convert your content into numerical vectors so the chatbot can find semantically similar results. Higher dimensions (e.g. 3072) capture more nuance and accuracy but are slower to search. Lower dimensions (e.g. 512, 1024) are faster with slightly reduced precision.",
                                        "ai-chat-wp",
                                    ),
                                ); ?>" tabindex="0" aria-label="<?php echo esc_attr(
    __("More info about embedding models", "ai-chat-wp"),
); ?>">?</span>
                                <span id="embedding-model-save-indicator" style="display: none; margin-left: 8px; font-size: 12px; color: #46b450; font-weight: 500;"></span>
                            </label>
                            <select id="aicwp_embedding_model" name="aicwp_embedding_model" class="aicwp-ui-input" style="max-width: 420px;" data-original-value="<?php echo esc_attr(
                                $selected_model,
                            ); ?>">
                                <optgroup class="embedding-model-group embedding-model-group-openai" style="<?php echo $current_provider !==
                                "openai"
                                    ? "display:none;"
                                    : ""; ?>">
                                    <?php $render_embedding_option(
                                        "text-embedding-3-small",
                                        "text-embedding-3-small (1536d) - Default",
                                        true,
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "text-embedding-3-large:512",
                                        "text-embedding-3-large (512d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "text-embedding-3-large:1024",
                                        "text-embedding-3-large (1024d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "text-embedding-3-large:1536",
                                        "text-embedding-3-large (1536d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "text-embedding-3-large:3072",
                                        "text-embedding-3-large (3072d)",
                                    ); ?>
                                </optgroup>
                                <optgroup class="embedding-model-group embedding-model-group-gemini" style="<?php echo $current_provider !==
                                "gemini"
                                    ? "display:none;"
                                    : ""; ?>">
                                    <?php $render_embedding_option(
                                        "gemini-embedding-001",
                                        "gemini-embedding-001 (1536d) - Default",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "gemini-embedding-2:768",
                                        "gemini-embedding-2 (768d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "gemini-embedding-2:1024",
                                        "gemini-embedding-2 (1024d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "gemini-embedding-2:1536",
                                        "gemini-embedding-2 (1536d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "gemini-embedding-2:3072",
                                        "gemini-embedding-2 (3072d)",
                                    ); ?>
                                </optgroup>
                                <optgroup class="embedding-model-group embedding-model-group-mistral" style="<?php echo $current_provider !==
                                "mistral"
                                    ? "display:none;"
                                    : ""; ?>">
                                    <?php $render_embedding_option(
                                        "mistral-embed",
                                        "mistral-embed (1024d)",
                                    ); ?>
                                </optgroup>
                                <optgroup class="embedding-model-group embedding-model-group-openrouter" style="<?php echo $current_provider !==
                                "openrouter"
                                    ? "display:none;"
                                    : ""; ?>">
                                    <?php $render_embedding_option(
                                        "openai/text-embedding-3-small",
                                        "openai/text-embedding-3-small (1536d) - Default",
                                        true,
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "openai/text-embedding-3-large:512",
                                        "openai/text-embedding-3-large (512d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "openai/text-embedding-3-large:1024",
                                        "openai/text-embedding-3-large (1024d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "openai/text-embedding-3-large:1536",
                                        "openai/text-embedding-3-large (1536d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "openai/text-embedding-3-large:3072",
                                        "openai/text-embedding-3-large (3072d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "google/gemini-embedding-2-preview:768",
                                        "google/gemini-embedding-2-preview (768d)",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "google/gemini-embedding-2-preview:1536",
                                        "google/gemini-embedding-2-preview (1536d) - Default",
                                    ); ?>
                                    <?php $render_embedding_option(
                                        "google/gemini-embedding-2-preview:3072",
                                        "google/gemini-embedding-2-preview (3072d)",
                                    ); ?>
                                </optgroup>
                            </select>
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Select the embedding model for generating content vectors.",
                                "ai-chat-wp",
                            ); ?></p>
                            <div id="embedding-model-retrain-notice" class="provider-retrain-notice" style="display: none; margin-top: 8px;">
                                <span class="notice-emoji">⚠️</span> <?php _e(
                                    "Embedding model changed. Save, then go to Data Training to retrain all content.",
                                    "ai-chat-wp",
                                ); ?>
                            </div>
                        </div>

                        <!-- Embedding Model Change Confirmation Modal -->
                        <div id="embedding-model-change-modal" class="aicwp-ui-modal" style="display: none;">
                            <div class="aicwp-ui-modal-overlay"></div>
                            <div class="aicwp-ui-modal-content">
                                <div class="aicwp-ui-modal-header">
                                    <h3><?php _e(
                                        "Change Embedding Model?",
                                        "ai-chat-wp",
                                    ); ?></h3>
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #0073aa;">
                                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
                                        <path d="M12 7V13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        <circle cx="12" cy="16.5" r="1" fill="currentColor"/>
                                    </svg>
                                </div>
                                <div class="aicwp-ui-modal-body">
                                    <p><?php _e(
                                        "Changing the embedding model requires clearing all existing embeddings and retraining all content from scratch.",
                                        "ai-chat-wp",
                                    ); ?></p>
                                </div>
                                <div class="aicwp-ui-modal-footer">
                                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="embedding-model-cancel-btn"><?php _e(
                                        "Cancel",
                                        "ai-chat-wp",
                                    ); ?></button>
                                    <button type="button" class="aicwp-ui-button aicwp-ui-button-primary" id="embedding-model-confirm-btn"><?php _e(
                                        "Clear & Change Model",
                                        "ai-chat-wp",
                                    ); ?></button>
                                </div>
                            </div>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label for="listing-id-input" class="aicwp-ui-label">
                                <?php _e(
                                    "Check Embedding",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <div class="db-action-btns">
                                <input type="number" id="listing-id-input" placeholder="Enter Item ID" class="aicwp-ui-input aicwp-ui-input-small" />
                                <button type="button" id="check-embedding" class="aicwp-ui-button aicwp-ui-button-secondary"><?php _e(
                                    "Check Embedding",
                                    "ai-chat-wp",
                                ); ?></button>
                            </div>
                            <div class="aicwp-ui-help-text"><?php _e(
                                "Enter an item ID to view its embedding data and processed content.",
                                "ai-chat-wp",
                            ); ?></div>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label for="regenerate-listing-id-input" class="aicwp-ui-label">
                                <?php _e(
                                    "Regenerate Embedding",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <div class="db-action-btns">
                                <input type="number" id="regenerate-listing-id-input" placeholder="Enter Item ID" class="aicwp-ui-input aicwp-ui-input-small" />
                                <button type="button" id="regenerate-embedding" class="aicwp-ui-button aicwp-ui-button-primary">
                                    <span class="button-text"><?php _e(
                                        "Regenerate Embedding",
                                        "ai-chat-wp",
                                    ); ?></span>
                                    <span class="button-spinner" style="display: none;">
                                        <span class="aicwp-ui-spinner"></span>
                                        <?php _e(
                                            "Processing...",
                                            "ai-chat-wp",
                                        ); ?>
                                    </span>
                                </button>
                            </div>
                            <div class="aicwp-ui-help-text"><?php _e(
                                "Enter an item ID to regenerate its embedding data. This will fetch fresh content and create a new embedding.",
                                "ai-chat-wp",
                            ); ?></div>
                            <div id="regenerate-embedding-result" style="margin-top: 10px; display: none;"></div>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label for="delete-post-type-select" class="aicwp-ui-label">
                                <?php _e(
                                    "Delete Embeddings by Post Type",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <div class="db-action-btns">
                                <select id="delete-post-type-select" class="aicwp-ui-input aicwp-ui-input-small" style="max-width: 200px;">
                                    <option value=""><?php _e(
                                        "Select post type...",
                                        "ai-chat-wp",
                                    ); ?></option>
                                    <?php
                                    // Get enabled post types
                                    $enabled_post_types = AICWP_Database_Manager::get_enabled_post_types();
                                    foreach (
                                        $enabled_post_types
                                        as $post_type
                                    ) {
                                        $post_type_obj = get_post_type_object(
                                            $post_type,
                                        );
                                        if ($post_type_obj) {
                                            echo '<option value="' .
                                                esc_attr($post_type) .
                                                '">' .
                                                esc_html(
                                                    $post_type_obj->label,
                                                ) .
                                                "</option>";
                                        }
                                    }
                                    ?>
                                </select>
                                <button type="button" id="delete-by-post-type" class="aicwp-ui-button aicwp-ui-button-danger"><?php _e(
                                    "Delete Embeddings",
                                    "ai-chat-wp",
                                ); ?></button>
                            </div>
                            <div class="aicwp-ui-help-text"><?php _e(
                                "Delete all embeddings for a specific post type. This will not delete the posts themselves, only their embeddings.",
                                "ai-chat-wp",
                            ); ?></div>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <button type="button" id="clear-database" class="aicwp-ui-button aicwp-ui-button-danger"><?php _e(
                                "Clear All Embeddings",
                                "ai-chat-wp",
                            ); ?></button>
                            <div class="aicwp-ui-help-text"><?php _e(
                                "Delete all embeddings. You will need to regenerate them after clearing.",
                                "ai-chat-wp",
                            ); ?></div>
                        </div>
                    </div>

                    <!-- Right Column: Database Status -->
                    <div class="aicwp-ui-db-status-column">
                        <h4><?php _e(
                            "Database Status",
                            "ai-chat-wp",
                        ); ?></h4>
                        <div id="database-status">
                            <div id="status-content">
                                <p><span class="aicwp-ui-spinner" style="margin-right: 6px;"></span><?php _e(
                                    "Loading database status...",
                                    "ai-chat-wp",
                                ); ?></p>
                            </div>
                            <div class="aicwp-ui-form-actions">
                                <button type="button" id="refresh-status" class="aicwp-ui-button aicwp-ui-button-secondary"><?php _e(
                                    "Refresh Status",
                                    "ai-chat-wp",
                                ); ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Embedding Viewer Section -->
        <div id="embedding-viewer" class="aicwp-ui-card aicwp-ui-card-full-width" style="display: none;">
            <div class="aicwp-ui-card-header">
                <h3><?php _e("Embedding Data", "ai-chat-wp"); ?></h3>
            </div>
            <div class="aicwp-ui-card-body">
                <div id="embedding-content"></div>
                <div class="aicwp-ui-form-actions">
                    <button type="button" id="close-embedding" class="aicwp-ui-button aicwp-ui-button-secondary"><?php _e(
                        "Close",
                        "ai-chat-wp",
                    ); ?></button>
                </div>
            </div>
        </div>

        <div id="action-result" class="aicwp-ui-card" style="display: none;">
            <div class="aicwp-ui-card-body">
                <div id="result-message-content" class="aicwp-ui-alert"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Minimum Match Percentage slider
     * Reusable component displayed in search-related sections
     */
    private function render_min_match_slider()
    {
        $min_match_value = intval(
            get_option("aicwp_min_match_percentage", 50),
        );
        if ($min_match_value < 20) {
            $quality_class = "quality-very-low";
            $quality_label = __(
                "Loose - many results, lower relevance",
                "ai-chat-wp",
            );
        } elseif ($min_match_value < 40) {
            $quality_class = "quality-low";
            $quality_label = __(
                "Broad - more results, some may be less relevant",
                "ai-chat-wp",
            );
        } elseif ($min_match_value < 60) {
            $quality_class = "quality-balanced";
            $quality_label = __(
                "Balanced - good mix of quantity and quality",
                "ai-chat-wp",
            );
        } elseif ($min_match_value < 80) {
            $quality_class = "quality-high";
            $quality_label = sprintf(
                __(
                    "Quality focused - pay attention because %syou might start getting little results%s",
                    "ai-chat-wp",
                ),
                "<strong>",
                "</strong>",
            );
        } else {
            $quality_class = "quality-very-high";
            $quality_label = sprintf(
                __(
                    "Very strict — %syou might get little to no results%s",
                    "ai-chat-wp",
                ),
                "<strong>",
                "</strong>",
            );
        }
        ?>
        <div class="aicwp-ui-form-group">
            <label for="aicwp_min_match_percentage" class="aicwp-ui-label">
                <?php _e("Minimum Match Percentage", "ai-chat-wp"); ?>
            </label>
            <div class="aicwp-ui-quality-slider-container">
                <div class="aicwp-ui-quality-value-display <?php echo esc_attr(
                    $quality_class,
                ); ?>" id="min-match-display">
                    <span class="aicwp-ui-quality-value-badge <?php echo esc_attr(
                        $quality_class,
                    ); ?>" id="min-match-badge"><?php echo esc_html(
    $min_match_value,
); ?>%</span>
                    <span class="aicwp-ui-quality-value-label" id="min-match-label"><?php echo wp_kses(
                        $quality_label,
                        ["strong" => []],
                    ); ?></span>
                </div>
                <div class="aicwp-ui-quality-slider-wrapper">
                    <div class="aicwp-ui-quality-slider-track <?php echo esc_attr(
                        $quality_class,
                    ); ?>" id="min-match-track" style="--slider-glow-position: <?php echo esc_attr(
    $min_match_value,
); ?>%;"></div>
                    <input type="range"
                           id="aicwp_min_match_percentage"
                           name="aicwp_min_match_percentage"
                           value="<?php echo esc_attr($min_match_value); ?>"
                           min="0"
                           max="100"
                           step="5"
                           class="aicwp-ui-quality-slider <?php echo esc_attr(
                               $quality_class,
                           ); ?>" />
                </div>
            </div>
            <div class="aicwp-ui-help-text" style="margin-top: 12px;">
                <?php echo sprintf(
                    __(
                        'Acts as a <strong>quality filter</strong> for search field results and RAG context retrieval. Only <strong><span title="%s" style="cursor: help; border-bottom: 1px dotted currentColor;">sources</span> scoring above this level</strong> will be shown. Does not affect chatbot product search - the LLM handles filtering there.',
                        "ai-chat-wp",
                    ),
                    esc_attr__(
                        "pages, documents, posts, products, etc.",
                        "ai-chat-wp",
                    ),
                ); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render AI Semantic Search Field shortcode builder
     * Shown for all sites
     */
    private function render_search_field_shortcode_builder()
    {
        // Get enabled post types from the training settings
        $enabled_post_types = get_option(
            "aicwp_enabled_post_types",
            [],
        );

        // Filter out attachment/media and PDF document post types
        $enabled_post_types = array_filter($enabled_post_types, function (
            $type,
        ) {
            // Always exclude these
            if (in_array($type, ["attachment", "ai_pdf_document"])) {
                return false;
            }
            return true;
        });

        // Get embedding counts per post type
        global $wpdb;
        $table_name = $wpdb->prefix . "aicwp_embeddings";
        $table_exists =
            $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;

        $post_type_counts = [];
        if ($table_exists && !empty($enabled_post_types)) {
            foreach ($enabled_post_types as $post_type) {
                $count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_name} e
                     INNER JOIN {$wpdb->posts} p ON e.listing_id = p.ID
                     WHERE p.post_type = %s AND p.post_status = 'publish'",
                        $post_type,
                    ),
                );
                $post_type_counts[$post_type] = intval($count);
            }
        }

        // Get human-readable post type labels
        $post_type_labels = [];
        foreach ($enabled_post_types as $post_type) {
            $post_type_obj = get_post_type_object($post_type);
            if ($post_type_obj) {
                $post_type_labels[$post_type] = $post_type_obj->labels->name;
            } else {
                $post_type_labels[$post_type] = ucfirst($post_type);
            }
        }
        ?>
        <div class="aicwp-ui-shortcode-builder" id="ai-search-field-builder">
            <?php if (empty($enabled_post_types)): ?>
                <div class="aicwp-ui-notice aicwp-ui-notice-warning">
                    <strong><?php _e(
                        "No content types trained yet!",
                        "ai-chat-wp",
                    ); ?></strong>
                    <p><?php _e(
                        'Please go to the "Data Training" tab first and train at least one content type before using this shortcode.',
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            <?php else: ?>
                <p class="aicwp-ui-help-text">
                    <?php _e(
                        "<strong>Do not need chatbot? No problems - use this shortcode </strong> builder to create an AI-powered semantic search field. Select which content types to include in search results.",
                        "ai-chat-wp",
                    ); ?>
                </p>

                <!-- Shortcode Preview Info -->
                <div class="aicwp-ui-shortcode-info-box">
                    <strong><?php _e(
                        "How it works:",
                        "ai-chat-wp",
                    ); ?></strong>
                    <ol>
                        <li><?php _e(
                            "Users type their search query in natural language",
                            "ai-chat-wp",
                        ); ?></li>
                        <li><?php _e(
                            "AI finds semantically related content (not just keyword matches)",
                            "ai-chat-wp",
                        ); ?></li>
                        <li><?php _e(
                            "Results are displayed in a dropdown according to Minimum Match Percentage setting",
                            "ai-chat-wp",
                        ); ?></li>
                                </ol>
                </div>

                <!-- Post Types Selection -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-label">
                        <?php _e(
                            "Select Content Types to Search:",
                            "ai-chat-wp",
                        ); ?>
                    </label>
                    <div class="aicwp-ui-post-type-grid">
                        <?php foreach ($enabled_post_types as $post_type):

                            $count = isset($post_type_counts[$post_type])
                                ? $post_type_counts[$post_type]
                                : 0;
                            $label = isset($post_type_labels[$post_type])
                                ? $post_type_labels[$post_type]
                                : ucfirst($post_type);
                            ?>
                        <label class="aicwp-ui-checkbox-label aicwp-ui-post-type-checkbox <?php echo $count ===
                        0
                            ? "disabled"
                            : ""; ?>">
                            <input type="checkbox"
                                   class="shortcode-post-type"
                                   value="<?php echo esc_attr($post_type); ?>"
                                   data-label="<?php echo esc_attr($label); ?>"
                                   <?php echo $count === 0
                                       ? "disabled"
                                       : ""; ?>>
                            <span class="aicwp-ui-checkbox-custom"></span>
                            <span class="aicwp-ui-checkbox-text">
                                <?php echo esc_html($label); ?>
                                <small class="<?php echo $count > 0
                                    ? "trained"
                                    : "not-trained"; ?>">
                                    <?php if ($count > 0) {
                                        printf(
                                            _n(
                                                "%d item trained",
                                                "%d items trained",
                                                $count,
                                                "ai-chat-wp",
                                            ),
                                            $count,
                                        );
                                    } else {
                                        _e("Not trained yet", "ai-chat-wp");
                                    } ?>
                                </small>
                            </span>
                        </label>
                        <?php
                        endforeach; ?>
                    </div>
                </div>

                <div class="aicwp-ui-shortcode-builder-info">
                    <span><?php _e(
                        "You can index/train content to search in the",
                        "ai-chat-wp",
                    ); ?> <a href="<?php echo esc_url(
     admin_url("admin.php?page=ai-chat-wp&tab=database"),
 ); ?>"><?php _e("Data Training", "ai-chat-wp"); ?></a> <?php _e(
    "tab.",
    "ai-chat-wp",
); ?></span>
                </div>

                <!-- Additional Options -->
                <div class="aicwp-ui-shortcode-form-row">
                    <div class="aicwp-ui-form-group">
                        <label for="shortcode-placeholder" class="aicwp-ui-label"><?php _e(
                            "Placeholder Text:",
                            "ai-chat-wp",
                        ); ?></label>
                        <input type="text"
                               id="shortcode-placeholder"
                               class="aicwp-ui-input"
                               value="<?php echo esc_attr__(
                                   "Search anything...",
                                   "ai-chat-wp",
                               ); ?>"
                               placeholder="<?php esc_attr_e(
                                   "Enter placeholder text",
                                   "ai-chat-wp",
                               ); ?>">
                    </div>
                    <div class="aicwp-ui-form-group">
                        <label for="shortcode-limit" class="aicwp-ui-label"><?php _e(
                            "Max Results:",
                            "ai-chat-wp",
                        ); ?></label>
                        <input type="number"
                               id="shortcode-limit"
                               class="aicwp-ui-input aicwp-ui-input-small"
                               value="<?php echo esc_attr(
                                   get_option(
                                       "aicwp_max_results",
                                       10,
                                   ),
                               ); ?>"
                               min="1"
                               max="50">
                    </div>
                </div>

                <!-- Generated Shortcode -->
                <div class="aicwp-ui-form-group aicwp-ui-generated-shortcode">
                    <label class="aicwp-ui-label">
                        <?php _e("Generated Shortcode:", "ai-chat-wp"); ?>
                    </label>
                    <div class="aicwp-ui-generated-shortcode-row">
                        <input type="text"
                               id="generated-shortcode"
                               class="aicwp-ui-input"
                               readonly
                               value='[ai_search_field]'>
                        <button type="button"
                                id="copy-shortcode-btn"
                                class="aicwp-ui-button aicwp-ui-button-primary">
                            <?php _e("Copy Shortcode", "ai-chat-wp"); ?>
                        </button>
                    </div>
                    <div class="aicwp-ui-help-text">
                        <?php _e(
                            "Copy this shortcode and paste it into any page, post, or widget to display the AI semantic search field.",
                            "ai-chat-wp",
                        ); ?>
                    </div>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render simple batch interface
     */
    private function render_simple_batch_interface()
    {
        ?>
        <div id="listing-detection-info" class="listing-detection-info">
            <div id="listing-count-text" class="listing-count-text">
                <span class="aicwp-ui-spinner" style="margin-right: 6px;"></span><?php _e(
                    "Loading content information...",
                    "ai-chat-wp",
                ); ?>
            </div>
        </div>

        <div id="regeneration-controls">
            <div class="aicwp-ui-form-actions">
                <button type="button" id="start-regeneration" class="aicwp-ui-button aicwp-ui-button-primary"><svg class="rocket-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path class="rocket-fire" d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg> <?php _e(
                    "Start Training",
                    "ai-chat-wp",
                ); ?></button>
                <button type="button" id="stop-regeneration" class="aicwp-ui-button aicwp-ui-button-secondary" style="display: none;"><?php _e(
                    "Stop",
                    "ai-chat-wp",
                ); ?></button>
            </div>
        </div>

        <div id="regeneration-progress" style="display: none; margin-top: 20px; padding: 20px; background: #f8f8f8; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
            <div class="rpa-icon-wrapper" style="display: inline-block; padding: 8px; padding-bottom: 0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="56" height="56" viewBox="4 4 40 40" class="rpa-animated" style="overflow: visible;">
                    <!-- Robot body (bounces) -->
                    <g class="rpa-body">
                        <!-- Main body -->
                        <path fill="#DBE8FF" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M22.323,8.063h3.354c4.986,0,9.027,4.042,9.027,9.027v14.127c0,2.074-1.681,3.755-3.755,3.755h-13.9c-2.074,0-3.755-1.681-3.755-3.755V17.09C13.295,12.105,17.337,8.063,22.323,8.063z"/>
                        <!-- Ears -->
                        <path fill="#DBE8FF" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M35.786,19.973h-1.081v-4.273h1.081c1.18,0,2.136,0.956,2.136,2.136C37.923,19.016,36.966,19.973,35.786,19.973z"/>
                        <path fill="#DBE8FF" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M12.214,19.973h1.081v-4.273h-1.081c-1.18,0-2.136,0.956-2.136,2.136C10.077,19.016,11.034,19.973,12.214,19.973z"/>
                        <!-- Face visor -->
                        <path fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M28.373,22.115h-8.745c-2.099,0-3.8-1.701-3.8-3.8v-0.503c0-2.099,1.701-3.8,3.8-3.8h8.745c2.099,0,3.8,1.701,3.8,3.8v0.503C32.173,20.414,30.471,22.115,28.373,22.115z"/>
                        <!-- Eyes (dots) -->
                        <circle fill="#0060FF" cx="20.337" cy="18.063" r="0.75"/>
                        <circle fill="#0060FF" cx="27.545" cy="18.063" r="0.75"/>
                        <!-- Antenna line -->
                        <line fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" x1="37.923" y1="17.518" x2="37.923" y2="9.293"/>
                        <!-- Antenna light -->
                        <circle class="rpa-light" fill="#DBE8FF" stroke="#0060FF" stroke-width="1.5" cx="37.923" cy="8.063" r="1.229"/>
                        <!-- Arms/connectors -->
                        <path fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M34.705,24.234h0.984c1.64,0,2.969,1.329,2.969,2.969v7.769"/>
                        <path fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M13.295,24.234h-0.984c-1.64,0-2.969,1.329-2.969,2.969v7.769"/>
                        <!-- Feet -->
                        <path fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M36.348,37.282c0-1.276,1.034-2.31,2.31-2.31s2.31,1.034,2.31,2.31"/>
                        <path fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M11.652,37.282c0-1.276-1.034-2.31-2.31-2.31s-2.31,1.034-2.31,2.31"/>
                    </g>
                    <!-- Gear (on top, rotates) -->
                    <g class="rpa-gear">
                        <path fill="#DBE8FF" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" d="M30.672,37.089l0.231-0.494c0.348-0.747,0.025-1.635-0.722-1.983l-1.088-0.508c0.012-0.143,0.043-0.281,0.043-0.427c0-0.378-0.047-0.744-0.124-1.099l0.995-0.634c0.695-0.443,0.9-1.365,0.457-2.061l-0.293-0.46c-0.443-0.695-1.365-0.9-2.061-0.457l-1.008,0.642c-0.404-0.309-0.85-0.557-1.336-0.736v-1.192c0-0.824-0.668-1.492-1.492-1.492h-0.546c-0.824,0-1.492,0.668-1.492,1.492v1.192c-0.702,0.258-1.339,0.649-1.858,1.167l-1.065-0.497c-0.747-0.348-1.635-0.025-1.983,0.722l-0.231,0.494c-0.348,0.747-0.025,1.635,0.722,1.983l1.088,0.508c-0.012,0.143-0.043,0.281-0.043,0.427c0,0.378,0.047,0.744,0.124,1.099l-0.995,0.634c-0.695,0.443-0.9,1.365-0.457,2.061l0.293,0.46c0.443,0.695,1.365,0.9,2.061,0.457l1.008-0.642c0.404,0.309,0.85,0.557,1.336,0.736v1.192c0,0.824,0.668,1.492,1.492,1.492h0.546c0.824,0,1.492-0.668,1.492-1.492v-1.192c0.702-0.258,1.339-0.649,1.858-1.167l1.065,0.497C29.435,38.16,30.323,37.836,30.672,37.089z"/>
                        <circle fill="none" stroke="#0060FF" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" cx="24" cy="33.677" r="2.114"/>
                    </g>
                </svg>
            </div>
            <p style="margin: 0; font-size: 15px; color: #222; font-weight: 500;"><?php _e(
                "Training in progress...",
                "ai-chat-wp",
            ); ?></p>
            <p style="margin: 5px 0 0 0; font-size: 13px; color: #666;"><?php _e(
                "This may take a while depending on the number of items selected",
                "ai-chat-wp",
            ); ?></p>
        </div>
        <style>
        .rpa-icon-wrapper {
            overflow: visible;
        }
        .rpa-animated .rpa-gear {
            animation: gear-rotate 2s linear infinite;
            transform-origin: 24px 33.7px;
        }
        .rpa-animated .rpa-body {
            animation: body-bounce 2s ease-in-out infinite;
        }
        .rpa-animated .rpa-light {
            animation: light-pulse 1s ease-in-out infinite;
            transform-origin: 37.923px 8.063px;
        }
        @keyframes gear-rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(180deg); }
        }
        @keyframes body-bounce {
            0%, 100% { transform: translateY(0); }
            25%, 45% { transform: translateY(-4px); }
        }
        @keyframes light-pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.5); }
        }
        </style>

        <div id="regeneration-log" style="display: none; margin-top: 15px;">
            <h4><?php _e("Progress Log:", "ai-chat-wp"); ?></h4>
            <div id="log-content" class="aicwp-ui-log"></div>
        </div>

        <!-- Training Confirmation Modal -->
        <div id="training-confirm-modal" class="aicwp-ui-modal" style="display: none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content">
                <div class="aicwp-ui-modal-header">
                    <h3><?php _e("Start Training?", "ai-chat-wp"); ?></h3>
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #0073aa;">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
                        <path d="M12 7V13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="12" cy="16.5" r="1" fill="currentColor"/>
                    </svg>
                </div>
                <div class="aicwp-ui-modal-body">
                    <p><?php _e(
                        "This will generate embeddings for all selected post types and consume API credits. You can stop anytime.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
                <div class="aicwp-ui-modal-footer">
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="training-cancel-btn">
                        <?php _e("Cancel", "ai-chat-wp"); ?>
                    </button>
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-primary" id="training-confirm-btn">
                        <?php _e("Yes, Start Training", "ai-chat-wp"); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- API Key Missing Modal -->
        <div id="api-key-missing-modal" class="aicwp-ui-modal" style="display: none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content">
                <div class="aicwp-ui-modal-header">
                    <h3><?php _e(
                        "API Key Not Configured",
                        "ai-chat-wp",
                    ); ?></h3>
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="color: #dc3545;">
                        <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>
                        <path d="M12 7V13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="12" cy="16.5" r="1" fill="currentColor"/>
                    </svg>
                </div>
                <div class="aicwp-ui-modal-body">
                    <p><?php
                    $provider = new AICWP_Provider();
                    printf(
                        /* translators: %s: AI provider name (e.g. OpenAI, Gemini, Mistral) */
                        esc_html__(
                            "Please add your %s API key in the Settings tab before starting training.",
                            "ai-chat-wp",
                        ),
                        esc_html($provider->get_provider_name()),
                    );
                    ?></p>
                </div>
                <div class="aicwp-ui-modal-footer">
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="api-key-missing-close-btn">
                        <?php _e("Cancel", "ai-chat-wp"); ?>
                    </button>
                    <button type="button" class="aicwp-ui-button aicwp-ui-button-primary" id="api-key-missing-settings-btn">
                        <?php _e("Go to Settings", "ai-chat-wp"); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render stats tab
     */
    private function render_stats_tab()
    {
        ?>
        <!-- AI Chatbot Stats Section -->
        <?php
        $chat_stats = get_option("aicwp_chat_stats", [
            "total_sessions" => 0,
            "user_messages" => 0,
        ]);

        // Chat History Section - delegated to AICWP_Admin_Chat_History class
        $history_enabled = get_option("aicwp_chat_history_enabled", 0);
        $this->chat_history->render_section($history_enabled);
        ?>

        <!-- Right Column: Chart + Audit + Contact Messages (stacked) -->
        <div class="aicwp-ui-stats-right-column">
            <!-- Activity Chart Card -->
            <div class="aicwp-ui-card aicwp-ui-card-toggleable" data-toggle-id="stats-activity-chart">
                <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                    <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path></svg>
                    </div>
                    <div class="aicwp-ui-card-header-text">
                        <h3><?php _e("Chat Activity", "ai-chat-wp"); ?></h3>
                        <p><?php _e(
                            "Chat activity trends over time",
                            "ai-chat-wp",
                        ); ?></p>
                    </div>
                    <span class="dashicons dashicons-arrow-down-alt2 aicwp-ui-card-toggle-icon"></span>
                </div>
                <div class="aicwp-ui-card-body">
                    <?php // Hook for chart rendering
                    do_action("aicwp_render_chart_card"); ?>
                </div>
            </div>

            <?php // Conversation Audit UI - renders between Activity Chart and
            // Contact Messages inside the right column.
            do_action("aicwp_chat_history_analysis_tab"); ?>

            <!-- Contact Messages Section -->
            <?php $this->contact_messages->render_section(); ?>
        </div>

        <?php // Popular Search Queries - always rendered after Chat History and Activity

        $this->search_analytics->render_section(); ?>

        <?php
    }

    /**
     * Render AI Chat tab
     */
    private function render_ai_chat_tab()
    {
        global $wpdb;

        // Get current settings
        $system_prompt = mb_substr(
            get_option("aicwp_chat_system_prompt", ""),
            0,
            AICWP_MAX_PROMPT_LENGTH,
        );
        $chat_enabled = get_option("aicwp_chat_enabled", 0);
        $chat_name = get_option("aicwp_chat_name", "Assistant");
        $welcome_message = get_option(
            "aicwp_chat_welcome_message",
            "Hello! I can help you find restaurants, hotels, and services. What would you like to search for?",
        );
        $model = get_option("aicwp_chat_model", "gpt-5.4-mini");
        $current_provider = get_option("aicwp_provider", "openai");
        ?>

        <!-- Sidebar layout: nav on the left, single form on the right -->
        <div class="aicwp-ui-chat-layout">
            <aside class="aicwp-ui-chat-sidebar" role="tablist" aria-label="<?php esc_attr_e(
                "AI Chat settings sections",
                "ai-chat-wp",
            ); ?>">
                <button type="button" class="aicwp-ui-chat-sidebar-item is-active" data-target="api-config" role="tab" aria-selected="true">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "API Configuration",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="general" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "General",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="widget" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Floating Widget",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="appearance" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"></circle><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"></circle><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"></circle><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.555C21.965 6.012 17.461 2 12 2z"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Appearance",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="quick-buttons" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Quick Action Buttons",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="tools" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Custom Instructions",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="access" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Access & Privacy",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="integrations" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Integrations",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="semantic-search" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path><path d="M11 8v6"></path><path d="M8 11h6"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Search Field",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
                <button type="button" class="aicwp-ui-chat-sidebar-item" data-target="developer-debug" role="tab" aria-selected="false">
                    <span class="aicwp-ui-chat-sidebar-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 16 4-4-4-4"></path><path d="m6 8-4 4 4 4"></path><path d="m14.5 4-5 16"></path></svg>
                    </span>
                    <span class="aicwp-ui-chat-sidebar-label"><?php _e(
                        "Developer & Debug",
                        "ai-chat-wp",
                    ); ?></span>
                </button>
            </aside>

            <div class="aicwp-ui-chat-content">

        <!-- Single form wrapping all sections -->
        <form class="aicwp-ui-ajax-form" data-section="ai-chat-config" id="ai-chat-settings-form">

        <?php $this->render_settings_tab(); ?>

        <!-- ========================================== -->
        <!-- SECTION 1: GENERAL -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="general">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e("General", "ai-chat-wp"); ?></h3>
                    <p><?php _e(
                        "Core settings for the AI chat functionality.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- Enable Chat & Shortcode Usage -->
                <div class="aicwp-ui-form-group">
                    <div class="aicwp-ui-form-row" style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" name="aicwp_chat_enabled" value="1" <?php checked(
                                    $chat_enabled,
                                    1,
                                ); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <?php _e(
                                        "Enable AI Chat",
                                        "ai-chat-wp",
                                    ); ?>
                                    <small><?php _e(
                                        "Global switch - disables shortcode and floating widget.",
                                        "ai-chat-wp",
                                    ); ?></small>
                                </span>
                            </label>
                        </div>
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <label class="aicwp-ui-label" style="margin-bottom: 0;"><?php _e(
                                    "Shortcode Usage",
                                    "ai-chat-wp",
                                ); ?></label>
                                <code style="padding: 2px 6px; border-radius: 5px; font-size: 13px; background: #e6f2ff; color: #0476ee;">[ai_chat]</code>
                            </div>
                            <p class="aicwp-ui-help-text" style="margin-bottom: 0;"><?php _e(
                                "Available parameters:",
                                "ai-chat-wp",
                            ); ?> <code>height="600px"</code> <code>style="1"</code> <?php _e(
     "or",
     "ai-chat-wp",
 ); ?> <code>style="2"</code></p>
                        </div>
                    </div>
                </div>

                <div>

                <!-- WooCommerce Settings (only show when product post type exists) -->
                <?php $has_products = post_type_exists("product"); ?>
                <div class="aicwp-ui-form-group" style="<?php echo !$has_products
                    ? "display: none;"
                    : ""; ?>">
                    <label class="aicwp-ui-label" style="margin-top: 25px;">
<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -1px; margin-right: 5px;"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg> WooCommerce
                    </label>
                    <div class="aicwp-ui-form-row aicwp-ui-group-block" style="display: flex; flex-wrap: wrap; gap: 0 20px; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; padding-bottom: 15px;">
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label for="aicwp_chat_max_results" class="aicwp-ui-label">
                                <?php _e(
                                    "Maximum Product Cards Displayed",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <input type="number" id="aicwp_chat_max_results" name="aicwp_chat_max_results" value="<?php echo esc_attr(
                                get_option("aicwp_chat_max_results", 10),
                            ); ?>" class="aicwp-ui-input" min="1" max="25" step="1" />
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Maximum number of WooCommerce products to display in chat results (1-25). Default: 10",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" name="aicwp_chat_hide_images" value="1" <?php checked(
                                    get_option("aicwp_chat_hide_images", 1),
                                    1,
                                ); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <?php _e(
                                        "Hide Images in Chat Results",
                                        "ai-chat-wp",
                                    ); ?>
                                    <small><?php _e(
                                        "Remove product thumbnails from search results in chat for a cleaner, text-only interface.",
                                        "ai-chat-wp",
                                    ); ?></small>
                                </span>
                            </label>
                        </div>
                        <?php if (class_exists("WooCommerce")): ?>
                        <div class="aicwp-ui-form-col" style="flex: 1 1 100%; padding-top: 15px; border-top: 1px solid #f0f0f0;">
                            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                                <div class="aicwp-ui-form-col" style="flex: 1;">
                                    <?php $woo_cart_enabled =
                                        get_option(
                                            "aicwp_chat_woo_cart_enabled",
                                            0,
                                        ); ?>
                                    <label class="aicwp-ui-checkbox-label">
                                        <input type="checkbox"
                                               name="aicwp_chat_woo_cart_enabled"
                                               id="aicwp_chat_woo_cart_enabled"
                                               value="1"
                                               <?php checked($woo_cart_enabled, 1); ?> />
                                        <span class="aicwp-ui-checkbox-custom"></span>
                                        <span class="aicwp-ui-checkbox-text">
                                            <?php _e(
                                                "Enable WooCommerce Cart in Chatbot",
                                                "ai-chat-wp",
                                            ); ?>
                                            <small><?php _e(
                                                'Show "Add to Cart" buttons on product cards and a cart icon in the chat header.',
                                                "ai-chat-wp",
                                            ); ?></small>
                                        </span>
                                    </label>
                                </div>
                                <div class="aicwp-ui-form-col" style="flex: 1;">
                                    <?php $woo_order_checking_enabled =
                                        get_option(
                                            "aicwp_chat_woo_order_checking_enabled",
                                            1,
                                        ); ?>
                                    <label class="aicwp-ui-checkbox-label">
                                        <input type="checkbox"
                                               name="aicwp_chat_woo_order_checking_enabled"
                                               id="aicwp_chat_woo_order_checking_enabled"
                                               value="1"
                                               <?php checked($woo_order_checking_enabled, 1); ?> />
                                        <span class="aicwp-ui-checkbox-custom"></span>
                                        <span class="aicwp-ui-checkbox-text">
                                            <?php _e(
                                                "Enable Order Checking",
                                                "ai-chat-wp",
                                            ); ?>
                                            <small><?php _e(
                                                "Allow users to check their WooCommerce order status and tracking via the chatbot.",
                                                "ai-chat-wp",
                                            ); ?></small>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>

                <!-- Speech-to-Text & Image Input -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg><?php _e(
                        "Multimodal Features",
                        "ai-chat-wp",
                    ); ?></label>
                    <div class="aicwp-ui-form-row aicwp-ui-group-block" style="display: flex; gap: 20px; flex-wrap: wrap; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; padding-bottom: 15px;">
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <?php $speech_enabled =
                                get_option(
                                    "aicwp_chat_enable_speech",
                                    0,
                                ); ?>
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox"
                                       name="aicwp_chat_enable_speech"
                                       id="aicwp_chat_enable_speech"
                                       value="1"
                                       <?php checked($speech_enabled, 1); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                        <?php _e(
                                            "Enable Speech-to-Text",
                                            "ai-chat-wp",
                                        ); ?>
                                        <span class="aicwp-ui-hint-icon" data-tooltip="<?php esc_attr_e(
                                            "Audio is sent directly to AI for transcription and is not stored on your server.",
                                            "ai-chat-wp",
                                        ); ?>" tabindex="0" aria-label="<?php esc_attr_e(
    "More info",
    "ai-chat-wp",
); ?>">?</span>
                                    </div>
                                    <small><?php _e(
                                        "Show a microphone button that allows users to send voice messages.",
                                        "ai-chat-wp",
                                    ); ?></small>
                                </span>
                            </label>
                        </div>
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <?php $image_input_enabled =
                                get_option(
                                    "aicwp_chat_enable_image_input",
                                    0,
                                ); ?>
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox"
                                       name="aicwp_chat_enable_image_input"
                                       id="aicwp_chat_enable_image_input"
                                       value="1"
                                       <?php checked(
                                           $image_input_enabled,
                                           1,
                                       ); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <div style="display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                                        <?php _e(
                                            "Enable Image Input",
                                            "ai-chat-wp",
                                        ); ?>
                                        <span class="aicwp-ui-hint-icon" data-tooltip="<?php esc_attr_e(
                                            "Images are sent directly to AI and are not stored on your server.",
                                            "ai-chat-wp",
                                        ); ?>" tabindex="0" aria-label="<?php esc_attr_e(
    "More info",
    "ai-chat-wp",
); ?>">?</span>
                                    </div>
                                    <small><?php _e(
                                        "Show a button that allows users to attach an image for the AI to analyze.",
                                        "ai-chat-wp",
                                    ); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Additional Settings - Collapsible -->
                <div class="aicwp-ui-collapsible-section">
                    <div class="aicwp-ui-collapsible-header" data-section="chat-context-sources">
                        <span class="aicwp-ui-collapsible-title">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                            <?php _e(
                                "Additional Settings",
                                "ai-chat-wp",
                            ); ?>
                        </span>
                        <span class="aicwp-ui-collapsible-toggle">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </span>
                    </div>
                    <div class="aicwp-ui-collapsible-content">
                        <div class="aicwp-ui-form-group">
                            <label for="aicwp_chat_rag_sources_limit" class="aicwp-ui-label">
                                <?php _e(
                                    "Number of Content Sources to Send to the AI",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <input type="number" id="aicwp_chat_rag_sources_limit" name="aicwp_chat_rag_sources_limit" value="<?php echo esc_attr(
                                get_option(
                                    "aicwp_chat_rag_sources_limit",
                                    5,
                                ),
                            ); ?>" min="2" max="10" step="1" class="aicwp-ui-input aicwp-ui-input-small" />
                            <span><?php _e(
                                "sources",
                                "ai-chat-wp",
                            ); ?></span>
                            <div class="aicwp-ui-help-text">
                                <?php _e(
                                    "For RAG responses <strong>(when searching pages, posts etc.)</strong>. Not related to WooCommerce products!",
                                    "ai-chat-wp",
                                ); ?>
                                <br>
                                <span style="color: #27ae60;">5</span> = <?php _e(
                                    "Balanced (recommended)",
                                    "ai-chat-wp",
                                ); ?>,
                                <span style="color: #2980b9;">3</span> = <?php _e(
                                    "Faster/cheaper",
                                    "ai-chat-wp",
                                ); ?>,
                                <span style="color: #f39c12;">10</span> = <?php _e(
                                    "More context (not recommended)",
                                    "ai-chat-wp",
                                ); ?>
                            </div>
                        </div>

                        <div class="aicwp-ui-form-group">
                            <label class="aicwp-ui-label">
                                <?php _e(
                                    "Conversation Context Length",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <?php $context_length = get_option(
                                "aicwp_chat_context_length",
                                "normal",
                            ); ?>
                            <div class="aicwp-ui-theme-toggle aicwp-ui-theme-toggle--text">
                                <button type="button" class="aicwp-ui-theme-btn<?php echo $context_length ===
                                "short"
                                    ? " active"
                                    : ""; ?>" data-value="short" title="<?php esc_attr_e(
    "Short, ~3 user messages, lowest cost",
    "ai-chat-wp",
); ?>">
                                    <?php _e("Short", "ai-chat-wp"); ?>
                                </button>
                                <button type="button" class="aicwp-ui-theme-btn<?php echo $context_length ===
                                "normal"
                                    ? " active"
                                    : ""; ?>" data-value="normal" title="<?php esc_attr_e(
    "Normal, ~9 user messages",
    "ai-chat-wp",
); ?>">
                                    <?php _e("Normal", "ai-chat-wp"); ?>
                                </button>
                                <button type="button" class="aicwp-ui-theme-btn<?php echo $context_length ===
                                "long"
                                    ? " active"
                                    : ""; ?>" data-value="long" title="<?php esc_attr_e(
    "Long, ~24 user messages",
    "ai-chat-wp",
); ?>">
                                    <?php _e("Long", "ai-chat-wp"); ?>
                                </button>
                                <input type="hidden" name="aicwp_chat_context_length" id="aicwp_chat_context_length" value="<?php echo esc_attr(
                                    $context_length,
                                ); ?>" />
                            </div>
                            <div class="aicwp-ui-help-text">
                                <strong><?php _e(
                                    "How many previous user messages are included as context for the AI.",
                                    "ai-chat-wp",
                                ); ?></strong> <?php _e(
    "Short prevents context pollution and saves tokens. Longer gives the AI more memory of previous user questions.",
    "ai-chat-wp",
); ?>
                                <br>
                                <span style="color: #27ae60;"><?php _e(
                                    "Short",
                                    "ai-chat-wp",
                                ); ?></span> = <?php _e(
    "~3 user messages",
    "ai-chat-wp",
); ?>,
                                <span style="color: #2980b9;"><?php _e(
                                    "Normal",
                                    "ai-chat-wp",
                                ); ?></span> = <?php _e(
    "~9 user messages",
    "ai-chat-wp",
); ?>,
                                <span style="color: #f39c12;"><?php _e(
                                    "Long",
                                    "ai-chat-wp",
                                ); ?></span> = <?php _e(
    "~24 user messages",
    "ai-chat-wp",
); ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECTION 2: APPEARANCE -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="appearance">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"></circle><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"></circle><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"></circle><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.555C21.965 6.012 17.461 2 12 2z"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e("Appearance", "ai-chat-wp"); ?></h3>
                    <p><?php _e(
                        "Customize how the chat looks and feels to your users.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- Chatbot Name & Avatar -->
                <div class="aicwp-ui-form-group">
                    <div class="aicwp-ui-form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label for="aicwp_chat_name" class="aicwp-ui-label">
                                <?php _e("Chatbot Name", "ai-chat-wp"); ?>
                            </label>
                            <input type="text" id="aicwp_chat_name" name="aicwp_chat_name" value="<?php echo esc_attr(
                                $chat_name,
                            ); ?>" class="aicwp-ui-input" placeholder="Assistant" />
                            <p class="aicwp-ui-help-text"><?php _e(
                                'Displayed in chat header. Default: "Assistant"',
                                "ai-chat-wp",
                            ); ?></p>

                            <label for="aicwp_chat_welcome_message" class="aicwp-ui-label" style="margin-top: 15px;">
                                <?php _e(
                                    "Welcome Message",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <textarea id="aicwp_chat_welcome_message" name="aicwp_chat_welcome_message" class="aicwp-ui-input" rows="2" placeholder="Hello! I can help you find restaurants, hotels, and services. What would you like to search for?"><?php echo esc_textarea(
                                $welcome_message,
                            ); ?></textarea>
                            <p class="aicwp-ui-help-text"><?php _e(
                                "The initial greeting message displayed when chat loads. HTML tags allowed (e.g., &lt;b&gt;, &lt;i&gt;, &lt;a&gt;, &lt;br&gt;).",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                        <div class="aicwp-ui-form-col aicwp-ui-group-block" style="flex: 0 0 auto; align-self: flex-start; margin-top: 10px; padding: 20px 20px 5px 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
                            <label for="aicwp_chat_avatar" class="aicwp-ui-label">
                                <?php _e("Chatbot Avatar", "ai-chat-wp"); ?>
                            </label>
                            <?php
                            $chat_avatar_id = get_option(
                                "aicwp_chat_avatar",
                                0,
                            );
                            $chat_avatar_url = $chat_avatar_id
                                ? wp_get_attachment_image_url(
                                    $chat_avatar_id,
                                    "thumbnail",
                                )
                                : "";
                            ?>
                            <div class="aicwp-ui-media-upload">
                                <input type="hidden" id="aicwp_chat_avatar" name="aicwp_chat_avatar" value="<?php echo esc_attr(
                                    $chat_avatar_id,
                                ); ?>" />
                                <div class="aicwp-ui-media-preview" id="aicwp-chat-avatar-preview">
                                    <?php if ($chat_avatar_url): ?>
                                        <img src="<?php echo esc_url(
                                            $chat_avatar_url,
                                        ); ?>" alt="Chat avatar" style="width: 38px; height: 38px; border-radius: 100px; object-fit: cover;" />
                                    <?php else: ?>
                                        <div class="aicwp-ui-media-placeholder" style="width: 38px; height: 38px; border: 2px dashed #ddd; border-radius: 100px; display: flex; align-items: center; justify-content: center; color: #999; box-sizing: content-box;">
                                            <i class="sl sl-icon-user" style="font-size: 14px;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="aicwp-ui-media-buttons" style="margin-top: 10px;">
                                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-upload-chat-avatar">
                                        <?php _e("Upload", "ai-chat-wp"); ?>
                                    </button>
                                    <?php if ($chat_avatar_id): ?>
                                        <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-remove-chat-avatar" style="margin-left: 5px;">
                                            <?php _e(
                                                "Remove",
                                                "ai-chat-wp",
                                            ); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="aicwp-ui-help-text"><?php _e(
                                "100x100px recommended.",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Color Settings -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-label"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -2px; margin-right: 5px;"><circle cx="13.5" cy="6.5" r="1.5" fill="currentColor" stroke="none"></circle><circle cx="17.5" cy="10.5" r="1.5" fill="currentColor" stroke="none"></circle><circle cx="8.5" cy="7.5" r="1.5" fill="currentColor" stroke="none"></circle><circle cx="6.5" cy="12.5" r="1.5" fill="currentColor" stroke="none"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.01 17.461 2 12 2z"></path></svg><?php _e(
                        "Color Settings",
                        "ai-chat-wp",
                    ); ?></label>
                    <?php $color_scheme = get_option(
                        "aicwp_color_scheme",
                        "light",
                    ); ?>
                    <div class="aicwp-ui-form-row aicwp-ui-group-block" style="display: flex; flex-wrap: wrap; gap: 0 30px; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; padding-bottom: 15px;">
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label for="aicwp_floating_button_color" class="aicwp-ui-label" style="font-weight: 500; font-size: 13px; margin-bottom: 6px;">
                                <?php _e("Buttons Color", "ai-chat-wp"); ?>
                            </label>
                            <input type="text" id="aicwp_floating_button_color" name="aicwp_floating_button_color" value="<?php echo esc_attr(
                                get_option(
                                    "aicwp_floating_button_color",
                                    "#222222",
                                ),
                            ); ?>" class="aicwp-ui-input aicwp-ui-color-picker" data-default-color="#222222" />
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Floating button, send button, context button.",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label for="aicwp_primary_color" class="aicwp-ui-label" style="font-weight: 500; font-size: 13px; margin-bottom: 6px;">
                                <?php _e("Primary Color", "ai-chat-wp"); ?>
                            </label>
                            <input type="text" id="aicwp_primary_color" name="aicwp_primary_color" value="<?php echo esc_attr(
                                get_option(
                                    "aicwp_primary_color",
                                    "#0073ee",
                                ),
                            ); ?>" class="aicwp-ui-input aicwp-ui-color-picker" data-default-color="#0073ee" />
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Links, user messages, and UI elements.",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <label class="aicwp-ui-label" style="font-weight: 500; font-size: 13px; margin-bottom: 6px;">
                                <?php _e("Color Scheme", "ai-chat-wp"); ?>
                            </label>
                            <div class="aicwp-ui-theme-toggle">
                                <button type="button" class="aicwp-ui-theme-btn<?php echo $color_scheme ===
                                "light"
                                    ? " active"
                                    : ""; ?>" data-value="light" title="<?php esc_attr_e(
    "Light",
    "ai-chat-wp",
); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                                    </svg>
                                    <!-- Hover preview -->
                                    <div class="aicwp-ui-theme-preview">
                                        <div class="color-scheme-preview color-scheme-light">
                                            <div class="preview-header"></div>
                                            <div class="preview-message preview-message-ai"></div>
                                            <div class="preview-message preview-message-user"></div>
                                            <div class="preview-input"></div>
                                        </div>
                                    </div>
                                </button>
                                <button type="button" class="aicwp-ui-theme-btn<?php echo $color_scheme ===
                                "auto"
                                    ? " active"
                                    : ""; ?>" data-value="auto" title="<?php esc_attr_e(
    "System",
    "ai-chat-wp",
); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25m18 0A2.25 2.25 0 0 0 18.75 3H5.25A2.25 2.25 0 0 0 3 5.25m18 0V12a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 12V5.25" />
                                    </svg>
                                    <!-- Hover preview -->
                                    <div class="aicwp-ui-theme-preview">
                                        <div class="color-scheme-preview color-scheme-auto">
                                            <div class="preview-split">
                                                <div class="preview-half preview-half-light">
                                                    <div class="preview-header"></div>
                                                    <div class="preview-message"></div>
                                                </div>
                                                <div class="preview-half preview-half-dark">
                                                    <div class="preview-header"></div>
                                                    <div class="preview-message"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </button>
                                <button type="button" class="aicwp-ui-theme-btn<?php echo $color_scheme ===
                                "dark"
                                    ? " active"
                                    : ""; ?>" data-value="dark" title="<?php esc_attr_e(
    "Dark",
    "ai-chat-wp",
); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                                    </svg>
                                    <!-- Hover preview -->
                                    <div class="aicwp-ui-theme-preview">
                                        <div class="color-scheme-preview color-scheme-dark">
                                            <div class="preview-header"></div>
                                            <div class="preview-message preview-message-ai"></div>
                                            <div class="preview-message preview-message-user"></div>
                                            <div class="preview-input"></div>
                                        </div>
                                    </div>
                                </button>
                                <input type="hidden" name="aicwp_color_scheme" id="aicwp_color_scheme" value="<?php echo esc_attr(
                                    $color_scheme,
                                ); ?>" />
                            </div>
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Light, system preference (auto), or dark mode.",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                        <div style="flex-basis: 100%;">
                            <label class="aicwp-ui-checkbox-label">
                                <input type="checkbox" name="aicwp_color_scheme_switcher" value="1" <?php checked(
                                    get_option(
                                        "aicwp_color_scheme_switcher",
                                    ),
                                    1,
                                ); ?> />
                                <span class="aicwp-ui-checkbox-custom"></span>
                                <span class="aicwp-ui-checkbox-text">
                                    <?php _e(
                                        "Show color scheme switcher in chat window",
                                        "ai-chat-wp",
                                    ); ?>
                                    <small><?php _e(
                                        "Adds a sun/moon toggle icon letting visitors switch between light and dark mode.",
                                        "ai-chat-wp",
                                    ); ?></small>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Loading Animation Style -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-label"><?php _e(
                        "Loading Animation Style",
                        "ai-chat-wp",
                    ); ?></label>
                    <p class="aicwp-ui-help-text" style="margin-top: 0; margin-bottom: 12px;"><?php _e(
                        "Choose how the loading indicator appears while the AI is processing your request.",
                        "ai-chat-wp",
                    ); ?></p>
                    <?php $loading_style = get_option(
                        "aicwp_chat_loading_style",
                        "spinner",
                    ); ?>
                    <div class="aicwp-ui-visual-radio-group">
                        <!-- Spinner + Text Option -->
                        <label class="aicwp-ui-visual-radio-card <?php echo $loading_style ===
                        "spinner"
                            ? "selected"
                            : ""; ?>">
                            <input type="radio" name="aicwp_chat_loading_style" value="spinner" <?php checked(
                                $loading_style,
                                "spinner",
                            ); ?> />
                            <div class="aicwp-ui-visual-radio-preview">
                                <div class="loading-preview-spinner">
                                    <span class="preview-spinner-icon"></span>
                                    <span class="preview-shimmer-text"><?php _e(
                                        "Thinking...",
                                        "ai-chat-wp",
                                    ); ?></span>
                                </div>
                            </div>
                            <div class="aicwp-ui-visual-radio-label"><?php _e(
                                "Icon + Text",
                                "ai-chat-wp",
                            ); ?></div>
                        </label>
                        <!-- Dots Only Option -->
                        <label class="aicwp-ui-visual-radio-card <?php echo $loading_style ===
                        "dots"
                            ? "selected"
                            : ""; ?>">
                            <input type="radio" name="aicwp_chat_loading_style" value="dots" <?php checked(
                                $loading_style,
                                "dots",
                            ); ?> />
                            <div class="aicwp-ui-visual-radio-preview">
                                <div class="loading-preview-dots">
                                    <span></span>
                                    <span></span>
                                    <span></span>
                                </div>
                            </div>
                            <div class="aicwp-ui-visual-radio-label"><?php _e(
                                "Dots Only",
                                "ai-chat-wp",
                            ); ?></div>
                        </label>
                    </div>
                </div>

                <!-- Typing Animation / Hidden -->
                <div class="aicwp-ui-form-group" style="display: none;">
                    <label class="aicwp-ui-checkbox-label">
                        <input type="checkbox" name="aicwp_chat_typing_animation" value="1" <?php checked(
                            get_option("aicwp_chat_typing_animation", 1),
                            1,
                        ); ?> />
                        <span class="aicwp-ui-checkbox-custom"></span>
                        <span class="aicwp-ui-checkbox-text">
                            <?php _e(
                                "Enable Typing Animation",
                                "ai-chat-wp",
                            ); ?>
                            <small><?php _e(
                                "Show AI responses with a smooth word-by-word typing effect, similar to ChatGPT streaming.",
                                "ai-chat-wp",
                            ); ?></small>
                        </span>
                    </label>
                </div>

                <!-- Whitelabel Option -->
                <div class="aicwp-ui-form-group">
                    <?php $whitelabel_enabled =
                        get_option("aicwp_chat_whitelabel_enabled", 0); ?>
                    <label class="aicwp-ui-checkbox-label">
                        <input type="checkbox"
                               id="aicwp_chat_whitelabel_enabled"
                               name="aicwp_chat_whitelabel_enabled"
                               value="1"
                               <?php checked($whitelabel_enabled, 1); ?> />
                        <span class="aicwp-ui-checkbox-custom"></span>
                        <span class="aicwp-ui-checkbox-text">
                            <?php _e("Enable Whitelabel", "ai-chat-wp"); ?>
                            <small><?php _e(
                                'Hide the "Powered by Community" badge from the chat interface.',
                                "ai-chat-wp",
                            ); ?></small>
                        </span>
                    </label>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECTION 3: FLOATING WIDGET -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="widget">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e("Floating Widget", "ai-chat-wp"); ?></h3>
                    <p><?php _e(
                        "Configure the floating chat bubble that appears on your site.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- Enable Floating Widget & Custom Icon -->
                <div class="aicwp-ui-form-group">
                    <div class="aicwp-ui-form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div class="aicwp-ui-form-col" style="flex: 1;">
                            <div class="aicwp-ui-form-row" style="display: flex; gap: 20px; flex-wrap: wrap;">
                                <div class="aicwp-ui-form-col" style="flex: 1; min-width: 200px;">
                                    <label class="aicwp-ui-checkbox-label">
                                        <input type="checkbox" name="aicwp_floating_chat_enabled" value="1" <?php checked(
                                            get_option(
                                                "aicwp_floating_chat_enabled",
                                                0,
                                            ),
                                            1,
                                        ); ?> />
                                        <span class="aicwp-ui-checkbox-custom"></span>
                                        <span class="aicwp-ui-checkbox-text">
                                            <?php _e(
                                                "Enable Floating Chat Widget",
                                                "ai-chat-wp",
                                            ); ?>
                                            <small><?php _e(
                                                "Show a floating chat button on all pages.",
                                                "ai-chat-wp",
                                            ); ?></small>
                                            <?php $widget_position = get_option(
                                                "aicwp_floating_position",
                                                "right",
                                            ); ?>
                                            <span class="aicwp-ui-position-toggle" style="margin-top: 6px;" onclick="event.preventDefault(); event.stopPropagation();">
                                                <button type="button" class="aicwp-ui-position-btn<?php echo $widget_position ===
                                                "left"
                                                    ? " active"
                                                    : ""; ?>" data-value="left">
                                                    <?php _e(
                                                        "Left",
                                                        "ai-chat-wp",
                                                    ); ?>
                                                </button>
                                                <button type="button" class="aicwp-ui-position-btn<?php echo $widget_position ===
                                                "right"
                                                    ? " active"
                                                    : ""; ?>" data-value="right">
                                                    <?php _e(
                                                        "Right",
                                                        "ai-chat-wp",
                                                    ); ?>
                                                </button>
                                                <input type="hidden" name="aicwp_floating_position" id="aicwp_floating_position" value="<?php echo esc_attr(
                                                    $widget_position,
                                                ); ?>" />
                                            </span>
                                        </span>
                                    </label>
                                </div>

                                <div class="aicwp-ui-form-col" style="flex: 1; min-width: 200px;">
                                    <label class="aicwp-ui-checkbox-label">
                                        <input type="checkbox" name="aicwp_floating_keep_chat_opened" value="1" <?php checked(
                                            get_option(
                                                "aicwp_floating_keep_chat_opened",
                                                0,
                                            ),
                                            1,
                                        ); ?> />
                                        <span class="aicwp-ui-checkbox-custom"></span>
                                        <span class="aicwp-ui-checkbox-text">
                                            <?php _e(
                                                "Keep Chat Open Between Pages",
                                                "ai-chat-wp",
                                            ); ?>
                                            <small><?php _e(
                                                "Remember open/closed state when user navigates between pages.",
                                                "ai-chat-wp",
                                            ); ?></small>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <label for="aicwp_floating_welcome_bubble" class="aicwp-ui-label" style="margin-top: 25px;">
                                <?php _e(
                                    "Welcome Bubble Message",
                                    "ai-chat-wp",
                                ); ?>
                            </label>
                            <input type="text" id="aicwp_floating_welcome_bubble" name="aicwp_floating_welcome_bubble" value="<?php echo esc_attr(
                                get_option(
                                    "aicwp_floating_welcome_bubble",
                                    __(
                                        "Hi! How can I help you?",
                                        "ai-chat-wp",
                                    ),
                                ),
                            ); ?>" class="aicwp-ui-input" placeholder="<?php esc_attr_e(
    "Hi! How can I help you?",
    "ai-chat-wp",
); ?>" />
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Short message displayed above the button on first visit.",
                                "ai-chat-wp",
                            ); ?> <strong><?php _e(
     "Leave empty to disable.",
     "ai-chat-wp",
 ); ?></strong></p>
                        </div>
                        <div class="aicwp-ui-form-col aicwp-ui-group-block" style="flex: 0 0 auto; align-self: flex-start; margin-top: 10px; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; max-width: 250px;">
                            <label for="aicwp_floating_custom_icon" class="aicwp-ui-label">
                                <?php _e("Button Icon", "ai-chat-wp"); ?>
                            </label>
                            <?php
                            $custom_icon_id = get_option(
                                "aicwp_floating_custom_icon",
                                0,
                            );
                            $custom_icon_url = $custom_icon_id
                                ? wp_get_attachment_image_url(
                                    $custom_icon_id,
                                    "thumbnail",
                                )
                                : "";
                            $button_color = get_option(
                                "aicwp_floating_button_color",
                                "#222222",
                            );
                            ?>
                            <?php
                            $custom_icon_size = absint(
                                get_option(
                                    "aicwp_floating_custom_icon_size",
                                    32,
                                ),
                            );
                            if ($custom_icon_size < 1) {
                                $custom_icon_size = 32;
                            }
                            ?>
                            <div class="aicwp-ui-media-upload">
                                <input type="hidden" id="aicwp_floating_custom_icon" name="aicwp_floating_custom_icon" value="<?php echo esc_attr(
                                    $custom_icon_id,
                                ); ?>" />
                                <div class="aicwp-ui-media-preview" id="aicwp-custom-icon-preview">
                                    <?php if ($custom_icon_url): ?>
                                        <div class="aicwp-ui-media-placeholder" style="width: 60px; height: 60px; background-color: <?php echo esc_attr(
                                            $button_color,
                                        ); ?>; border-radius: 100px; display: flex; align-items: center; justify-content: center;">
                                            <img src="<?php echo esc_url(
                                                $custom_icon_url,
                                            ); ?>" alt="Custom icon" id="aicwp-custom-icon-preview-img" style="width: <?php echo esc_attr(
    $custom_icon_size,
); ?>px; height: <?php echo esc_attr(
    $custom_icon_size,
); ?>px; max-width: <?php echo esc_attr(
    $custom_icon_size,
); ?>px; max-height: <?php echo esc_attr(
    $custom_icon_size,
); ?>px; object-fit: contain;" />
                                        </div>
                                    <?php else: ?>
                                        <div class="aicwp-ui-media-placeholder" style="width: 60px; height: 60px; background-color: <?php echo esc_attr(
                                            $button_color,
                                        ); ?>; border-radius: 100px; display: flex; align-items: center; justify-content: center;">
                                            <img src="<?php echo esc_url(
                                                AICWP_PLUGIN_URL .
                                                    "assets/icons/chat.svg",
                                            ); ?>" alt="Default icon" width="28" height="28" />
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="aicwp-ui-media-buttons" style="margin-top: 10px;">
                                    <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-upload-custom-icon">
                                        <?php _e(
                                            "Change Icon",
                                            "ai-chat-wp",
                                        ); ?>
                                    </button>
                                    <?php if ($custom_icon_id): ?>
                                        <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-remove-custom-icon" style="margin-left: 5px;">
                                            <?php _e(
                                                "Remove",
                                                "ai-chat-wp",
                                            ); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="aicwp-custom-icon-size-wrapper"<?php echo $custom_icon_id
                                ? ""
                                : ' style="display: none;"'; ?>>
                                <label for="aicwp_floating_custom_icon_size" class="aicwp-ui-label" style="margin-top: 15px;">
                                    <?php _e(
                                        "Icon Size (px)",
                                        "ai-chat-wp",
                                    ); ?>
                                </label>
                                <input type="number" id="aicwp_floating_custom_icon_size" name="aicwp_floating_custom_icon_size" value="<?php echo esc_attr(
                                    $custom_icon_size,
                                ); ?>" class="aicwp-ui-input" min="8" max="100" step="1" placeholder="32" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Popup Dimensions & Widget Offset -->
                <div class="aicwp-ui-form-group">
                    <div style="display: flex; gap: 30px; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;" class="aicwp-ui-group-block">
                        <div style="flex: 1;">
                            <div style="display: flex; gap: 12px;">
                                <div style="flex: 1;">
                                    <label for="aicwp_floating_popup_width" class="aicwp-ui-label">
                                        <?php _e(
                                            "Popup Width (px)",
                                            "ai-chat-wp",
                                        ); ?>
                                    </label>
                                    <input type="number" id="aicwp_floating_popup_width" name="aicwp_floating_popup_width" value="<?php echo esc_attr(
                                        get_option(
                                            "aicwp_floating_popup_width",
                                            390,
                                        ),
                                    ); ?>" class="aicwp-ui-input" min="320" max="800" step="10" />
                                    <p class="aicwp-ui-help-text"><?php _e(
                                        "Default: 390px. Range: 320-800px.",
                                        "ai-chat-wp",
                                    ); ?></p>
                                </div>
                                <div style="flex: 1;">
                                    <label for="aicwp_floating_popup_height" class="aicwp-ui-label">
                                        <?php _e(
                                            "Popup Height (px)",
                                            "ai-chat-wp",
                                        ); ?>
                                    </label>
                                    <input type="number" id="aicwp_floating_popup_height" name="aicwp_floating_popup_height" value="<?php echo esc_attr(
                                        get_option(
                                            "aicwp_floating_popup_height",
                                            600,
                                        ),
                                    ); ?>" class="aicwp-ui-input" min="400" max="900" step="10" />
                                    <p class="aicwp-ui-help-text"><?php _e(
                                        "Default: 600px. Range: 400-900px.",
                                        "ai-chat-wp",
                                    ); ?></p>
                                </div>
                            </div>
                        </div>
                        <div style="flex: 1;">
                            <?php
                            $offset_desktop_h = get_option(
                                "aicwp_floating_offset_desktop_h",
                                20,
                            );
                            $offset_desktop_v = get_option(
                                "aicwp_floating_offset_desktop_v",
                                20,
                            );
                            $offset_mobile_h = get_option(
                                "aicwp_floating_offset_mobile_h",
                                20,
                            );
                            $offset_mobile_v = get_option(
                                "aicwp_floating_offset_mobile_v",
                                20,
                            );
                            ?>
                            <label class="aicwp-ui-label"><?php _e(
                                "Widget Offset",
                                "ai-chat-wp",
                            ); ?></label>
                            <div>
                                <div class="aicwp-ui-position-toggle aicwp-ui-offset-device-toggle" style="margin-bottom: 10px;">
                                    <button type="button" class="aicwp-ui-position-btn active" data-target="aicwp-ui-offset-desktop"><?php _e(
                                        "Desktop",
                                        "ai-chat-wp",
                                    ); ?></button>
                                    <button type="button" class="aicwp-ui-position-btn" data-target="aicwp-ui-offset-mobile"><?php _e(
                                        "Mobile",
                                        "ai-chat-wp",
                                    ); ?></button>
                                </div>
                                <div class="aicwp-ui-offset-panels">
                                    <div class="aicwp-ui-offset-panel" id="aicwp-ui-offset-desktop">
                                        <div style="display: flex; gap: 12px; align-items: center;">
                                            <div>
                                                <label class="aicwp-ui-label" style="font-size: 12px; margin-bottom: 4px;"><?php _e(
                                                    "Horizontal",
                                                    "ai-chat-wp",
                                                ); ?></label>
                                                <div style="display: flex; align-items: center; gap: 4px;">
                                                    <input type="number" name="aicwp_floating_offset_desktop_h" value="<?php echo esc_attr(
                                                        $offset_desktop_h,
                                                    ); ?>" class="aicwp-ui-input" style="width: 70px;" min="0" max="200" />
                                                    <span style="color: #9ca3af; font-size: 12px;">px</span>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="aicwp-ui-label" style="font-size: 12px; margin-bottom: 4px;"><?php _e(
                                                    "Vertical",
                                                    "ai-chat-wp",
                                                ); ?></label>
                                                <div style="display: flex; align-items: center; gap: 4px;">
                                                    <input type="number" name="aicwp_floating_offset_desktop_v" value="<?php echo esc_attr(
                                                        $offset_desktop_v,
                                                    ); ?>" class="aicwp-ui-input" style="width: 70px;" min="0" max="200" />
                                                    <span style="color: #9ca3af; font-size: 12px;">px</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="aicwp-ui-offset-panel" id="aicwp-ui-offset-mobile" style="display: none;">
                                        <div style="display: flex; gap: 12px; align-items: center;">
                                            <div>
                                                <label class="aicwp-ui-label" style="font-size: 12px; margin-bottom: 4px;"><?php _e(
                                                    "Horizontal",
                                                    "ai-chat-wp",
                                                ); ?></label>
                                                <div style="display: flex; align-items: center; gap: 4px;">
                                                    <input type="number" name="aicwp_floating_offset_mobile_h" value="<?php echo esc_attr(
                                                        $offset_mobile_h,
                                                    ); ?>" class="aicwp-ui-input" style="width: 70px;" min="0" max="200" />
                                                    <span style="color: #9ca3af; font-size: 12px;">px</span>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="aicwp-ui-label" style="font-size: 12px; margin-bottom: 4px;"><?php _e(
                                                    "Vertical",
                                                    "ai-chat-wp",
                                                ); ?></label>
                                                <div style="display: flex; align-items: center; gap: 4px;">
                                                    <input type="number" name="aicwp_floating_offset_mobile_v" value="<?php echo esc_attr(
                                                        $offset_mobile_v,
                                                    ); ?>" class="aicwp-ui-input" style="width: 70px;" min="0" max="200" />
                                                    <span style="color: #9ca3af; font-size: 12px;">px</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Initial Header Style -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-label">
                        <?php _e("Initial Header Style", "ai-chat-wp"); ?>
                    </label>
                    <?php $header_style = get_option(
                        "aicwp_floating_header_style",
                        "simple",
                    ); ?>
                    <div class="aicwp-ui-header-style-toggle">
                        <button type="button" class="aicwp-ui-header-style-btn<?php echo $header_style ===
                        "simple"
                            ? " active"
                            : ""; ?>" data-value="simple" title="<?php esc_attr_e(
    "Simple",
    "ai-chat-wp",
); ?>">
                            <span class="aicwp-ui-header-style-text"><?php _e(
                                "Simple",
                                "ai-chat-wp",
                            ); ?></span>
                            <!-- Hover preview -->
                            <div class="aicwp-ui-header-style-preview">
                                <div class="header-style-preview header-style-simple">
                                    <div class="preview-header-bar"></div>
                                    <div class="preview-chat-body">
                                        <div class="preview-message"></div>
                                    </div>
                                </div>
                            </div>
                        </button>
                        <button type="button" class="aicwp-ui-header-style-btn<?php echo $header_style ===
                        "image"
                            ? " active"
                            : ""; ?>" data-value="image" title="<?php esc_attr_e(
    "Image",
    "ai-chat-wp",
); ?>">
                            <span class="aicwp-ui-header-style-text"><?php _e(
                                "Image",
                                "ai-chat-wp",
                            ); ?></span>
                            <!-- Hover preview -->
                            <div class="aicwp-ui-header-style-preview">
                                <div class="header-style-preview header-style-image">
                                    <div class="preview-header-bar preview-header-image preview-header-pixelated"></div>
                                    <div class="preview-chat-body">
                                        <div class="preview-message"></div>
                                    </div>
                                </div>
                            </div>
                        </button>
                        <button type="button" class="aicwp-ui-header-style-btn<?php echo $header_style ===
                        "animated"
                            ? " active"
                            : ""; ?>" data-value="animated" title="<?php esc_attr_e(
    "Animated",
    "ai-chat-wp",
); ?>">
                            <span class="aicwp-ui-header-style-text"><?php _e(
                                "Animated",
                                "ai-chat-wp",
                            ); ?></span>
                            <!-- Hover preview -->
                            <div class="aicwp-ui-header-style-preview">
                                <div class="header-style-preview header-style-image">
                                    <div class="preview-header-bar preview-header-image preview-header-animated"></div>
                                    <div class="preview-chat-body">
                                        <div class="preview-message"></div>
                                    </div>
                                </div>
                            </div>
                        </button>
                        <input type="hidden" name="aicwp_floating_header_style" id="aicwp_floating_header_style" value="<?php echo esc_attr(
                            $header_style,
                        ); ?>" />
                    </div>

                    <?php
                    $header_bg_id = get_option(
                        "aicwp_floating_header_bg",
                        0,
                    );
                    $header_bg_url = $header_bg_id
                        ? wp_get_attachment_image_url($header_bg_id, "medium")
                        : "";
                    $animated_bg_color = get_option(
                        "aicwp_animated_bg_color",
                        "#1560d0",
                    );
                    ?>

                    <!-- Panel: Background Image (shown when "Image" is selected) -->
                    <div id="aicwp-ui-header-bg-panel" style="margin-top: 15px;<?php echo $header_style !==
                    "image"
                        ? " display: none;"
                        : ""; ?>">
                        <div style="display: flex; gap: 30px; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;" class="aicwp-ui-group-block">
                            <div style="flex: 1;">
                                <label class="aicwp-ui-label">
                                    <?php _e(
                                        "Header Background Image",
                                        "ai-chat-wp",
                                    ); ?>
                                </label>
                                <div class="aicwp-ui-media-upload">
                                    <input type="hidden" id="aicwp_floating_header_bg" name="aicwp_floating_header_bg" value="<?php echo esc_attr(
                                        $header_bg_id,
                                    ); ?>" />
                                    <div class="aicwp-ui-media-preview" id="aicwp-header-bg-preview">
                                        <?php if ($header_bg_url): ?>
                                            <div class="aicwp-ui-header-bg-preview-frame">
                                                <img src="<?php echo esc_url(
                                                    $header_bg_url,
                                                ); ?>" alt="Header background" />
                                            </div>
                                        <?php else: ?>
                                            <div class="aicwp-ui-header-bg-preview-frame aicwp-ui-header-bg-placeholder">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="aicwp-ui-media-buttons" style="margin-top: 10px;">
                                        <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-upload-header-bg">
                                            <?php _e(
                                                "Upload",
                                                "ai-chat-wp",
                                            ); ?>
                                        </button>
                                        <?php if ($header_bg_id): ?>
                                            <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary" id="aicwp-remove-header-bg" style="margin-left: 5px;">
                                                <?php _e(
                                                    "Remove",
                                                    "ai-chat-wp",
                                                ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <p class="aicwp-ui-help-text"><?php _e(
                                    "Recommended size: 400x120px or larger. Image will be cropped to fit.",
                                    "ai-chat-wp",
                                ); ?></p>
                            </div>
                            <div style="flex: 1;">
                                <label class="aicwp-ui-checkbox-label" style="margin-top: 0;">
                                    <input type="checkbox" name="aicwp_floating_header_overlay" value="1" <?php checked(
                                        get_option(
                                            "aicwp_floating_header_overlay",
                                            0,
                                        ),
                                        1,
                                    ); ?> />
                                    <span class="aicwp-ui-checkbox-custom"></span>
                                    <span class="aicwp-ui-checkbox-text">
                                        <?php _e(
                                            "Enable overlay",
                                            "ai-chat-wp",
                                        ); ?>
                                        <small><?php _e(
                                            "Blends image with chat background.",
                                            "ai-chat-wp",
                                        ); ?></small>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Panel: Animated BG (shown when "Animated" is selected) -->
                    <div id="aicwp-ui-header-animated-panel" style="margin-top: 15px;<?php echo $header_style !==
                    "animated"
                        ? " display: none;"
                        : ""; ?>">
                        <div style="display: flex; gap: 30px; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0; align-items: flex-start;" class="aicwp-ui-group-block">
                            <div style="flex: 0 0 auto;">
                                <label for="aicwp_animated_bg_color" class="aicwp-ui-label" style="font-weight: 500; font-size: 13px; margin-bottom: 6px;">
                                    <?php _e("Wave Color", "ai-chat-wp"); ?>
                                </label>
                                <input type="text"
                                       id="aicwp_animated_bg_color"
                                       name="aicwp_animated_bg_color"
                                       value="<?php echo esc_attr(
                                           $animated_bg_color,
                                       ); ?>"
                                       class="aicwp-ui-input aicwp-ui-color-picker"
                                       data-default-color="#1560d0" />
                                <p class="aicwp-ui-help-text"><?php _e(
                                    "Base color for the animated wave effect.",
                                    "ai-chat-wp",
                                ); ?></p>
                            </div>
                            <div style="flex: 1;">
                                <label class="aicwp-ui-label" style="font-weight: 500; font-size: 13px; margin-bottom: 6px;">
                                    <?php _e("Preview", "ai-chat-wp"); ?>
                                </label>
                                <div class="aicwp-ui-animated-bg-preview" id="aicwp-ui-animated-bg-preview"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hide Chat on Selected Pages - Collapsible -->
                <div class="aicwp-ui-collapsible-section">
                    <div class="aicwp-ui-collapsible-header" data-section="hide-pages">
                        <span class="aicwp-ui-collapsible-title">
                            <span class="dashicons dashicons-hidden"></span>
                            <?php _e(
                                "Hide Chat on Selected Pages",
                                "ai-chat-wp",
                            ); ?>
                        </span>
                        <span class="aicwp-ui-collapsible-toggle">
                            <span class="dashicons dashicons-arrow-down-alt2"></span>
                        </span>
                    </div>
                    <div class="aicwp-ui-collapsible-content">
                        <div class="aicwp-ui-form-group">
                            <?php
                            $excluded_pages = get_option(
                                "aicwp_floating_excluded_pages",
                                [],
                            );
                            if (!is_array($excluded_pages)) {
                                $excluded_pages = [];
                            }
                            $all_pages = get_pages([
                                "sort_column" => "post_title",
                                "sort_order" => "ASC",
                            ]);
                            ?>
                            <div class="aicwp-ui-page-exclusion-list">
                                <?php if (empty($all_pages)): ?>
                                    <p><?php _e(
                                        "No pages found.",
                                        "ai-chat-wp",
                                    ); ?></p>
                                <?php else: ?>
                                    <?php foreach ($all_pages as $page): ?>
                                        <label class="aicwp-ui-page-exclusion-item">
                                            <input
                                                type="checkbox"
                                                name="aicwp_floating_excluded_pages[]"
                                                value="<?php echo esc_attr(
                                                    $page->ID,
                                                ); ?>"
                                                <?php checked(
                                                    in_array(
                                                        $page->ID,
                                                        $excluded_pages,
                                                    ),
                                                ); ?>
                                            />
                                            <?php echo esc_html(
                                                $page->post_title,
                                            ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <p class="aicwp-ui-help-text"><?php _e(
                                "Select pages where the floating chat widget should be hidden.",
                                "ai-chat-wp",
                            ); ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECTION: QUICK ACTION BUTTONS -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="quick-buttons">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e(
                        "Quick Action Buttons",
                        "ai-chat-wp",
                    ); ?></h3>
                    <p><?php _e(
                        "Customizable shortcut buttons displayed above the chat input.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- Enable Quick Action Buttons -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-checkbox-label">
                        <input type="checkbox" name="aicwp_chat_quick_buttons_enabled" id="aicwp_chat_quick_buttons_enabled" value="1" <?php checked(
                            get_option(
                                "aicwp_chat_quick_buttons_enabled",
                                1,
                            ),
                            1,
                        ); ?> />
                        <span class="aicwp-ui-checkbox-custom"></span>
                        <span class="aicwp-ui-checkbox-text">
                            <?php _e(
                                "Enable Quick Action Buttons",
                                "ai-chat-wp",
                            ); ?>
                            <small><?php _e(
                                "Show quick action buttons above the chat input field.",
                                "ai-chat-wp",
                            ); ?></small>
                        </span>
                    </label>
                </div>

                <div id="aicwp_chat_quick_buttons_wrapper" style="display: <?php echo get_option(
    "aicwp_chat_quick_buttons_enabled",
    1,
)
    ? "block"
    : "none"; ?>;">
                        <p class="aicwp-ui-help-text" style="margin-bottom: 15px;">
                            <?php _e(
                                'Add buttons that appear above the chat input. "<strong>Chat Message</strong>" buttons send a message to the AI, "<strong>Link</strong>" buttons open a link and "<strong>Contact Form</strong>" buttons open a contact form.',
                                "ai-chat-wp",
                            ); ?>
                        </p>

                        <div id="aicwp-quick-buttons-container">
                            <?php
                            $quick_buttons = get_option(
                                "aicwp_chat_quick_buttons",
                                [],
                            );
                            if (empty($quick_buttons)) {
                                $quick_buttons = [
                                    [
                                        "text" => "",
                                        "type" => "chat",
                                        "value" => "",
                                    ],
                                ];
                            }
                            $default_primary_color = get_option(
                                "aicwp_primary_color",
                                "#0073ee",
                            );
                            foreach ($quick_buttons as $index => $button):

                                $button_text = isset($button["text"])
                                    ? $button["text"]
                                    : "";
                                $button_type = isset($button["type"])
                                    ? $button["type"]
                                    : "chat";
                                $button_value = isset($button["value"])
                                    ? $button["value"]
                                    : "";
                                $button_color = isset($button["color"])
                                    ? $button["color"]
                                    : "";
                                ?>
                            <div class="aicwp-quick-button-row">
                                <div class="aicwp-quick-button-color-wrap">
                                    <button type="button" class="aicwp-quick-btn-color-swatch" style="background-color: <?php echo esc_attr(
                                        $button_color
                                            ? $button_color
                                            : "#ebebeb",
                                    ); ?>;"></button>
                                    <input type="hidden"
                                           name="aicwp_chat_quick_buttons[<?php echo $index; ?>][color]"
                                           value="<?php echo esc_attr(
                                               $button_color,
                                           ); ?>"
                                           class="aicwp-quick-button-color-value"
                                           data-default-color="<?php echo esc_attr(
                                               $default_primary_color,
                                           ); ?>" />
                                </div>
                                <input type="text"
                                       name="aicwp_chat_quick_buttons[<?php echo $index; ?>][text]"
                                       value="<?php echo esc_attr(
                                           $button_text,
                                       ); ?>"
                                       placeholder="<?php esc_attr_e(
                                           "Button Text",
                                           "ai-chat-wp",
                                       ); ?>"
                                       class="aicwp-ui-input aicwp-quick-button-text" />
                                <select name="aicwp_chat_quick_buttons[<?php echo $index; ?>][type]"
                                        class="aicwp-ui-input aicwp-quick-button-type">
                                    <option value="chat" <?php selected(
                                        $button_type,
                                        "chat",
                                    ); ?>><?php _e(
    "Chat Message",
    "ai-chat-wp",
); ?></option>
                                    <option value="url" <?php selected(
                                        $button_type,
                                        "url",
                                    ); ?>><?php _e(
    "Link",
    "ai-chat-wp",
); ?></option>
                                    <option value="contact" <?php selected(
                                        $button_type,
                                        "contact",
                                    ); ?>><?php _e(
    "Contact Form",
    "ai-chat-wp",
); ?></option>
                                </select>
                                <input type="text"
                                       name="aicwp_chat_quick_buttons[<?php echo $index; ?>][value]"
                                       value="<?php echo esc_attr(
                                           $button_value,
                                       ); ?>"
                                       placeholder="<?php echo $button_type ===
                                       "url"
                                           ? esc_attr__(
                                               "https://example.com",
                                               "ai-chat-wp",
                                           )
                                           : esc_attr__(
                                               "Message to send",
                                               "ai-chat-wp",
                                           ); ?>"
                                       class="aicwp-ui-input aicwp-quick-button-value"
                                       <?php echo $button_type === "contact"
                                           ? 'style="display: none;"'
                                           : ""; ?> />
                                <button type="button" class="aicwp-ui-button aicwp-ui-button-primary aicwp-configure-contact-form" <?php echo $button_type !==
                                "contact"
                                    ? 'style="display: none;"'
                                    : ""; ?>>
                                    <?php _e("Configure", "ai-chat-wp"); ?>
                                </button>
                                <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-remove-quick-button" title="<?php esc_attr_e(
                                    "Remove",
                                    "ai-chat-wp",
                                ); ?>">
                                    <span class="remove-icon">×</span>
                                </button>
                            </div>
                            <?php
                            endforeach;
                            ?>
                        </div>

                        <button type="button" id="aicwp-add-quick-button" class="aicwp-ui-button aicwp-ui-button-secondary">
                            <?php _e("+ Add Button", "ai-chat-wp"); ?>
                        </button>

                        <div class="aicwp-ui-form-group" style="margin: 20px 0 0 0; padding-top: 15px; border-top: 1px solid #eee;">
                            <label class="aicwp-ui-label" for="aicwp_chat_quick_buttons_visibility"><?php _e(
                                "Visibility",
                                "ai-chat-wp",
                            ); ?></label>
                            <select name="aicwp_chat_quick_buttons_visibility" id="aicwp_chat_quick_buttons_visibility" class="aicwp-ui-input" style="max-width: 250px;">
                                <option value="always" <?php selected(
                                    get_option(
                                        "aicwp_chat_quick_buttons_visibility",
                                        "always",
                                    ),
                                    "always",
                                ); ?>><?php _e(
    "Always show",
    "ai-chat-wp",
); ?></option>
                                <option value="hide_after_first" <?php selected(
                                    get_option(
                                        "aicwp_chat_quick_buttons_visibility",
                                        "always",
                                    ),
                                    "hide_after_first",
                                ); ?>><?php _e(
    "Hide after 1st message",
    "ai-chat-wp",
); ?></option>
                            </select>
                        </div>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECTION 4: CUSTOM INSTRUCTIONS -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="tools">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e(
                        "Custom Instructions",
                        "ai-chat-wp",
                    ); ?></h3>
                    <p><?php _e(
                        "Configure AI behavior, prompts, and interactive features.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- Force Language -->
                <div class="aicwp-ui-form-group">
                    <label for="aicwp_chat_force_language" class="aicwp-ui-label">
                        <?php _e(
                            "Force Response Language",
                            "ai-chat-wp",
                        ); ?>
                    </label>
                    <input type="text"
                           id="aicwp_chat_force_language"
                           name="aicwp_chat_force_language"
                           class="aicwp-ui-input"
                           style="max-width: 300px;"
                           value="<?php echo esc_attr(
                               get_option("aicwp_chat_force_language", ""),
                           ); ?>" />
                    <p class="aicwp-ui-help-text">
                        <strong><?php _e(
                            "Leave empty to auto-detect language from messages and user browser.",
                            "ai-chat-wp",
                        ); ?></strong>
                        <?php _e(
                            'Set a language (e.g., "French") to force AI to always respond in that language.',
                            "ai-chat-wp",
                        ); ?>
                    </p>
                </div>

                <!-- System Prompt -->
                <div class="aicwp-ui-form-group">
                    <?php $max_prompt_length = AICWP_MAX_PROMPT_LENGTH; ?>
                    <label for="aicwp_chat_system_prompt" class="aicwp-ui-label">
                        <?php _e(
                            "Custom System Prompt (Additional Instructions)",
                            "ai-chat-wp",
                        ); ?>
                    </label>
                    <textarea id="aicwp_chat_system_prompt" name="aicwp_chat_system_prompt" rows="8" class="aicwp-ui-input" maxlength="<?php echo esc_attr(
                        $max_prompt_length,
                    ); ?>" data-max-length="<?php echo esc_attr(
    $max_prompt_length,
); ?>" placeholder="<?php _e(
    "Add custom instructions about your website, business focus, special features...",
    "ai-chat-wp",
); ?>"><?php echo esc_textarea($system_prompt); ?></textarea>
                    <div style="display: flex;align-items: baseline;gap: 0;margin-top: 5px;flex-direction: column;">
                        <span id="system-prompt-counter" style="color: #666;"></span>
                    </div>
                    <p class="aicwp-ui-help-text">
                        <?php _e(
                            "Use this to describe your website, special features, or guide the AI behavior.",
                            "ai-chat-wp",
                        ); ?>
                        <br><strong><?php _e(
                            "Instructions should be short and concise. Long prompts can mislead the LLM - stay under 3000 characters.",
                            "ai-chat-wp",
                        ); ?></strong>
                    </p>
                    <button type="button" id="insert-post-reference-btn" class="aicwp-ui-button aicwp-ui-button-secondary" style="margin-top: 8px; font-size: 13px;">
                        <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; line-height: 1.4; margin-right: 4px;"></span>
                        <?php _e("Hints for AI", "ai-chat-wp"); ?>
                    </button>
                    <p class="aicwp-ui-help-text" style="margin-top: 4px;">
                        <?php _e(
                            "Direct the AI to use a specific page or document when answering certain questions.",
                            "ai-chat-wp",
                        ); ?>
                        <br><strong><?php _e(
                            "Optional. Use only if needed for sensitive topics where accuracy matters most.",
                            "ai-chat-wp",
                        ); ?></strong>
                    </p>
                </div>

                <!-- Hints for AI Modal -->
                <div id="post-reference-modal" class="aicwp-ui-modal" style="display: none;">
                    <div class="aicwp-ui-modal-overlay"></div>
                    <div class="aicwp-ui-modal-content" style="max-width: 560px;">
                        <div class="aicwp-ui-modal-header" style="flex-direction: row; justify-content: space-between; align-items: center;">
                            <h3 style="margin: 0;"><?php esc_html_e(
                                "Hints for AI",
                                "ai-chat-wp",
                            ); ?></h3>
                            <button type="button" class="aicwp-modal-close" id="post-reference-modal-close">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                        <div class="aicwp-ui-modal-body" style="padding: 20px 24px;">
                            <!-- Add new source form -->
                            <div class="aicwp-ui-form-group" style="margin-bottom: 12px;">
                                <label class="aicwp-ui-label"><?php esc_html_e(
                                    "When user asks about",
                                    "ai-chat-wp",
                                ); ?></label>
                                <input type="text" id="post-reference-topic" class="aicwp-ui-input" style="max-width: 100%;" placeholder="<?php esc_attr_e(
                                    "e.g. shipping, delivery, returns",
                                    "ai-chat-wp",
                                ); ?>" autocomplete="off" />
                            </div>

                            <div class="aicwp-ui-form-group" style="margin-bottom: 12px;">
                                <label class="aicwp-ui-label"><?php esc_html_e(
                                    "Search in",
                                    "ai-chat-wp",
                                ); ?></label>
                                <input type="text" id="post-reference-search" class="aicwp-ui-input" style="max-width: 100%;" placeholder="<?php esc_attr_e(
                                    "Search page or document by title...",
                                    "ai-chat-wp",
                                ); ?>" autocomplete="off" />
                                <div id="post-reference-results" style="display: none;"></div>
                                <div id="post-reference-selected" style="margin-top: 8px; display: none;">
                                    <span id="post-reference-selected-text"></span>
                                </div>
                            </div>

                            <button type="button" id="post-reference-add" class="aicwp-ui-button aicwp-ui-button-primary" disabled>
                                <span class="dashicons dashicons-plus-alt2" style="font-size: 16px; line-height: 1.4; margin-right: 4px;"></span>
                                <?php esc_html_e("Add", "ai-chat-wp"); ?>
                            </button>

                            <!-- Existing sources list -->
                            <div id="knowledge-sources-separator">
                                <div id="knowledge-sources-list">
                                    <?php
                                    $knowledge_sources = get_option(
                                        "aicwp_knowledge_sources",
                                        [],
                                    );
                                    if (
                                        !empty($knowledge_sources) &&
                                        is_array($knowledge_sources)
                                    ):
                                        foreach (
                                            $knowledge_sources
                                            as $idx => $ks
                                        ): ?>
                                        <div class="knowledge-source-item" data-index="<?php echo esc_attr(
                                            $idx,
                                        ); ?>">
                                            <div style="flex: 1; min-width: 0;">
                                                <div class="ks-label"><?php esc_html_e(
                                                    "When user asks about",
                                                    "ai-chat-wp",
                                                ); ?>:</div>
                                                <div class="ks-topic"><?php echo esc_html(
                                                    $ks["topic"],
                                                ); ?></div>
                                                <div class="ks-post"><?php echo esc_html(
                                                    $ks["post_title"],
                                                ); ?> (ID: <?php echo intval(
     $ks["post_id"],
 ); ?>)</div>
                                            </div>
                                            <button type="button" class="knowledge-source-delete" data-index="<?php echo esc_attr(
                                                $idx,
                                            ); ?>" title="<?php esc_attr_e(
    "Delete",
    "ai-chat-wp",
); ?>">&times;</button>
                                        </div>
                                        <?php endforeach;
                                    endif;
                                    ?>
                                </div>
                                <div id="knowledge-sources-empty" <?php echo !empty(
                                    $knowledge_sources
                                )
                                    ? 'style="display: none;"'
                                    : ""; ?>>
                                    <?php esc_html_e(
                                        "No hints added yet.",
                                        "ai-chat-wp",
                                    ); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Allow AI to Send Emails -->
                <div class="aicwp-ui-form-group" style="margin-top: 20px;">
                    <?php
                    $ai_contact_enabled =
                        get_option("aicwp_contact_form_allow_ai_send", 0);
                    ?>
                    <div style="display: flex; align-items: flex-start; gap: 15px;">
                        <label class="aicwp-ui-checkbox-label" style="flex: 1;">
                            <input type="checkbox"
                                   id="aicwp_contact_form_allow_ai_send"
                                   name="aicwp_contact_form_allow_ai_send"
                                   value="1"
                                   <?php checked($ai_contact_enabled, 1); ?> />
                            <span class="aicwp-ui-checkbox-custom"></span>
                            <span class="aicwp-ui-checkbox-text">
                                <?php _e(
                                    "Allow AI to Send Emails to You",
                                    "ai-chat-wp",
                                ); ?>
                                <small><?php _e(
                                    "When enabled, AI can send emails to you on behalf of users who explicitly request it when chatting with AI.",
                                    "ai-chat-wp",
                                ); ?></small>
                            </span>
                        </label>
                        <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-configure-contact-form" style="white-space: nowrap; margin-top: 3px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="margin-right: 5px; vertical-align: middle;">
                                <path d="M12 15a3 3 0 100-6 3 3 0 000 6z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?php _e("Configure", "ai-chat-wp"); ?>
                        </button>
                    </div>

                    <!-- Contact Form Examples (Conditional - shown when AI send emails is enabled) -->
                    <?php $default_examples =
                        "EXAMPLES OF WHEN TO USE:\n- \"Can you send a message to the site owner for me?\"\n- \"I want to contact support about X\"\n- \"Please send them my inquiry about Y\"\n\nEXAMPLES OF WHEN NOT TO USE:\n- \"How can I contact you?\" (just provide contact info)\n- \"What's your email?\" (just provide info, don't send)"; ?>
                    <div id="aicwp_contact_form_examples_wrapper" style="display: <?php echo $ai_contact_enabled
                        ? "block"
                        : "none"; ?>; margin-left: 30px; margin-top: 10px;">
                        <label for="aicwp_contact_form_examples" class="aicwp-ui-label">
                            <?php _e(
                                "Contact Tool Instructions for AI",
                                "ai-chat-wp",
                            ); ?>
                        </label>
                        <textarea id="aicwp_contact_form_examples" name="aicwp_contact_form_examples" class="aicwp-ui-input" rows="8" maxlength="1000" style="font-family: monospace; font-size: 12px;"><?php echo esc_textarea(
                            get_option(
                                "aicwp_contact_form_examples",
                                $default_examples,
                            ),
                        ); ?></textarea>
                        <p class="aicwp-ui-help-text"><?php _e(
                            "Customize when AI should or should not use the contact form tool. This helps AI understand your specific use cases.",
                            "ai-chat-wp",
                        ); ?></p>
                    </div>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECTION 5: ACCESS & PRIVACY -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="access">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e("Access & Privacy", "ai-chat-wp"); ?></h3>
                    <p><?php _e(
                        "Control who can use the chat and how data is handled.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <!-- Require Login -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-checkbox-label">
                        <input type="checkbox" name="aicwp_chat_require_login" value="1" <?php checked(
                            get_option("aicwp_chat_require_login", 0),
                            1,
                        ); ?> />
                        <span class="aicwp-ui-checkbox-custom"></span>
                        <span class="aicwp-ui-checkbox-text">
                            <?php _e(
                                "Require Login to Use Chat",
                                "ai-chat-wp",
                            ); ?>
                            <small><?php _e(
                                "Only logged-in WordPress users can access the AI chat. Shortcode will show a login message, floating widget will be hidden.",
                                "ai-chat-wp",
                            ); ?></small>
                        </span>
                    </label>
                </div>

                <!-- Terms of Use Notice -->
                <div class="aicwp-ui-form-group">
                    <label class="aicwp-ui-checkbox-label">
                        <input type="checkbox" id="aicwp_chat_terms_notice_enabled" name="aicwp_chat_terms_notice_enabled" value="1" <?php checked(
                            get_option(
                                "aicwp_chat_terms_notice_enabled",
                                0,
                            ),
                            1,
                        ); ?> />
                        <span class="aicwp-ui-checkbox-custom"></span>
                        <span class="aicwp-ui-checkbox-text">
                            <?php _e(
                                "Show Terms of Use Notice",
                                "ai-chat-wp",
                            ); ?>
                            <small><?php _e(
                                "Display a terms notice below the chat input for privacy compliance.",
                                "ai-chat-wp",
                            ); ?></small>
                        </span>
                    </label>
                </div>

                <!-- Terms Notice Text (Conditional - shown when checkbox is checked) -->
                <div id="aicwp_chat_terms_notice_text_wrapper" style="display: <?php echo get_option(
                    "aicwp_chat_terms_notice_enabled",
                    0,
                )
                    ? "block"
                    : "none"; ?>; margin-left: 30px;">
                    <label for="aicwp_chat_terms_notice_text" class="aicwp-ui-label">
                        <?php _e("Terms Notice Text", "ai-chat-wp"); ?>
                    </label>
                    <textarea id="aicwp_chat_terms_notice_text" name="aicwp_chat_terms_notice_text" class="aicwp-ui-input" rows="2" placeholder="By using this chat, you agree to our Terms of Use and Privacy Policy"><?php echo esc_textarea(
                        get_option(
                            "aicwp_chat_terms_notice_text",
                            'By using this chat, you agree to our <a href="/terms-of-use" target="_blank">Terms of Use</a> and <a href="/privacy-policy" target="_blank">Privacy Policy</a>',
                        ),
                    ); ?></textarea>
                    <p class="aicwp-ui-help-text"><?php _e(
                        'Text displayed below the chat input. HTML tags allowed (e.g., &lt;a href="/terms"&gt;Terms&lt;/a&gt;).',
                        "ai-chat-wp",
                    ); ?></p>
                </div>

                <?php // Full Pre-Chat Form Configuration
                do_action("aicwp_chat_access_privacy_settings"); ?>

                <!-- Block IP Addresses -->
                <div class="aicwp-ui-form-group aicwp-ui-group-block" style="margin-top: 20px; border: 1px solid #e0e0e0; border-radius: 5px; padding: 20px;">
                    <label class="aicwp-ui-label">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 18px; height: 18px; vertical-align: text-bottom; margin-right: 4px; display: inline-block;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5a17.92 17.92 0 0 1-8.716-2.247m0 0A8.966 8.966 0 0 1 3 12c0-1.264.26-2.467.732-3.558" /></svg><?php _e(
                            "Block IP Addresses",
                            "ai-chat-wp",
                        ); ?>
                    </label>
                    <p class="aicwp-ui-help-text" style="margin-bottom: 15px;">
                        <?php _e(
                            "The chat widget will be completely hidden for visitors from these IP addresses. Supports individual IPs and CIDR ranges (e.g., 192.168.1.0/24).",
                            "ai-chat-wp",
                        ); ?>
                    </p>

                    <?php // Full IP Blocking Configuration
                    do_action("aicwp_chat_blocked_ips_fields"); ?>
                </div>

            </div>
        </div>

        <!-- ========================================== -->
        <!-- SECTION 6: INTEGRATIONS -->
        <!-- ========================================== -->
        <div class="aicwp-ui-card" data-chat-section="integrations">
            <div class="aicwp-ui-card-header aicwp-ui-card-header-with-icon">
                <div class="aicwp-ui-card-icon aicwp-ui-card-icon-indigo">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
                </div>
                <div class="aicwp-ui-card-header-text">
                    <h3><?php _e("Integrations", "ai-chat-wp"); ?></h3>
                    <p><?php _e(
                        "Connect AI chat to external services and automation platforms.",
                        "ai-chat-wp",
                    ); ?></p>
                </div>
            </div>
            <div class="aicwp-ui-card-body">

                <?php do_action("aicwp_integrations_section"); ?>

            </div>
        </div>

        <!-- Hidden fields for remaining settings -->
        <?php $this->add_hidden_fields_except([
            "aicwp_chat_force_language",
            "aicwp_chat_system_prompt",
            "aicwp_chat_enabled",
            "aicwp_chat_avatar",
            "aicwp_chat_model",
            "aicwp_embedding_model",
            "aicwp_chat_name",
            "aicwp_chat_welcome_message",
            "aicwp_chat_max_results",
            "aicwp_chat_rag_sources_limit",
            "aicwp_chat_context_length",
            "aicwp_chat_woo_cart_enabled",
            "aicwp_chat_woo_order_checking_enabled",
            "aicwp_chat_hide_images",
            "aicwp_chat_loading_style",
            "aicwp_chat_typing_animation",
            "aicwp_chat_require_login",
            "aicwp_chat_terms_notice_enabled",
            "aicwp_chat_terms_notice_text",
            "aicwp_chat_whitelabel_enabled",
            "aicwp_contact_form_allow_ai_send",
            "aicwp_contact_form_examples",
            "aicwp_whatsapp_enabled",
            "aicwp_telegram_enabled",
            "aicwp_floating_chat_enabled",
            "aicwp_floating_keep_chat_opened",
            "aicwp_floating_position",
            "aicwp_floating_custom_icon",
            "aicwp_floating_custom_icon_size",
            "aicwp_floating_welcome_bubble",
            "aicwp_floating_popup_width",
            "aicwp_floating_popup_height",
            "aicwp_floating_button_color",
            "aicwp_floating_offset_desktop_h",
            "aicwp_floating_offset_desktop_v",
            "aicwp_floating_offset_mobile_h",
            "aicwp_floating_offset_mobile_v",
            "aicwp_primary_color",
            "aicwp_color_scheme",
            "aicwp_color_scheme_switcher",
            "aicwp_floating_header_style",
            "aicwp_floating_header_bg",
            "aicwp_floating_header_overlay",
            "aicwp_animated_bg_color",
            "aicwp_floating_excluded_pages",
            "aicwp_chat_quick_buttons_enabled",
            "aicwp_chat_quick_buttons_visibility",
            "aicwp_chat_blocked_ips",
            "aicwp_chat_enable_speech",
            "aicwp_chat_enable_image_input",
            "aicwp_openrouter_reasoning",
            "aicwp_chat_quick_buttons",
            "aicwp_provider",
            "aicwp_api_key",
            "aicwp_gemini_api_key",
            "aicwp_mistral_api_key",
            "aicwp_openrouter_api_key",
            "aicwp_api_key_remove",
            "aicwp_gemini_api_key_remove",
            "aicwp_mistral_api_key_remove",
            "aicwp_openrouter_api_key_remove",
            "aicwp_debug_mode",
            "aicwp_chat_lazy_load",
            "aicwp_chat_custom_css",
            "aicwp_rate_limit_per_hour",
            "aicwp_chat_rate_limit_tier1",
            "aicwp_chat_rate_limit_tier2",
            "aicwp_chat_rate_limit_tier3",
            "aicwp_max_results",
            "aicwp_min_match_percentage",
        ]); ?>

        <!-- Sticky Footer with Save Button -->
        <div class="aicwp-ui-sticky-footer">
            <div class="aicwp-ui-sticky-footer-inner">
                <div class="aicwp-ui-form-message aicwp-ui-footer-message" style="display: none;"></div>
                <button type="submit" class="aicwp-ui-button aicwp-ui-button-primary">
                    <span class="button-text"><?php _e(
                        "Save Settings",
                        "ai-chat-wp",
                    ); ?></span>
                    <span class="button-spinner" style="display: none;">
                        <span class="aicwp-ui-spinner"></span>
                    </span>
                </button>
            </div>
        </div>

        </form>

            </div><!-- /.aicwp-ui-chat-content -->
        </div><!-- /.aicwp-ui-chat-layout -->

        <script>
        jQuery(document).ready(function($) {
            var quickBtnDefaultColor = '<?php echo esc_js(
                get_option("aicwp_primary_color", "#0073ee"),
            ); ?>';

            // Add new quick button row
            $(document).on('click', '#aicwp-add-quick-button', function() {
                var container = $('#aicwp-quick-buttons-container');
                var index = container.find('.aicwp-quick-button-row').length;
                var newRow = `
                    <div class="aicwp-quick-button-row">
                        <div class="aicwp-quick-button-color-wrap">
                            <button type="button" class="aicwp-quick-btn-color-swatch" style="background-color: #ebebeb;"></button>
                            <input type="hidden"
                                   name="aicwp_chat_quick_buttons[${index}][color]"
                                   value=""
                                   class="aicwp-quick-button-color-value"
                                   data-default-color="${quickBtnDefaultColor}" />
                        </div>
                        <input type="text"
                               name="aicwp_chat_quick_buttons[${index}][text]"
                               value=""
                               placeholder="<?php esc_attr_e(
                                   "Button Text",
                                   "ai-chat-wp",
                               ); ?>"
                               class="aicwp-ui-input aicwp-quick-button-text" />
                        <select name="aicwp_chat_quick_buttons[${index}][type]"
                                class="aicwp-ui-input aicwp-quick-button-type">
                            <option value="chat"><?php _e(
                                "Chat Message",
                                "ai-chat-wp",
                            ); ?></option>
                            <option value="url"><?php _e(
                                "Link",
                                "ai-chat-wp",
                            ); ?></option>
                            <option value="contact"><?php _e(
                                "Contact Form",
                                "ai-chat-wp",
                            ); ?></option>
                        </select>
                        <input type="text"
                               name="aicwp_chat_quick_buttons[${index}][value]"
                               value=""
                               placeholder="<?php esc_attr_e(
                                   "Message to send",
                                   "ai-chat-wp",
                               ); ?>"
                               class="aicwp-ui-input aicwp-quick-button-value" />
                        <button type="button" class="aicwp-ui-button aicwp-ui-button-primary aicwp-configure-contact-form" style="display: none;">
                            <?php _e("Configure", "ai-chat-wp"); ?>
                        </button>
                        <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-remove-quick-button" title="<?php esc_attr_e(
                            "Remove",
                            "ai-chat-wp",
                        ); ?>">
                            <span class="remove-icon">×</span>
                        </button>
                    </div>
                `;
                container.append(newRow);
            });
        });
        </script>

        <!-- Contact Form Configuration Modal -->
        <div id="contact-form-config-modal" class="aicwp-ui-modal" style="display: none;">
            <div class="aicwp-ui-modal-overlay"></div>
            <div class="aicwp-ui-modal-content" style="max-width: 550px;">
                <div class="aicwp-ui-modal-header" style="flex-direction: row; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0;"><?php _e(
                        "Contact Form Settings",
                        "ai-chat-wp",
                    ); ?></h3>
                    <button type="button" id="contact-form-modal-close" class="aicwp-modal-close">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                </div>
                <div class="aicwp-ui-modal-body">
                    <?php
                    // SMTP Detection
                    $smtp_plugins = [
                        "wp-mail-smtp/wp_mail_smtp.php" => "WP Mail SMTP",
                        "fluent-smtp/fluent-smtp.php" => "FluentSMTP",
                        "post-smtp/postman-smtp.php" => "Post SMTP",
                        "easy-wp-smtp/easy-wp-smtp.php" => "Easy WP SMTP",
                        "smtp-mailer/main.php" => "SMTP Mailer",
                        "wp-smtp/wp-smtp.php" => "WP SMTP",
                        "mailgun/mailgun.php" => "Mailgun",
                        "sendgrid-email-delivery-simplified/wpsendgrid.php" =>
                            "SendGrid",
                    ];
                    $smtp_detected = false;
                    $smtp_plugin_name = "";
                    foreach ($smtp_plugins as $plugin_file => $plugin_name) {
                        if (is_plugin_active($plugin_file)) {
                            $smtp_detected = true;
                            $smtp_plugin_name = $plugin_name;
                            break;
                        }
                    }
                    ?>

                    <!-- SMTP Status Notice -->
                    <div class="aicwp-ui-notice <?php echo $smtp_detected
                        ? "aicwp-ui-notice-success"
                        : "aicwp-ui-notice-warning"; ?>" style="margin-bottom: 20px; padding: 12px 15px; border-radius: 6px; display: flex; align-items: flex-start; gap: 10px;">
                        <?php if ($smtp_detected): ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink: 0; color: #28a745;">
                                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <div>
                                <strong><?php _e(
                                    "SMTP Configured",
                                    "ai-chat-wp",
                                ); ?></strong><br>
                                <span style="font-size: 13px; color: #666;">
                                    <?php printf(
                                        __(
                                            "%s detected. Emails should be delivered reliably.",
                                            "ai-chat-wp",
                                        ),
                                        "<strong>" .
                                            esc_html($smtp_plugin_name) .
                                            "</strong>",
                                    ); ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink: 0; color: #ffc107;">
                                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <div>
                                <strong><?php _e(
                                    "No SMTP Plugin Detected",
                                    "ai-chat-wp",
                                ); ?></strong><br>
                                <span style="font-size: 13px; color: #666;">
                                    <?php _e(
                                        "Using PHP mail() which has poor deliverability. We recommend installing an SMTP plugin like WP Mail SMTP for reliable email delivery.",
                                        "ai-chat-wp",
                                    ); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Test Email Section -->
                    <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 15px;">
                            <div>
                                <strong><?php _e(
                                    "Test Email Delivery",
                                    "ai-chat-wp",
                                ); ?></strong><br>
                                <span style="font-size: 13px; color: #666;"><?php _e(
                                    "Send a test email to verify your configuration.",
                                    "ai-chat-wp",
                                ); ?></span>
                            </div>
                            <button type="button" id="contact-form-test-email-btn" class="aicwp-ui-button aicwp-ui-button-secondary" style="white-space: nowrap;">
                                <span class="button-text"><?php _e(
                                    "Send Test",
                                    "ai-chat-wp",
                                ); ?></span>
                                <span class="button-spinner" style="display: none;">
                                    <span class="aicwp-ui-spinner"></span>
                                </span>
                            </button>
                        </div>
                        <div id="contact-form-test-result" class="aicwp-ui-result-message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px; font-size: 13px;"></div>
                    </div>

                    <!-- Form Settings -->
                    <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                        <label for="aicwp_contact_form_recipient" class="aicwp-ui-label"><?php _e(
                            "Recipient Email",
                            "ai-chat-wp",
                        ); ?></label>
                        <input type="email" id="aicwp_contact_form_recipient" class="aicwp-ui-input" value="<?php echo esc_attr(
                            get_option(
                                "aicwp_contact_form_recipient",
                                get_option("admin_email"),
                            ),
                        ); ?>" placeholder="<?php echo esc_attr(
    get_option("admin_email"),
); ?>" />
                        <p class="aicwp-ui-help-text"><?php _e(
                            "Email address where contact form messages will be sent.",
                            "ai-chat-wp",
                        ); ?></p>
                    </div>

                    <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                        <label for="aicwp_contact_form_from_name" class="aicwp-ui-label"><?php _e(
                            "From Name",
                            "ai-chat-wp",
                        ); ?></label>
                        <input type="text" id="aicwp_contact_form_from_name" class="aicwp-ui-input" value="<?php echo esc_attr(
                            get_option(
                                "aicwp_contact_form_from_name",
                                get_bloginfo("name"),
                            ),
                        ); ?>" placeholder="<?php echo esc_attr(
    get_bloginfo("name"),
); ?>" />
                    </div>

                    <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                        <label for="aicwp_contact_form_from_email" class="aicwp-ui-label"><?php _e(
                            "From Email",
                            "ai-chat-wp",
                        ); ?></label>
                        <?php
                        // Try to detect From Email from popular SMTP plugins
                        $smtp_from_email = "";
                        $smtp_source = "";

                        // WP Mail SMTP
                        if (empty($smtp_from_email)) {
                            $wp_mail_smtp = get_option("wp_mail_smtp", []);
                            if (!empty($wp_mail_smtp["mail"]["from_email"])) {
                                $smtp_from_email =
                                    $wp_mail_smtp["mail"]["from_email"];
                                $smtp_source = "WP Mail SMTP";
                            }
                        }

                        // FluentSMTP
                        if (empty($smtp_from_email)) {
                            $fluent_settings = get_option(
                                "fluentmail-settings",
                                [],
                            );
                            if (!empty($fluent_settings["connections"])) {
                                $connections = $fluent_settings["connections"];
                                $first_connection = reset($connections);
                                if (!empty($first_connection["sender_email"])) {
                                    $smtp_from_email =
                                        $first_connection["sender_email"];
                                    $smtp_source = "FluentSMTP";
                                }
                            }
                        }

                        // Post SMTP
                        if (empty($smtp_from_email)) {
                            $postman_options = get_option(
                                "postman_options",
                                [],
                            );
                            if (!empty($postman_options["sender_email"])) {
                                $smtp_from_email =
                                    $postman_options["sender_email"];
                                $smtp_source = "Post SMTP";
                            }
                        }

                        // Easy WP SMTP
                        if (empty($smtp_from_email)) {
                            $easy_smtp = get_option("swpsmtp_options", []);
                            if (!empty($easy_smtp["from_email_field"])) {
                                $smtp_from_email =
                                    $easy_smtp["from_email_field"];
                                $smtp_source = "Easy WP SMTP";
                            }
                        }

                        $current_from_email = get_option(
                            "aicwp_contact_form_from_email",
                            "",
                        );
                        $default_from_email = !empty($smtp_from_email)
                            ? $smtp_from_email
                            : get_option("admin_email");
                        $display_value = !empty($current_from_email)
                            ? $current_from_email
                            : $default_from_email;

                        // Check for mismatch
                        $has_mismatch =
                            !empty($smtp_from_email) &&
                            !empty($current_from_email) &&
                            $current_from_email !== $smtp_from_email;
                        ?>

                        <?php if (!empty($smtp_from_email)): ?>
                            <div class="aicwp-ui-notice aicwp-ui-notice-info" style="margin-bottom: 10px; padding: 10px 12px; border-radius: 4px; font-size: 13px; background: #e8f4fc; border-left: 3px solid #0073ee;">
                                <?php printf(
                                    __(
                                        "Detected from %s: %s",
                                        "ai-chat-wp",
                                    ),
                                    "<strong>" .
                                        esc_html($smtp_source) .
                                        "</strong>",
                                    "<code>" .
                                        esc_html($smtp_from_email) .
                                        "</code>",
                                ); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($has_mismatch): ?>
                            <div class="aicwp-ui-notice aicwp-ui-notice-error" style="margin-bottom: 10px; padding: 10px 12px; border-radius: 4px; font-size: 13px; background: #fef2f2; border-left: 3px solid #dc3545; color: #991b1b;">
                                <strong><?php _e(
                                    "⚠️ Mismatch detected!",
                                    "ai-chat-wp",
                                ); ?></strong><br>
                                <?php _e(
                                    'Your From Email doesn\'t match your SMTP plugin settings. Emails may fail to deliver.',
                                    "ai-chat-wp",
                                ); ?>
                            </div>
                        <?php endif; ?>

                        <input type="email" id="aicwp_contact_form_from_email" class="aicwp-ui-input <?php echo $has_mismatch
                            ? "input-error"
                            : ""; ?>" value="<?php echo esc_attr(
    $display_value,
); ?>" placeholder="<?php echo esc_attr($default_from_email); ?>" />
                        <p class="aicwp-ui-help-text">
                            <?php if (!empty($smtp_from_email)): ?>
                                <?php _e(
                                    'Must match your SMTP plugin\'s verified sender address for reliable delivery.',
                                    "ai-chat-wp",
                                ); ?>
                            <?php else: ?>
                                <?php _e(
                                    'The "From" address for outgoing emails. If using an SMTP plugin, this should match your verified sender.',
                                    "ai-chat-wp",
                                ); ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                        <label for="aicwp_contact_form_subject" class="aicwp-ui-label"><?php _e(
                            "Email Subject",
                            "ai-chat-wp",
                        ); ?></label>
                        <input type="text" id="aicwp_contact_form_subject" class="aicwp-ui-input" value="<?php echo esc_attr(
                            get_option(
                                "aicwp_contact_form_subject",
                                "[{site_name}] New message from {name}",
                            ),
                        ); ?>" placeholder="[{site_name}] New message from {name}" />
                        <p class="aicwp-ui-help-text"><?php _e(
                            "Available placeholders: {site_name}, {name}, {email}",
                            "ai-chat-wp",
                        ); ?></p>
                    </div>

                    <div class="aicwp-ui-form-group" style="margin-bottom: 15px;">
                        <label for="aicwp_contact_form_success_message" class="aicwp-ui-label"><?php _e(
                            "Success Message",
                            "ai-chat-wp",
                        ); ?></label>
                        <input type="text" id="aicwp_contact_form_success_message" class="aicwp-ui-input" value="<?php echo esc_attr(
                            get_option(
                                "aicwp_contact_form_success_message",
                                __(
                                    "Your message has been sent successfully!",
                                    "ai-chat-wp",
                                ),
                            ),
                        ); ?>" />
                        <p class="aicwp-ui-help-text"><?php _e(
                            "Message shown to users after successful form submission.",
                            "ai-chat-wp",
                        ); ?></p>
                    </div>

                    <div id="contact-form-save-result" class="aicwp-ui-result-message" style="display: none; margin-bottom: 15px; padding: 10px; border-radius: 4px; font-size: 13px;"></div>
                </div>
                <div class="aicwp-ui-modal-footer">
                    <button type="button" id="contact-form-save-settings-btn" class="aicwp-ui-button aicwp-ui-button-primary">
                        <span class="button-text"><?php _e(
                            "Save Settings",
                            "ai-chat-wp",
                        ); ?></span>
                        <span class="button-spinner" style="display: none;">
                            <span class="aicwp-ui-spinner"></span>
                        </span>
                    </button>
                </div>
            </div>
        </div>

        <style>
            .aicwp-ui-notice-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
            .aicwp-ui-notice-warning { background: #fff3cd; border: 1px solid #ffc107; color: #856404; }
            .aicwp-ui-result-message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .aicwp-ui-result-message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        </style>

        <?php // Hook for integration modals (WhatsApp, Telegram, etc.) — renders inside aicwp-ui-admin-wrap

        do_action("aicwp_admin_modals"); ?>

        <?php
    }

    /**
     * Get list of available languages for translation importer
     *
     * @return array
     */
    public static function get_translation_languages()
    {
        return [
            "af" => "Afrikaans",
            "ar" => "العربية",
            "bg_BG" => "Български",
            "bn_BD" => "বাংলা",
            "ca" => "Català",
            "cs_CZ" => "Čeština‎",
            "cy" => "Cymraeg",
            "da_DK" => "Dansk",
            "de_AT" => "Deutsch (Österreich)",
            "de_CH" => "Deutsch (Schweiz)",
            "de_DE" => "Deutsch",
            "de_DE_formal" => "Deutsch (Sie)",
            "el" => "Ελληνικά",
            "en_AU" => "English (Australia)",
            "en_CA" => "English (Canada)",
            "en_GB" => "English (UK)",
            "en_NZ" => "English (New Zealand)",
            "en_US" => "English",
            "es_AR" => "Español de Argentina",
            "es_CL" => "Español de Chile",
            "es_CO" => "Español de Colombia",
            "es_ES" => "Español",
            "es_MX" => "Español de México",
            "et" => "Eesti",
            "eu" => "Euskara",
            "fa_IR" => "فارسی",
            "fi" => "Suomi",
            "fr_BE" => "Français de Belgique",
            "fr_CA" => "Français du Canada",
            "fr_FR" => "Français",
            "gl_ES" => "Galego",
            "he_IL" => "עִבְרִית",
            "hi_IN" => "हिन्दी",
            "hr" => "Hrvatski",
            "hu_HU" => "Magyar",
            "id_ID" => "Bahasa Indonesia",
            "is_IS" => "Íslenska",
            "it_IT" => "Italiano",
            "ja" => "日本語",
            "ko_KR" => "한국어",
            "lt_LT" => "Lietuvių kalba",
            "lv" => "Latviešu valoda",
            "mk_MK" => "Македонски јазик",
            "ms_MY" => "Bahasa Melayu",
            "nb_NO" => "Norsk bokmål",
            "nl_BE" => "Nederlands (België)",
            "nl_NL" => "Nederlands",
            "nn_NO" => "Norsk nynorsk",
            "pl_PL" => "Polski",
            "pt_BR" => "Português do Brasil",
            "pt_PT" => "Português",
            "ro_RO" => "Română",
            "ru_RU" => "Русский",
            "sk_SK" => "Slovenčina",
            "sl_SI" => "Slovenščina",
            "sq" => "Shqip",
            "sr_RS" => "Српски језик",
            "sv_SE" => "Svenska",
            "th" => "ไทย",
            "tl" => "Tagalog",
            "tr_TR" => "Türkçe",
            "uk" => "Українська",
            "vi" => "Tiếng Việt",
            "zh_CN" => "简体中文",
            "zh_HK" => "香港中文版",
            "zh_TW" => "繁體中文",
        ];
    }

    /**
     * AJAX handler for clearing all embeddings when switching AI provider
     */
    public function ajax_clear_embeddings_for_provider_switch()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_clear_embeddings", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        // Clear all embeddings using Database Manager
        if (!class_exists("AICWP_Database_Manager")) {
            wp_send_json_error([
                "message" => __(
                    "Database manager class not found.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        $result = AICWP_Database_Manager::clear_all_embeddings();

        if ($result === false) {
            wp_send_json_error([
                "message" => __(
                    "Failed to clear embeddings.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        wp_send_json_success([
            "message" => __(
                "All embeddings have been successfully cleared.",
                "ai-chat-wp",
            ),
        ]);
    }

    /**
     * AJAX handler for saving the embedding model selection
     */
    public function ajax_save_embedding_model()
    {
        // Verify nonce
        if (!check_ajax_referer("aicwp_nonce", "nonce", false)) {
            wp_send_json_error([
                "message" => __("Security check failed.", "ai-chat-wp"),
            ]);
            return;
        }

        // Check user permissions
        if (!current_user_can("manage_options")) {
            wp_send_json_error([
                "message" => __("Insufficient permissions.", "ai-chat-wp"),
            ]);
            return;
        }

        $model = isset($_POST["model"])
            ? sanitize_text_field($_POST["model"])
            : "";
        if (empty($model)) {
            wp_send_json_error([
                "message" => __(
                    "No embedding model selected.",
                    "ai-chat-wp",
                ),
            ]);
            return;
        }

        update_option("aicwp_embedding_model", $model);

        wp_send_json_success([
            "message" => __("Embedding model saved.", "ai-chat-wp"),
            "model" => $model,
        ]);
    }

    // Note: Admin JavaScript has been moved to external files in assets/js/admin/
    // - admin-core.js: AJAX helpers and utilities
    // - ai-admin-settings.js: Provider toggle, API tests, form handling
    // - admin-ui-embeddings.js: Batch processing, embedding viewer
    // - admin-database.js: Database status, actions, search
    // - admin-media.js: WordPress media uploader
    // Dead Safe Mode code has been removed as it was never implemented.

    /**
     * Show debug mode notice
     */
    public function show_debug_mode_notice()
    {
        $screen = get_current_screen();
        if ($screen && $screen->id === "toplevel_page_ai-chat-wp") {
            $debug_file = WP_CONTENT_DIR . "/debug.log";
            echo '<div class="aicwp-ui-debug-notice">';
            echo '<p><span class="aicwp-ui-debug-icon">&#9432;</span> <strong>' .
                esc_html__("Debug Mode Active:", "ai-chat-wp") .
                "</strong> " .
                sprintf(
                    esc_html__(
                        "Detailed logs are being written to %s",
                        "ai-chat-wp",
                    ),
                    "<code>" . esc_html($debug_file) . "</code>",
                ) .
                "</p>";
            echo "</div>";
        }
    }
}
