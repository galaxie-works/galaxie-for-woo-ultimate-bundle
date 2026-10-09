<?php
/**
 * Plugin Name: AI Chat for WordPress
 * Description: AI chatbot and semantic search for WordPress, trained on your own content. Supports OpenAI, Gemini, Mistral and OpenRouter.
 * Version: 3.0.0
 * Author: Community
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: ai-chat-wp
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined("ABSPATH")) {
    exit();
}

// Define plugin constants
define("AICWP_VERSION", "3.0.0");
define("AICWP_PLUGIN_URL", plugin_dir_url(__FILE__));
define("AICWP_PLUGIN_PATH", plugin_dir_path(__FILE__));

// Maximum length of the custom system prompt (characters)
define("AICWP_MAX_PROMPT_LENGTH", 6000);

/**
 * Main plugin class
 */
class AICWP_Plugin
{
    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Search handler instance
     *
     * @var AICWP_Search_Handler
     */
    private $search_handler;

    /**
     * Shortcode handler instance
     *
     * @var AICWP_Shortcode_Handler
     */
    private $shortcode_handler;

    /**
     * Admin interface instance
     *
     * @var AICWP_Admin_Interface
     */
    private $admin_interface;

    /**
     * Get instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        // Load dependencies first
        $this->load_dependencies();

        // Initialize AJAX handlers early (before init)
        $this->search_handler = new AICWP_Search_Handler();

        add_action("init", [$this, "init"]);
        add_action("wp_enqueue_scripts", [$this, "enqueue_scripts"]);

        $this->init_wp_rocket_compatibility();

        // Prevent WordPress from translating plugin name in admin — brand name should stay as-is
        if (is_admin()) {
            add_filter('gettext_ai-chat-wp', function ($translation, $text) {
                if ($text === 'AI Chat for WordPress') {
                    return $text;
                }
                return $translation;
            }, 10, 2);
        }

        register_activation_hook(__FILE__, [$this, "activate"]);
        register_deactivation_hook(__FILE__, [$this, "deactivate"]);
    }

    /**
     * Initialize plugin
     */
    public function init()
    {
        // Bundled translations (languages/ai-chat-wp-{locale}.mo)
        load_plugin_textdomain("ai-chat-wp", false, dirname(plugin_basename(__FILE__)) . "/languages");

        // Register external pages CPT (Pro feature - hidden CPT for storing scraped web pages)
        register_post_type('ai_external_page', array(
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title', 'editor'),
            'can_export' => false,
            'label' => __('External Pages', 'ai-chat-wp'),
        ));

        // Initialize remaining components
        $this->shortcode_handler = new AICWP_Shortcode_Handler();
        $this->admin_interface = new AICWP_Admin_Interface();

        // Initialize background processor if available
        if (class_exists("AICWP_Background_Processor")) {
            AICWP_Background_Processor::init();
        }

        // Auto-process posts on save (generate/update embeddings)
        add_action("save_post", [$this, "process_listing_on_save"], 10, 2);

        // WP All Import integration - auto-generate embeddings during import
        if (class_exists("PMXI_Plugin") || defined("PMXI_VERSION")) {
            add_action(
                "pmxi_saved_post",
                [$this, "process_wpallimport_post"],
                10,
                3,
            );
        }

        // Run upgrade check only on plugin settings page (handles plugin updates)
        if (
            is_admin() &&
            isset($_GET["page"]) &&
            $_GET["page"] === "ai-chat-wp"
        ) {
            $this->maybe_upgrade();
        }
    }

    /**
     * Check if plugin was updated and run necessary upgrades
     * This handles cases where plugin is updated (activation hook doesn't fire)
     */
    private function maybe_upgrade()
    {
        $installed_version = get_option("aicwp_version", "0");

        // If version changed or never set, run upgrades
        if (
            version_compare($installed_version, AICWP_VERSION, "<")
        ) {
            // Ensure contact messages table exists (added in 1.6.0)
            if (class_exists("AICWP_Contact_Messages")) {
                AICWP_Contact_Messages::create_table();
            }

            // Ensure chat history table and columns are up to date (ip_address added in 1.6.5)
            if (class_exists("AICWP_Chat_History")) {
                AICWP_Chat_History::create_table();
            }

            // 1.9.6: Pin cart setting to 0 for existing installs so it doesn't
            // get auto-enabled via initialize_default_settings() default of 1
            if (version_compare($installed_version, "1.9.6", "<") && get_option("aicwp_chat_woo_cart_enabled") === false) {
                add_option("aicwp_chat_woo_cart_enabled", 0);
            }

            // 2.1.2: Enable order checking by default for existing installs
            if (version_compare($installed_version, "2.1.2", "<") && get_option("aicwp_chat_woo_order_checking_enabled") === false) {
                add_option("aicwp_chat_woo_order_checking_enabled", 1);
            }

            // Update stored version
            update_option("aicwp_version", AICWP_VERSION);
        }
    }

    /**
     * Load all required class files
     */
    private function load_dependencies()
    {
        // PDF post type (loads early)
        require_once AICWP_PLUGIN_PATH .
            "includes/class-pdf-post-type.php";

        // Content chunker (for long posts/pages - loads early)
        require_once AICWP_PLUGIN_PATH .
            "includes/class-content-chunker.php";

        // Content extractors (must load before embedding manager)
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-factory.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-post.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-pdf.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-chunk.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-default.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-null.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/content-extractors/class-content-extractor-external-page.php";
        // Note: Page and Product extractors moved to Pro plugin

        // Core utility classes
        require_once AICWP_PLUGIN_PATH .
            "includes/class-utility-helper.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/class-result-formatter.php";

        // AI Provider abstraction layer (OpenAI/Gemini)
        require_once AICWP_PLUGIN_PATH .
            "includes/class-ai-provider.php";

        require_once AICWP_PLUGIN_PATH .
            "includes/class-embedding-manager.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/class-database-manager.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/class-analytics.php";

        // Search engines
        require_once AICWP_PLUGIN_PATH .
            "includes/search/class-fallback-engine.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/search/class-ai-engine.php";

        // Main handlers
        require_once AICWP_PLUGIN_PATH .
            "includes/class-search-handler.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/frontend/class-shortcode-handler.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/admin/class-admin-interface.php";
        require_once AICWP_PLUGIN_PATH .
            "includes/admin/class-universal-settings.php";

        // Chat API for conversational search
        require_once AICWP_PLUGIN_PATH .
            "includes/class-chat-api.php";

        // Chat history tracking
        // Load from free version unless Pro has already loaded it
        if (!class_exists("AICWP_Chat_History")) {
            require_once AICWP_PLUGIN_PATH .
                "includes/class-chat-history.php";
        }

        // Chat shortcode
        require_once AICWP_PLUGIN_PATH .
            "includes/class-chat-shortcode.php";

        // Floating chat widget
        require_once AICWP_PLUGIN_PATH .
            "includes/class-floating-chat-widget.php";

        // Contact form handler
        require_once AICWP_PLUGIN_PATH .
            "includes/class-contact-form.php";

        // Contact messages logger
        require_once AICWP_PLUGIN_PATH .
            "includes/class-contact-messages.php";

        // WooCommerce integration (Pro feature - moved to Pro plugin)
        // Pro plugin hooks into 'aicwp_woocommerce_integration' to provide this
        add_action(
            "plugins_loaded",
            function () {
                if (class_exists("WooCommerce")) {
                    do_action("aicwp_woocommerce_integration");
                }
            },
            20,
        );

        // Background processor (existing)
        if (
            file_exists(
                AICWP_PLUGIN_PATH .
                    "includes/class-background-processor.php",
            )
        ) {
            require_once AICWP_PLUGIN_PATH .
                "includes/class-background-processor.php";
        }
    }

    /**
     * Exclude our CSS/JS from cache/optimization plugins
     */
    private function init_wp_rocket_compatibility()
    {
        // --- WP Rocket ---
        if (defined('WP_ROCKET_VERSION')) {
            $js_re = '/plugins/ai-chat-wp/assets/js/(.*).js';

            add_filter('rocket_rucss_external_exclusions', function ($ex) {
                $ex[] = '/plugins/ai-chat-wp/assets/css/';
                return $ex;
            });
            add_filter('rocket_exclude_js', function ($ex) use ($js_re) {
                $ex[] = $js_re;
                return $ex;
            });
            add_filter('rocket_exclude_defer_js', function ($ex) use ($js_re) {
                $ex[] = $js_re;
                return $ex;
            });
            add_filter('rocket_delay_js_exclusions', function ($ex) use ($js_re) {
                $ex[] = $js_re;
                return $ex;
            });
        }

        // --- LiteSpeed Cache ---
        if (defined('LSCWP_V')) {
            add_filter('litespeed_optimize_css_excludes', function ($ex) {
                $ex[] = 'ai-chat-wp/assets/css/';
                return $ex;
            });
            add_filter('litespeed_optimize_js_excludes', function ($ex) {
                $ex[] = 'ai-chat-wp/assets/js/';
                return $ex;
            });
            add_filter('litespeed_optm_js_defer_exc', function ($ex) {
                $ex[] = 'ai-chat-wp/assets/js/';
                return $ex;
            });
        }

        // --- Autoptimize ---
        if (defined('AUTOPTIMIZE_PLUGIN_VERSION')) {
            add_filter('autoptimize_filter_css_exclude', function ($ex) {
                $paths = 'ai-chat-wp/assets/css/';
                return ($ex !== '' ? $ex . ', ' : '') . $paths;
            });
            add_filter('autoptimize_filter_js_exclude', function ($ex) {
                $paths = 'ai-chat-wp/assets/js/';
                return ($ex !== '' ? $ex . ', ' : '') . $paths;
            });
        }

        // --- SiteGround Optimizer (uses WP handles) ---
        if (defined('SiteGround_Optimizer\VERSION')) {
            $handles = [
                'ai-chat-wp',
                'aicwp-chat',
                'aicwp-chat-dark-mode',
                'aicwp-floating-chat',
                'aicwp-silk-wave-bg',
                'aicwp-speech',
            ];
            add_filter('sgo_css_combine_exclude', function ($ex) use ($handles) {
                return array_merge((array) $ex, $handles);
            });
            add_filter('sgo_css_minify_exclude', function ($ex) use ($handles) {
                return array_merge((array) $ex, $handles);
            });
            add_filter('sgo_javascript_combine_exclude', function ($ex) use ($handles) {
                return array_merge((array) $ex, $handles);
            });
            add_filter('sgo_js_minify_exclude', function ($ex) use ($handles) {
                return array_merge((array) $ex, $handles);
            });
        }
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            "ai-chat-wp",
            AICWP_PLUGIN_URL . "assets/js/search.js",
            ["jquery"],
            AICWP_VERSION,
            true,
        );

        wp_localize_script("ai-chat-wp", "aicwpSearch", [
            "ajax_url" => get_admin_url(
                get_current_blog_id(),
                "admin-ajax.php",
            ),
            "nonce" => wp_create_nonce("aicwp_nonce"),
            "debugMode" => (bool) get_option(
                "aicwp_debug_mode",
                false,
            ), // Debug mode from settings
            "ai_enabled" => true, // AI search is always enabled
            "max_results" => intval(
                get_option("aicwp_max_results", 10),
            ),
            "strings" => [
                "searching" => __("Searching...", "ai-chat-wp"),
                "no_results" => __("No results found.", "ai-chat-wp"),
                "error" => __("Search error occurred.", "ai-chat-wp"),
                "best_match" => __("Best Match", "ai-chat-wp"),
                "type_keywords_first" => __(
                    "Type keywords first",
                    "ai-chat-wp",
                ),
                "top_result_singular" => __(
                    "Top 1 result matching",
                    "ai-chat-wp",
                ),
                "top_results_plural" => __(
                    "Top %d results matching",
                    "ai-chat-wp",
                ),
                // Error messages for search
                "rateLimitError" => __(
                    "Too many searches. Please wait a moment and try again.",
                    "ai-chat-wp",
                ),
                "apiUnavailable" => __(
                    "AI search temporarily unavailable. Using basic search instead.",
                    "ai-chat-wp",
                ),
                "sessionExpired" => __(
                    "Session expired. Please refresh the page.",
                    "ai-chat-wp",
                ),
                "fallbackNotice" => __(
                    "Showing regular search results instead.",
                    "ai-chat-wp",
                ),
                // Stock status
                "inStock" => __("In Stock", "ai-chat-wp"),
                "outOfStock" => __("Out of Stock", "ai-chat-wp"),
            ],
        ]);

        wp_enqueue_style(
            "ai-chat-wp",
            AICWP_PLUGIN_URL . "assets/css/ai-search.css",
            [],
            AICWP_VERSION,
        );
    }

    /**
     * Process listing on save
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function process_listing_on_save($post_id, $post)
    {
        AICWP_Database_Manager::process_listing_on_save(
            $post_id,
            $post,
        );
    }

    /**
     * Process post imported via WP All Import
     * Generates embedding immediately, bypassing throttle
     *
     * @param int $post_id Post ID
     * @param object $xml_node XML node data
     * @param bool $is_update Whether this is an update
     */
    public function process_wpallimport_post($post_id, $xml_node, $is_update)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== "publish") {
            return;
        }

        // Check if post type is enabled for embeddings
        $enabled_types = AICWP_Database_Manager::get_enabled_post_types();
        if (!in_array($post->post_type, $enabled_types)) {
            return;
        }

        // Remove throttle to allow immediate embedding generation
        delete_transient("aicwp_last_embedding_" . $post_id);

        // Generate embedding
        AICWP_Database_Manager::generate_single_embedding($post_id);
    }

    /**
     * Custom debug logging to debug.log
     *
     * @param string $message Log message
     * @param string $level Log level (info, error, warning, debug)
     */
    public static function debug_log($message, $level = "info")
    {
        // Only log if debug mode is explicitly enabled (must be truthy value like 1 or '1')
        $debug_mode = get_option("aicwp_debug_mode", 0);
        if (empty($debug_mode) || $debug_mode === "0") {
            return;
        }

        // Use WordPress standard debug logging
        $timestamp = date("Y-m-d H:i:s");
        $formatted_message = "[{$timestamp}] [{$level}] AI Chat: {$message}";

        error_log($formatted_message);
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Copy settings and data stored under the plugin's previous names (runs once)
        AICWP_Legacy_Migration::maybe_run();

        // Initialize default settings (only if not already set)
        $this->initialize_default_settings();

        // Auto-set force language on first install to match WordPress locale
        $this->maybe_set_force_language();

        // Create database tables
        AICWP_Database_Manager::create_tables();

        // Create chat history table (only if feature is enabled)
        if (get_option("aicwp_chat_history_enabled", 0)) {
            AICWP_Chat_History::create_table();
        }

        // Create contact messages table
        AICWP_Contact_Messages::create_table();

        // Embeddings are generated:
        // 1. Manually via "Start Training" button in admin
        // 2. Automatically when individual posts are published/updated (via save_post hook)

        // Schedule weekly chat history cleanup (runs every 7 days)
        if (!wp_next_scheduled("aicwp_cleanup_chat_history")) {
            wp_schedule_event(
                time(),
                "weekly",
                "aicwp_cleanup_chat_history",
            );
        }

        // Schedule yearly contact messages cleanup
        if (!wp_next_scheduled("aicwp_cleanup_contact_messages")) {
            wp_schedule_event(
                time(),
                "weekly",
                "aicwp_cleanup_contact_messages",
            );
        }

        // Store plugin version for upgrade checks
        update_option("aicwp_version", AICWP_VERSION);
    }

    /**
     * Get default settings array
     * Centralized defaults for consistency across plugin
     *
     * @return array Default settings
     */
    public static function get_default_settings()
    {
        return [
            // API Provider settings
            "aicwp_provider" => "openai",

            // Search settings
            "aicwp_min_match_percentage" => 50,
            "aicwp_best_match_threshold" => 75,
            "aicwp_max_results" => 10,
            "aicwp_rate_limit_per_hour" => 200,
            // batch_size removed - now auto-detected (5k threshold, 3k batch)
            "aicwp_embedding_delay" => 5,

            // Chat settings
            "aicwp_chat_enabled" => 1,
            "aicwp_chat_name" => __("AI Assistant", "ai-chat-wp"),
            "aicwp_chat_welcome_message" => __(
                "Hello! How can I help you today?",
                "ai-chat-wp",
            ),
            "aicwp_chat_system_prompt" => "",
            "aicwp_chat_model" => "gpt-5.4-mini",
            "aicwp_chat_max_results" => 10,
            "aicwp_chat_rag_sources_limit" => 5,
            "aicwp_chat_hide_images" => 0,
            "aicwp_chat_require_login" => 0,
            "aicwp_chat_history_enabled" => 1,
            "aicwp_chat_retention_days" => 30,
            "aicwp_chat_terms_notice_enabled" => 0,
            "aicwp_chat_terms_notice_text" => "",
            "aicwp_chat_rate_limit_tier1" => 10,
            "aicwp_chat_rate_limit_tier2" => 30,
            "aicwp_chat_rate_limit_tier3" => 100,
            "aicwp_chat_context_length" => "normal",

            // Contact form tool settings
            "aicwp_contact_form_examples" =>
                "EXAMPLES OF WHEN TO USE:\n- \"Can you send a message to the site owner for me?\"\n- \"I want to contact support about X\"\n- \"Please send them my inquiry about Y\"\n\nEXAMPLES OF WHEN NOT TO USE:\n- \"How can I contact you?\" (just provide contact info)\n- \"What's your email?\" (just provide info, don't send)",

            // Floating widget settings
            "aicwp_floating_chat_enabled" => 1,
            "aicwp_floating_button_icon" => "default",
            "aicwp_floating_custom_icon" => 0,
            "aicwp_floating_welcome_bubble" => __(
                "Hi! How can I help you?",
                "ai-chat-wp",
            ),
            "aicwp_floating_popup_width" => 390,
            "aicwp_floating_popup_height" => 600,
            "aicwp_floating_button_color" => "#222222",
            "aicwp_primary_color" => "#0073ee",
            "aicwp_floating_excluded_pages" => [],
            "aicwp_chat_quick_buttons_enabled" => 1,
            "aicwp_chat_quick_buttons_visibility" => "always",
            "aicwp_chat_quick_buttons" => [],

            // WooCommerce cart (enabled by default for new installs)
            "aicwp_chat_woo_cart_enabled" => 1,

            // Checkbox settings
            "aicwp_debug_mode" => 0,
            "aicwp_query_expansion" => 0,
            "aicwp_enable_analytics" => 1,
            "aicwp_suggestions_enabled" => 0,
        ];
    }

    /**
     * Initialize default settings
     * Only sets values if they don't exist in database
     */
    private function initialize_default_settings()
    {
        $defaults = self::get_default_settings();

        foreach ($defaults as $option_name => $default_value) {
            // Only add if option doesn't exist (won't overwrite existing values)
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    /**
     * Auto-set force language on first plugin install to match WordPress locale.
     * Only runs if the setting has never been saved (no user modification).
     */
    private function maybe_set_force_language()
    {
        if (get_option('aicwp_chat_force_language') !== false) {
            return;
        }

        $locale = get_locale();
        if (empty($locale)) {
            return;
        }

        $languages = AICWP_Admin_Interface::get_translation_languages();

        if (isset($languages[$locale])) {
            add_option('aicwp_chat_force_language', $languages[$locale]);
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Clean up scheduled events
        wp_clear_scheduled_hook("aicwp_aggregate_monthly_stats");
        wp_clear_scheduled_hook("aicwp_bulk_process_listings");
        wp_clear_scheduled_hook("aicwp_process_listing");
        wp_clear_scheduled_hook("aicwp_cleanup_chat_history");
        wp_clear_scheduled_hook("aicwp_cleanup_contact_messages");
    }
}

/**
 * Get chat strings for localization (shared by shortcode and floating widget)
 * Centralized in one place to avoid duplication
 *
 * @param string $welcome_message Custom welcome message
 * @return array Localized strings
 */
function aicwp_get_chat_strings($welcome_message = "")
{
    if (empty($welcome_message)) {
        $welcome_message = __(
            "Hello! How can I help you today?",
            "ai-chat-wp",
        );
    }

    return [
        "sendButton" => __("Send", "ai-chat-wp"),
        "welcomeMessage" => $welcome_message,
        "loading" => __("Thinking...", "ai-chat-wp"),
        "loadingConfig" => __(
            "Please wait, loading configuration...",
            "ai-chat-wp",
        ),
        "searchingDatabase" => __("Thinking...", "ai-chat-wp"),
        "analyzingResults" => __("Analyzing...", "ai-chat-wp"),
        "generatingAnswer" => __("Thinking...", "ai-chat-wp"),
        "loadingButton" => __("Loading...", "ai-chat-wp"),
        "errorApiKey" => __(
            "Please add API key in plugin settings.",
            "ai-chat-wp",
        ),
        "apiNotConfigured" => __(
            "⚠️ Hey, to start using the chatbot <strong>please add OpenAI or Gemini API key</strong> in plugin settings!",
            "ai-chat-wp",
        ),
        "noEmbeddings" => __(
            "⚠️ No trained content found. Go to the <strong>Data Training</strong> tab to train your content.",
            "ai-chat-wp",
        ),
        "errorConfig" => __(
            "Failed to load chat configuration. Please try again later.",
            "ai-chat-wp",
        ),
        "errorGeneral" => __(
            "Sorry, an error occurred. Please try again.",
            "ai-chat-wp",
        ),
        "chatDisabled" => __(
            "AI Chat is currently disabled.",
            "ai-chat-wp",
        ),
        "bestMatch" => __("Best Match", "ai-chat-wp"),
        "showMore" => __("Show more (%d)", "ai-chat-wp"),
        // Product context strings (WooCommerce)
        "talkAboutProduct" => __("Talk about this product", "ai-chat-wp"),
        "productContextLoaded" => __(
            "Product context loaded! You can now ask me anything about",
            "ai-chat-wp",
        ),
        "errorLoadingProduct" => __("Error loading product.", "ai-chat-wp"),
        "failedLoadProductDetails" => __(
            "Failed to load product details.",
            "ai-chat-wp",
        ),
        // Chat loading states
        "searchingProducts" => __("Searching products...", "ai-chat-wp"),
        "searchingSiteContent" => __(
            "Searching site content...",
            "ai-chat-wp",
        ),
        "analyzingProducts" => __("Analyzing products...", "ai-chat-wp"),
        "selectingBestMatches" => __("Analyzing results...", "ai-chat-wp"),
        "gettingProductDetails" => __(
            "Getting product details...",
            "ai-chat-wp",
        ),
        "analyzingProduct" => __("Analyzing product...", "ai-chat-wp"),
        "comparingProducts" => __("Comparing products...", "ai-chat-wp"),
        "analyzingContent" => __("Analyzing content...", "ai-chat-wp"),
        // Order status messages
        "checkingOrderStatus" => __(
            "Checking order status...",
            "ai-chat-wp",
        ),
        "analyzingOrderDetails" => __(
            "Analyzing order details...",
            "ai-chat-wp",
        ),
        // Contact form messages
        "sendingMessage" => __("Sending message...", "ai-chat-wp"),
        "orderNotFound" => __(
            "Order not found. Please check the order number and try again.",
            "ai-chat-wp",
        ),
        "orderVerificationRequired" => __(
            "Please provide your billing email to verify the order.",
            "ai-chat-wp",
        ),
        "errorGettingOrder" => __(
            "Unable to retrieve order status. Please try again later.",
            "ai-chat-wp",
        ),
        // Error messages
        "searchFailed" => __(
            "Search failed. Please try again.",
            "ai-chat-wp",
        ),
        "productSearchFailed" => __(
            "Product search failed. Please try again.",
            "ai-chat-wp",
        ),
        "unknownFunction" => __(
            "Unknown function requested.",
            "ai-chat-wp",
        ),
        "contentNotFound" => __(
            "Content not found or not published.",
            "ai-chat-wp",
        ),
        "errorGettingContent" => __(
            "Error getting content details.",
            "ai-chat-wp",
        ),
        "productNotFound" => __(
            "Product not found or not published.",
            "ai-chat-wp",
        ),
        "errorGettingProduct" => __(
            "Error getting product details.",
            "ai-chat-wp",
        ),
        // Detailed error messages for diagnostics
        "errorNetwork" => __(
            "Network error - request could not be sent. Please check your connection.",
            "ai-chat-wp",
        ),
        "errorConnection" => __(
            "Connection was interrupted. Please try again.",
            "ai-chat-wp",
        ),
        "errorTimeout" => __(
            "Request timed out. Please try again.",
            "ai-chat-wp",
        ),
        "errorRateLimit" => __(
            "Too many requests. Please wait a moment and try again.",
            "ai-chat-wp",
        ),
        "errorServer" => __(
            "Server error occurred. Please try again.",
            "ai-chat-wp",
        ),
        // No results messages
        "thinkingAboutQuery" => __("Thinking...", "ai-chat-wp"),
        "noResultsGeneric" => __(
            'I couldn\'t find results matching your search. Try different keywords or be more specific about what you\'re looking for.',
            "ai-chat-wp",
        ),
        // Rate limit messages
        "rateLimitPrefix" => __(
            'You\'ve reached the limit of',
            "ai-chat-wp",
        ),
        "rateLimitSuffix" => __("messages per", "ai-chat-wp"),
        "rateLimitWait" => __("Please wait", "ai-chat-wp"),
        "rateLimitBeforeTrying" => __("before trying again.", "ai-chat-wp"),
        "minute" => __("minute", "ai-chat-wp"),
        "minutes" => __("minutes", "ai-chat-wp"),
        "hour" => __("hour", "ai-chat-wp"),
        "hours" => __("hours", "ai-chat-wp"),
        "second" => __("second", "ai-chat-wp"),
        "seconds" => __("seconds", "ai-chat-wp"),
        // Stock status
        "inStock" => __("In Stock", "ai-chat-wp"),
        "outOfStock" => __("Out of Stock", "ai-chat-wp"),
        // Product
        "sku" => __("SKU", "ai-chat-wp"),
        // Contact form
        "contactFormFillAll" => __(
            "Please fill in all fields.",
            "ai-chat-wp",
        ),
        "contactFormSent" => __("Message sent successfully!", "ai-chat-wp"),
        "contactFormError" => __(
            "Failed to send message. Please try again.",
            "ai-chat-wp",
        ),
        // Speech-to-text (PRO feature)
        "micStartRecording" => __("Recording...", "ai-chat-wp"),
        "micStopRecording" => __("Processing...", "ai-chat-wp"),
        "micAccessDenied" => __(
            "Microphone access denied. Please allow microphone access in your browser settings.",
            "ai-chat-wp",
        ),
        "micNotSupported" => __(
            "Speech-to-text is not supported in your browser.",
            "ai-chat-wp",
        ),
        "micNoSSL" => __("Not available without SSL", "ai-chat-wp"),
        "audioTooLarge" => __(
            "Recording is too long. Please try a shorter message.",
            "ai-chat-wp",
        ),
        "transcriptionFailed" => __(
            "Could not transcribe audio. Please try again.",
            "ai-chat-wp",
        ),
        "speechNotAvailable" => __(
            "Speech-to-text requires PRO version.",
            "ai-chat-wp",
        ),
        // Image input
        "imageTooLarge" => __(
            "Image is too large. Maximum size is 4MB.",
            "ai-chat-wp",
        ),
        "imageResolutionTooLarge" => __(
            "Image resolution is too large. Maximum is 3000x3000 pixels.",
            "ai-chat-wp",
        ),
        "imageInvalidFormat" => __(
            "Invalid image format. Allowed: JPEG, PNG, GIF, WebP.",
            "ai-chat-wp",
        ),
        "imageAttached" => __("[Image attached]", "ai-chat-wp"),
        "analyzingImage" => __("Analyzing image...", "ai-chat-wp"),
        // Pre-chat fields
        "preChatRequired" => __("Fill out required fields", "ai-chat-wp"),
        // WooCommerce cart
        "addToCart" => __("Add to Cart", "ai-chat-wp"),
        "selectOptions" => __("Select Options", "ai-chat-wp"),
        "addingToCart" => __("Adding...", "ai-chat-wp"),
        "addedToCart" => __("Added!", "ai-chat-wp"),
        "cartErrorAdd" => __("Could not add to cart.", "ai-chat-wp"),
        "shoppingCart" => __("Shopping Cart", "ai-chat-wp"),
        "cartEmpty" => __("Your cart is empty.", "ai-chat-wp"),
        "cartSubtotal" => __("Subtotal", "ai-chat-wp"),
        "viewCart" => __("View Cart", "ai-chat-wp"),
        "checkout" => __("Checkout", "ai-chat-wp"),
    ];
}

/**
 * Get chat JavaScript configuration (shared by shortcode and floating widget)
 * Centralizes all config to avoid duplication and eliminate /chat-config API call
 *
 * @return array Configuration array for wp_localize_script
 */
function aicwp_get_chat_js_config()
{
    // Get widget settings
    $chat_name = get_option(
        "aicwp_chat_name",
        __("AI Assistant", "ai-chat-wp")
    );
    $welcome_message = get_option(
        "aicwp_chat_welcome_message",
        __("Hello! How can I help you today?", "ai-chat-wp")
    );

    // Get chat avatar URL
    $chat_avatar_id = intval(get_option("aicwp_chat_avatar", 0));
    $chat_avatar_url = $chat_avatar_id
        ? wp_get_attachment_image_url($chat_avatar_id, "thumbnail")
        : "";

    $chat_config = [];
    if (class_exists("AICWP_Chat_API")) {
        $chat_config = AICWP_Chat_API::get_chat_config();
    }

    // Expose model to frontend only in debug mode (for plugin tester)
    $debug_mode = (bool) get_option("aicwp_debug_mode", false);
    if ($debug_mode) {
        $chat_config["model"] = get_option("aicwp_chat_model", "gpt-5.4-mini");
    }

    $config = [
        // API settings
        "apiBase" => esc_url(rest_url("aicwp/v1")),
        "nonce" => wp_create_nonce("wp_rest"),
        "isLoggedIn" => is_user_logged_in(),

        // Debug mode
        "debugMode" => $debug_mode,

        // UI settings
        "placeholderImage" => "",
        "chatName" => esc_html($chat_name),
        "chatAvatarUrl" => esc_url($chat_avatar_url),
        "hideImages" => get_option("aicwp_chat_hide_images", 1),
        "loadingStyle" => get_option("aicwp_chat_loading_style", "spinner"),
        "typingAnimation" => (bool) get_option("aicwp_chat_typing_animation", 1),
        "hasImageHeader" => in_array(get_option("aicwp_floating_header_style", "simple"), ["image", "animated"]),
        "hasImageHeaderOverlay" => (get_option("aicwp_floating_header_style", "simple") === "animated")
            || (get_option("aicwp_floating_header_style", "simple") === "image"
            && (bool) get_option("aicwp_floating_header_overlay", 0)),
        "hasAnimatedHeader" => (get_option("aicwp_floating_header_style", "simple") === "animated"),
        "animatedBgColor" => sanitize_hex_color(get_option("aicwp_animated_bg_color", "#1560d0")) ?: "#1560d0",

        // WooCommerce cart in chatbot
        "wooCartEnabled" => class_exists('WooCommerce') && (bool) get_option('aicwp_chat_woo_cart_enabled', 0),
        "ajaxUrl" => (class_exists('WooCommerce') && get_option('aicwp_chat_woo_cart_enabled', 0))
            ? admin_url('admin-ajax.php') : '',
        "cartNonce" => (class_exists('WooCommerce') && get_option('aicwp_chat_woo_cart_enabled', 0))
            ? wp_create_nonce('aicwp_cart_nonce') : '',
        "cartUrl" => (class_exists('WooCommerce') && get_option('aicwp_chat_woo_cart_enabled', 0))
            ? esc_url(wc_get_cart_url()) : '',
        "checkoutUrl" => (class_exists('WooCommerce') && get_option('aicwp_chat_woo_cart_enabled', 0))
            ? esc_url(wc_get_checkout_url()) : '',
        "cartCount" => (class_exists('WooCommerce') && get_option('aicwp_chat_woo_cart_enabled', 0) && WC()->cart)
            ? WC()->cart->get_cart_contents_count() : 0,

        // Quick buttons visibility
        "quickButtonsVisibility" => get_option("aicwp_chat_quick_buttons_visibility", "always"),

        // Context length multiplier for JS-side history trimming
        "contextLength" => get_option("aicwp_chat_context_length", "normal"),

        // Rate limits
        "rateLimits" => [
            "tier1" => intval(get_option("aicwp_chat_rate_limit_tier1", 10)),
            "tier2" => intval(get_option("aicwp_chat_rate_limit_tier2", 30)),
            "tier3" => intval(get_option("aicwp_chat_rate_limit_tier3", 100)),
        ],

        // Localized strings
        "strings" => aicwp_get_chat_strings($welcome_message),

        // Minimal frontend UI flags - everything else is server-side
        "chatConfig" => $chat_config,
    ];

    // Allow Pro plugin to add extra config (e.g., pre-chat fields)
    return apply_filters('aicwp_chat_js_config', $config);
}

/**
 * Localize chat config onto a script handle.
 * Guarded so it only outputs once per page regardless of how many
 * components (floating widget, shortcode) enqueue it.
 */
function aicwp_localize_chat_config($handle)
{
    static $localized = false;
    if ($localized) {
        return;
    }
    wp_localize_script($handle, 'aicwpChatConfig', aicwp_get_chat_js_config());
    $localized = true;
}

/**
 * WooCommerce Cart AJAX Handlers for Chatbot
 * Registered unconditionally — WooCommerce availability checked inside each handler
 */
add_action('wp_ajax_aicwp_add_to_cart', 'aicwp_handle_add_to_cart');
add_action('wp_ajax_nopriv_aicwp_add_to_cart', 'aicwp_handle_add_to_cart');

add_action('wp_ajax_aicwp_get_cart', 'aicwp_handle_get_cart');
add_action('wp_ajax_nopriv_aicwp_get_cart', 'aicwp_handle_get_cart');

add_action('wp_ajax_aicwp_remove_cart_item', 'aicwp_handle_remove_cart_item');
add_action('wp_ajax_nopriv_aicwp_remove_cart_item', 'aicwp_handle_remove_cart_item');

add_action('wp_ajax_aicwp_update_cart_qty', 'aicwp_handle_update_cart_qty');
add_action('wp_ajax_nopriv_aicwp_update_cart_qty', 'aicwp_handle_update_cart_qty');

add_action('wp_ajax_aicwp_log_cart_event', 'aicwp_handle_log_cart_event');
add_action('wp_ajax_nopriv_aicwp_log_cart_event', 'aicwp_handle_log_cart_event');

function aicwp_handle_add_to_cart() {
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(array('message' => __('WooCommerce not available.', 'ai-chat-wp')));
        return;
    }
    check_ajax_referer('aicwp_cart_nonce', 'nonce');

    $product_id = intval($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? max(1, min(100, intval($_POST['quantity']))) : 1;

    if ($product_id <= 0) {
        wp_send_json_error(array('message' => __('Invalid product.', 'ai-chat-wp')));
        return;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error(array('message' => __('Product not found.', 'ai-chat-wp')));
        return;
    }

    // Clear any existing WC notices before adding
    wc_clear_notices();

    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
    if ($cart_item_key) {
        wp_send_json_success(array(
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_subtotal' => WC()->cart->get_cart_subtotal(),
        ));
    } else {
        // Check if this is the product owner trying to buy their own product
        $post_author = get_post_field('post_author', $product_id);
        if ($post_author && (int) $post_author === get_current_user_id()) {
            wc_clear_notices();
            wp_send_json_error(array('message' => __('You cannot purchase your own product.', 'ai-chat-wp')));
            return;
        }

        // Get WooCommerce error notices for debugging
        $notices = wc_get_notices('error');
        $error_msg = !empty($notices) ? wp_strip_all_tags($notices[0]['notice'] ?? $notices[0]) : __('Could not add to cart.', 'ai-chat-wp');
        wc_clear_notices();
        wp_send_json_error(array('message' => $error_msg));
    }
}

function aicwp_handle_get_cart() {
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(array('message' => __('WooCommerce not available.', 'ai-chat-wp')));
        return;
    }
    check_ajax_referer('aicwp_cart_nonce', 'nonce');

    $items = array();
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $product = $cart_item['data'];
        $thumbnail = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
        if (!$thumbnail) {
            $thumbnail = wc_placeholder_img_src('thumbnail');
        }

        $items[] = array(
            'key'       => $cart_item_key,
            'product_id'=> $cart_item['product_id'],
            'title'     => $product->get_name(),
            'price'     => wc_price($product->get_price()),
            'quantity'  => $cart_item['quantity'],
            'thumbnail' => $thumbnail,
            'url'       => get_permalink($cart_item['product_id']),
        );
    }

    wp_send_json_success(array(
        'items'     => $items,
        'count'     => WC()->cart->get_cart_contents_count(),
        'subtotal'  => WC()->cart->get_cart_subtotal(),
    ));
}

function aicwp_handle_remove_cart_item() {
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(array('message' => __('WooCommerce not available.', 'ai-chat-wp')));
        return;
    }
    check_ajax_referer('aicwp_cart_nonce', 'nonce');

    $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
    if (empty($cart_item_key)) {
        wp_send_json_error(array('message' => __('Invalid cart item.', 'ai-chat-wp')));
        return;
    }

    if (WC()->cart->remove_cart_item($cart_item_key)) {
        wp_send_json_success(array(
            'count'    => WC()->cart->get_cart_contents_count(),
            'subtotal' => WC()->cart->get_cart_subtotal(),
        ));
    } else {
        wp_send_json_error(array('message' => __('Could not remove item.', 'ai-chat-wp')));
    }
}

function aicwp_handle_update_cart_qty() {
    if (!function_exists('WC') || !WC()->cart) {
        wp_send_json_error(array('message' => __('WooCommerce not available.', 'ai-chat-wp')));
        return;
    }
    check_ajax_referer('aicwp_cart_nonce', 'nonce');

    $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
    if (empty($cart_item_key)) {
        wp_send_json_error(array('message' => __('Invalid cart item.', 'ai-chat-wp')));
        return;
    }

    $quantity = intval($_POST['quantity']);

    if ($quantity <= 0) {
        $result = WC()->cart->remove_cart_item($cart_item_key);
    } else {
        $result = WC()->cart->set_quantity($cart_item_key, min(100, $quantity));
    }

    if ($result) {
        wp_send_json_success(array(
            'count'    => WC()->cart->get_cart_contents_count(),
            'subtotal' => WC()->cart->get_cart_subtotal(),
        ));
    } else {
        wp_send_json_error(array('message' => __('Could not update cart.', 'ai-chat-wp')));
    }
}

function aicwp_handle_log_cart_event() {
    if (!get_option('aicwp_chat_woo_cart_enabled', 0)) {
        wp_send_json_error();
        return;
    }
    check_ajax_referer('aicwp_cart_nonce', 'nonce');

    $conversation_id = substr(sanitize_text_field($_POST['conversation_id'] ?? ''), 0, 64);
    $product_id = intval($_POST['product_id'] ?? 0);
    $product_name = sanitize_text_field($_POST['product_name'] ?? '');
    $quantity = max(1, intval($_POST['quantity'] ?? 1));

    if (empty($conversation_id) || empty($product_id)) {
        wp_send_json_error();
        return;
    }

    // Resolve product name from ID if not provided
    if (empty($product_name) && function_exists('wc_get_product')) {
        $product = wc_get_product($product_id);
        if ($product) {
            $product_name = $product->get_name();
        }
    }

    $events = get_option('aicwp_cart_events', array());
    $is_new_conversation = !isset($events[$conversation_id]);

    // Rate limit: max 1,000 new conversation keys per 24h (transient counter)
    if ($is_new_conversation) {
        $rate_key = 'aicwp_cart_events_daily';
        $daily_count = (int) get_transient($rate_key);
        if ($daily_count >= 1000) {
            wp_send_json_error();
            return;
        }
        set_transient($rate_key, $daily_count + 1, DAY_IN_SECONDS);
        $events[$conversation_id] = array();
    }

    $events[$conversation_id][] = array(
        'product_id'   => $product_id,
        'product_name' => $product_name,
        'quantity'      => $quantity,
        'timestamp'     => current_time('mysql'),
    );

    // Cap per-conversation events to 50 (keep latest)
    if (count($events[$conversation_id]) > 50) {
        $events[$conversation_id] = array_slice($events[$conversation_id], -50);
    }

    // Prune old events occasionally (1 in 20 requests) to avoid looping every time
    if (wp_rand(1, 20) === 1) {
        $retention_days = get_option('aicwp_chat_retention_days', 30);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
        foreach ($events as $cid => $conv_events) {
            $events[$cid] = array_filter($conv_events, function ($e) use ($cutoff) {
                return $e['timestamp'] >= $cutoff;
            });
            if (empty($events[$cid])) {
                unset($events[$cid]);
            }
        }
    }

    update_option('aicwp_cart_events', $events, false);
    wp_send_json_success();
}

// Initialize plugin
AICWP_Plugin::get_instance();

// Feature modules (PDFs, WooCommerce, webhooks, messaging, ...). Priority 4 so their
// hooks are in place before the core fires 'aicwp_woocommerce_integration' at priority 20.
require_once AICWP_PLUGIN_PATH . "includes/class-feature-loader.php";
add_action("plugins_loaded", ["AICWP_Feature_Loader", "get_instance"], 4);

// One-time copy of data stored under the plugin's previous names. Runs on
// activation; admin_init is a fallback for sites where activation was skipped.
require_once AICWP_PLUGIN_PATH . "includes/class-legacy-migration.php";
add_action("admin_init", ["AICWP_Legacy_Migration", "maybe_run"]);

/**
 * Chat history cleanup cron job
 * Runs weekly to delete old chat records based on retention setting
 */
add_action("aicwp_cleanup_chat_history", function () {
    if (!class_exists("AICWP_Chat_History")) {
        return;
    }

    // Get retention days from settings (default: 30 days)
    $retention_days = get_option("aicwp_chat_retention_days", 30);

    // Run cleanup
    $deleted = AICWP_Chat_History::cleanup_old_records(
        $retention_days,
    );

    // Log if debug mode enabled
    if (
        get_option("aicwp_debug_mode", false) &&
        $deleted !== false
    ) {
        AICWP_Plugin::debug_log(
            "Chat history cleanup: Deleted {$deleted} records older than {$retention_days} days",
            "info",
        );
    }

});

/**
 * Contact messages cleanup cron job
 * Runs weekly to delete old contact messages (older than 1 year)
 */
add_action("aicwp_cleanup_contact_messages", function () {
    if (!class_exists("AICWP_Contact_Messages")) {
        return;
    }

    // Delete messages older than 365 days
    $deleted = AICWP_Contact_Messages::cleanup_old_messages(365);

    // Log if debug mode enabled
    if (
        get_option("aicwp_debug_mode", false) &&
        $deleted !== false
    ) {
        AICWP_Plugin::debug_log(
            "Contact messages cleanup: Deleted {$deleted} records older than 365 days",
            "info",
        );
    }
});
