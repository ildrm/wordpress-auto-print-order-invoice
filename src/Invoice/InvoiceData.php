<?php

namespace WCInvoicePrinter\Invoice;

use WCInvoicePrinter\Support\ReadOnlyProperties;

/**
 * @property-read array $order
 * @property-read array $store
 * @property-read array $customer
 * @property-read array $items
 * @property-read array $totals
 * @property-read array $fulfillment
 * @property-read bool $rtl
 */
final class InvoiceData implements \JsonSerializable {
	use ReadOnlyProperties;

	private array $order;
	private array $store;
	private array $customer;
	private array $items;
	private array $totals;
	private array $fulfillment;
	private bool $rtl;

	public function __construct(
		array $order,
		array $store,
		array $customer,
		array $items,
		array $totals,
		array $fulfillment,
		bool $rtl = false
	) {
		$this->assert_uninitialized();
		$this->order = $order;
		$this->store = $store;
		$this->customer = $customer;
		$this->items = $items;
		$this->totals = $totals;
		$this->fulfillment = $fulfillment;
		$this->rtl = $rtl;
	}

	public function with_rtl( bool $rtl ): self {
		return new self( $this->order, $this->store, $this->customer, $this->items, $this->totals, $this->fulfillment, $rtl );
	}
}
