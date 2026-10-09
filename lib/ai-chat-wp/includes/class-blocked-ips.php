<?php
/**
 * AI Chat for WordPress - IP Address Blocking
 *
 * Blocks the chat widget from displaying for specified IP addresses.
 * This feature is exclusive to Pro version.
 *
 * @package AICWP
 * @since 1.7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AICWP_Blocked_IPs {

    /**
     * Constructor
     */
    public function __construct() {
        // Hook into the Access & Privacy section to render IP blocking fields (PRO only)
        add_action('aicwp_chat_blocked_ips_fields', array($this, 'render_blocked_ips_fields'));

        // Hook into the chat widget visibility check
        add_filter('aicwp_chat_should_block_ip', array($this, 'should_block_current_ip'));

        // Register setting
        add_filter('aicwp_settings_registry', array($this, 'register_setting'));

        // Sanitize setting
        add_filter('aicwp_sanitize_setting', array($this, 'sanitize_blocked_ips'), 10, 3);

        // Add to hidden fields exception list
        add_filter('aicwp_hidden_fields_except', array($this, 'add_to_hidden_fields_except'));
    }

    /**
     * Register the blocked IPs setting
     *
     * @param array $registry Settings registry
     * @return array Modified registry
     */
    public function register_setting($registry) {
        $registry['aicwp_chat_blocked_ips'] = array(
            'type' => 'array',
            'section' => 'ai-chat-config',
            'sanitize' => 'sanitize_blocked_ips',
            'default' => array(),
            'description' => 'IP addresses blocked from using the chat widget'
        );
        return $registry;
    }

    /**
     * Sanitize blocked IPs array
     *
     * @param mixed $value Sanitized value (passed through)
     * @param string $key Setting key
     * @param mixed $raw_value Raw value from POST
     * @return mixed Sanitized value
     */
    public function sanitize_blocked_ips($value, $key, $raw_value) {
        if ($key !== 'aicwp_chat_blocked_ips') {
            return $value;
        }

        if (!is_array($raw_value)) {
            return array();
        }

        $sanitized = array();
        foreach ($raw_value as $ip_entry) {
            if (!empty($ip_entry['ip'])) {
                $ip = sanitize_text_field(trim($ip_entry['ip']));
                // Validate IP address format (IPv4 or IPv6, with optional CIDR)
                if ($this->is_valid_ip_or_range($ip)) {
                    $sanitized[] = array(
                        'ip' => $ip
                    );
                }
            }
        }
        return $sanitized;
    }

    /**
     * Validate IP address or CIDR range
     *
     * @param string $ip IP address or CIDR range
     * @return bool True if valid
     */
    private function is_valid_ip_or_range($ip) {
        // Check if it's a CIDR range
        if (strpos($ip, '/') !== false) {
            list($ip_part, $cidr) = explode('/', $ip, 2);
            // Validate the IP part
            if (!filter_var($ip_part, FILTER_VALIDATE_IP)) {
                return false;
            }
            // Validate CIDR (0-32 for IPv4, 0-128 for IPv6)
            $cidr = intval($cidr);
            $is_ipv4 = filter_var($ip_part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            $max_cidr = $is_ipv4 ? 32 : 128;
            return $cidr >= 0 && $cidr <= $max_cidr;
        }

        // Simple IP address validation
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Add to hidden fields exception list
     *
     * @param array $fields Fields to exclude from hidden fields
     * @return array Modified fields array
     */
    public function add_to_hidden_fields_except($fields) {
        $fields[] = 'aicwp_chat_blocked_ips';
        return $fields;
    }

    /**
     * Check if current visitor's IP should be blocked
     *
     * @param bool $should_block Current block status
     * @return bool True if IP should be blocked
     */
    public function should_block_current_ip($should_block) {
        // If already blocked by another filter, respect that
        if ($should_block) {
            return true;
        }

        $blocked_ips = get_option('aicwp_chat_blocked_ips', array());
        if (empty($blocked_ips) || !is_array($blocked_ips)) {
            return false;
        }

        if (!class_exists('AICWP_Utility_Helper')) {
            return false;
        }
        $visitor_ip = AICWP_Utility_Helper::get_client_ip_secure();
        if (empty($visitor_ip)) {
            return false;
        }

        foreach ($blocked_ips as $entry) {
            if (!empty($entry['ip'])) {
                if ($this->ip_matches($visitor_ip, $entry['ip'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if visitor IP matches a blocked IP or range
     *
     * @param string $visitor_ip Visitor's IP
     * @param string $blocked_ip Blocked IP or CIDR range
     * @return bool True if matches
     */
    private function ip_matches($visitor_ip, $blocked_ip) {
        // CIDR range match
        if (strpos($blocked_ip, '/') !== false) {
            return $this->ip_in_cidr($visitor_ip, $blocked_ip);
        }

        // Exact match - normalize both IPs for comparison
        $visitor_normalized = $this->normalize_ip($visitor_ip);
        $blocked_normalized = $this->normalize_ip($blocked_ip);

        if ($visitor_normalized === false || $blocked_normalized === false) {
            // Fallback to string comparison if normalization fails
            return $visitor_ip === $blocked_ip;
        }

        return $visitor_normalized === $blocked_normalized;
    }

    /**
     * Normalize IP address for consistent comparison
     * Converts IPv6 to full expanded format, IPv4 stays as-is
     *
     * @param string $ip IP address
     * @return string|false Normalized IP or false on failure
     */
    private function normalize_ip($ip) {
        // Validate IP first
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // IPv4 - return as-is
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }

        // IPv6 - convert to binary and back to get consistent format
        $binary = inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        // Convert to full expanded IPv6 format
        $hex = bin2hex($binary);
        return implode(':', str_split($hex, 4));
    }

    /**
     * Check if IP is within a CIDR range
     *
     * @param string $ip IP address to check
     * @param string $cidr CIDR range (e.g., 192.168.1.0/24)
     * @return bool True if IP is in range
     */
    private function ip_in_cidr($ip, $cidr) {
        list($range, $bits) = explode('/', $cidr, 2);
        $bits = intval($bits);

        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
            filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip_long = ip2long($ip);
            $range_long = ip2long($range);
            $mask = -1 << (32 - $bits);
            return ($ip_long & $mask) === ($range_long & $mask);
        }

        // IPv6 - simplified check
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
            filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ip_bin = inet_pton($ip);
            $range_bin = inet_pton($range);
            if ($ip_bin === false || $range_bin === false) {
                return false;
            }
            // Compare prefix bits
            $bytes = intval($bits / 8);
            $remaining_bits = $bits % 8;
            // Compare full bytes
            for ($i = 0; $i < $bytes; $i++) {
                if ($ip_bin[$i] !== $range_bin[$i]) {
                    return false;
                }
            }
            // Compare remaining bits
            if ($remaining_bits > 0 && $bytes < 16) {
                $mask = 0xFF << (8 - $remaining_bits);
                if ((ord($ip_bin[$bytes]) & $mask) !== (ord($range_bin[$bytes]) & $mask)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Render the blocked IPs input fields (called via hook from free plugin)
     * Only renders when PRO license is valid
     */
    public function render_blocked_ips_fields() {
        $blocked_ips = get_option('aicwp_chat_blocked_ips', array());
        if (empty($blocked_ips)) {
            $blocked_ips = array(array('ip' => ''));
        }
        ?>
        <div id="aicwp-blocked-ips-container">
            <?php foreach ($blocked_ips as $index => $entry) :
                $ip_value = isset($entry['ip']) ? $entry['ip'] : '';
            ?>
            <div class="aicwp-blocked-ip-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                <input type="text"
                       name="aicwp_chat_blocked_ips[<?php echo $index; ?>][ip]"
                       value="<?php echo esc_attr($ip_value); ?>"
                       placeholder="<?php esc_attr_e('e.g., 192.168.1.100 or 10.0.0.0/8', 'ai-chat-wp'); ?>"
                       class="aicwp-ui-input aicwp-blocked-ip-input"
                       style="flex: 1; max-width: 300px;" />
                <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-remove-blocked-ip" title="<?php esc_attr_e('Remove', 'ai-chat-wp'); ?>">
                    <span class="remove-icon">&times;</span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="button" id="aicwp-add-blocked-ip" class="aicwp-ui-button aicwp-ui-button-secondary">
            <?php _e('+ Add IP Address', 'ai-chat-wp'); ?>
        </button>

        <script>
        jQuery(document).ready(function($) {
            // Add new IP row
            $('#aicwp-add-blocked-ip').on('click', function() {
                var container = $('#aicwp-blocked-ips-container');
                var index = container.find('.aicwp-blocked-ip-row').length;
                var row = `
                    <div class="aicwp-blocked-ip-row" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                        <input type="text"
                               name="aicwp_chat_blocked_ips[${index}][ip]"
                               value=""
                               placeholder="<?php echo esc_js(__('e.g., 192.168.1.100 or 10.0.0.0/8', 'ai-chat-wp')); ?>"
                               class="aicwp-ui-input aicwp-blocked-ip-input"
                               style="flex: 1; max-width: 300px;" />
                        <button type="button" class="aicwp-ui-button aicwp-ui-button-secondary aicwp-remove-blocked-ip" title="<?php echo esc_js(__('Remove', 'ai-chat-wp')); ?>">
                            <span class="remove-icon">&times;</span>
                        </button>
                    </div>
                `;
                container.append(row);
            });

            // Remove IP row
            $(document).on('click', '.aicwp-remove-blocked-ip', function() {
                var container = $('#aicwp-blocked-ips-container');
                if (container.find('.aicwp-blocked-ip-row').length > 1) {
                    $(this).closest('.aicwp-blocked-ip-row').remove();
                    // Reindex remaining rows
                    container.find('.aicwp-blocked-ip-row').each(function(i) {
                        $(this).find('input').attr('name', 'aicwp_chat_blocked_ips[' + i + '][ip]');
                    });
                } else {
                    // Clear the input instead of removing last row
                    $(this).closest('.aicwp-blocked-ip-row').find('input').val('');
                }
            });
        });
        </script>
        <?php
    }
}
