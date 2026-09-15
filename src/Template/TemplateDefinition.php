<?php

namespace WCInvoicePrinter\Template;

final class TemplateDefinition {
	public function __construct(
		public readonly string $id,
		public readonly string $name,
		public readonly string $description,
		public readonly string $paper_size,
		public readonly string $orientation,
		public readonly bool $supports_rtl,
		public readonly string $path
	) {}
}
