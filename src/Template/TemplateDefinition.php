<?php

namespace WCInvoicePrinter\Template;

use WCInvoicePrinter\Support\ReadOnlyProperties;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $description
 * @property-read string $paper_size
 * @property-read string $orientation
 * @property-read bool $supports_rtl
 * @property-read string $path
 */
final class TemplateDefinition implements \JsonSerializable {
	use ReadOnlyProperties;

	private string $id;
	private string $name;
	private string $description;
	private string $paper_size;
	private string $orientation;
	private bool $supports_rtl;
	private string $path;

	public function __construct(
		string $id,
		string $name,
		string $description,
		string $paper_size,
		string $orientation,
		bool $supports_rtl,
		string $path
	) {
		$this->assert_uninitialized();
		$this->id = $id;
		$this->name = $name;
		$this->description = $description;
		$this->paper_size = $paper_size;
		$this->orientation = $orientation;
		$this->supports_rtl = $supports_rtl;
		$this->path = $path;
	}
}
