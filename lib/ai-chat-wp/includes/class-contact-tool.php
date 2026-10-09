<?php
/**
 * AI Chat for WordPress - Contact Tool for AI
 *
 * Adds the send_contact_message tool that allows AI to send
 * contact messages on behalf of users.
 * This feature is exclusive to Pro version.
 *
 * @package AICWP
 * @since 1.7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Contact_Tool {

    /**
     * Constructor
     */
    public function __construct() {
        // Add contact tool to the list of AI tools
        add_filter('aicwp_chat_tools', array($this, 'add_contact_tool'));

        // Add contact tool instructions to system prompt
        add_filter('aicwp_chat_system_prompt_contact_tool', array($this, 'add_contact_instructions'), 10, 2);
    }

    /**
     * Add send_contact_message tool to AI tools array
     *
     * @param array $tools Existing tools array
     * @return array Modified tools array
     */
    public function add_contact_tool($tools) {
        // Check if contact tool is enabled in admin
        $ai_contact_enabled = get_option('aicwp_contact_form_allow_ai_send', 0);
        if (!$ai_contact_enabled) {
            return $tools;
        }

        // Add the contact tool
        $tools[] = array(
            'type' => 'function',
            'function' => array(
                'name' => 'send_contact_message',
                'description' => 'Send a contact message to the website administrators. Use this ONLY when the user explicitly asks you to send a message or contact the site on their behalf. You MUST collect the user\'s name, email, and message content before calling this function. ALWAYS ask for confirmation before sending.',
                'parameters' => array(
                    'type' => 'object',
                    'properties' => array(
                        'name' => array(
                            'type' => 'string',
                            'description' => 'The sender\'s full name. Must be provided by the user - never assume or fabricate.'
                        ),
                        'email' => array(
                            'type' => 'string',
                            'description' => 'The sender\'s email address. Must be a valid email provided by the user - never assume or fabricate.'
                        ),
                        'message' => array(
                            'type' => 'string',
                            'description' => 'The message content to send. Should be the actual message the user wants to send.'
                        )
                    ),
                    'required' => array('name', 'email', 'message')
                )
            )
        );

        return $tools;
    }

    /**
     * Add contact tool instructions to system prompt
     *
     * @param string $prompt Current system prompt
     * @param bool $include_tools Whether tools are included
     * @return string Modified prompt
     */
    public function add_contact_instructions($prompt, $include_tools) {
        // Only add instructions if tools are included
        if (!$include_tools) {
            return $prompt;
        }

        // Check if contact tool is enabled
        $ai_contact_enabled = get_option('aicwp_contact_form_allow_ai_send', 0);
        if (!$ai_contact_enabled) {
            return $prompt;
        }

        // Get customizable examples from settings
        $default_examples = "EXAMPLES OF WHEN TO USE:\n- \"Can you send a message to the site owner for me?\"\n- \"I want to contact support about X\"\n- \"Please send them my inquiry about Y\"\n\nEXAMPLES OF WHEN NOT TO USE:\n- \"How can I contact you?\" (just provide contact info)\n- \"What's your email?\" (just provide info, don't send)";
        $contact_examples = get_option('aicwp_contact_form_examples', $default_examples);

        // Add contact tool instructions
        $prompt .= "
========================================
CONTACT FORM TOOL (send_contact_message):
========================================
CRITICAL RULE - HIGHEST PRIORITY:
DO NOT use send_contact_message UNLESS user EXPLICITLY says words like:
- \"send a message for me\"
- \"contact them for me\"
- \"send my inquiry\"
- \"email them on my behalf\"
========================================

If user asks \"how to contact\" or \"what's your email\" → just ANSWER with contact info, DO NOT send anything.

REQUIRED BEFORE CALLING send_contact_message (ALL must be true):
1. User EXPLICITLY requested you to send a message on their behalf
2. You collected their NAME (asked if not provided)
3. You collected their EMAIL (asked if not provided)
4. You collected MESSAGE content (asked if not provided)
5. You asked \"Should I send this now?\" and user confirmed YES

NEVER call send_contact_message:
- For general questions about the site
- When user just asks about contact information
- Without explicit user confirmation
- Without ALL three fields (name, email, message)

{$contact_examples}

";

        return $prompt;
    }

}
