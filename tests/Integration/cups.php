<?php
/** wp eval-file tests/Integration/cups.php on an isolated HTTPS CUPS fixture. */
if ( ! defined( 'WCIP_PATH' ) || '1' !== getenv( 'WCIP_RUN_CUPS_TEST' ) ) { throw new RuntimeException( 'Requires an active plugin and explicit isolated CUPS test opt-in.' ); }
use WCInvoicePrinter\Printing\Cups\CupsProvider;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Settings\SettingsRepository;
define( 'WCIP_CUPS_TRUSTED_HOSTS', array( 'wordpress' ) );
define( 'WCIP_CUPS_CA_BUNDLE', '/tmp/wcip-cups-ca.pem' );
$original = get_option( 'wcip_settings' ); $checks=0;
$check=static function(bool $ok,string $message) use(&$checks):void { ++$checks; if(!$ok){throw new RuntimeException($message);} };
try {
	$s=new SettingsRepository(); $s->update(array('cups_endpoint'=>'https://wordpress:631','cups_username'=>'wcip-cups-test','cups_password'=>'wcip-cups-disposable'));
	$p=new CupsProvider($s); $check($p->test_connection()['connected'],'CUPS connection');
	$queues=$p->printers(true); $check(in_array('WCIP_Virtual',array_column($queues,'id'),true),'Named queue discovery');
	$t=(new \WCInvoicePrinter\Template\TemplateRegistry())->get('classic');
	$html=(new \WCInvoicePrinter\Template\HtmlRenderer(new \WCInvoicePrinter\Template\TemplateRegistry()))->render((new \WCInvoicePrinter\Invoice\InvoiceFactory($s))->sample(false),'classic');
	$pdf=(new \WCInvoicePrinter\Pdf\MpdfRenderer())->render($html,$t);
	$r=$p->submit($pdf,'WCIP_Virtual',2,'WCIP real HTTPS IPP fixture'); $check(ctype_digit($r->external_job_id),'CUPS accepted real generated PDF');
	$check($s->cups_password()==='wcip-cups-disposable','Password retained server-side');
	$s->update(array('cups_password'=>'deliberately-wrong')); try { $p->submit($pdf,'WCIP_Virtual',1,'Rejected fixture'); throw new RuntimeException('Bad password accepted'); } catch(ProviderException $e){$check(!$e->ambiguous()&&!$e->retryable(),'Authentication failure definitive');}
	$s->update(array('cups_password'=>'wcip-cups-disposable')); try {$p->submit($pdf,'Missing_Queue',1,'Rejected fixture');throw new RuntimeException('Missing queue accepted');} catch(ProviderException $e){$check(!$e->ambiguous(),'Missing queue definitive');}
	// Without deployment trust, the same private origin must be rejected by WP.
	$blocked=wp_safe_remote_request('https://wordpress:631/',array('method'=>'POST','timeout'=>2)); $check(is_wp_error($blocked),'WP public-address guard blocks private CUPS by default');
	echo 'PASS: real CUPS HTTPS discovery/auth/PDF/multi-copy acceptance and private-host guard; '.$checks.' assertions; CUPS job '.$r->external_job_id."\n";
} finally {update_option('wcip_settings',$original,false);}
