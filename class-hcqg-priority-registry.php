<?php
/**
 * Default Action Scheduler priority registry for future per-action throttling.
 *
 * @package Hypercart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HCQG_Priority_Registry' ) ) {

	final class HCQG_Priority_Registry {

		/**
		 * Priority tiers.
		 */
		const TIER_CRITICAL   = 'critical';
		const TIER_HIGH       = 'high';
		const TIER_NORMAL     = 'normal';
		const TIER_DEFERRABLE = 'deferrable';

		/**
		 * Canonical tier order. First match wins in get_priority(); filters
		 * cannot add new tiers or reorder these.
		 */
		const TIERS = array(
			self::TIER_CRITICAL,
			self::TIER_HIGH,
			self::TIER_NORMAL,
			self::TIER_DEFERRABLE,
		);

		/**
		 * Default hook-pattern registry.
		 */
		const DEFAULT_REGISTRY = array(
			'critical'   => array(
				'nofraud_*',
				'woocommerce_payment_*',
				'wc_payment_*',
			),
			'high'       => array(
				'woocommerce_scheduled_subscription_*',
				'wcs_*',
				'woocommerce_deliver_webhook_*',
			),
			'normal'     => array(
				'woocommerce_run_*',
				'action_scheduler_*',
			),
			'deferrable' => array(
				'facebook_for_woocommerce_*',
				'wc_facebook_*',
				'shipstation_*',
				'klaviyo_*',
				'woocommerce_flush_*',
			),
		);

		/**
		 * Get the filterable registry.
		 *
		 * Filter contract for `hypercart_query_guard_priority_registry`:
		 *   - Tiers are fixed (see self::TIERS) and always evaluated in
		 *     critical → high → normal → deferrable order. Filters cannot add
		 *     new tiers or change ordering; unknown tier keys are dropped.
		 *   - For each tier, return an array of patterns to override the
		 *     defaults, or an empty array to clear them. Omitted tiers inherit
		 *     defaults — to clear a tier you must explicitly pass an empty
		 *     array (e.g. `'critical' => array()`).
		 *
		 * @return array<string,array<int,string>>
		 */
		public static function get_registry() {
			$filtered = apply_filters( 'hypercart_query_guard_priority_registry', self::DEFAULT_REGISTRY );
			if ( ! is_array( $filtered ) ) {
				$filtered = self::DEFAULT_REGISTRY;
			}

			$registry = array();
			foreach ( self::TIERS as $tier ) {
				$patterns = ( array_key_exists( $tier, $filtered ) && is_array( $filtered[ $tier ] ) )
					? $filtered[ $tier ]
					: self::DEFAULT_REGISTRY[ $tier ];
				$registry[ $tier ] = array_values(
					array_filter(
						array_map( 'strval', $patterns ),
						'strlen'
					)
				);
			}

			return $registry;
		}

		/**
		 * Resolve a hook name to a priority tier.
		 *
		 * The `hypercart_query_guard_action_priority` filter may override the
		 * resolved tier, but its return value MUST be one of self::TIERS;
		 * unknown tier strings are ignored and the registry-resolved tier is
		 * returned instead.
		 *
		 * @param string $hook_name
		 * @return string
		 */
		public static function get_priority( $hook_name ) {
			$hook_name = (string) $hook_name;
			$priority  = self::TIER_NORMAL;

			foreach ( self::get_registry() as $tier => $patterns ) {
				foreach ( $patterns as $pattern ) {
					if ( self::matches_pattern( $hook_name, $pattern ) ) {
						$priority = (string) $tier;
						break 2;
					}
				}
			}

			$filtered = apply_filters( 'hypercart_query_guard_action_priority', $priority, $hook_name );
			if ( is_string( $filtered ) && in_array( $filtered, self::TIERS, true ) ) {
				return $filtered;
			}
			return $priority;
		}

		/**
		 * Match a hook name against a wildcard pattern.
		 *
		 * @param string $hook_name
		 * @param string $pattern
		 * @return bool
		 */
		private static function matches_pattern( $hook_name, $pattern ) {
			if ( $hook_name === $pattern ) {
				return true;
			}

			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			return 1 === preg_match( $regex, $hook_name );
		}
	}
}
