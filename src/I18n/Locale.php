<?php

namespace WCInvoicePrinter\I18n;

final class Locale {
	public static function site_locale(): string {
		$locale = get_option( 'WPLANG', 'en_US' );
		return is_string( $locale ) && '' !== $locale ? $locale : 'en_US';
	}

	public static function language_tag(): string {
		// determine_locale follows the user's language for admin/preview requests.
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return str_replace( '_', '-', $locale );
	}
}
