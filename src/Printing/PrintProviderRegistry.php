<?php

namespace WCInvoicePrinter\Printing;

final class PrintProviderRegistry {
	/** @var array<string, PrintProviderInterface> */
	private array $providers = array();

	public function register( PrintProviderInterface $provider ): void {
		if ( ! preg_match( '/^[a-z0-9_-]+$/', $provider->id() ) ) {
			throw new \InvalidArgumentException( 'Invalid print provider.' );
		}
		$this->providers[ $provider->id() ] = $provider;
	}

	public function get( string $id ): PrintProviderInterface {
		$providers = $this->all();
		if ( ! isset( $providers[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Unknown print provider.' );
		}
		return $providers[ $id ];
	}

	public function all(): array {
		$providers = apply_filters( 'wcip_print_providers', $this->providers );
		if ( ! is_array( $providers ) ) {
			throw new \InvalidArgumentException( 'Invalid print providers.' );
		}
		foreach ( $providers as $id => $provider ) {
			if ( ! $provider instanceof PrintProviderInterface || ! preg_match( '/^[a-z0-9_-]+$/', (string) $id ) || (string) $id !== $provider->id() ) {
				throw new \InvalidArgumentException( 'Invalid print provider.' );
			}
		}
		return $providers;
	}
}
