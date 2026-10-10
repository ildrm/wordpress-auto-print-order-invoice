<?php
/** Separate-process race fixture for an isolated, cron-disabled test site. */
if ( '1' !== getenv( 'WCIP_RUN_CONCURRENCY_TEST' ) || ! defined( 'ABSPATH' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) { throw new RuntimeException( 'Disposable cron-disabled site required.' ); }
use WCInvoicePrinter\Settings\SettingsRepository;
use WCInvoicePrinter\PrintJob\PrintJobRepository;
use WCInvoicePrinter\PrintJob\PrintJobService;
use WCInvoicePrinter\Automation\Scheduler;
use WCInvoicePrinter\Template\TemplateRegistry;
use WCInvoicePrinter\Automation\AutomaticPrintHandler;
use WCInvoicePrinter\Automation\PaidOrderReconciler;
use WCInvoicePrinter\PrintJob\PrintConfirmationService;
add_filter('pre_wp_mail', static function () { return true; });
$mode = getenv('WCIP_RACE_MODE'); $settings = new SettingsRepository(); $jobs = new PrintJobRepository();
$service = new PrintJobService($jobs,new Scheduler($jobs),$settings,new TemplateRegistry());
if ('prepare' === $mode) {
 if (get_option('wcip_race_fixture')) { throw new RuntimeException('Existing fixture needs cleanup.'); }
 $saved = array('settings'=>get_option('wcip_settings'),'since'=>get_option('wcip_discovery_since'),'cursor'=>get_option('wcip_reconciliation_state'));
 $settings->update(array('automatic_enabled'=>true,'cups_endpoint'=>'https://cups.example.test','cups_printer_id'=>'7','fulfillment_on_confirmation'=>true,'fulfillment_confirmation_stage'=>'preparing'));
 update_option('wcip_discovery_since',time()-60); delete_option('wcip_reconciliation_state');
 $o=wc_create_order(); $o->set_payment_method('fixture-online'); $o->set_status('processing'); $o->set_date_paid(time()); $o->save(); $saved['order']=$o->get_id(); update_option('wcip_race_fixture',$saved,false); echo "Race fixture prepared\n";
} else {
 $f=get_option('wcip_race_fixture'); if (!$f) { throw new RuntimeException('Missing fixture.'); }
 if ('event' === $mode) { (new AutomaticPrintHandler($service))->payment_complete($f['order']); }
 elseif ('reconcile' === $mode) { (new PaidOrderReconciler($settings,$service,$jobs))->run(); }
 elseif ('verify' === $mode) {
  global $wpdb; $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_invoice_print_jobs WHERE order_id=%d AND trigger_type='automatic'",$f['order']));
  if (1!==$count) { throw new RuntimeException('Duplicate automatic jobs.'); }
  $j=$jobs->latest_for_order($f['order'],'automatic'); $actions=as_get_scheduled_actions(array('hook'=>Scheduler::HOOK,'args'=>array('job_id'=>(int)$j['id']),'status'=>'pending','per_page'=>100),'ids');
  if (1!==count($actions)) { throw new RuntimeException('Expected one pending action; got '.count($actions)); }
  $o=wc_get_order($f['order']); $j=$service->create_manual($o,'classic','browser','',1);$jobs->browser_ready((int)$j['id']);$f['manual']=(int)$j['id'];update_option('wcip_race_fixture',$f,false);echo "PASS: three separate processes converged to one automatic job and one queue action\n";
 } elseif ('confirm' === $mode) { $admin=get_users(array('role'=>'administrator','number'=>1))[0];wp_set_current_user($admin->ID);(new PrintConfirmationService($jobs))->confirm($f['manual']); }
 elseif ('verify_confirmation' === $mode) {
  global $wpdb; $events=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wcip_fulfillment_events WHERE event_key=%s",'confirmation:'.$f['manual']));
  $j=$jobs->find($f['manual']);$notes=wc_get_order_notes(array('order_id'=>$f['order'],'limit'=>100));$count=0;foreach($notes as $n){if ((int)$n->id===(int)$j['printed_note_id']){++$count;}}
  if (1!==$events || 1!==$count || !$j['printed_at']) { throw new RuntimeException('Confirmation race failed.'); }echo "PASS: concurrent confirmations preserved one note and one warehouse event\n";
 } elseif ('cleanup' === $mode) {
  global $wpdb;foreach(array('wc_invoice_print_jobs','wcip_document_references','wcip_fulfillment','wcip_fulfillment_events','wcip_export_outbox') as $t){$wpdb->delete($wpdb->prefix.$t,array('order_id'=>$f['order']));}
  $o=wc_get_order($f['order']);if($o){$o->delete(true);}update_option('wcip_settings',$f['settings']);update_option('wcip_discovery_since',$f['since']);update_option('wcip_reconciliation_state',$f['cursor']);delete_option('wcip_race_fixture');as_unschedule_all_actions(Scheduler::HOOK,array(),Scheduler::GROUP);echo "Fixture removed\n";
 }
}
