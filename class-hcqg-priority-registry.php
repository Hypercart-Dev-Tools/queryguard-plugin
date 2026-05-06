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
		 * Get the filterable default registry.
		 *
		 * @return array<string,array<int,string>>
		 */
		public static function get_registry() {
			$registry = apply_filters( 'hypercart_query_guard_priority_registry', self::DEFAULT_REGISTRY );
			if ( ! is_array( $registry ) ) {
				return self::DEFAULT_REGISTRY;
			}

			$normalized = array();
			foreach ( $registry as $tier => $patterns ) {
				if ( ! is_array( $patterns ) ) {
					continue;
				}
				$normalized[ (string) $tier ] = array_values(
					array_filter(
						array_map( 'strval', $patterns ),
						'strlen'
					)
				);
			}

			return array_merge( self::DEFAULT_REGISTRY, $normalized );
		}

		/**
		 * Resolve a hook name to a priority tier.
		 *
		 * This registry is inert until Wave B wires it into throttle decisions.
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

			return (string) apply_filters( 'hypercart_query_guard_action_priority', $priority, $hook_name );
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
