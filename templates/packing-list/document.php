<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="<?php echo esc_attr( \WCInvoicePrinter\I18n\Locale::language_tag() ); ?>" dir="<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?>">
<head><meta charset="utf-8"><style>
body { color:#000; font:12px/1.5 DejaVu Sans,sans-serif; direction:<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?> }
table { width:100%; border-collapse:collapse } thead { display:table-header-group } th,td { border:1px solid #bbb; padding:8px; text-align:<?php echo $invoice->rtl ? 'right' : 'left'; ?> } th { background:#eee }
img { max-width:100%; height:auto }
</style></head><body>
<h1><?php esc_html_e( 'Packing list', 'wc-invoice-printer' ); ?> <bdi dir="ltr">#<?php echo esc_html( $invoice->order['number'] ); ?></bdi></h1>
<p><?php echo esc_html( $invoice->store['name'] ); ?></p>
<p><?php echo nl2br( esc_html( $invoice->customer['recipient']['formatted_address'] ?? $invoice->customer['shipping_address'] ) ); ?></p>
<table><thead><tr><th><?php esc_html_e( 'Item', 'wc-invoice-printer' ); ?></th><th><?php esc_html_e( 'SKU', 'wc-invoice-printer' ); ?></th><th><?php esc_html_e( 'Qty', 'wc-invoice-printer' ); ?></th><th><?php esc_html_e( 'Weight', 'wc-invoice-printer' ); ?></th></tr></thead><tbody>
<?php foreach ( $invoice->items as $item ) : ?><tr><td><strong><?php echo esc_html( $item['name'] ); ?></strong><br><?php echo esc_html( $item['variation'] ); ?></td><td><bdi dir="ltr"><?php echo esc_html( $item['sku'] ); ?></bdi></td><td><bdi dir="ltr"><?php echo esc_html( (string) $item['quantity'] ); ?></bdi></td><td><bdi dir="ltr"><?php echo esc_html( isset( $item['weight']['line_kg'] ) ? (string) $item['weight']['line_kg'] . ' kg' : __( 'Unknown', 'wc-invoice-printer' ) ); ?></bdi></td></tr><?php endforeach; ?>
</tbody></table>
<?php if ( $invoice->fulfillment['note'] ) : ?><p><?php echo nl2br( esc_html( $invoice->fulfillment['note'] ) ); ?></p><?php endif; ?>
<?php include WCIP_PATH . 'templates/shared/code.php'; ?>
</body></html>
