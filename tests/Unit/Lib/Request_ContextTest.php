<?php

declare( strict_types=1 );

namespace WODO_Bridge\Tests\Unit\Lib;

use WODO_Bridge\Lib\Request_Context;
use WODO_Bridge\Tests\TestCase;

/**
 * @covers \WODO_Bridge\Lib\Request_Context
 */
final class Request_ContextTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		unset( $_SERVER['REMOTE_ADDR'] );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_ip_returns_remote_addr_when_no_proxy_trust(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.42';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';

		// WB_TRUST_PROXY is NOT defined in this environment.
		$ctx = Request_Context::ip();

		$this->assertSame( '203.0.113.42', $ctx['ip'], 'must ignore XFF without WB_TRUST_PROXY' );
		$this->assertFalse( $ctx['proxy_trusted'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ip_honors_xff_first_hop_when_wb_trust_proxy_is_true(): void {
		// Constants can only be defined once per process; isolate this case.
		if ( ! defined( 'WB_TRUST_PROXY' ) ) {
			define( 'WB_TRUST_PROXY', true );
		}

		// Plugin under test must be available in the isolated subprocess.
		require_once dirname( __DIR__, 3 ) . '/wodo-bridge.php';

		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.1';

		$ctx = Request_Context::ip();

		$this->assertSame( '198.51.100.7', $ctx['ip'], 'should pick first XFF hop' );
		$this->assertTrue( $ctx['proxy_trusted'] );
	}

	public function test_ip_falls_back_to_remote_addr_for_invalid_xff_first_hop(): void {
		// Without WB_TRUST_PROXY trust is off; XFF must be ignored entirely.
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';

		$ctx = Request_Context::ip();

		$this->assertSame( '10.0.0.5', $ctx['ip'] );
	}

	public function test_user_agent_is_sanitized_and_truncated(): void {
		$_SERVER['HTTP_USER_AGENT'] = '<script>x</script>' . str_repeat( 'A', 600 );

		$ua = Request_Context::user_agent();

		$this->assertLessThanOrEqual( 512, strlen( $ua ) );
		$this->assertStringNotContainsString( '<script>', $ua );
	}

	public function test_user_agent_returns_empty_string_when_unset(): void {
		$this->assertSame( '', Request_Context::user_agent() );
	}

	public function test_is_https_true_when_wb_allow_insecure_defined(): void {
		// WB_ALLOW_INSECURE = true is set in phpunit.xml.dist, so dev/local
		// behaviour is exercised here.
		$this->assertTrue( Request_Context::is_https() );
	}
}
