<?php
/**
 * Tests that the new classification fields appear in the structured log
 * payloads emitted for slow_query and query_killed events.
 *
 * Strategy: hook the hypercart_query_guard_log_payload filter (registered
 * in the log() method) to capture the payload array before it reaches
 * error_log(). Each test installs the hook, triggers the code path under
 * test, then inspects the captured payload and clears the hook.
 *
 * A ReflectionClass helper resets the private static $last_checked_query_num
 * between kill-detection tests so the idempotency guard does not interfere.
 */
class PayloadFieldsTest extends \PHPUnit\Framework\TestCase {

	/** @var array|null */
	private $captured = null;

	protected function setUp(): void {
		$this->captured = null;
		// Clear any test-specific hook registered by a previous test.
		remove_all_filters( 'hypercart_query_guard_log_payload' );
		// Reset SERVER URI so context detection doesn't bleed across tests.
		$_SERVER['REQUEST_URI'] = '/';
	}

	protected function tearDown(): void {
		// Remove the capture hook and unset the mock $wpdb global.
		remove_all_filters( 'hypercart_query_guard_log_payload' );
		global $wpdb;
		$wpdb = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_SERVER['REQUEST_URI'] = '/';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Install the log-payload capture hook and store the payload for assertion.
	 */
	private function capture_payload() {
		add_filter(
			'hypercart_query_guard_log_payload',
			function ( $payload ) {
				$this->captured = $payload;
				return $payload;
			}
		);
	}

	/**
	 * Reset the private static kill-detection high-water mark to -1.
	 */
	private function reset_kill_counter() {
		$ref  = new ReflectionClass( Hypercart_Query_Guard::class );
		$prop = $ref->getProperty( 'last_checked_query_num' );
		$prop->setAccessible( true );
		$prop->setValue( null, -1 );
	}

	/**
	 * Build a minimal $wpdb mock that looks like a killed query.
	 *
	 * @param string $last_query SQL of the killed query.
	 * @param int    $num_queries Monotonic query counter value.
	 * @return stdClass
	 */
	private function mock_killed_wpdb( $last_query, $num_queries = 1 ) {
		$mock               = new stdClass();
		$mock->last_error   = 'Query execution was interrupted, maximum statement execution time exceeded';
		$mock->last_query   = $last_query;
		$mock->num_queries  = $num_queries;
		return $mock;
	}

	// -----------------------------------------------------------------------
	// query_killed event — classification fields present
	// -----------------------------------------------------------------------

	public function test_kill_payload_has_all_classification_fields() {
		global $wpdb;
		$ids  = implode( ', ', range( 1, 300 ) );
		$wpdb = $this->mock_killed_wpdb(
			"SELECT comment_ID FROM wp_comments WHERE comment_ID IN ({$ids})",
			10
		);
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNotNull( $this->captured, 'log payload was not captured' );
		$expected_keys = array(
			'table_hint',
			'is_comment_query',
			'has_large_in_list',
			'estimated_in_list_size',
			'is_probable_woocommerce',
			'is_probable_order_note_query',
			'is_admin_ajax',
		);
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $this->captured, "Missing key: {$key}" );
		}
	}

	public function test_kill_payload_classifies_large_wp_comments_query() {
		global $wpdb;
		$ids  = implode( ', ', range( 1, 500 ) );
		$wpdb = $this->mock_killed_wpdb(
			"SELECT comment_ID, comment_content FROM wp_comments WHERE comment_ID IN ({$ids})",
			20
		);
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNotNull( $this->captured );
		$this->assertSame( 'query_killed', $this->captured['event'] );
		$this->assertSame( 'wp_comments', $this->captured['table_hint'] );
		$this->assertTrue( $this->captured['is_comment_query'] );
		$this->assertTrue( $this->captured['has_large_in_list'] );
		$this->assertSame( 500, $this->captured['estimated_in_list_size'] );
		$this->assertFalse( $this->captured['is_probable_order_note_query'] );
	}

	public function test_kill_payload_classifies_order_note_query() {
		global $wpdb;
		$ids  = implode( ', ', range( 1, 300 ) );
		$wpdb = $this->mock_killed_wpdb(
			"SELECT * FROM wp_comments WHERE comment_type = 'order_note' AND comment_ID IN ({$ids})",
			30
		);
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNotNull( $this->captured );
		$this->assertTrue( $this->captured['is_probable_order_note_query'] );
		$this->assertTrue( $this->captured['is_probable_woocommerce'] );
		$this->assertTrue( $this->captured['is_comment_query'] );
	}

	public function test_kill_payload_is_admin_ajax_true_for_ajax_uri() {
		global $wpdb;
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=woocommerce_load_order_notes';
		$ids  = implode( ', ', range( 1, 300 ) );
		$wpdb = $this->mock_killed_wpdb(
			"SELECT * FROM wp_comments WHERE comment_ID IN ({$ids})",
			40
		);
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNotNull( $this->captured );
		$this->assertTrue( $this->captured['is_admin_ajax'] );
		$this->assertSame( '/wp-admin/admin-ajax.php?action=woocommerce_load_order_notes', $this->captured['uri'] );
	}

	public function test_kill_payload_is_admin_ajax_false_for_non_ajax_uri() {
		global $wpdb;
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=shop_order';
		$ids  = implode( ', ', range( 1, 300 ) );
		$wpdb = $this->mock_killed_wpdb(
			"SELECT * FROM wp_comments WHERE comment_ID IN ({$ids})",
			50
		);
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNotNull( $this->captured );
		$this->assertFalse( $this->captured['is_admin_ajax'] );
	}

	public function test_kill_payload_clean_query_no_large_in_or_comments_flags() {
		global $wpdb;
		$wpdb = $this->mock_killed_wpdb( 'SELECT ID FROM wp_posts WHERE post_status = \'publish\'', 60 );
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNotNull( $this->captured );
		$this->assertSame( '', $this->captured['table_hint'] );
		$this->assertFalse( $this->captured['is_comment_query'] );
		$this->assertFalse( $this->captured['has_large_in_list'] );
		$this->assertSame( 0, $this->captured['estimated_in_list_size'] );
		$this->assertFalse( $this->captured['is_probable_woocommerce'] );
		$this->assertFalse( $this->captured['is_probable_order_note_query'] );
	}

	public function test_non_kill_error_does_not_emit_payload() {
		global $wpdb;
		$mock              = new stdClass();
		$mock->last_error  = 'Table wp_comments does not exist';
		$mock->last_query  = 'SELECT * FROM wp_comments';
		$mock->num_queries = 70;
		$wpdb              = $mock;
		$this->reset_kill_counter();
		$this->capture_payload();

		Hypercart_Query_Guard::detect_and_log_kill();

		$this->assertNull( $this->captured, 'Non-kill error should not emit a payload' );
	}

	// -----------------------------------------------------------------------
	// slow_query event — classification fields present
	// -----------------------------------------------------------------------

	public function test_slow_query_payload_has_classification_fields() {
		global $wpdb;
		$ids       = implode( ', ', range( 1, 300 ) );
		$wpdb      = new stdClass();
		$wpdb->queries = array(
			array(
				"SELECT comment_ID FROM wp_comments WHERE comment_type = 'order_note' AND comment_ID IN ({$ids})",
				6.5, // 6.5 s > WARN_THRESHOLD_MS (5 s)
				'WC_Order_Notes::get_notes',
			),
		);
		$this->capture_payload();

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertNotNull( $this->captured, 'slow_query payload was not captured' );
		$this->assertSame( 'slow_query', $this->captured['event'] );

		foreach ( array( 'table_hint', 'is_comment_query', 'has_large_in_list', 'estimated_in_list_size', 'is_probable_woocommerce', 'is_probable_order_note_query', 'is_admin_ajax' ) as $key ) {
			$this->assertArrayHasKey( $key, $this->captured, "Missing key: {$key}" );
		}

		$this->assertSame( 'wp_comments', $this->captured['table_hint'] );
		$this->assertTrue( $this->captured['is_comment_query'] );
		$this->assertTrue( $this->captured['has_large_in_list'] );
		$this->assertTrue( $this->captured['is_probable_order_note_query'] );
		$this->assertTrue( $this->captured['is_probable_woocommerce'] );
	}

	public function test_slow_query_payload_is_admin_ajax_for_ajax_uri() {
		global $wpdb;
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php?action=woocommerce_load_order_notes';
		$wpdb                   = new stdClass();
		$wpdb->queries          = array(
			array( 'SELECT ID FROM wp_posts WHERE post_status = \'publish\'', 7.0, 'SomeClass::method' ),
		);
		$this->capture_payload();

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertNotNull( $this->captured );
		$this->assertTrue( $this->captured['is_admin_ajax'] );
	}

	public function test_slow_query_below_threshold_not_emitted() {
		global $wpdb;
		$wpdb          = new stdClass();
		$wpdb->queries = array(
			array( 'SELECT ID FROM wp_posts WHERE post_status = \'publish\'', 1.5, 'SomeClass::method' ),
		);
		$this->capture_payload();

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertNull( $this->captured, 'Sub-threshold query must not produce a payload' );
	}

	public function test_slow_query_normal_select_flags_are_false() {
		global $wpdb;
		$wpdb          = new stdClass();
		$wpdb->queries = array(
			array( 'SELECT ID FROM wp_posts WHERE post_status = \'publish\'', 6.0, 'SomeClass::method' ),
		);
		$this->capture_payload();

		Hypercart_Query_Guard::log_slow_queries();

		$this->assertNotNull( $this->captured );
		$this->assertSame( '', $this->captured['table_hint'] );
		$this->assertFalse( $this->captured['is_comment_query'] );
		$this->assertFalse( $this->captured['has_large_in_list'] );
		$this->assertFalse( $this->captured['is_probable_woocommerce'] );
		$this->assertFalse( $this->captured['is_probable_order_note_query'] );
	}
}
