<?php
/**
 * Tests for consequence-tier timeout resolution in Hypercart_Query_Guard.
 *
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

final class QueryConsequenceTest extends TestCase {

	protected function setUp(): void {
		WP_Stub_State::reset();
		$_REQUEST = array();
		$_SERVER  = array();
	}

	/**
	 * Invoke a private static on Hypercart_Query_Guard.
	 *
	 * @param string $method
	 * @param mixed  ...$args
	 * @return mixed
	 */
	private function call( string $method, ...$args ) {
		$ref = new ReflectionMethod( Hypercart_Query_Guard::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	/**
	 * Set a private static property on Hypercart_Query_Guard.
	 *
	 * @param string $name
	 * @param mixed  $value
	 * @return void
	 */
	private function setStatic( string $name, $value ): void {
		$ref  = new ReflectionClass( Hypercart_Query_Guard::class );
		$prop = $ref->getProperty( $name );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	public function test_detect_consequence_tier_defaults_for_context(): void {
		$this->assertSame( 'transactional', $this->call( 'detect_consequence_tier', 'checkout' ) );
		$this->assertSame( 'retry_safe', $this->call( 'detect_consequence_tier', 'action_scheduler' ) );
		$this->assertSame( 'user_visible', $this->call( 'detect_consequence_tier', 'frontend' ) );
	}

	public function test_detect_consequence_tier_filter_accepts_known_tier(): void {
		add_filter(
			'hypercart_query_guard_consequence_tier',
			static function ( $tier, $context ) {
				return 'rest_api' === $context ? 'invisible' : $tier;
			},
			10,
			2
		);

		$this->assertSame( 'invisible', $this->call( 'detect_consequence_tier', 'rest_api' ) );
	}

	public function test_detect_consequence_tier_filter_rejects_unknown_tier(): void {
		add_filter(
			'hypercart_query_guard_consequence_tier',
			static function () {
				return 'urgent';
			}
		);

		$this->assertSame( 'user_visible', $this->call( 'detect_consequence_tier', 'frontend' ) );
	}

	public function test_context_consequence_limits_filter_merges_and_clamps(): void {
		add_filter(
			'hypercart_query_guard_context_consequence_limits_ms',
			static function ( $matrix ) {
				$matrix['frontend']['user_visible'] = 12000;
				$matrix['frontend']['retry_safe']   = -10;
				$matrix['panic']                    = array( 'invisible' => 1 );
				return $matrix;
			}
		);

		$matrix = $this->call( 'get_context_consequence_limits_ms' );
		$this->assertSame( 12000, $matrix['frontend']['user_visible'] );
		$this->assertSame( 0, $matrix['frontend']['retry_safe'] );
		$this->assertArrayNotHasKey( 'panic', $matrix );
	}

	public function test_default_matrix_is_derived_from_limits_ms(): void {
		$matrix = $this->call( 'get_context_consequence_limits_ms' );
		foreach ( Hypercart_Query_Guard::LIMITS_MS as $context => $limit ) {
			$this->assertArrayHasKey( $context, $matrix );
			foreach ( Hypercart_Query_Guard::CONSEQUENCE_TIERS as $tier ) {
				$this->assertSame( (int) $limit, $matrix[ $context ][ $tier ] );
			}
		}
	}

	public function test_get_limit_ms_defaults_to_existing_frontend_limit(): void {
		$this->assertSame( 30000, $this->call( 'get_limit_ms' ) );
	}

	public function test_get_limit_ms_uses_context_and_consequence_matrix(): void {
		add_filter(
			'hypercart_query_guard_context_consequence_limits_ms',
			static function ( $matrix ) {
				$matrix['frontend']['user_visible'] = 11000;
				return $matrix;
			}
		);

		$this->assertSame( 11000, $this->call( 'get_limit_ms' ) );
	}

	public function test_get_limit_ms_filter_receives_consequence_tier(): void {
		add_filter(
			'hypercart_query_guard_limit_ms',
			static function ( $limit, $context, $tier ) {
				if ( 'frontend' === $context && 'user_visible' === $tier ) {
					return 7777;
				}
				return $limit;
			},
			10,
			3
		);

		$this->assertSame( 7777, $this->call( 'get_limit_ms' ) );
	}

	public function test_logging_policy_prefers_applied_snapshot(): void {
		$this->setStatic(
			'timeout_runtime',
			array(
				'applied_policy' => array(
					'context'          => 'checkout',
					'consequence_tier' => 'transactional',
					'limit_ms'         => 90000,
				),
			)
		);

		$policy = $this->call( 'get_timeout_policy_for_logging' );
		$this->assertSame( 'checkout', $policy['context'] );
		$this->assertSame( 'transactional', $policy['consequence_tier'] );
		$this->assertSame( 90000, $policy['limit_ms'] );
	}
}
