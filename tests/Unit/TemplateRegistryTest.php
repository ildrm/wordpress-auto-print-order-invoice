<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Template\TemplateRegistry;

final class TemplateRegistryTest extends TestCase {
	public function test_three_distinct_built_in_templates_are_registered(): void {
		$templates = ( new TemplateRegistry() )->all();
		self::assertSame( array( 'classic', 'compact', 'thermal' ), array_keys( $templates ) );
		self::assertSame( '80mm', $templates['thermal']->paper_size );
		self::assertTrue( $templates['classic']->supports_rtl );
	}

	public function test_unknown_template_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new TemplateRegistry() )->get( '../secret' );
	}
}
