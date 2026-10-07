<?php

namespace WCInvoicePrinter\Support;

/** Expose initialized private value fields without allowing external writes. */
trait ReadOnlyProperties {
	private function assert_uninitialized(): void {
		if ( get_object_vars( $this ) ) {
			throw new \Error( 'Cannot reinitialize read-only data: ' . get_class( $this ) );
		}
	}

	public function jsonSerialize(): array {
		return get_object_vars( $this );
	}

	/** @return mixed */
	public function __get( string $name ) {
		if ( ! property_exists( $this, $name ) ) {
			throw new \Error( 'Undefined property: ' . get_class( $this ) . '::$' . $name );
		}
		// Return by value so callers cannot change array fields through a reference.
		return $this->$name;
	}

	public function __isset( string $name ): bool {
		return isset( $this->$name );
	}

	/** @param mixed $value */
	public function __set( string $name, $value ): void {
		throw new \Error( 'Cannot modify read-only property: ' . get_class( $this ) . '::$' . $name );
	}

	public function __unset( string $name ): void {
		throw new \Error( 'Cannot unset read-only property: ' . get_class( $this ) . '::$' . $name );
	}
}
