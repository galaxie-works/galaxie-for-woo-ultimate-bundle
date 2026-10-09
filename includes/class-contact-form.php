<?php
/**
 * AI Chat Contact Form Handler
 *
 * Handles contact form submissions from chat widget
 * Separate class for potential future LLM tool integration
 *
 * @package AICWP_Plugin
 * @since 1.5.9
 */

if (!defined('ABSPATH')) exit;

class AICWP_Contact_Form {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('aicwp/v1', '/contact-form', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_submission'),
            'permission_callback' => '__return_true', // Public: 5 submissions/IP/hour rate limit enforced in callback
            'args' => array(
                'name' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'email' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ),
                'message' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'source' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => 'button',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($value) {
                        return in_array($value, array('button', 'llm'), true);
                    },
                ),
                'conversation_id' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * Handle form submission
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error
     */
    public function handle_submission($request) {
        $name = $request->get_param('name');
        $email = $request->get_param('email');
        $message = $request->get_param('message');
        $source = $request->get_param('source') ?: 'button';
        $conversation_id = $request->get_param('conversation_id') ?: null;

        // Validate source
        if (!in_array($source, array('button', 'llm'), true)) {
            $source = 'button';
        }

        // Only keep conversation_id for LLM source
        if ($source !== 'llm') {
            $conversation_id = null;
        }

        // Validate email
        if (!is_email($email)) {
            return new WP_Error(
                'invalid_email',
                __('Please provide a valid email address.', 'ai-chat-wp'),
                array('status' => 400)
            );
        }

        // Validate message length
        if (strlen($message) < 10) {
            return new WP_Error(
                'message_too_short',
                __('Message must be at least 10 characters.', 'ai-chat-wp'),
                array('status' => 400)
            );
        }

        // Rate limiting: max 5 submissions per IP per hour
        $rate_limit_check = $this->check_rate_limit();
        if (is_wp_error($rate_limit_check)) {
            return $rate_limit_check;
        }

        // Get recipient email
        $recipient = $this->get_recipient_email();

        // Prepare email subject with placeholders
        $subject = $this->prepare_subject($name, $email);

        $email_body = $this->prepare_email_body($name, $email, $message);
        $headers = $this->prepare_email_headers($name, $email);

        // Send email
        $sent = wp_mail($recipient, $subject, $email_body, $headers);

        // Log message to database (regardless of email success, for tracking)
        if (class_exists('AICWP_Contact_Messages')) {
            AICWP_Contact_Messages::log_message($name, $email, $message, $source, $sent, $conversation_id);
        }

        if (!$sent) {
            AICWP_Plugin::debug_log('Contact form email failed to send', 'error');
            return new WP_Error(
                'email_failed',
                __('Failed to send message. Please try again later.', 'ai-chat-wp'),
                array('status' => 500)
            );
        }

        // Log successful submission
        AICWP_Plugin::debug_log("Contact form submitted by {$email} via {$source}", 'info');

        // Record rate limit
        $this->record_submission();

        // Get configurable success message
        $success_message = get_option('aicwp_contact_form_success_message', '');
        if (empty($success_message)) {
            $success_message = __('Your message has been sent successfully!', 'ai-chat-wp');
        }

        return new WP_REST_Response(array(
            'success' => true,
            'message' => $success_message,
        ), 200);
    }

    /**
     * Prepare email subject with placeholders
     *
     * @param string $name Sender name
     * @param string $email Sender email
     * @return string
     */
    private function prepare_subject($name, $email) {
        $subject_template = get_option('aicwp_contact_form_subject', '');

        if (empty($subject_template)) {
            $subject_template = '[{site_name}] New message from {name}';
        }

        // Replace placeholders
        $subject = str_replace(
            array('{site_name}', '{name}', '{email}'),
            array(get_bloginfo('name'), $name, $email),
            $subject_template
        );

        return $subject;
    }

    /**
     * Get recipient email address
     *
     * @return string
     */
    private function get_recipient_email() {
        // Check for custom contact form recipient setting
        $recipient = get_option('aicwp_contact_form_recipient', '');

        // Fallback to admin email
        if (empty($recipient) || !is_email($recipient)) {
            $recipient = get_option('admin_email');
        }

        return $recipient;
    }

    /**
     * Prepare email body
     *
     * @param string $name Sender name
     * @param string $email Sender email
     * @param string $message Message content
     * @return string
     */
    private function prepare_email_body($name, $email, $message) {
        $body = sprintf(
            /* translators: Contact form email template */
            __("New message received via AI Chat:\n\n" .
            "Name: %1\$s\n" .
            "Email: %2\$s\n\n" .
            "Message:\n%3\$s\n\n" .
            "---\n" .
            "Sent from: %4\$s\n" .
            "Page: %5\$s", 'ai-chat-wp'),
            $name,
            $email,
            $message,
            get_bloginfo('name'),
            wp_get_referer() ?: home_url()
        );

        return $body;
    }

    /**
     * Prepare email headers
     *
     * @param string $name Sender name
     * @param string $email Sender email
     * @return array
     */
    private function prepare_email_headers($name, $email) {
        // Get configurable from settings
        $from_name = get_option('aicwp_contact_form_from_name', '');
        $from_email = get_option('aicwp_contact_form_from_email', '');

        // Fallback to defaults
        if (empty($from_name)) {
            $from_name = get_bloginfo('name');
        }
        if (empty($from_email) || !is_email($from_email)) {
            $from_email = get_option('admin_email');
        }

        return array(
            'Content-Type: text/plain; charset=UTF-8',
            sprintf('From: %s <%s>', $from_name, $from_email),
            sprintf('Reply-To: %s <%s>', $name, $email),
        );
    }

    /**
     * Check rate limit for submissions
     *
     * @return true|WP_Error
     */
    private function check_rate_limit() {
        $ip = AICWP_Utility_Helper::get_client_ip_secure();
        $transient_key = 'aicwp_contact_' . md5($ip);
        $submissions = get_transient($transient_key);

        if ($submissions !== false && $submissions >= 3) {
            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many submissions. Please try again later.', 'ai-chat-wp'),
                array('status' => 429)
            );
        }

        return true;
    }

    /**
     * Record submission for rate limiting
     */
    private function record_submission() {
        $ip = AICWP_Utility_Helper::get_client_ip_secure();
        $transient_key = 'aicwp_contact_' . md5($ip);
        $submissions = get_transient($transient_key);

        if ($submissions === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
        } else {
            // Increment count without resetting the TTL window
            $timeout_key = '_transient_timeout_' . $transient_key;
            $timeout = get_option($timeout_key);
            $remaining = $timeout ? max((int) $timeout - time(), 60) : HOUR_IN_SECONDS;
            set_transient($transient_key, (int) $submissions + 1, $remaining);
        }
    }

    /**
     * Submit contact form via LLM tool (for future integration)
     *
     * @param string $name Sender name
     * @param string $email Sender email
     * @param string $message Message content
     * @return array Result with success status and message
     */
    public static function submit_via_tool($name, $email, $message) {
        $instance = new self();

        // Validate inputs
        $name = sanitize_text_field($name);
        $email = sanitize_email($email);
        $message = sanitize_textarea_field($message);

        if (empty($name) || empty($email) || empty($message)) {
            return array(
                'success' => false,
                'message' => __('All fields are required.', 'ai-chat-wp'),
            );
        }

        if (!is_email($email)) {
            return array(
                'success' => false,
                'message' => __('Invalid email address.', 'ai-chat-wp'),
            );
        }

        // Send email using configurable settings
        $recipient = $instance->get_recipient_email();
        $subject = $instance->prepare_subject($name, $email);
        $email_body = $instance->prepare_email_body($name, $email, $message);
        $headers = $instance->prepare_email_headers($name, $email);

        $sent = wp_mail($recipient, $subject, $email_body, $headers);

        if ($sent) {
            // Get configurable success message
            $success_message = get_option('aicwp_contact_form_success_message', '');
            if (empty($success_message)) {
                $success_message = __('Message sent successfully.', 'ai-chat-wp');
            }

            return array(
                'success' => true,
                'message' => $success_message,
            );
        }

        return array(
            'success' => false,
            'message' => __('Failed to send message.', 'ai-chat-wp'),
        );
    }

}

// Initialize contact form handler
new AICWP_Contact_Form();
