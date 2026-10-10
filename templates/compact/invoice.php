<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="<?php echo esc_attr( \WCInvoicePrinter\I18n\Locale::language_tag() ); ?>" dir="<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?>">
<head><meta charset="utf-8"><style>
@page { margin:8mm }
body { margin:0; color:#202124; font:10px/1.35 DejaVu Sans,sans-serif; direction:<?php echo $invoice->rtl ? 'rtl' : 'ltr'; ?> }
table { border-collapse:collapse; width:100% }
thead { display:table-header-group }
th { border-bottom:2px solid #2c3338; text-align:<?php echo $invoice->rtl ? 'right' : 'left'; ?>; padding:5px 4px }
td { border-bottom:1px solid #ddd; padding:5px 4px; vertical-align:top }
.layout td { border:0 }
.top { border-bottom:1px solid #8c8f94; margin-bottom:10px }
.top h1 { font-size:18px; margin:0 }
.brand { font-size:13px; font-weight:bold }
.grid { margin-bottom:10px }
.grid td { width:50% }
.label { font-size:8px; font-weight:bold; color:#646970 }
.num { text-align:<?php echo $invoice->rtl ? 'left' : 'right'; ?>; white-space:nowrap }
.meta { font-size:8px; color:#646970 }
.bottom { margin-top:10px }
.note { width:60% }
.totals td { padding:3px 4px }
.totals tr:last-child { font-size:12px; font-weight:bold }
.logo { max-width:100px; max-height:36px }
</style></head>
<body>
<table class="layout top"><tr><td>
<?php if ( $invoice->store['logo_data_uri'] ) : ?><img class="logo" src="<?php echo esc_attr( $invoice->store['logo_data_uri'] ); ?>" alt=""><?php endif; ?>
<div class="brand"><?php echo esc_html( $invoice->store['name'] ); ?></div>
<?php echo esc_html( $invoice->store['address'] ); ?><br>
<?php echo nl2br( esc_html( $invoice->store['details'] ) ); ?><br>
<bdi dir="ltr"><?php echo esc_html( $invoice->store['phone'] ); ?> · <?php echo esc_html( $invoice->store['email'] ); ?></bdi>
</td><td class="num"><h1><?php esc_html_e( 'Invoice', 'wc-invoice-printer' ); ?> #<?php echo esc_html( $invoice->order['number'] ); ?></h1>
<?php echo esc_html( $invoice->order['date'] ); ?> · <?php echo esc_html( $invoice->order['status'] ); ?>
</td></tr></table>
<table class="layout grid"><tr><td>
<span class="label"><?php esc_html_e( 'Customer', 'wc-invoice-printer' ); ?></span><br>
<strong><?php echo esc_html( $invoice->customer['name'] ); ?></strong> · <?php echo esc_html( $invoice->customer['company'] ); ?><br>
<?php echo nl2br( esc_html( $invoice->customer['billing_address'] ) ); ?>
</td><td><span class="label"><?php esc_html_e( 'Payment / delivery', 'wc-invoice-printer' ); ?></span><br>
<?php echo esc_html( $invoice->fulfillment['payment_method'] ); ?><br>
<?php echo esc_html( $invoice->fulfillment['shipping_method'] ); ?>
<?php if ( $invoice->customer['shipping_address'] ) : ?><br><?php echo nl2br( esc_html( $invoice->customer['shipping_address'] ) ); ?><?php endif; ?>
</td></tr></table>
<?php include WCIP_PATH . 'templates/shared/details.php'; ?>
<table><thead><tr>
<th><?php esc_html_e( 'Description', 'wc-invoice-printer' ); ?></th>
<th><?php esc_html_e( 'SKU', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Qty', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Price', 'wc-invoice-printer' ); ?></th>
<th class="num"><?php esc_html_e( 'Total', 'wc-invoice-printer' ); ?></th>
</tr></thead><tbody>
<?php foreach ( $invoice->items as $item ) : ?><tr>
<td><strong><?php echo esc_html( $item['name'] ); ?></strong><div class="meta"><?php echo esc_html( $item['variation'] ); ?></div></td>
<td><bdi dir="ltr"><?php echo esc_html( $item['sku'] ); ?></bdi></td>
<td class="num"><bdi dir="ltr"><?php echo esc_html( (string) $item['quantity'] ); ?></bdi></td>
<td class="num"><?php echo wp_kses_post( $item['unit_price'] ); ?></td>
<td class="num"><?php echo wp_kses_post( $item['total'] ); ?></td>
</tr><?php endforeach; ?>
</tbody></table>
<p class="meta"><?php esc_html_e( 'Unit prices exclude tax; line totals include discounts and tax.', 'wc-invoice-printer' ); ?></p>
<table class="layout bottom"><tr><td class="note">
<?php if ( $invoice->fulfillment['note'] ) : ?><span class="label"><?php esc_html_e( 'Note', 'wc-invoice-printer' ); ?></span><br><?php echo nl2br( esc_html( $invoice->fulfillment['note'] ) ); ?><?php endif; ?>
</td><td><table class="totals">
<?php foreach ( $invoice->totals as $total ) : ?><tr><td><?php echo esc_html( $total['label'] ); ?></td><td class="num"><?php echo wp_kses_post( $total['value'] ); ?></td></tr><?php endforeach; ?>
</table></td></tr></table>
<?php include WCIP_PATH . 'templates/shared/code.php'; ?>
</body></html>
