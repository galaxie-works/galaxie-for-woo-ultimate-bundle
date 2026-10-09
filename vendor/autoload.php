<?php
/**
 * Simple autoloader for bundled PDF parser
 * This allows the plugin to work without requiring Composer on the target server
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Prevent conflicts if Composer autoloader already exists
if ( ! class_exists( 'Smalot\\PdfParser\\Parser' ) ) {
    
    // Register autoloader for smalot/pdfparser
    spl_autoload_register( function( $class ) {
        // Only handle Smalot\PdfParser classes
        if ( strpos( $class, 'Smalot\\PdfParser\\' ) !== 0 ) {
            return;
        }
        
        // Convert namespace to file path
        $relative_class = str_replace( 'Smalot\\PdfParser\\', '', $class );
        $file = str_replace( '\\', DIRECTORY_SEPARATOR, $relative_class );
        
        // Build full file path
        $file_path = __DIR__ . '/smalot/pdfparser/src/Smalot/PdfParser/' . $file . '.php';
        
        if ( file_exists( $file_path ) ) {
            require_once $file_path;
        }
    } );

    // Load core dependencies in proper order
    $core_files = [
        '/smalot/pdfparser/src/Smalot/PdfParser/Exception/EncodingNotFoundException.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/RawData/FilterHelper.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/RawData/RawDataParser.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Element.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Header.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/PDFObject.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Document.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Page.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Pages.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Parser.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Config.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Encoding.php',
        '/smalot/pdfparser/src/Smalot/PdfParser/Font.php',
    ];

    foreach ( $core_files as $file ) {
        $file_path = __DIR__ . $file;
        if ( file_exists( $file_path ) ) {
            require_once $file_path;
        }
    }
}
