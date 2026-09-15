<?php

namespace WCInvoicePrinter\Invoice;

final class InvoiceData {
	public function __construct(
		public readonly array $order,
		public readonly array $store,
		public readonly array $customer,
		public readonly array $items,
		public readonly array $totals,
		public readonly array $fulfillment,
		public readonly bool $rtl = false
	) {}

	public function with_rtl( bool $rtl ): self {
		return new self( $this->order, $this->store, $this->customer, $this->items, $this->totals, $this->fulfillment, $rtl );
	}
}
