<?php

namespace WCInvoicePrinter\Pdf;

use WCInvoicePrinter\Template\TemplateDefinition;

final class MpdfRenderer implements PdfRendererInterface {
	public function render( string $html, TemplateDefinition $template ): string {
		if ( ! class_exists( \Mpdf\Mpdf::class ) ) {
			throw new \RuntimeException( __( 'PDF support is unavailable. Install the Composer dependencies.', 'wc-invoice-printer' ) );
		}
		$temp_dir = trailingslashit( get_temp_dir() ) . 'wcip-mpdf';
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			throw new \RuntimeException( __( 'A secure PDF working directory could not be created.', 'wc-invoice-printer' ) );
		}
		$format = '80mm' === $template->paper_size ? array( 80, $this->thermal_height( $html ) ) : $template->paper_size;
		$mpdf   = new \Mpdf\Mpdf(
			array(
				'mode'             => 'utf-8',
				'format'           => $format,
				'orientation'      => strtoupper( substr( $template->orientation, 0, 1 ) ),
				'tempDir'          => $temp_dir,
				'autoScriptToLang' => true,
				// DejaVu Sans covers Latin, Persian, and Arabic; pinning it keeps the
				// distributable deterministic and avoids mPDF selecting optional fonts.
				'autoLangToFont'   => false,
				'default_font'     => 'dejavusans',
				'margin_left'      => '80mm' === $template->paper_size ? 4 : 10,
				'margin_right'     => '80mm' === $template->paper_size ? 4 : 10,
				'margin_top'       => '80mm' === $template->paper_size ? 4 : 10,
				'margin_bottom'    => '80mm' === $template->paper_size ? 4 : 10,
			)
		);
		$mpdf->WriteHTML( $html );
		return $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
	}

	private function thermal_height( string $html ): int {
		$item_count = max( 1, substr_count( $html, 'class="item"' ) );
		// Base header, addresses, totals and footer plus room for each receipt item.
		return max( 175, min( 1000, 155 + ( 22 * $item_count ) ) );
	}
}
