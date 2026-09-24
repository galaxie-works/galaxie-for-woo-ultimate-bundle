<?php
/**
 * A kit change the rules refuse.
 *
 * @package Galaxie\Woo
 */

namespace Galaxie\Woo\Modules\GiftWrap\Kit;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine reason (`no_draft`, `no_room`, …) for the popup and the
 * shopper's sentence, plus whatever the popup needs to act on it (how many
 * still fit, which kit is open).
 */
final class KitError extends \RuntimeException {

	/**
	 * @param string              $reason  Machine reason.
	 * @param string              $message The shopper's sentence.
	 * @param array<string,mixed> $data    Extra fields for the response.
	 */
	public function __construct( public readonly string $reason, string $message, public readonly array $data = array() ) {
		parent::__construct( $message );
	}
}
