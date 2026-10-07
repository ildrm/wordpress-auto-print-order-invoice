<?php

namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Exercise the real Activator with a controllable dbDelta/WordPress boundary. */
final class SchemaInstallationTest extends TestCase {
	private string $site_path;

	protected function setUp(): void {
		$this->site_path = sys_get_temp_dir() . '/wcip-schema-test-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->site_path . '/wp-admin/includes', 0700, true );
		file_put_contents( $this->site_path . '/wp-admin/includes/upgrade.php', '<?php function dbDelta( string $sql ): array { global $wpdb; $wpdb->query( $sql ); return array(); }' );
	}

	protected function tearDown(): void {
		unlink( $this->site_path . '/wp-admin/includes/upgrade.php' );
		rmdir( $this->site_path . '/wp-admin/includes' );
		rmdir( $this->site_path . '/wp-admin' );
		rmdir( $this->site_path );
	}

	private function run_case( string $scenario ): array {
		$script = <<<'PHP'
define( 'ABSPATH', $argv[1] . '/' );
require $argv[2];
$scenario = $argv[3];
$options = array();
function get_option( string $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function update_option( string $key, $value, $autoload = null ): bool { global $options; $options[$key] = $value; return true; }
function esc_html__( string $message, string $domain ): string { return $message; }
function wp_die( string $message, string $title, array $args ) { throw new RuntimeException( $message, $args['response'] ); }
class TestRole {
    public array $caps = array();
    public int $writes = 0;
    public function has_cap( string $cap ): bool { return $this->caps[$cap] ?? false; }
    public function add_cap( string $cap ): void { $this->caps[$cap] = true; ++$this->writes; }
}
$roles = array( 'administrator' => new TestRole(), 'shop_manager' => new TestRole() );
function get_role( string $name ) { global $roles; return $roles[$name] ?? null; }
class TestDatabase {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $exists = false;
    public string $mode = '';
    public array $ddl = array();
    public function get_charset_collate(): string { return ''; }
    public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
    public function prepare( string $sql, string $value ): string { return str_replace( '%s', "'" . $value . "'", $sql ); }
    public function get_var( string $sql ): ?string { $this->last_error = ''; return $this->exists ? 'wp_wc_invoice_print_jobs' : null; }
    public function query( string $sql ): bool {
        $this->ddl[] = $sql;
        if ( 'thrown_failure' === $this->mode ) { throw new RuntimeException( 'CREATE failed' ); }
        if ( in_array( $this->mode, array( 'create_failure', 'activation_failure', 'alter_failure' ), true ) ) { $this->last_error = 'CREATE/ALTER denied'; return false; }
        if ( 'silent_failure' === $this->mode ) { return false; }
        $this->exists = true;
        $this->last_error = '';
        return true;
    }
}
$wpdb = new TestDatabase();
$wpdb->mode = $scenario;
if ( 'alter_failure' === $scenario ) { $wpdb->exists = true; $options['wcip_db_version'] = '0.9.0'; }
if ( 'lost_table' === $scenario ) { $options['wcip_db_version'] = '1.0.0'; }
if ( 'missing_role' === $scenario ) { unset( $roles['shop_manager'] ); }
if ( 'already_provisioned' === $scenario ) {
    $roles['administrator']->caps = array( 'wcip_print_invoices' => true, 'wcip_view_print_jobs' => true, 'wcip_manage_settings' => true );
    $roles['shop_manager']->caps = array( 'wcip_print_invoices' => true, 'wcip_view_print_jobs' => true );
}
$exception = null;
try {
    if ( 'activation_failure' === $scenario ) { \WCInvoicePrinter\Infrastructure\Activator::activate(); $ready = true; }
    else { $ready = \WCInvoicePrinter\Infrastructure\Activator::maybe_upgrade(); }
} catch ( Throwable $error ) { $ready = false; $exception = array( 'code' => $error->getCode(), 'message' => $error->getMessage() ); }
$first_options = $options;
if ( 'missing_role' === $scenario ) { $roles['shop_manager'] = new TestRole(); $ready = \WCInvoicePrinter\Infrastructure\Activator::maybe_upgrade(); }
if ( 'already_provisioned' === $scenario ) { $ready = \WCInvoicePrinter\Infrastructure\Activator::maybe_upgrade(); }
echo json_encode( array( 'ready' => $ready, 'options' => $options, 'first_options' => $first_options, 'exists' => $wpdb->exists, 'ddl' => $wpdb->ddl, 'roles' => $roles, 'exception' => $exception ) );
PHP;
		$process = proc_open( array( PHP_BINARY, '-r', $script, $this->site_path, WCIP_PATH . 'src/Infrastructure/Activator.php', $scenario ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * @dataProvider failed_schema_cases
	 */
	public function test_failed_schema_installation_does_not_mark_version_current( string $scenario ): void {
		$result = $this->run_case( $scenario );
		self::assertFalse( $result['ready'] );
		self::assertSame( 'alter_failure' === $scenario ? '0.9.0' : null, $result['options']['wcip_db_version'] ?? null );
		self::assertArrayNotHasKey( 'wcip_capabilities_version', $result['options'] );
		self::assertCount( 1, $result['ddl'] );
	}

	public static function failed_schema_cases(): array {
		return array( array( 'create_failure' ), array( 'silent_failure' ), array( 'alter_failure' ), array( 'thrown_failure' ) );
	}

	public function test_activation_reports_clear_failure_and_does_not_add_capabilities(): void {
		$result = $this->run_case( 'activation_failure' );
		self::assertSame( 500, $result['exception']['code'] );
		self::assertStringContainsString( 'database permissions', $result['exception']['message'] );
		self::assertSame( array(), $result['options'] );
		self::assertSame( 0, $result['roles']['administrator']['writes'] );
	}

	public function test_successful_installation_marks_schema_and_capabilities_current(): void {
		$result = $this->run_case( 'success' );
		self::assertTrue( $result['ready'] );
		self::assertTrue( $result['exists'] );
		self::assertSame( '1.0.0', $result['options']['wcip_db_version'] );
		self::assertSame( '1.0.0', $result['options']['wcip_capabilities_version'] );
	}

	public function test_missing_table_is_repaired_even_if_a_previous_install_stamped_version(): void {
		$result = $this->run_case( 'lost_table' );
		self::assertTrue( $result['ready'] );
		self::assertTrue( $result['exists'] );
		self::assertCount( 1, $result['ddl'] );
	}

	public function test_missing_shop_manager_role_is_provisioned_on_a_later_request(): void {
		$result = $this->run_case( 'missing_role' );
		self::assertArrayNotHasKey( 'wcip_capabilities_version', $result['first_options'] );
		self::assertSame( '1.0.0', $result['options']['wcip_capabilities_version'] );
		self::assertTrue( $result['roles']['shop_manager']['caps']['wcip_print_invoices'] );
		self::assertTrue( $result['roles']['shop_manager']['caps']['wcip_view_print_jobs'] );
		self::assertCount( 1, $result['ddl'] );
		self::assertSame( 3, $result['roles']['administrator']['writes'] );
	}

	public function test_existing_capabilities_are_not_written_again(): void {
		$result = $this->run_case( 'already_provisioned' );
		self::assertTrue( $result['ready'] );
		self::assertSame( 0, $result['roles']['administrator']['writes'] );
		self::assertSame( 0, $result['roles']['shop_manager']['writes'] );
		self::assertCount( 1, $result['ddl'] );
	}
}
