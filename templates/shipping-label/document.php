<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="<?php echo esc_attr( \WCInvoicePrinter\I18n\Locale::language_tag() ); ?>" dir="<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?>">
<head><meta charset="utf-8"><style>
body { margin:0; color:#000; font:14px/1.4 DejaVu Sans,sans-serif; direction:<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?> }
h1 { font-size:20px } .reference { font-size:18px; font-weight:bold } .contact { font-size:16px }
img { max-width:100%; height:auto } p { margin:10px 0 }
</style></head><body>
<h1><?php esc_html_e( 'Shipping label', 'wc-invoice-printer' ); ?></h1>
<?php if ( $invoice->fulfillment['label_show_sender'] ?? false ) : ?><p><?php echo esc_html( $invoice->store['name'] ); ?><br><?php echo nl2br( esc_html( $invoice->store['address'] ) ); ?></p><?php endif; ?>
<p class="reference"><bdi dir="ltr">#<?php echo esc_html( $invoice->order['number'] ); ?></bdi></p>
<p><strong><?php esc_html_e( 'Recipient', 'wc-invoice-printer' ); ?></strong><br><?php echo nl2br( esc_html( $invoice->customer['recipient']['formatted_address'] ?? $invoice->customer['shipping_address'] ) ); ?></p>
<?php if ( ( $invoice->fulfillment['show_shipping_phone'] ?? true ) && ( $invoice->customer['shipping_phone'] ?? '' ) ) : ?><p class="contact"><bdi dir="ltr"><?php echo esc_html( $invoice->customer['shipping_phone'] ); ?></bdi></p><?php endif; ?>
<?php foreach ( $invoice->customer['recipient']['extra'] ?? array() as $name => $value ) : ?><p><?php echo esc_html( $name . ': ' . $value ); ?></p><?php endforeach; ?>
<p><?php echo esc_html( $invoice->fulfillment['shipping_method'] ); ?></p>
<?php if ( $invoice->fulfillment['note'] ) : ?><p><?php echo nl2br( esc_html( $invoice->fulfillment['note'] ) ); ?></p><?php endif; ?>
<?php include WCIP_PATH . 'templates/shared/code.php'; ?>
</body></html>
