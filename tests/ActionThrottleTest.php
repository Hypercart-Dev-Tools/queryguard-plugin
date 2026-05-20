<?php
/**
 * Tests for Wave B per-action throttle helpers (Hypercart_Query_Guard).
 *
 * The targets are private statics, so we use ReflectionMethod to invoke them.
 *
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

final class ActionThrottleTest extends TestCase {

	protected function setUp(): void {
		WP_Stub_State::reset();
	}

	/**
	 * @param string $method
	 * @param mixed  ...$args
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		$ref = new ReflectionMethod( Hypercart_Query_Guard::class, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		return $ref->invoke( null, ...$args );
	}

	// ---- get_action_delay_matrix --------------------------------------

	public function test_default_matrix_has_expected_shape(): void {
		$matrix = $this->call( 'get_action_delay_matrix' );
		$this->assertSame( array( 'elevated', 'critical' ), array_keys( $matrix ) );
		$this->assertSame( array( 'critical', 'high', 'normal', 'deferrable' ), array_keys( $matrix['elevated'] ) );
		$this->assertSame( 0, $matrix['elevated']['critical'] );
		$this->assertSame( 900, $matrix['elevated']['deferrable'] );
		$this->assertSame( 3600, $matrix['critical']['deferrable'] );
	}

	public function test_filter_overrides_existing_cell_and_clamps(): void {
		add_filter(
			'hypercart_query_guard_action_delay_matrix',
			static function ( $matrix ) {
				$matrix['critical']['deferrable'] = 1800;
				$matrix['critical']['high']       = -10; // clamped to 0
				return $matrix;
			}
		);
		$matrix = $this->call( 'get_action_delay_matrix' );
		$this->assertSame( 1800, $matrix['critical']['deferrable'] );
		$this->assertSame( 0, $matrix['critical']['high'] );
		// Untouched cell still has its default.
		$this->assertSame( 900, $matrix['critical']['normal'] );
	}

	public function test_filter_unknown_level_is_ignored(): void {
		add_filter(
			'hypercart_query_guard_action_delay_matrix',
			static function ( $matrix ) {
				$matrix['panic'] = array( 'critical' => 60 );
				return $matrix;
			}
		);
		$matrix = $this->call( 'get_action_delay_matrix' );
		$this->assertArrayNotHasKey( 'panic', $matrix );
	}

	public function test_filter_unknown_tier_within_known_level_is_ignored(): void {
		add_filter(
			'hypercart_query_guard_action_delay_matrix',
			static function ( $matrix ) {
				$matrix['critical']['urgent'] = 30; // not a real tier
				return $matrix;
			}
		);
		$matrix = $this->call( 'get_action_delay_matrix' );
		$this->assertArrayNotHasKey( 'urgent', $matrix['critical'] );
		// And the rest of the level survived intact.
		$this->assertSame( 0, $matrix['critical']['critical'] );
	}

	public function test_filter_non_array_falls_back_to_defaults(): void {
		add_filter(
			'hypercart_query_guard_action_delay_matrix',
			static function () {
				return 'nonsense';
			}
		);
		$matrix = $this->call( 'get_action_delay_matrix' );
		$this->assertSame( 900, $matrix['elevated']['deferrable'] );
	}

	public function test_filter_non_array_level_value_is_skipped(): void {
		add_filter(
			'hypercart_query_guard_action_delay_matrix',
			static function ( $matrix ) {
				$matrix['critical'] = 'not_an_array';
				return $matrix;
			}
		);
		$matrix = $this->call( 'get_action_delay_matrix' );
		$this->assertSame( 3600, $matrix['critical']['deferrable'] );
	}

	// ---- get_action_delay_seconds -------------------------------------

	public function test_delay_seconds_normal_level_returns_zero(): void {
		// 'normal' level isn't in the matrix; lookup should return 0.
		$this->assertSame( 0, $this->call( 'get_action_delay_seconds', 'normal', 'deferrable' ) );
	}

	public function test_delay_seconds_known_cell(): void {
		$this->assertSame( 900, $this->call( 'get_action_delay_seconds', 'elevated', 'deferrable' ) );
		$this->assertSame( 0, $this->call( 'get_action_delay_seconds', 'elevated', 'critical' ) );
		$this->assertSame( 3600, $this->call( 'get_action_delay_seconds', 'critical', 'deferrable' ) );
	}

	public function test_delay_seconds_unknown_tier_returns_zero(): void {
		$this->assertSame( 0, $this->call( 'get_action_delay_seconds', 'critical', 'urgent' ) );
	}

	public function test_delay_seconds_uses_filter_overrides(): void {
		add_filter(
			'hypercart_query_guard_action_delay_matrix',
			static function ( $matrix ) {
				$matrix['critical']['deferrable'] = 1800;
				return $matrix;
			}
		);
		$this->assertSame( 1800, $this->call( 'get_action_delay_seconds', 'critical', 'deferrable' ) );
	}

	// ---- defer_count_key / get_defer_count / increment_defer_count ----

	public function test_defer_count_key_is_deterministic(): void {
		$args = array( 'order_id' => 42, 'attempt' => 1 );
		$a    = $this->call( 'defer_count_key', 'wc_run_thing', $args, 'group_a' );
		$b    = $this->call( 'defer_count_key', 'wc_run_thing', $args, 'group_a' );
		$this->assertSame( $a, $b );
	}

	public function test_defer_count_key_varies_with_inputs(): void {
		$base = $this->call( 'defer_count_key', 'wc_run', array( 1 ), 'g' );
		$this->assertNotSame( $base, $this->call( 'defer_count_key', 'wc_run', array( 2 ), 'g' ) );
		$this->assertNotSame( $base, $this->call( 'defer_count_key', 'wc_run', array( 1 ), 'h' ) );
		$this->assertNotSame( $base, $this->call( 'defer_count_key', 'wc_run_other', array( 1 ), 'g' ) );
	}

	public function test_defer_count_starts_at_zero(): void {
		$count = $this->call( 'get_defer_count', 'wc_run', array( 1 ), 'g' );
		$this->assertSame( 0, $count );
	}

	public function test_increment_defer_count_increments_and_persists(): void {
		$this->assertSame( 1, $this->call( 'increment_defer_count', 'wc_run', array( 1 ), 'g' ) );
		$this->assertSame( 1, $this->call( 'get_defer_count', 'wc_run', array( 1 ), 'g' ) );
		$this->assertSame( 2, $this->call( 'increment_defer_count', 'wc_run', array( 1 ), 'g' ) );
		$this->assertSame( 2, $this->call( 'get_defer_count', 'wc_run', array( 1 ), 'g' ) );
	}

	public function test_increment_defer_count_is_keyed_by_signature(): void {
		$this->call( 'increment_defer_count', 'hook_a', array( 1 ), 'g' );
		$this->call( 'increment_defer_count', 'hook_a', array( 1 ), 'g' );
		$this->call( 'increment_defer_count', 'hook_b', array( 1 ), 'g' );

		$this->assertSame( 2, $this->call( 'get_defer_count', 'hook_a', array( 1 ), 'g' ) );
		$this->assertSame( 1, $this->call( 'get_defer_count', 'hook_b', array( 1 ), 'g' ) );
		$this->assertSame( 0, $this->call( 'get_defer_count', 'hook_a', array( 2 ), 'g' ) );
	}
}
