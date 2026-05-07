<?php
/**
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

final class PriorityRegistryTest extends TestCase {

	protected function setUp(): void {
		WP_Stub_State::reset();
	}

	public function test_unknown_hook_resolves_to_normal(): void {
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'totally_unrelated_hook' ) );
	}

	public function test_wildcard_prefix_match(): void {
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
		$this->assertSame( 'high', HCQG_Priority_Registry::get_priority( 'wcs_renew_subscription' ) );
		$this->assertSame( 'deferrable', HCQG_Priority_Registry::get_priority( 'klaviyo_sync_customer' ) );
	}

	public function test_first_matching_tier_wins_in_canonical_order(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return array(
					'critical'   => array( 'shared_*' ),
					'deferrable' => array( 'shared_*' ),
				);
			}
		);
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'shared_hook' ) );
	}

	public function test_filter_can_override_a_tiers_patterns(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return array( 'critical' => array( 'mystore_charge_*' ) );
			}
		);

		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'mystore_charge_card' ) );
		// Default critical patterns are replaced for that tier.
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
	}

	public function test_omitted_tier_keeps_defaults(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				// Only override 'high'; 'critical' should still hold defaults.
				return array( 'high' => array( 'mystore_*' ) );
			}
		);
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
		$this->assertSame( 'high', HCQG_Priority_Registry::get_priority( 'mystore_anything' ) );
	}

	public function test_empty_array_clears_a_tier(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return array( 'critical' => array() );
			}
		);
		// nofraud_* was a default critical pattern; with critical cleared it
		// should fall through to normal.
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
	}

	public function test_unknown_tier_keys_in_filter_are_dropped(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return array(
					'ultra_critical' => array( 'nofraud_*' ),
					'critical'       => array(),
				);
			}
		);
		// 'ultra_critical' is ignored, and we cleared 'critical', so the hook
		// resolves to normal — proves the unknown tier did not take effect.
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
	}

	public function test_get_registry_returns_only_canonical_tiers_in_order(): void {
		$registry = HCQG_Priority_Registry::get_registry();
		$this->assertSame(
			array( 'critical', 'high', 'normal', 'deferrable' ),
			array_keys( $registry )
		);
	}

	public function test_non_array_filter_return_falls_back_to_defaults(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return 'nope';
			}
		);
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
	}

	public function test_action_priority_filter_can_override_to_known_tier(): void {
		add_filter(
			'hypercart_query_guard_action_priority',
			static function ( $priority, $hook ) {
				return 'mystore_promote_me' === $hook ? 'critical' : $priority;
			}
		);
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'mystore_promote_me' ) );
	}

	public function test_action_priority_filter_unknown_tier_is_ignored(): void {
		add_filter(
			'hypercart_query_guard_action_priority',
			static function () {
				return 'urgent'; // not in TIERS
			}
		);
		// Falls back to the registry-resolved priority instead of trusting the
		// bogus return.
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'nofraud_check_order' ) );
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'unknown_hook' ) );
	}

	public function test_action_priority_filter_non_string_is_ignored(): void {
		add_filter(
			'hypercart_query_guard_action_priority',
			static function () {
				return 99;
			}
		);
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'unknown_hook' ) );
	}

	public function test_pattern_matching_is_anchored(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return array( 'critical' => array( 'foo_*' ) );
			}
		);
		// Substring match should NOT count — the regex is anchored ^...$.
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'prefix_foo_bar' ) );
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'foo_bar' ) );
	}

	public function test_exact_pattern_match(): void {
		add_filter(
			'hypercart_query_guard_priority_registry',
			static function () {
				return array( 'critical' => array( 'exactly_this' ) );
			}
		);
		$this->assertSame( 'critical', HCQG_Priority_Registry::get_priority( 'exactly_this' ) );
		$this->assertSame( 'normal', HCQG_Priority_Registry::get_priority( 'exactly_this_plus_more' ) );
	}
}
