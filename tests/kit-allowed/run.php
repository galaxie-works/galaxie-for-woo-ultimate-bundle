<?php
/**
 * GiftPacking::kit_allowed(): "Pode ser inserido em kits?" — yes unless the
 * product or its parent says no. Run: php tests/kit-allowed/run.php
 */
define( 'ABSPATH', __DIR__ );

class WC_Product {
	public function __construct( public int $id, public array $meta = array(), public int $parent = 0 ) {}
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function is_type( $type ) { return 'variation' === $type && $this->parent > 0; }
	public function get_parent_id() { return $this->parent; }
}

$GLOBALS['post_meta'] = array( 100 => array( '_galaxie_kit_allowed' => 'no' ) );
function get_post_meta( $id, $key, $single ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }

require dirname( __DIR__, 2 ) . '/src/Support/GiftPacking.php';
use Galaxie\Woo\Support\GiftPacking;

$pass  = 0;
$fail  = 0;
$check = function ( $name, $got, $want ) use ( &$pass, &$fail ) {
	if ( $got === $want ) { $pass++; echo "  ok    $name\n"; } else { $fail++; echo "  FAIL  $name: got " . var_export( $got, true ) . "\n"; }
};

$check( 'no meta -> allowed (every existing product keeps going in kits)', GiftPacking::kit_allowed( new WC_Product( 1 ) ), true );
$check( 'simple product set to no -> out', GiftPacking::kit_allowed( new WC_Product( 2, array( '_galaxie_kit_allowed' => 'no' ) ) ), false );
$check( 'variation of a product set to no -> out', GiftPacking::kit_allowed( new WC_Product( 101, array(), 100 ) ), false );
$check( 'variation set to no, product allowed -> out', GiftPacking::kit_allowed( new WC_Product( 201, array( '_galaxie_kit_allowed' => 'no' ), 200 ) ), false );
$check( 'variation of an allowed product -> allowed', GiftPacking::kit_allowed( new WC_Product( 201, array(), 200 ) ), true );
$check( 'any value other than no -> allowed', GiftPacking::kit_allowed( new WC_Product( 3, array( '_galaxie_kit_allowed' => 'yes' ) ) ), true );

echo "\n  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
