<?php
/**
 * PixfortImageAlt: pixfort's "Image link" placeholder becomes the Media Library alt.
 * Run: php tests/image-alt/run.php
 */
define( 'ABSPATH', __DIR__ );
class WP_Post { public $ID; public function __construct( $id ) { $this->ID = $id; } }
$GLOBALS['meta'] = array( 10 => 'Fiorde ao amanhecer', 11 => '' );
function __( $s, $d = null ) { return 'pixfort-core' === $d && 'Image link' === $s ? 'Link da imagem' : $s; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ] ?? ''; }
require dirname( __DIR__, 2 ) . '/src/Core/Module.php';
require dirname( __DIR__, 2 ) . '/src/Modules/PixfortImageAlt/Module.php';
use Galaxie\Woo\Modules\PixfortImageAlt\Module;
$pass = 0; $fail = 0;
$check = function ( $name, $got, $want ) use ( &$pass, &$fail ) {
	if ( $got === $want ) { $pass++; echo "  ok    $name\n"; } else { $fail++; echo "  FAIL  $name: got " . var_export( $got, true ) . "\n"; }
};
$check( 'placeholder -> library alt', Module::alt( array( 'alt' => 'Image link' ), new WP_Post( 10 ) )['alt'], 'Fiorde ao amanhecer' );
$check( 'translated placeholder -> library alt', Module::alt( array( 'alt' => 'Link da imagem' ), new WP_Post( 10 ) )['alt'], 'Fiorde ao amanhecer' );
$check( 'placeholder, no library alt -> empty (decorative)', Module::alt( array( 'alt' => 'Image link' ), new WP_Post( 11 ) )['alt'], '' );
$check( 'alt typed in the widget is kept', Module::alt( array( 'alt' => 'Logo' ), new WP_Post( 10 ) )['alt'], 'Logo' );
$check( 'no alt key untouched', Module::alt( array( 'class' => 'x' ), new WP_Post( 10 ) ), array( 'class' => 'x' ) );
$check( 'non-post attachment -> empty', Module::alt( array( 'alt' => 'Image link' ), null )['alt'], '' );
echo "\n  $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
