<?php

namespace WCInvoicePrinter\Printing;

final class RetryPolicy {
	public const MAX_ATTEMPTS = 3;

	public function should_retry( ProviderException $exception, int $attempt ): bool {
		return ! $exception->ambiguous() && $exception->retryable() && $attempt < self::MAX_ATTEMPTS;
	}

	public function delay( int $attempt ): int {
		return min( 3600, 60 * ( 2 ** max( 0, $attempt - 1 ) ) );
	}
}
