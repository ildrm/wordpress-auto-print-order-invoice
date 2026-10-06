<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="<?php echo esc_attr( get_locale() ); ?>" dir="<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?>">
<head><meta charset="utf-8"><style>
@page { margin:12mm }
body { margin:0; color:#1d2327; font:12px/1.5 DejaVu Sans,sans-serif; direction:<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?> }
table { width:100%; border-collapse:collapse }
thead { display:table-header-group }
th { background:#1d2327; color:#fff; font-size:10px; text-align:<?php echo $invoice->rtl ? 'right' : 'left'; ?>; padding:9px 6px }
td { border-bottom:1px solid #dcdcde; padding:10px 6px; vertical-align:top }
.num,.title { text-align:<?php echo $invoice->rtl ? 'left' : 'right'; ?> }
.num { white-space:nowrap }
.layout td { border:0; padding:0 0 18px }
.header { border-bottom:3px solid #1d2327; margin-bottom:24px }
.brand { width:60% }
.logo { max-width:160px; max-height:64px }
.title h1 { font-size:28px; margin:0 }
.meta,.item-meta { color:#646970; font-size:10px }
.addresses { margin-bottom:24px }
.addresses td { width:50%; background:#f6f7f7; padding:14px }
.addresses h2 { font-size:11px; margin:0 0 8px }
.item-name { font-weight:bold }
.totals { width:45%; margin-top:18px; margin-<?php echo $invoice->rtl ? 'right' : 'left'; ?>:auto }
.totals td { padding:5px 7px }
.totals tr:last-child td { border-top:2px solid #1d2327; font-size:14px }
.notes { margin-top:28px; padding-top:14px; border-top:1px solid #c3c4c7 }
.footer { margin-top:24px; text-align:center; color:#646970 }
</style></head>
<body>
<table class="layout header"><tr>
<td class="brand">
<?php if ( $invoice->store['logo_data_uri'] ) : ?><img class="logo" src="<?php echo esc_attr( $invoice->store['logo_data_uri'] ); ?>" alt=""><?php endif; ?>
<h2><?php echo esc_html( $invoice->store['name'] ); ?></h2>
<div><?php echo nl2br( esc_html( $invoice->store['details'] ) ); ?></div>
<div><?php echo esc_html( $invoice->store['address'] ); ?></div>
</td>
<td class="title"><h1><?php esc_html_e( 'INVOICE', 'wc-invoice-printer' ); ?></h1>
<strong>#<?php echo esc_html( $invoice->order['number'] ); ?></strong>
<div class="meta"><?php echo esc_html( $invoice->order['date'] ); ?></div></td>
</tr></table>
<table class="layout addresses"><tr>
<td><h2><?php esc_html_e( 'Bill to', 'wc-invoice-printer' ); ?></h2>
<strong><?php echo esc_html( $invoice->customer['name'] ); ?></strong><br>
<?php echo esc_html( $invoice->customer['company'] ); ?><br>
<?php echo nl2br( esc_html( $invoice->customer['billing_address'] ) ); ?><br>
<bdi dir="ltr"><?php echo esc_html( $invoice->customer['email'] ); ?> · <?php echo esc_html( $invoice->customer['phone'] ); ?></bdi></td>
<td><h2><?php esc_html_e( 'Payment & shipping', 'wc-invoice-printer' ); ?></h2>
<?php echo esc_html( $invoice->fulfillment['payment_method'] ); ?><br>
<?php echo esc_html( $invoice->fulfillment['shipping_method'] ); ?>
<?php if ( $invoice->customer['shipping_address'] ) : ?><br><?php echo nl2br( esc_html( $invoice->customer['shipping_address'] ) ); ?><?php endif; ?>
</td></tr></table>
<table><thead><tr>
<th><?php esc_html_e( 'Item', 'wc-invoice-printer' ); ?></th>
<th><?php esc_html_e( 'SKU', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Qty', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Unit', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Discount', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Tax', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Total', 'wc-invoice-printer' ); ?></th>
</tr></thead><tbody>
<?php foreach ( $invoice->items as $item ) : ?><tr>
<td><div class="item-name"><?php echo esc_html( $item['name'] ); ?></div><div class="item-meta"><?php echo esc_html( $item['variation'] ); ?></div></td>
<td><?php echo esc_html( $item['sku'] ); ?></td>
<td class="num"><?php echo esc_html( (string) $item['quantity'] ); ?></td>
<td class="num"><?php echo wp_kses_post( $item['unit_price'] ); ?></td>
<td class="num"><?php echo wp_kses_post( $item['discount'] ); ?></td>
<td class="num"><?php echo wp_kses_post( $item['tax'] ); ?></td>
<td class="num"><?php echo wp_kses_post( $item['total'] ); ?></td>
</tr><?php endforeach; ?>
</tbody></table>
<p class="meta"><?php esc_html_e( 'Unit prices and discounts exclude tax; line totals include tax.', 'wc-invoice-printer' ); ?></p>
<table class="totals">
<?php foreach ( $invoice->totals as $total ) : ?><tr><td><?php echo esc_html( $total['label'] ); ?></td><td class="num"><?php echo wp_kses_post( $total['value'] ); ?></td></tr><?php endforeach; ?>
</table>
<?php if ( $invoice->fulfillment['note'] ) : ?><div class="notes"><strong><?php esc_html_e( 'Customer note', 'wc-invoice-printer' ); ?></strong><br><?php echo nl2br( esc_html( $invoice->fulfillment['note'] ) ); ?></div><?php endif; ?>
<div class="footer"><bdi dir="ltr"><?php echo esc_html( $invoice->store['phone'] ); ?> · <?php echo esc_html( $invoice->store['email'] ); ?></bdi></div>
</body></html>
