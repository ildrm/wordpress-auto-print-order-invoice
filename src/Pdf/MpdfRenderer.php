<?php

namespace WCInvoicePrinter\Pdf;

use WCInvoicePrinter\Template\TemplateDefinition;

final class MpdfRenderer implements PdfRendererInterface {
	public function render( string $html, TemplateDefinition $template ): string {
		if ( strlen( $html ) > 4 * MB_IN_BYTES ) { throw new \RuntimeException( __( 'The document exceeds the safe rendering limit.', 'wc-invoice-printer' ) ); }
		if ( ! class_exists( \Mpdf\Mpdf::class ) ) {
			throw new \RuntimeException( __( 'PDF support is unavailable. Install the Composer dependencies.', 'wc-invoice-printer' ) );
		}
		$temp_dir = trailingslashit( get_temp_dir() ) . 'wcip-mpdf-' . bin2hex( random_bytes( 16 ) );
		// A private, per-render directory prevents other sites/users reading cached
		// images and avoids mPDF cleanup races between concurrent print workers.
		if ( ! @mkdir( $temp_dir, 0700 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A safe user-facing error is emitted below.
			throw new \RuntimeException( __( 'A secure PDF working directory could not be created.', 'wc-invoice-printer' ) );
		}
		try {
			$thermal = in_array( $template->paper_size, array( '80mm', '58mm' ), true );
			$format = $thermal ? array( '58mm' === $template->paper_size ? 58 : 80, 297 ) : $template->paper_size;
			$mpdf   = new \Mpdf\Mpdf(
				array(
					'mode'             => 'utf-8',
					'format'           => $format,
					'orientation'      => strtoupper( substr( $template->orientation, 0, 1 ) ),
					'tempDir'          => $temp_dir,
					'autoScriptToLang' => true,
					// mPDF ships FreeSerif (Indic) and Sun-ExtA (CJK) alongside DejaVu.
					'autoLangToFont'   => true,
					'default_font'     => 'dejavusans',
					'margin_left'      => $thermal ? 4 : 10,
					'margin_right'     => $thermal ? 4 : 10,
					'margin_top'       => $thermal ? 4 : 10,
					'margin_bottom'    => $thermal ? 4 : 10,
				)
			);
			$mpdf->WriteHTML( $html );
			$pdf = $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
			if ( strlen( $pdf ) > 20 * MB_IN_BYTES ) { throw new \RuntimeException( __( 'The document exceeds the safe rendering limit.', 'wc-invoice-printer' ) ); }
			return $pdf;
		} finally {
			$this->remove_temp_directory( $temp_dir );
		}
	}

	private function remove_temp_directory( string $directory ): void {
		$entries = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup must not replace a generation error.
		if ( false !== $entries ) {
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path = $directory . DIRECTORY_SEPARATOR . $entry;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					$this->remove_temp_directory( $path );
				} else {
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup must not replace a generation error.
				}
			}
		}
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort cleanup.
	}

}
