<?php
/** Explicitly opted-in fixture; preserve confirmed legacy evidence. */
if('1'!==getenv('WCIP_RUN_PRINTING_MIGRATION_TEST')||!defined('WCIP_PATH')){throw new RuntimeException('Disposable-site migration opt-in required.');}
global $wpdb;
$table=$wpdb->prefix.'wc_invoice_print_jobs'; $settings=get_option('wcip_settings'); $stamp=get_option('wcip_printing_migration_version'); $ids=array(); $before=array(); $checks=0;
$check=static function(bool $ok,string $why) use(&$checks):void {++$checks;if(!$ok){throw new RuntimeException($why);}};
try {
 foreach(array('queued','processing','submitted','printed') as $status){
  $data=array('order_id'=>99999999,'trigger_type'=>'automatic','idempotency_key'=>hash('sha256',random_bytes(32)),'template_id'=>'classic','provider_id'=>'printnode','printer_id'=>'123','copies'=>2,'status'=>$status,'created_at'=>current_time('mysql',true),'updated_at'=>current_time('mysql',true));
  if('printed'===$status){$data['printed_at']=current_time('mysql',true);$data['printed_note_id']=98765;}
  $check(false!==$wpdb->insert($table,$data),'Insert legacy fixture');$ids[$status]=(int)$wpdb->insert_id;$before[$status]=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d",$ids[$status]),ARRAY_A);
 }
 update_option('wcip_settings',array('business_name'=>'Preserved identity','automatic_enabled'=>true,'printnode_api_key'=>'retired-disposable-secret','printnode_printer_id'=>'123','printnode_printer_name'=>'Legacy'),false);
 delete_option('wcip_printing_migration_version');set_transient('wcip_printnode_printers_fixture',array('legacy'),600);
 $check(\WCInvoicePrinter\Infrastructure\PrintingMigration::run(),'Migration completed');
 $new=get_option('wcip_settings');$check(!$new['automatic_enabled'],'Old automation disabled');$check('Preserved identity'===$new['business_name'],'Branding preserved');$check(!isset($new['printnode_api_key'],$new['printnode_printer_id'],$new['printnode_printer_name']),'Retired credentials removed');
 foreach($ids as $old=>$id){$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d",$id),ARRAY_A);$expected=array('queued'=>'cancelled','processing'=>'unknown','submitted'=>'submitted','printed'=>'printed')[$old];$check($expected===$row['status'],'Correct state '.$old);$check('printnode'===$row['provider_id'],'No destination remapping');if(in_array($old,array('submitted','printed'),true)){$check($before[$old]===$row,'History unchanged byte-for-byte');}}
 $check('1.3.0'===get_option('wcip_printing_migration_version'),'Separate migration version');$check(\WCInvoicePrinter\Infrastructure\PrintingMigration::run(),'Rerun safe');
 // Direct SQL removes the DB value; persistent cache invalidation is separate.
 $check(null===$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",'_transient_wcip_printnode_printers_fixture')),'Retired database cache purged');
 echo 'PASS: '.$checks." legacy-provider migration checks\n";
} finally {
 foreach($ids as $id){$wpdb->delete($table,array('id'=>$id),array('%d'));} update_option('wcip_settings',$settings,false);update_option('wcip_printing_migration_version',$stamp,false);delete_transient('wcip_printnode_printers_fixture');
}
