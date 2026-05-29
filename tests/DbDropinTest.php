<?php
/**
 * Tests for the v2 db.php drop-in coordination with the mu-plugin.
 *
 * Verifies the three integration points between HCQG_DB and
 * Hypercart_Query_Guard: dropin_active() detection, log_slow_queries()
 * routing, and apply_session_timeout() delegation.
 *
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

class DbDropinTest extends TestCase {

	/** @var ReflectionMethod */
	private static $dropin_active;

	public static function setUpBeforeClass(): void {
		$ref = new ReflectionMethod( 'Hypercart_Query_Guard', 'dropin_active' );
		$ref->setAccessible( true );
		self::$dropin_active = $ref;
	}

	protected function setUp(): void {
		WP_Stub_State::reset();
		$GLOBALS['wpdb']->reset();
		Hypercart_Logger::reset();
	}

	// -- dropin_active() detection -------------------------------------------

	public function test_dropin_active_false_by_default(): void {
		$this->assertFalse( self::$dropin_active->invoke( null ) );
	}

	public function test_dropin_active_true_when_hcqg_methods_present_and_active(): void {
		$GLOBALS['wpdb']->hcqg_set_active( true );
		$this->assertTrue( self::$dropin_active->invoke( null ) );
	}

	public function test_dropin_active_false_when_methods_present_but_inactive(): void {
		$GLOBALS['wpdb']->hcqg_set_active( false );
		$this->assertFalse( self::$dropin_active->invoke( null ) );
	}

	// -- log_slow_queries() v2 codepath --------------------------------------

	public function test_log_slow_queries_reads_hcqg_slow_queries_when_dropin_active(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );

		$wpdb->hcqg_slow_queries = array(
			array(
				'SELECT * FROM wp_posts WHERE ID = 1',
				6.5, // 6500ms > 5000ms threshold
				'test_caller → some_function',
				microtime( true ) - 6.5,
				array(),
			),
		);

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertCount( 1, Hypercart_Logger::$calls );
		$this->assertSame( 'warning', Hypercart_Logger::$calls[0]['level'] );
		$this->assertSame( 'slow_query', Hypercart_Logger::$calls[0]['payload']['event'] );
		$this->assertSame( 6500, Hypercart_Logger::$calls[0]['payload']['duration_ms'] );
	}

	public function test_log_slow_queries_skips_below_threshold_from_dropin(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );

		$wpdb->hcqg_slow_queries = array(
			array( 'SELECT 1', 3.0, 'caller', microtime( true ), array() ),
		);

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertEmpty( Hypercart_Logger::$calls );
	}

	public function test_log_slow_queries_returns_early_when_dropin_buffer_empty(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );
		$wpdb->hcqg_slow_queries = array();

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertEmpty( Hypercart_Logger::$calls );
	}

	public function test_log_slow_queries_ignores_hcqg_buffer_without_dropin(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( false );
		$wpdb->hcqg_slow_queries = array(
			array( 'SELECT should_be_ignored', 7.0, 'caller', microtime( true ), array() ),
		);

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertEmpty( Hypercart_Logger::$calls );
	}

	public function test_log_slow_queries_logs_multiple_slow_queries(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );

		$wpdb->hcqg_slow_queries = array(
			array( 'SELECT * FROM wp_posts', 5.5, 'caller_a', microtime( true ), array() ),
			array( 'SELECT * FROM wp_options', 8.2, 'caller_b', microtime( true ), array() ),
		);

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertCount( 2, Hypercart_Logger::$calls );
		$this->assertSame( 5500, Hypercart_Logger::$calls[0]['payload']['duration_ms'] );
		$this->assertSame( 8200, Hypercart_Logger::$calls[1]['payload']['duration_ms'] );
	}

	public function test_log_slow_queries_truncates_long_sql(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );

		$long_query = str_repeat( 'X', 1000 );
		$wpdb->hcqg_slow_queries = array(
			array( $long_query, 6.0, 'caller', microtime( true ), array() ),
		);

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertCount( 1, Hypercart_Logger::$calls );
		$logged_query = Hypercart_Logger::$calls[0]['payload']['query'];
		$this->assertLessThanOrEqual( 520, strlen( $logged_query ) );
		$this->assertStringContainsString( 'truncated', $logged_query );
	}

	// -- apply_session_timeout() v2 codepath ---------------------------------

	public function test_apply_session_timeout_uses_hcqg_update_limit_when_dropin_active(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );
		$wpdb->dbh = new stdClass();

		Hypercart_Query_Guard::apply_session_timeout();

		$this->assertSame( 30000, $wpdb->hcqg_last_limit );
	}

	public function test_apply_session_timeout_clears_limit_for_unlimited_context_when_dropin_active(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( true );
		$wpdb->dbh = new stdClass();

		add_filter(
			'hypercart_query_guard_limit_ms',
			static function () {
				return 0;
			}
		);

		Hypercart_Query_Guard::apply_session_timeout();

		$this->assertSame( 0, $wpdb->hcqg_last_limit );
	}

	public function test_apply_session_timeout_v1_fallback_without_dropin(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->hcqg_set_active( false );
		$wpdb->dbh = new stdClass();

		Hypercart_Query_Guard::apply_session_timeout();

		$this->assertNotEmpty( $wpdb->queries );
		$this->assertStringContainsString( 'MAX_EXECUTION_TIME', $wpdb->queries[0] );
	}
}
