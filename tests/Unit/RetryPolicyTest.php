<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Printing\RetryPolicy;

final class RetryPolicyTest extends TestCase {
	/**
	 * @dataProvider retry_cases
	 */
	public function test_retry_decision( bool $retryable, bool $ambiguous, int $attempt, bool $expected ): void {
		$exception = new ProviderException( 'safe message', 'test', $retryable, $ambiguous );
		self::assertSame( $expected, ( new RetryPolicy() )->should_retry( $exception, $attempt ) );
	}

	public static function retry_cases(): array {
		return array( 'definite transient' => array( true, false, 1, true ), 'ambiguous transport' => array( true, true, 1, false ), 'definite permanent' => array( false, false, 1, false ), 'attempt limit' => array( true, false, 3, false ) );
	}

	public function test_backoff_is_bounded(): void {
		$policy = new RetryPolicy();
		self::assertSame( 60, $policy->delay( 1 ) );
		self::assertSame( 3600, $policy->delay( 99 ) );
	}
}
