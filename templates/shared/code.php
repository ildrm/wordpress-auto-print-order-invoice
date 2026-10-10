<?php
defined( 'ABSPATH' ) || exit;
$code = $invoice->fulfillment['document_codes'][ $template->document_type ] ?? array();
if ( '58mm' === $template->paper_size && 'Code128' === ( $code['type'] ?? '' ) ) { $code['image'] = ''; }
if ( ! empty( $code['image'] ) ) : ?>
<div style="margin-top:10mm;text-align:center;direction:ltr;page-break-inside:avoid"><img src="<?php echo esc_attr( $code['image'] ); ?>" style="width:<?php echo 'QR' === ( $code['type'] ?? '' ) ? '25' : '68'; ?>mm;height:auto" alt="<?php echo esc_attr( $code['reference'] ); ?>"><br><bdi dir="ltr"><?php echo esc_html( $code['reference'] ); ?></bdi></div>
<?php elseif ( ! empty( $code['reference'] ) ) : ?><p style="direction:ltr;text-align:center"><bdi dir="ltr"><?php echo esc_html( $code['reference'] ); ?></bdi></p><?php endif; ?>
