<?php
/**
 * Feature loader
 *
 * Loads the feature modules that used to ship as the separate "Pro" add-on.
 * Class names keep their historical AICWP_* prefix.
 *
 * @package AICWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Feature_Loader {

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
        $this->load_dependencies();

        add_action('plugins_loaded', array($this, 'init_admin'), 20);
    }

    /**
     * Load feature modules
     */
    private function load_dependencies() {
        // PDF Management
        require_once AICWP_PLUGIN_PATH . 'includes/class-pdf-manager.php';
        require_once AICWP_PLUGIN_PATH . 'includes/class-pdf-admin-ui.php';

        // Quick Action Buttons
        require_once AICWP_PLUGIN_PATH . 'includes/class-quick-buttons.php';
        new AICWP_Quick_Buttons();

        // IP Address Blocking
        require_once AICWP_PLUGIN_PATH . 'includes/class-blocked-ips.php';
        new AICWP_Blocked_IPs();

        // Pre-Chat Required Fields
        require_once AICWP_PLUGIN_PATH . 'includes/class-pre-chat-fields.php';
        new AICWP_Pre_Chat_Fields();

        // Contact Tool for AI
        require_once AICWP_PLUGIN_PATH . 'includes/class-contact-tool.php';
        new AICWP_Contact_Tool();

        // Webhook Tool for AI
        require_once AICWP_PLUGIN_PATH . 'includes/class-webhook-tool.php';
        new AICWP_Webhook_Tool();

        // Webhook Admin UI (checkbox, modal, settings — injected via hooks)
        require_once AICWP_PLUGIN_PATH . 'includes/class-webhook-admin.php';

        // Chat History Data Provider
        require_once AICWP_PLUGIN_PATH . 'includes/class-chat-history-data.php';
        new AICWP_Chat_History_Data();

        // Chat History Chart
        require_once AICWP_PLUGIN_PATH . 'includes/class-chat-history-chart.php';
        new AICWP_Chat_History_Chart();

        // Conversation Auditor
        require_once AICWP_PLUGIN_PATH . 'includes/class-conversation-auditor.php';
        AICWP_Conversation_Auditor::get_instance();

        // Content Extractors for Page/Product
        require_once AICWP_PLUGIN_PATH . 'includes/class-content-extractors.php';
        new AICWP_Content_Extractors();

        // Speech-to-Text
        require_once AICWP_PLUGIN_PATH . 'includes/class-speech-to-text.php';
        new AICWP_Speech_To_Text();

        // External Pages
        require_once AICWP_PLUGIN_PATH . 'includes/external-pages/class-external-pages.php';
        AICWP_External_Pages::get_instance();

        // WooCommerce Integration
        add_action('aicwp_woocommerce_integration', array($this, 'init_woocommerce_integration'));

        // Cart popup overlay
        add_action('aicwp_chat_cart_popup', function () {
            include AICWP_PLUGIN_PATH . 'includes/cart-popup-template.php';
        });

        // Messaging Channels (WhatsApp, Telegram)
        require_once AICWP_PLUGIN_PATH . 'includes/messaging/class-messaging-migrations.php';
        require_once AICWP_PLUGIN_PATH . 'includes/messaging/class-messaging-channel.php';
        require_once AICWP_PLUGIN_PATH . 'includes/messaging/class-whatsapp-handler.php';
        require_once AICWP_PLUGIN_PATH . 'includes/messaging/class-whatsapp-admin.php';
        require_once AICWP_PLUGIN_PATH . 'includes/messaging/class-telegram-handler.php';
        require_once AICWP_PLUGIN_PATH . 'includes/messaging/class-telegram-admin.php';
        AICWP_Messaging_Migrations::init();
        new AICWP_WhatsApp_Handler();
        new AICWP_Telegram_Handler();
    }

    /**
     * Admin-only modules
     */
    public function init_admin() {
        if (is_admin()) {
            new AICWP_Webhook_Admin();
            new AICWP_WhatsApp_Admin();
            new AICWP_Telegram_Admin();
        }
    }

    /**
     * Initialize WooCommerce integration
     * Called via 'aicwp_woocommerce_integration' action
     */
    public function init_woocommerce_integration() {
        require_once AICWP_PLUGIN_PATH . 'includes/integrations/class-woocommerce-integration.php';
        new AICWP_WooCommerce_Integration();
    }
}
