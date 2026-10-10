<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="<?php echo esc_attr( \WCInvoicePrinter\I18n\Locale::language_tag() ); ?>" dir="<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?>">
<head><meta charset="utf-8"><style>
@page { margin:4mm }
body { width:<?php echo '58mm' === $template->paper_size ? '50' : '72'; ?>mm; margin:0; color:#000; font:10pt/1.4 DejaVu Sans,sans-serif; direction:<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?> }
.center { text-align:center }
.brand { font-size:15pt; font-weight:bold; line-height:1.5 }
.logo { max-width:42mm; max-height:18mm }
.rule { border:0; border-top:1px dashed #000; margin:7px 0 }
.pair { width:100%; border-collapse:collapse; table-layout:fixed }
.pair td { width:50%; padding:1.2mm 0; vertical-align:top }
.first { text-align:<?php echo $invoice->rtl ? 'right' : 'left'; ?> }
.last { text-align:<?php echo $invoice->rtl ? 'left' : 'right'; ?> }
.item { padding:5px 0; border-bottom:1px dotted #777 }
.item-title { font-weight:bold }
.totals td { padding-top:.7mm; padding-bottom:.7mm }
.grand td { font-size:12pt; font-weight:bold; border-top:1px solid #000; padding-top:1.5mm }
.small { font-size:9pt }
</style></head>
<body>
<div class="center">
<?php if ( $invoice->store['logo_data_uri'] ) : ?><img class="logo" src="<?php echo esc_attr( $invoice->store['logo_data_uri'] ); ?>" alt=""><br><?php endif; ?>
<div class="brand"><?php echo esc_html( $invoice->store['name'] ); ?></div>
<div><?php echo esc_html( $invoice->store['address'] ); ?></div>
<div class="small"><?php echo nl2br( esc_html( $invoice->store['details'] ) ); ?></div>
<div><bdi dir="ltr"><?php echo esc_html( $invoice->store['phone'] ); ?></bdi></div>
</div><hr class="rule">
<table class="pair"><tr>
<td class="first"><strong><?php esc_html_e( 'INVOICE', 'wc-invoice-printer' ); ?></strong></td>
<td class="last"><strong>#<?php echo esc_html( $invoice->order['number'] ); ?></strong></td>
</tr><tr><td class="first"><?php esc_html_e( 'Date', 'wc-invoice-printer' ); ?></td><td class="last"><?php echo esc_html( $invoice->order['date'] ); ?></td></tr></table>
<hr class="rule">
<div><strong><?php echo esc_html( $invoice->customer['name'] ); ?></strong><br>
<?php echo nl2br( esc_html( $invoice->customer['billing_address'] ) ); ?></div>
<div class="small"><?php echo esc_html( $invoice->fulfillment['payment_method'] ); ?> · <?php echo esc_html( $invoice->fulfillment['shipping_method'] ); ?></div>
<?php if ( $invoice->customer['shipping_address'] ) : ?><div class="small"><?php echo nl2br( esc_html( $invoice->customer['shipping_address'] ) ); ?></div><?php endif; ?>
<?php include WCIP_PATH . 'templates/shared/details.php'; ?>
<hr class="rule">
<?php foreach ( $invoice->items as $item ) : ?><div class="item">
<div class="item-title"><?php echo esc_html( $item['name'] ); ?></div>
<?php if ( $item['variation'] || $item['sku'] ) : ?><div class="small"><?php echo esc_html( $item['variation'] ); ?> <bdi dir="ltr"><?php echo esc_html( $item['sku'] ); ?></bdi></div><?php endif; ?>
<table class="pair"><tr><td class="first"><bdi dir="ltr"><?php echo esc_html( (string) $item['quantity'] ); ?></bdi> × <?php echo wp_kses_post( $item['unit_price'] ); ?></td>
<td class="last"><strong><?php echo wp_kses_post( $item['total'] ); ?></strong></td></tr></table>
</div><?php endforeach; ?>
<p class="small"><?php esc_html_e( 'Unit prices exclude tax; line totals include discounts and tax.', 'wc-invoice-printer' ); ?></p>
<table class="pair totals">
<?php foreach ( $invoice->totals as $index => $total ) : ?><tr class="<?php echo count( $invoice->totals ) - 1 === $index ? 'grand' : ''; ?>">
<td class="first"><?php echo esc_html( $total['label'] ); ?></td><td class="last"><?php echo wp_kses_post( $total['value'] ); ?></td></tr><?php endforeach; ?>
</table>
<?php if ( $invoice->fulfillment['note'] ) : ?><hr class="rule"><div><strong><?php esc_html_e( 'Note:', 'wc-invoice-printer' ); ?></strong> <?php echo nl2br( esc_html( $invoice->fulfillment['note'] ) ); ?></div><?php endif; ?>
<hr class="rule"><div class="center"><?php esc_html_e( 'Thank you for your order.', 'wc-invoice-printer' ); ?><br>
<bdi dir="ltr" class="small"><?php echo esc_html( $invoice->store['email'] ); ?></bdi></div>
<?php include WCIP_PATH . 'templates/shared/code.php'; ?>
</body></html>
