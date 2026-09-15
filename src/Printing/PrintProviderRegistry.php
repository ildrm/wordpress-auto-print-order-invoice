<?php

namespace WCInvoicePrinter\Printing;

final class PrintProviderRegistry {
	/** @var array<string, PrintProviderInterface> */
	private array $providers = array();

	public function register( PrintProviderInterface $provider ): void {
		$this->providers[ $provider->id() ] = $provider;
	}

	public function get( string $id ): PrintProviderInterface {
		if ( ! isset( $this->providers[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Unknown print provider.' );
		}
		return $this->providers[ $id ];
	}

	public function all(): array {
		return apply_filters( 'wcip_print_providers', $this->providers );
	}
}
