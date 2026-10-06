<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Template\TemplateDefinition;

final class TemplateRegistryTest extends TestCase {
	protected function setUp(): void { $GLOBALS['wcip_test_filters'] = array(); }
	protected function tearDown(): void { $GLOBALS['wcip_test_filters'] = array(); }
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

	public function test_directory_cannot_be_registered_as_a_template_file(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new TemplateRegistry() )->register( new TemplateDefinition( 'invalid', 'Invalid', '', 'A4', 'portrait', true, __DIR__ ) );
	}

	public function test_filter_added_template_is_validated_before_rendering(): void {
		$template = new TemplateDefinition( 'custom', 'Custom', '', 'A4', 'sideways', true, __FILE__ );
		$GLOBALS['wcip_test_filters']['wcip_invoice_templates'][] = static fn() => array( 'custom' => $template );
		$this->expectException( \InvalidArgumentException::class );
		( new TemplateRegistry() )->get( 'custom' );
	}

	public function test_filter_identifier_must_match_template_identifier(): void {
		$template = new TemplateDefinition( 'custom', 'Custom', '', 'A4', 'portrait', true, __FILE__ );
		$GLOBALS['wcip_test_filters']['wcip_invoice_templates'][] = static fn() => array( 'another' => $template );
		$this->expectException( \InvalidArgumentException::class );
		( new TemplateRegistry() )->all();
	}
}
