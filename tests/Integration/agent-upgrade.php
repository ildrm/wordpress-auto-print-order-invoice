<?php
/** Destructive 1.3.0 CUPS schema fixture: explicit disposable-site opt-in only. */
global $wpdb;
if ('1' !== getenv('WCIP_RUN_UPGRADE_TEST') || !defined('ABSPATH')) { throw new RuntimeException('Explicit disposable-site opt-in required.'); }
$table=$wpdb->prefix.'wc_invoice_print_jobs';
if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}")) { throw new RuntimeException('Empty disposable job table required.'); }
$settings=new \WCInvoicePrinter\Settings\SettingsRepository();
$settings->update(array('cups_endpoint'=>'https://print.example.org:631','cups_username'=>'fixture','cups_password'=>'disposable-fixture','cups_printer_id'=>'Office_A4','cups_printer_name'=>'Existing queue','automatic_enabled'=>true));
$legacy_settings=get_option('wcip_settings');
foreach (array('automatic_provider','agent_queue','agent_user_id') as $key) { unset($legacy_settings[$key]); }
update_option('wcip_settings',$legacy_settings,false);
$before_settings=get_option('wcip_settings');
$cutoff=get_option('wcip_discovery_since');
$now=current_time('mysql',true);
$row=array('order_id'=>987654321,'document_type'=>'invoice','trigger_type'=>'automatic','idempotency_key'=>str_repeat('b',64),'template_id'=>'classic','provider_id'=>'cups','printer_id'=>'Office_A4','copies'=>2,'status'=>'submitted','created_at'=>$now,'updated_at'=>$now,'printed_at'=>$now,'printed_by'=>1,'printed_note_id'=>99);
if (!$wpdb->insert($table,$row)) { throw new RuntimeException('Could not create fixture.'); }
$id=(int)$wpdb->insert_id;
$wpdb->query("ALTER TABLE {$table} DROP INDEX agent_queue, DROP COLUMN agent_token, DROP COLUMN agent_user, DROP COLUMN agent_phase, DROP COLUMN agent_claimed_at");
$before=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id),ARRAY_A);
update_option('wcip_db_version','1.2.0'); update_option('wcip_capabilities_version','1.2.0'); remove_role('wcip_print_agent');
if (!\WCInvoicePrinter\Infrastructure\Activator::maybe_upgrade()) { throw new RuntimeException('1.3.0 CUPS upgrade failed.'); }
$after=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id),ARRAY_A);
foreach(array('agent_token','agent_user','agent_phase','agent_claimed_at') as $column) { if (null !== $after[$column]) { throw new RuntimeException('Historical job acquired agent ownership.'); } unset($after[$column]); }
if ($before !== $after || $before_settings !== get_option('wcip_settings') || $cutoff !== get_option('wcip_discovery_since')) { throw new RuntimeException('CUPS configuration/history/cutoff changed.'); }
if ('1.4.0' !== get_option('wcip_db_version') || '1.4.0' !== get_option('wcip_capabilities_version') || '1.3.0' !== get_option('wcip_printing_migration_version') || !get_role('wcip_print_agent')) { throw new RuntimeException('Upgrade stamp/role failed.'); }
\WCInvoicePrinter\Infrastructure\Activator::activate();
if ($before_settings !== get_option('wcip_settings')) { throw new RuntimeException('Rerun changed CUPS setup.'); }
$wpdb->delete($table,array('id'=>$id));
$settings->update(array('automatic_enabled'=>false));
echo "PASS: populated 1.3.0 CUPS upgrade preserves all historical job/confirmation values, CUPS settings and discovery cutoff; adds nullable agent columns, schema/capability stamps and role; rerun safe\n";
