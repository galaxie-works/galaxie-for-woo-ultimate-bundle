<?php
/**
 * AI Chat for WordPress - Quick Action Buttons
 *
 * Renders customizable quick action buttons in the chat widget.
 * This feature is exclusive to Pro version.
 *
 * @package AICWP
 * @since 1.7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Quick_Buttons {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into the chat widget to render quick buttons
        add_action('aicwp_chat_quick_buttons', array($this, 'render_quick_buttons'));

        // Hook to render contact form overlay (Pro feature)
        add_action('aicwp_chat_contact_form_overlay', array($this, 'render_contact_form_overlay'));
    }

    /**
     * Render quick action buttons
     * Hooked into 'aicwp_chat_quick_buttons' action
     */
    public function render_quick_buttons() {
        // Check if quick buttons are enabled
        $quick_buttons_enabled = get_option('aicwp_chat_quick_buttons_enabled', 1);
        if (!$quick_buttons_enabled) {
            return;
        }

        // Get quick buttons from settings
        $quick_buttons = get_option('aicwp_chat_quick_buttons', array());
        if (empty($quick_buttons)) {
            return;
        }

        // Filter out buttons with empty text
        $valid_buttons = array_filter($quick_buttons, function($btn) {
            return !empty($btn['text']);
        });

        if (empty($valid_buttons)) {
            return;
        }

        // Check if we should show the toggle button (only when hide_after_first is enabled)
        $visibility_mode = get_option('aicwp_chat_quick_buttons_visibility', 'always');

        // Render the buttons
        ?>
        <div class="aicwp-chat-quick-buttons">
            <?php foreach ($valid_buttons as $button):
                $btn_type = isset($button['type']) ? $button['type'] : 'chat';
                $btn_value = isset($button['value']) ? $button['value'] : '';
                $has_custom_color = !empty($button['color']);
                $btn_style = '';
                if ($has_custom_color) {
                    $btn_color = $button['color'];
                    $btn_color_light = 'rgba(' . implode(', ', array_map('hexdec', str_split(ltrim($btn_color, '#'), 2))) . ', 0.1)';
                    $btn_style = '--quick-btn-color: ' . esc_attr($btn_color) . '; --quick-btn-color-light: ' . esc_attr($btn_color_light);
                }
            ?>
            <div class="aicwp-quick-btn<?php echo $has_custom_color ? ' has-custom-color' : ''; ?>"
                 data-type="<?php echo esc_attr($btn_type); ?>"
                 data-value="<?php echo esc_attr($btn_value); ?>"
                 <?php echo $btn_style ? 'style="' . $btn_style . '"' : ''; ?>>
                <span><?php echo esc_html($button['text']); ?></span>
                <?php if ($btn_type === 'url'): ?>
                <svg class="aicwp-btn-icon" style="margin-left:-3px" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="19" x2="19" y2="5"/><polyline points="9 5 19 5 19 15"/></svg>
                <?php elseif ($btn_type === 'contact'): ?>
                <svg class="aicwp-btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render contact form overlay
     * Only shown when Pro is active and quick buttons are enabled
     */
    public function render_contact_form_overlay() {
        // Check if quick buttons are enabled
        $quick_buttons_enabled = get_option('aicwp_chat_quick_buttons_enabled', 1);
        if (!$quick_buttons_enabled) {
            return;
        }

        // Check if any contact button exists in quick buttons
        $quick_buttons = get_option('aicwp_chat_quick_buttons', array());
        $has_contact_button = false;
        foreach ($quick_buttons as $button) {
            if (!empty($button['text']) && isset($button['type']) && $button['type'] === 'contact') {
                $has_contact_button = true;
                break;
            }
        }

        // Only render if there's a contact button configured
        if (!$has_contact_button) {
            return;
        }

        ?>
        <!-- Contact Form Overlay -->
        <div class="aicwp-contact-form-overlay" style="display: none;">
            <div class="aicwp-contact-form">
                <div class="aicwp-contact-form-header">
                    <h3><?php _e('Contact Us', 'ai-chat-wp'); ?></h3>
                    <div class="aicwp-contact-form-close" role="button" aria-label="<?php esc_attr_e('Close', 'ai-chat-wp'); ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </div>
                </div>
                <form class="aicwp-contact-form-body">
                    <div class="aicwp-contact-form-field">
                        <label for="aicwp-contact-name"><?php _e('Name', 'ai-chat-wp'); ?> <span class="required">*</span></label>
                        <input type="text" id="aicwp-contact-name" name="name" required />
                    </div>
                    <div class="aicwp-contact-form-field">
                        <label for="aicwp-contact-email"><?php _e('Email', 'ai-chat-wp'); ?> <span class="required">*</span></label>
                        <input type="email" id="aicwp-contact-email" name="email" required />
                    </div>
                    <div class="aicwp-contact-form-field">
                        <label for="aicwp-contact-message"><?php _e('Message', 'ai-chat-wp'); ?> <span class="required">*</span></label>
                        <textarea id="aicwp-contact-message" name="message" rows="4" required></textarea>
                    </div>
                    <div class="aicwp-contact-form-actions">
                        <button type="submit" class="aicwp-contact-form-submit">
                            <span class="button-text"><?php _e('Send Message', 'ai-chat-wp'); ?></span>
                            <span class="button-spinner" style="display: none;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12,1A11,11,0,1,0,23,12,11,11,0,0,0,12,1Zm0,19a8,8,0,1,1,8-8A8,8,0,0,1,12,20Z" opacity="0.25" fill="currentColor"/>
                                    <path d="M12,4a8,8,0,0,1,7.89,6.7A1.53,1.53,0,0,0,21.38,12h0a1.5,1.5,0,0,0,1.48-1.75,11,11,0,0,0-21.72,0A1.5,1.5,0,0,0,2.62,12h0a1.53,1.53,0,0,0,1.49-1.3A8,8,0,0,1,12,4Z" fill="currentColor">
                                        <animateTransform attributeName="transform" dur="0.75s" repeatCount="indefinite" type="rotate" values="0 12 12;360 12 12"/>
                                    </path>
                                </svg>
                            </span>
                        </button>
                    </div>
                    <div class="aicwp-contact-form-message" style="display: none;"></div>
                </form>
            </div>
        </div>
        <?php
    }

}
