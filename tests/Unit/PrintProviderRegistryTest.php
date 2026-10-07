<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Printing\PrintProviderInterface;
use WCInvoicePrinter\Printing\PrintProviderRegistry;
use WCInvoicePrinter\Printing\SubmissionResult;

final class PrintProviderRegistryTest extends TestCase {
	protected function setUp(): void { $GLOBALS['wcip_test_filters'] = array(); }
	protected function tearDown(): void { $GLOBALS['wcip_test_filters'] = array(); }

	public function test_filter_registered_provider_is_usable_for_submission(): void {
		$provider = $this->provider( 'custom' );
		$GLOBALS['wcip_test_filters']['wcip_print_providers'][] = static function ( array $providers ) use ( $provider ): array {
			$providers['custom'] = $provider;
			return $providers;
		};
		$registry = new PrintProviderRegistry();
		self::assertSame( $provider, $registry->get( 'custom' ) );
		self::assertSame( $provider, $registry->all()['custom'] );
	}

	public function test_filtered_replacement_is_used_by_get(): void {
		$first = $this->provider( 'custom' );
		$second = $this->provider( 'custom' );
		$registry = new PrintProviderRegistry();
		$registry->register( $first );
		$GLOBALS['wcip_test_filters']['wcip_print_providers'][] = static fn() => array( 'custom' => $second );
		self::assertSame( $second, $registry->get( 'custom' ) );
	}

	public function test_invalid_registration_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new PrintProviderRegistry() )->register( $this->provider( '../custom' ) );
	}

	public function test_invalid_filter_value_is_rejected(): void {
		$GLOBALS['wcip_test_filters']['wcip_print_providers'][] = static fn() => array( 'custom' => new \stdClass() );
		$this->expectException( \InvalidArgumentException::class );
		( new PrintProviderRegistry() )->get( 'custom' );
	}

	public function test_filter_identifier_must_match_provider_identifier(): void {
		$provider = $this->provider( 'custom' );
		$GLOBALS['wcip_test_filters']['wcip_print_providers'][] = static fn() => array( 'another' => $provider );
		$this->expectException( \InvalidArgumentException::class );
		( new PrintProviderRegistry() )->all();
	}

	public function test_unknown_provider_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new PrintProviderRegistry() )->get( 'unknown' );
	}

	private function provider( string $id ): PrintProviderInterface {
		return new class( $id ) implements PrintProviderInterface {
			private string $provider_id;

			public function __construct( string $provider_id ) {
				$this->provider_id = $provider_id;
			}
			public function id(): string { return $this->provider_id; }
			public function test_connection(): array { return array(); }
			public function printers( bool $force_refresh = false ): array { return array(); }
			public function submit( string $pdf_bytes, string $printer_id, int $copies, string $title ): SubmissionResult { return new SubmissionResult( 'custom-job' ); }
		};
	}
}
