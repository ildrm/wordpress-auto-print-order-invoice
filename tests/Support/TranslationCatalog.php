<?php

namespace WCInvoicePrinter\Tests\Support;

/** Read the compiled catalog for rendering tests without a WordPress installation. */
final class TranslationCatalog {
	public static function load( string $locale ): array {
		$bytes = file_get_contents( WCIP_PATH . 'languages/wc-invoice-printer-' . $locale . '.mo' );
		$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations', substr( $bytes, 0, 20 ) );
		if ( 0x950412de !== $header['magic'] ) { throw new \RuntimeException( 'Unsupported translation catalog.' ); }
		$messages = array();
		for ( $i = 0; $i < $header['count']; ++$i ) {
			$original = unpack( 'Vlength/Voffset', substr( $bytes, $header['originals'] + 8 * $i, 8 ) );
			$translated = unpack( 'Vlength/Voffset', substr( $bytes, $header['translations'] + 8 * $i, 8 ) );
			$key = explode( "\0", substr( $bytes, $original['offset'], $original['length'] ) )[0];
			$value = explode( "\0", substr( $bytes, $translated['offset'], $translated['length'] ) )[0];
			if ( '' !== $key ) { $messages[ $key ] = $value; }
		}
		return $messages;
	}
}
