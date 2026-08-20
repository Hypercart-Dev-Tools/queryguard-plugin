<?php
/**
 * Cart Type Diagnostic tests.
 *
 * Covers corruption classification (field, safe value rendering, origin
 * attribution), the per-process log budget and signature de-duplication,
 * hook callback enumeration, and the shutdown fatal capture — all against
 * the stub bootstrap. Integration gaps (real WC_Cart objects, live hook
 * dispatch ordering, the actual shutdown sequence) are exercised manually
 * on the Local site; see CHANGELOG 1.3.0.
 *
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

/**
 * Minimal stand-in for WC_Cart: get_cart() + get_applied_coupons().
 */
class HCQG_Fake_Cart {
	/** @var array */
	public $items;
	/** @var array */
	public $coupons;

	public function __construct( array $items, array $coupons = array() ) {
		$this->items   = $items;
		$this->coupons = $coupons;
	}

	public function get_cart() {
		return $this->items;
	}

	public function get_applied_coupons() {
		return $this->coupons;
	}
}

/**
 * Minimal stand-in for WC_Product: get_price() + get_type().
 */
class HCQG_Fake_Product {
	/** @var mixed */
	private $price;

	public function __construct( $price ) {
		$this->price = $price;
	}

	public function get_price( $context = 'view' ) {
		return $this->price;
	}

	public function get_type() {
		return 'simple';
	}
}

/**
 * Product whose price read throws, like a broken third-party price filter.
 */
class HCQG_Throwing_Product {
	public function get_price( $context = 'view' ) {
		throw new RuntimeException( 'broken price filter' );
	}

	public function get_type() {
		return 'simple';
	}
}

final class CartDiagTest extends TestCase {

	/** @var array[] All log payloads captured during the test. */
	private $captured = array();

	protected function setUp(): void {
		parent::setUp();
		WP_Stub_State::reset();
		Hypercart_Logger::reset();
		$this->captured = array();
		$this->reset_diag_state();
		unset( $GLOBALS['wp_filter'] );

		add_filter(
			'hypercart_query_guard_log_payload',
			function ( $payload ) {
				$this->captured[] = $payload;
				return $payload;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_filter'] );
		parent::tearDown();
	}

	/**
	 * Reset the diagnostic's private static state between tests.
	 */
	private function reset_diag_state() {
		$ref = new ReflectionClass( Hypercart_Query_Guard::class );
		foreach ( array(
			'cart_diag_snapshot'    => array(),
			'cart_diag_log_count'   => 0,
			'cart_diag_logged_sigs' => array(),
			'cart_diag_in_progress' => false,
		) as $prop => $value ) {
			$p = $ref->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, $value );
		}
	}

	/**
	 * Build a well-formed cart item row.
	 *
	 * @param mixed $quantity Quantity value (any shape under test).
	 * @param mixed $price    Product price.
	 * @param array $extra    Extra keys merged into the row.
	 * @return array
	 */
	private function item( $quantity, $price = 10.0, $extra = array() ) {
		return array_merge(
			array(
				'quantity'     => $quantity,
				'product_id'   => 123,
				'variation_id' => 0,
				'data'         => new HCQG_Fake_Product( $price ),
			),
			$extra
		);
	}

	/**
	 * @return array[] Captured cart_type_corruption payloads.
	 */
	private function corruption_events() {
		return array_values(
			array_filter(
				$this->captured,
				function ( $p ) {
					return isset( $p['event'] ) && 'cart_type_corruption' === $p['event'];
				}
			)
		);
	}

	private function run_pair( $cart ) {
		Hypercart_Query_Guard::cart_diag_snapshot( $cart );
		Hypercart_Query_Guard::cart_diag_check( $cart );
	}

	// -----------------------------------------------------------------------
	// Classification and attribution
	// -----------------------------------------------------------------------

	public function test_clean_cart_logs_nothing() {
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $this->item( 2 ) ) ) );
		$this->assertSame( array(), $this->corruption_events() );
	}

	public function test_numeric_string_quantity_is_not_flagged() {
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $this->item( '2' ) ) ) );
		$this->assertSame( array(), $this->corruption_events() );
	}

	public function test_string_quantity_present_at_snapshot_is_upstream() {
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $this->item( 'undefined' ) ) ) );

		$events = $this->corruption_events();
		$this->assertCount( 1, $events );
		$row = $events[0]['corrupted'][0];
		$this->assertSame( 'quantity', $row['field'] );
		$this->assertSame( 'undefined', $row['current_value'] );
		$this->assertSame( 'string', $row['current_type'] );
		$this->assertSame( 'upstream', $row['corrupted_by'] );
		$this->assertSame( 'simple', $row['product_type'] );
	}

	public function test_quantity_corrupted_mid_hook_is_hook_callback() {
		$cart = new HCQG_Fake_Cart( array( 'k1' => $this->item( 2 ) ) );
		Hypercart_Query_Guard::cart_diag_snapshot( $cart );
		$cart->items['k1']['quantity'] = 'NaN'; // what a mid-hook callback would do
		Hypercart_Query_Guard::cart_diag_check( $cart );

		$row = $this->corruption_events()[0]['corrupted'][0];
		$this->assertSame( 'hook_callback', $row['corrupted_by'] );
		$this->assertSame( '2', $row['early_value'] );
		$this->assertSame( 'integer', $row['early_type'] );
	}

	public function test_item_added_mid_hook_is_added_during_hook() {
		$cart = new HCQG_Fake_Cart( array( 'k1' => $this->item( 1 ) ) );
		Hypercart_Query_Guard::cart_diag_snapshot( $cart );
		$cart->items['k2'] = $this->item( '1 (gift)' ); // BOGO-style auto-add
		Hypercart_Query_Guard::cart_diag_check( $cart );

		$row = $this->corruption_events()[0]['corrupted'][0];
		$this->assertSame( 'added_during_hook', $row['corrupted_by'] );
	}

	// -----------------------------------------------------------------------
	// Malformed value shapes must never fatal
	// -----------------------------------------------------------------------

	public function test_object_quantity_does_not_fatal_and_is_labeled() {
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $this->item( new stdClass() ) ) ) );

		$row = $this->corruption_events()[0]['corrupted'][0];
		$this->assertSame( '{object:stdClass}', $row['current_value'] );
		$this->assertSame( 'object', $row['current_type'] );
	}

	public function test_array_quantity_is_encoded_not_cast() {
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $this->item( array( 3 ) ) ) ) );

		$row = $this->corruption_events()[0]['corrupted'][0];
		$this->assertStringStartsWith( '{array:', $row['current_value'] );
		$this->assertSame( 'array', $row['current_type'] );
	}

	public function test_missing_quantity_key_is_flagged_as_missing() {
		$item = $this->item( 1 );
		unset( $item['quantity'] );
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $item ) ) );

		$row = $this->corruption_events()[0]['corrupted'][0];
		$this->assertSame( 'quantity', $row['field'] );
		$this->assertSame( '{missing}', $row['current_value'] );
		$this->assertSame( 'missing', $row['current_type'] );
	}

	public function test_non_array_item_row_is_flagged() {
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => 'garbage' ) ) );

		$row = $this->corruption_events()[0]['corrupted'][0];
		$this->assertSame( 'item', $row['field'] );
		$this->assertSame( 'garbage', $row['current_value'] );
	}

	public function test_throwing_price_read_is_captured_not_fatal() {
		$item         = $this->item( 1 );
		$item['data'] = new HCQG_Throwing_Product();
		$this->run_pair( new HCQG_Fake_Cart( array( 'k1' => $item ) ) );

		$rows       = $this->corruption_events()[0]['corrupted'];
		$price_rows = array_values(
			array_filter(
				$rows,
				function ( $r ) {
					return 'price' === $r['field'];
				}
			)
		);
		$this->assertCount( 1, $price_rows );
		$this->assertStringStartsWith( '{get_price threw', $price_rows[0]['current_value'] );
	}

	public function test_non_cart_arguments_are_ignored_without_error() {
		Hypercart_Query_Guard::cart_diag_snapshot( null );
		Hypercart_Query_Guard::cart_diag_check( null );
		Hypercart_Query_Guard::cart_diag_snapshot( '' );
		Hypercart_Query_Guard::cart_diag_check( '' );
		Hypercart_Query_Guard::cart_diag_snapshot( new stdClass() );
		Hypercart_Query_Guard::cart_diag_check( new stdClass() );
		$this->assertSame( array(), $this->captured );
	}

	// -----------------------------------------------------------------------
	// Log budget and de-duplication
	// -----------------------------------------------------------------------

	public function test_identical_corruption_logged_once_per_process() {
		$cart = new HCQG_Fake_Cart( array( 'k1' => $this->item( 'undefined' ) ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->run_pair( $cart );
		}
		$this->assertCount( 1, $this->corruption_events() );
	}

	public function test_new_corruption_gets_its_own_event_up_to_cap() {
		for ( $i = 0; $i < 8; $i++ ) {
			$this->run_pair( new HCQG_Fake_Cart( array( "k{$i}" => $this->item( "bad-{$i}" ) ) ) );
		}
		$this->assertCount( Hypercart_Query_Guard::CART_DIAG_MAX_LOGS, $this->corruption_events() );
	}

	// -----------------------------------------------------------------------
	// Payload context fields
	// -----------------------------------------------------------------------

	public function test_coupons_and_context_fields_present() {
		$this->run_pair(
			new HCQG_Fake_Cart(
				array( 'k1' => $this->item( 'x' ) ),
				array( 'SAVE20', 'bogo-free' )
			)
		);

		$event = $this->corruption_events()[0];
		$this->assertSame( array( 'SAVE20', 'bogo-free' ), $event['applied_coupons'] );
		$this->assertSame( 1, $event['cart_item_count'] );
		$this->assertArrayHasKey( 'uri', $event );
		$this->assertArrayHasKey( 'context', $event );
		$this->assertArrayHasKey( 'is_admin_ajax', $event );
		$this->assertArrayHasKey( 'user_id', $event );
		$this->assertArrayHasKey( 'callbacks', $event );
	}

	// -----------------------------------------------------------------------
	// Hook callback enumeration
	// -----------------------------------------------------------------------

	public function test_enumerate_hook_callbacks_labels_all_shapes() {
		$closure              = function () {};
		$GLOBALS['wp_filter'] = array(
			'woocommerce_before_calculate_totals' => (object) array(
				'callbacks' => array(
					10 => array(
						array( 'function' => 'my_named_function', 'accepted_args' => 1 ),
						array( 'function' => array( 'Some_Class', 'some_method' ), 'accepted_args' => 1 ),
						array( 'function' => $closure, 'accepted_args' => 1 ),
						array( 'function' => new HCQG_Fake_Product( 1 ), 'accepted_args' => 1 ),
					),
				),
			),
		);

		$method = new ReflectionMethod( Hypercart_Query_Guard::class, 'enumerate_hook_callbacks' );
		$method->setAccessible( true );
		$list = $method->invoke( null, 'woocommerce_before_calculate_totals' );

		$this->assertContains( '10:my_named_function', $list );
		$this->assertContains( '10:Some_Class::some_method', $list );
		$this->assertCount( 1, preg_grep( '/^10:\{closure@CartDiagTest\.php:\d+\}$/', $list ) );
		$this->assertContains( '10:{invokable:HCQG_Fake_Product}', $list );
	}

	public function test_enumerate_handles_plain_array_wp_filter_row() {
		$GLOBALS['wp_filter'] = array(
			'woocommerce_before_calculate_totals' => array( 10 => array() ), // pre-4.7-style row
		);

		$method = new ReflectionMethod( Hypercart_Query_Guard::class, 'enumerate_hook_callbacks' );
		$method->setAccessible( true );
		$this->assertSame( array(), $method->invoke( null, 'woocommerce_before_calculate_totals' ) );
	}

	// -----------------------------------------------------------------------
	// Shutdown fatal capture
	// -----------------------------------------------------------------------

	public function test_fatal_capture_matches_discounts_type_error() {
		$cart = new HCQG_Fake_Cart(
			array( 'k1' => $this->item( 'undefined' ) ),
			array( 'SAVE20' )
		);
		$err  = array(
			'type'    => E_ERROR,
			'message' => 'Uncaught TypeError: Unsupported operand types: float / string in /srv/wp-content/plugins/woocommerce/includes/class-wc-discounts.php:296',
			'file'    => '/srv/wp-content/plugins/woocommerce/includes/class-wc-discounts.php',
			'line'    => 296,
		);

		$this->assertTrue( Hypercart_Query_Guard::cart_diag_capture_fatal( $err, $cart ) );

		$events = array_values(
			array_filter(
				$this->captured,
				function ( $p ) {
					return isset( $p['event'] ) && 'cart_fatal_captured' === $p['event'];
				}
			)
		);
		$this->assertCount( 1, $events );
		$this->assertSame( 'undefined', $events[0]['cart_items'][0]['quantity'] );
		$this->assertSame( 'string', $events[0]['cart_items'][0]['quantity_type'] );
		$this->assertSame( array( 'SAVE20' ), $events[0]['applied_coupons'] );
		$this->assertStringContainsString( 'class-wc-discounts.php:296', $events[0]['fatal_file'] );
	}

	public function test_fatal_capture_ignores_unrelated_fatals() {
		$err = array(
			'type'    => E_ERROR,
			'message' => 'Uncaught Error: Call to undefined function foo() in /srv/whatever.php:1',
			'file'    => '/srv/whatever.php',
			'line'    => 1,
		);
		$this->assertFalse( Hypercart_Query_Guard::cart_diag_capture_fatal( $err, null ) );
		$this->assertSame( array(), $this->captured );
	}

	public function test_fatal_capture_without_cart_still_logs_error_details() {
		$err = array(
			'type'    => E_ERROR,
			'message' => 'Uncaught TypeError: Unsupported operand types: float / string in /srv/wp-content/plugins/woocommerce/includes/class-wc-discounts.php:296',
			'file'    => '/srv/wp-content/plugins/woocommerce/includes/class-wc-discounts.php',
			'line'    => 296,
		);
		$this->assertTrue( Hypercart_Query_Guard::cart_diag_capture_fatal( $err, null ) );
		$this->assertCount( 1, $this->captured );
		$this->assertSame( array(), $this->captured[0]['cart_items'] );
	}
}
