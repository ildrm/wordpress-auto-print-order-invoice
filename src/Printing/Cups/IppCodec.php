<?php

namespace WCInvoicePrinter\Printing\Cups;

/** Bounded IPP/1.1 wire encoding, implemented from RFC 8010. */
final class IppCodec {
	public static function attribute( int $tag, string $name, string $value ): string {
		if ( strlen( $name ) > 1024 || strlen( $value ) > 65535 ) { throw new \InvalidArgumentException( 'IPP attribute too large.' ); }
		return chr( $tag ) . pack( 'n', strlen( $name ) ) . $name . pack( 'n', strlen( $value ) ) . $value;
	}

	public static function request( int $operation, int $id, string $attributes, string $job_attributes = '', string $document = '' ): string {
		return pack( 'CCnN', 1, 1, $operation, $id ) . "\x01"
			. self::attribute( 0x47, 'attributes-charset', 'utf-8' )
			. self::attribute( 0x48, 'attributes-natural-language', 'en' )
			. $attributes . ( '' !== $job_attributes ? "\x02" . $job_attributes : '' ) . "\x03" . $document;
	}

	/** @return array{status:int,groups:array} */
	public static function response( string $bytes, int $request_id ): array {
		$length = strlen( $bytes );
		if ( $length < 9 || $length > 2 * 1024 * 1024 ) { throw new \UnexpectedValueException( 'Invalid IPP length.' ); }
		$header = unpack( 'Cmajor/Cminor/nstatus/Nid', substr( $bytes, 0, 8 ) );
		if ( ! in_array( $header['major'], array( 1, 2 ), true ) || $header['id'] !== $request_id ) { throw new \UnexpectedValueException( 'Invalid IPP header.' ); }
		$groups = array(); $offset = 8; $group = -1; $name = ''; $attributes = 0;
		while ( $offset < $length ) {
			$tag = ord( $bytes[ $offset++ ] );
			if ( 3 === $tag ) {
				if ( $offset !== $length || $group < 0 ) { throw new \UnexpectedValueException( 'Invalid IPP ending.' ); }
				return array( 'status' => $header['status'], 'groups' => $groups );
			}
			if ( $tag < 0x10 ) {
				if ( ! in_array( $tag, array( 1, 2, 4, 5, 6, 7, 9 ), true ) || count( $groups ) >= 1000 ) { throw new \UnexpectedValueException( 'Invalid IPP group.' ); }
				$groups[] = array( 'tag' => $tag, 'attributes' => array() ); $group++; $name = ''; continue;
			}
			if ( $group < 0 || ++$attributes > 10000 || $offset + 2 > $length ) { throw new \UnexpectedValueException( 'Invalid IPP attribute.' ); }
			$name_length = unpack( 'n', substr( $bytes, $offset, 2 ) )[1]; $offset += 2;
			if ( $name_length > 1024 || $offset + $name_length + 2 > $length ) { throw new \UnexpectedValueException( 'Invalid IPP name.' ); }
			if ( $name_length ) { $name = substr( $bytes, $offset, $name_length ); }
			if ( '' === $name ) { throw new \UnexpectedValueException( 'Invalid IPP continuation.' ); }
			$offset += $name_length; $value_length = unpack( 'n', substr( $bytes, $offset, 2 ) )[1]; $offset += 2;
			if ( $offset + $value_length > $length ) { throw new \UnexpectedValueException( 'Truncated IPP value.' ); }
			$value = substr( $bytes, $offset, $value_length ); $offset += $value_length;
			if ( in_array( $tag, array( 0x21, 0x23 ), true ) ) {
				if ( 4 !== $value_length ) { throw new \UnexpectedValueException( 'Invalid IPP integer.' ); }
				$value = unpack( 'N', $value )[1];
			}
			$groups[ $group ]['attributes'][ $name ][] = array( 'tag' => $tag, 'value' => $value );
		}
		throw new \UnexpectedValueException( 'Missing IPP ending.' );
	}
}
