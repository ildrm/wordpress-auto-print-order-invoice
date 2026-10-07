<?php

namespace WCInvoicePrinter\Template;

final class TemplateRegistry {
	/** @var array<string, TemplateDefinition> */
	private array $templates = array();

	public function __construct() {
		$this->register( new TemplateDefinition( 'classic', __( 'Classic', 'wc-invoice-printer' ), __( 'A polished full invoice for office printers.', 'wc-invoice-printer' ), 'A4', 'portrait', true, WCIP_PATH . 'templates/classic/invoice.php' ) );
		$this->register( new TemplateDefinition( 'compact', __( 'Compact', 'wc-invoice-printer' ), __( 'Dense and readable for high-volume operations.', 'wc-invoice-printer' ), 'A4', 'portrait', true, WCIP_PATH . 'templates/compact/invoice.php' ) );
		$this->register( new TemplateDefinition( 'thermal', __( 'Thermal 80 mm', 'wc-invoice-printer' ), __( 'Purpose-built for narrow receipt printers.', 'wc-invoice-printer' ), '80mm', 'portrait', true, WCIP_PATH . 'templates/thermal/invoice.php' ) );
	}

	public function register( TemplateDefinition $template ): void {
		$this->validate( $template );
		$this->templates[ $template->id ] = $template;
	}

	public function all(): array {
		$templates = apply_filters( 'wcip_invoice_templates', $this->templates );
		if ( ! is_array( $templates ) ) {
			throw new \InvalidArgumentException( 'Invalid invoice templates.' );
		}
		foreach ( $templates as $id => $template ) {
			if ( ! $template instanceof TemplateDefinition || (string) $id !== $template->id ) {
				throw new \InvalidArgumentException( 'Invalid invoice template.' );
			}
			$this->validate( $template );
		}
		return $templates;
	}

	public function get( string $id ): TemplateDefinition {
		$templates = $this->all();
		if ( ! isset( $templates[ $id ] ) || ! $templates[ $id ] instanceof TemplateDefinition ) {
			throw new \InvalidArgumentException( 'Unknown invoice template.' );
		}
		return $templates[ $id ];
	}

	public function has( string $id ): bool {
		try {
			$this->get( $id );
			return true;
		} catch ( \InvalidArgumentException $error ) {
			return false;
		}
	}

	private function validate( TemplateDefinition $template ): void {
		if ( ! preg_match( '/^[a-z0-9_-]+$/', $template->id ) || ! is_file( $template->path ) || ! is_readable( $template->path ) || '' === trim( $template->paper_size ) || ! in_array( $template->orientation, array( 'portrait', 'landscape' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid invoice template.' );
		}
	}
}
