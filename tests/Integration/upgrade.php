<?php
/** Destructive schema fixture: disposable test database only. */
if ('1' !== getenv('WCIP_RUN_UPGRADE_TEST') || !defined('ABSPATH')) { throw new RuntimeException('Explicit disposable-site opt-in required.'); }
global $wpdb;
$table = $wpdb->prefix . 'wc_invoice_print_jobs';
if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}") !== 0) { throw new RuntimeException('Upgrade fixture requires an empty job table.'); }
$charset = $wpdb->get_charset_collate();
$wpdb->query("DROP TABLE {$table}");
$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			trigger_type varchar(20) NOT NULL,
			idempotency_key char(64) NOT NULL,
			template_id varchar(64) NOT NULL,
			provider_id varchar(64) NOT NULL,
			printer_id varchar(191) NOT NULL,
			copies smallint(5) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL,
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			action_id bigint(20) unsigned NULL,
			external_job_id varchar(191) NULL,
			error_code varchar(64) NULL,
			error_message varchar(500) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			started_at datetime NULL,
			completed_at datetime NULL,
			printed_at datetime NULL,
			printed_by bigint(20) unsigned NULL,
			printed_note_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_created (status, created_at),
			KEY order_created (order_id, created_at),
			KEY order_printed (order_id, printed_at),
			KEY trigger_created (trigger_type, created_at),
			KEY action_id (action_id)
		) {$charset};";
$wpdb->query($sql);
$now = current_time('mysql', true);
$wpdb->insert($table, array('order_id'=>987654321, 'trigger_type'=>'automatic', 'idempotency_key'=>str_repeat('a',64), 'template_id'=>'classic','provider_id'=>'browser','printer_id'=>'','copies'=>1,'status'=>'browser_ready','created_at'=>$now,'updated_at'=>$now,'printed_at'=>$now,'printed_by'=>1,'printed_note_id'=>99));
$id = (int)$wpdb->insert_id;
$before = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id), ARRAY_A);
update_option('wcip_db_version','1.1.2'); delete_option('wcip_discovery_since');
if (!WCInvoicePrinter\Infrastructure\Activator::maybe_upgrade()) { throw new RuntimeException('Migration failed.'); }
$after = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$id), ARRAY_A);
if ('invoice' !== $after['document_type']) { throw new RuntimeException('Legacy document type missing.'); }
unset($after['document_type']);
foreach (array('agent_token','agent_user','agent_phase','agent_claimed_at') as $column) {
    if (null !== $after[$column]) { throw new RuntimeException('Legacy row acquired agent ownership.'); }
    unset($after[$column]);
}
if ($after !== $before) { throw new RuntimeException('Legacy job or confirmation changed.'); }
WCInvoicePrinter\Infrastructure\Activator::activate();
if ('1.4.0' !== get_option('wcip_db_version') || (int)get_option('wcip_discovery_since') < time()-60) { throw new RuntimeException('Version/cutoff incorrect.'); }
$wpdb->delete($table,array('id'=>$id));
echo "PASS: populated 1.1.2 migration preserves all job/confirmation fields; default invoice; rerun; no historical discovery\n";
