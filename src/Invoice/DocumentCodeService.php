<?php

namespace WCInvoicePrinter\Invoice;

/** Random references confer no authorization and contain no customer data. */
final class DocumentCodeService {
	private string $table;
	public function __construct() { global $wpdb; $this->table = $wpdb->prefix . 'wcip_document_references'; }

	public function reference( int $order_id, string $document_type, string $package_id = '' ): string {
		global $wpdb;
		if ( $order_id < 1 || ! in_array( $document_type, array( 'invoice', 'shipping_label', 'packing_list' ), true ) || ! preg_match( '/^[a-zA-Z0-9_-]{0,64}$/D', $package_id ) ) { throw new \InvalidArgumentException( 'Invalid document identity.' ); }
		$sql = $wpdb->prepare( "SELECT reference FROM {$this->table} WHERE order_id = %d AND document_type = %s AND package_id = %s", $order_id, $document_type, $package_id );
		$reference = $wpdb->get_var( $sql );
		if ( $reference ) { return $reference; }
		$wpdb->insert( $this->table, array( 'order_id' => $order_id, 'document_type' => $document_type, 'package_id' => $package_id, 'reference' => 'W1-' . strtr( base64_encode( random_bytes( 12 ) ), '+/', '-_' ) ), array( '%d', '%s', '%s', '%s' ) );
		$reference = $wpdb->get_var( $sql );
		if ( ! is_string( $reference ) || '' === $reference ) { throw new \RuntimeException( 'The document reference could not be saved.' ); }
		return $reference;
	}

	public static function normalize_reference( string $reference ): string { return trim( (string) preg_replace( '/^\](?:C[01]|Q[13])/', '', trim( $reference ) ) ); }

	public function lookup( string $reference ): ?array {
		global $wpdb;
		$reference = self::normalize_reference( $reference );
		if ( ! preg_match( '/^W1-[A-Za-z0-9_-]{16}$/D', $reference ) ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE reference = %s", $reference ), ARRAY_A );
		return is_array( $row ) && hash_equals( $row['reference'], $reference ) ? $row : null;
	}

	public static function image( string $reference, string $type ): string {
		if ( ! in_array( $type, array( 'Code128', 'QR' ), true ) ) { throw new \InvalidArgumentException( 'Invalid code type.' ); }
		if ( ! preg_match( '/^W1-[A-Za-z0-9_-]{16}$/D', $reference ) ) { throw new \InvalidArgumentException( 'Invalid scan reference.' ); }
		if ( ( 'QR' === $type && ! class_exists( \Mpdf\QrCode\QrCode::class ) ) || ( 'QR' !== $type && ! class_exists( \Mpdf\Barcode::class ) ) ) { return ''; }
		if ( 'QR' === $type ) {
			$svg = ( new \Mpdf\QrCode\Output\Svg() )->output( new \Mpdf\QrCode\QrCode( $reference, 'M' ), 300, 'white', 'black' );
		} else {
			$code = ( new \Mpdf\Barcode() )->getBarcodeArray( $reference, 'C128B' );
			$width = (int) $code['maxw'] + 20;
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="60" viewBox="0 0 ' . $width . ' 60"><rect width="100%" height="100%" fill="white"/>';
			$x = 10;
			foreach ( $code['bcode'] as $bar ) {
				if ( $bar['t'] && $bar['w'] > 0 ) { $svg .= '<rect x="' . $x . '" y="5" width="' . (int) $bar['w'] . '" height="50" fill="black"/>'; }
				$x += (int) $bar['w'];
			}
			$svg .= '</svg>';
		}
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
