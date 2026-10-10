<?php
namespace WCInvoicePrinter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCInvoicePrinter\Printing\Cups\CupsProvider;
use WCInvoicePrinter\Printing\Cups\IppCodec;
use WCInvoicePrinter\Printing\ProviderException;
use WCInvoicePrinter\Settings\SettingsRepository;

final class CupsProviderTest extends TestCase {
	private CupsProvider $provider;
	protected function setUp(): void {
		$GLOBALS['wcip_test_options'] = array( 'wcip_settings' => array( 'cups_endpoint' => 'https://cups.example.test', 'cups_username' => 'printer', 'cups_password' => 'secret' ) );
		$GLOBALS['wcip_test_transients'] = array(); unset( $GLOBALS['wcip_last_http'], $GLOBALS['wcip_http_callback'] );
		$this->provider = new CupsProvider( new SettingsRepository() );
		$GLOBALS['wcip_http_callback'] = static function ( $url, $args ) {
			$id = unpack( 'N', substr( $args['body'], 4, 4 ) )[1];
			$attributes = false !== strpos( $url, '/printers/' ) ? "\x02" . IppCodec::attribute( 0x21, 'job-id', pack( 'N', 623 ) ) : "\x04" . IppCodec::attribute( 0x42, 'printer-name', 'Office_A4' ) . IppCodec::attribute( 0x41, 'printer-info', '<b>Office</b>' ) . IppCodec::attribute( 0x23, 'printer-state', pack( 'N', 3 ) );
			return self::reply( $id, 0, $attributes );
		};
	}
	protected function tearDown(): void { unset( $GLOBALS['wcip_http_callback'] ); }
	public static function reply( int $id, int $status, string $attributes = '' ): array {
		return array( 'headers' => array( 'content-type' => 'application/ipp' ), 'response' => array( 'code' => 200 ), 'body' => pack( 'CCnN', 1, 1, $status, $id ) . "\x01" . IppCodec::attribute( 0x47, 'attributes-charset', 'utf-8' ) . $attributes . "\x03" );
	}
	private function failure( callable $operation ): ProviderException { try { $operation(); } catch ( ProviderException $error ) { return $error; } self::fail( 'Expected provider rejection.' ); }
	public function test_binary_pdf_submission_preserves_named_queue_copies_and_authentication(): void {
		self::assertSame( '623', $this->provider->submit( '%PDF-test', 'Office_A4', 2, 'Invoice 42' )->external_job_id );
		[$url,$args] = $GLOBALS['wcip_last_http'];
		self::assertSame( 'https://cups.example.test/printers/Office_A4', $url );
		self::assertStringEndsWith( "\x03%PDF-test", $args['body'] );
		self::assertStringContainsString( IppCodec::attribute( 0x21, 'copies', pack( 'N', 2 ) ), $args['body'] );
		self::assertStringContainsString( 'ipps://cups.example.test/printers/Office_A4', $args['body'] );
		self::assertSame( 'Basic ' . base64_encode( 'printer:secret' ), $args['headers']['Authorization'] );
		self::assertSame( 0, $args['redirection'] ); self::assertTrue( $args['sslverify'] );
	}
	public function test_printer_discovery_cache_is_bound_to_endpoint_and_credentials(): void {
		self::assertSame( array( 'id' => 'Office_A4', 'name' => 'Office_A4', 'description' => 'Office', 'state' => 'online' ), $this->provider->printers()[0] );
		$GLOBALS['wcip_http_callback'] = static fn() => new \WP_Error( 'timeout', 'private secret' );
		self::assertCount( 1, $this->provider->printers() );
		self::assertFalse( $this->failure( fn() => $this->provider->printers( true ) )->ambiguous() );
		(new SettingsRepository())->update( array( 'cups_password' => 'changed' ) );
		self::assertTrue( $this->failure( fn() => $this->provider->printers() )->retryable() );
	}
	public function test_connection_checks_a_real_ipp_discovery_response(): void { self::assertTrue( $this->provider->test_connection()['connected'] ); }
	/** @dataProvider invalid_queues */
	public function test_unsafe_queue_names_never_reach_http( string $queue ): void { self::assertSame( 'invalid_printer', $this->failure( fn() => $this->provider->submit( '%PDF-test', $queue, 1, 'Invoice' ) )->error_code() ); self::assertArrayNotHasKey( 'wcip_last_http', $GLOBALS ); }
	public static function invalid_queues(): array { return array_map( static fn($s)=>array($s), array( '', '../office', 'office/a', "office\n", '-office', 'office?x=1', str_repeat('x',128) ) ); }
	/** @dataProvider invalid_endpoints */
	public function test_unsafe_server_addresses_never_reach_http( string $endpoint ): void { $GLOBALS['wcip_test_options']['wcip_settings']['cups_endpoint']=$endpoint; self::assertSame( 'invalid_endpoint', $this->failure( fn() => $this->provider->test_connection() )->error_code() ); self::assertArrayNotHasKey( 'wcip_last_http', $GLOBALS ); }
	public static function invalid_endpoints(): array { return array_map( static fn($s)=>array($s), array( '', 'http://cups.example.test', 'file:///etc/passwd', 'https://user:password@cups.example.test', 'https://cups.example.test:22', 'https://cups.example.test/admin', 'https://cups.example.test?x=1', 'https://cups.example.test#fragment' ) ); }
	/** @dataProvider http_errors */
	public function test_http_error_does_not_expose_secrets_or_retry_uncertain_prints( int $http, bool $ambiguous, bool $retryable ): void {
		$GLOBALS['wcip_http_callback'] = static fn() => array('response'=>array('code'=>$http),'body'=>'private secret');
		$error=$this->failure(fn()=>$this->provider->submit('%PDF-test','Office_A4',1,'Invoice'));
		self::assertSame($ambiguous,$error->ambiguous()); self::assertSame($retryable,$error->retryable()); self::assertStringNotContainsString('secret',$error->getMessage());
	}
	public static function http_errors(): array { return array(array(0,true,false),array(302,true,false),array(401,false,false),array(403,false,false),array(404,false,false),array(408,true,false),array(429,false,true),array(500,true,false),array(503,true,false)); }
	public function test_transport_timeout_is_ambiguous(): void { $GLOBALS['wcip_http_callback']=static fn()=>new \WP_Error('timeout','secret'); $e=$this->failure(fn()=>$this->provider->submit('%PDF-test','Office_A4',1,'Invoice')); self::assertTrue($e->ambiguous()); self::assertFalse($e->retryable()); }
	/** @dataProvider ipp_errors */
	public function test_ipp_error_and_substitution_status( int $status, bool $ambiguous ): void { $GLOBALS['wcip_http_callback']=static fn($url,$a)=>self::reply(unpack('N',substr($a['body'],4,4))[1],$status); $e=$this->failure(fn()=>$this->provider->submit('%PDF-test','Office_A4',1,'Invoice')); self::assertSame($ambiguous,$e->ambiguous()); self::assertFalse($e->retryable()); }
	public static function ipp_errors(): array { return array(array(1,true),array(0x0401,false),array(0x0406,false),array(0x0500,true),array(0x0507,true)); }
	/** @dataProvider malformed_responses */
	public function test_malformed_success_is_always_uncertain( string $variant ): void {
		$GLOBALS['wcip_http_callback']=static function($url,$a) use($variant) { $id=unpack('N',substr($a['body'],4,4))[1]; $r=self::reply($id,0,"\x02".IppCodec::attribute(0x21,'job-id',pack('N',623))); if('wrong_id'===$variant){$r=self::reply($id+1,0);} if('html'===$variant){$r['body']='<html>secret</html>'; $r['headers']['content-type']='text/html';} if('truncated'===$variant){$r['body']=substr($r['body'],0,-2);} if('zero'===$variant){$r=self::reply($id,0,"\x02".IppCodec::attribute(0x21,'job-id',pack('N',0)));} if('oversize'===$variant){$r['body']=str_repeat('x',2*1024*1024+1);} if('missing_job'===$variant){$r=self::reply($id,0);} return $r; };
		$e=$this->failure(fn()=>$this->provider->submit('%PDF-test','Office_A4',1,'Invoice')); self::assertTrue($e->ambiguous()); self::assertFalse($e->retryable());
	}
	public static function malformed_responses(): array { return array_map(static fn($s)=>array($s),array('wrong_id','html','truncated','zero','oversize','missing_job')); }
	public function test_duplicate_printer_groups_are_not_cached(): void { $GLOBALS['wcip_http_callback']=static fn($url,$a)=>self::reply(unpack('N',substr($a['body'],4,4))[1],0,str_repeat("\x04".IppCodec::attribute(0x42,'printer-name','Office_A4'),2)); self::assertFalse($this->failure(fn()=>$this->provider->printers())->ambiguous()); self::assertSame(array(),$GLOBALS['wcip_test_transients']); }
	public function test_no_document_or_invalid_copies_never_reach_http(): void { self::assertSame('invalid_document',$this->failure(fn()=>$this->provider->submit('not PDF','Office_A4',1,'Invoice'))->error_code()); self::assertSame('invalid_document',$this->failure(fn()=>$this->provider->submit('%PDF-test','Office_A4',21,'Invoice'))->error_code()); self::assertArrayNotHasKey('wcip_last_http',$GLOBALS); }
}
