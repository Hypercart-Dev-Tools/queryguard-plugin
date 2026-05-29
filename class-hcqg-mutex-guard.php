<?php
/**
 * Mutex Guard — coalesce concurrent invocations of the same operation.
 *
 * Wave C subsystem. Independent of the Wave A/B throttle. See
 * PROJECT/2-WORKING/P1-MUTEX-GUARD.md for the design doc.
 *
 * STORAGE / CORRECTNESS NOTES — read before changing this file.
 *
 * 1. We use raw $wpdb SQL with INSERT ... ON DUPLICATE KEY UPDATE because
 *    add_option() / update_option() are TOCTOU and not atomic. Two callers
 *    can both pass an existence check and race on the write; the second
 *    add_option() throws a duplicate-key error that surfaces in PHP logs.
 *    Do NOT replace the SQL with WP option helpers.
 *
 * 2. Time comparisons in SQL use a `%d` parameter from PHP's time(),
 *    NEVER MySQL's UNIX_TIMESTAMP(). Web nodes and DB nodes can drift on
 *    managed hosts; anchoring TTL to the web node is the only way to make
 *    the requested TTL match the effective TTL.
 *
 * 3. acquire_lock() is NOT memoized per request. Memoization creates a
 *    same-process reentrancy hole — if A acquires, B "acquires" via the
 *    memo without a DB write, A releases, then an external process can
 *    take the lock while B is still running. Mutex defeated. See the
 *    design doc for the full trace. Reference-counted reentrancy could
 *    be added later behind the same API if a real caller needs it.
 *
 * @package Hypercart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HCQG_Mutex_Guard' ) ) {

	final class HCQG_Mutex_Guard {

		/**
		 * Stored option_name is `hcqg_mutex_<md5($key)>` — 43 chars total,
		 * well within the wp_options.option_name varchar(191) limit
		 * regardless of caller-supplied key length.
		 */
		const OPTION_PREFIX = 'hcqg_mutex_';

		/**
		 * 16-char hex nonce from random_bytes(8). Hex characters are
		 * disjoint from the `|` delimiter we use in option_value, so the
		 * nonce is safe to embed without escaping.
		 */
		const NONCE_BYTES = 8;

		// SAFETY: interpolated into SQL via string concat (not %s) so
		// SUBSTRING_INDEX can extract fields. Must remain a single
		// character that is safe in SQL literals and disjoint from
		// hex nonce chars. Do not change without auditing acquire_lock
		// and release_lock SQL.
		const VALUE_DELIMITER = '|';

		const DEFAULT_TTL = 60;

		/**
		 * Acquire a lock for $operation_key.
		 *
		 * Returns the holder nonce on success, false if a live lock is
		 * already held by someone else. Pass the returned nonce to
		 * release_lock() — release is a no-op without it.
		 *
		 * Caller patterns when this returns false (see PROJECT design doc):
		 *   ✅ skip the work, serve cached/stale result
		 *   ⚠️ schedule via Action Scheduler for later
		 *   ❌ busy-wait / retry within the same request
		 *
		 * @param string $operation_key  Caller-meaningful key. Hashed before storage.
		 * @param int    $ttl            TTL in seconds. Default 60.
		 * @return string|false          Holder nonce on success, false if held.
		 */
		public static function acquire_lock( $operation_key, $ttl = self::DEFAULT_TTL ) {
			global $wpdb;
			if ( empty( $wpdb ) ) {
				return false;
			}

			$ttl    = max( 1, (int) $ttl );
			$now    = time();
			$expire = $now + $ttl;
			$nonce  = self::generate_nonce();
			$value  = self::pack_value( $expire, $nonce );
			$name   = self::option_name_for( $operation_key );

			// INSERT if absent; UPDATE only if the existing row is past
			// expires_at. Time comparison uses PHP's time() as a parameter,
			// not UNIX_TIMESTAMP() — see class header note 2.
			$sql = $wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'no')
				 ON DUPLICATE KEY UPDATE
				   option_value = IF(
				     CAST(SUBSTRING_INDEX(option_value, '" . self::VALUE_DELIMITER . "', 1) AS UNSIGNED) < %d,
				     VALUES(option_value),
				     option_value
				   )",
				$name,
				$value,
				$now
			);

			$wpdb->query( $sql );

			if ( ! empty( $wpdb->last_error ) ) {
				self::log(
					'error',
					array(
						'event'         => 'mutex_acquire_error',
						'operation_key' => (string) $operation_key,
						'option_name'   => $name,
						'db_error'      => $wpdb->last_error,
					)
				);
				return false;
			}

			$affected = (int) $wpdb->rows_affected;

			// 1 = fresh INSERT, 2 = UPDATE replacing an expired lock,
			// 0 = a live lock is held by someone else.
			if ( 1 === $affected || 2 === $affected ) {
				return $nonce;
			}

			self::log_held( $operation_key, $name );
			return false;
		}

		/**
		 * Release a lock we hold. CAS-conditional: only deletes the row
		 * when the stored nonce matches ours. A no-op if our TTL expired
		 * and another process took the lock in the meantime.
		 *
		 * @param string $operation_key
		 * @param string $holder_nonce  The nonce returned from acquire_lock().
		 * @return bool                 True if our row was deleted.
		 */
		public static function release_lock( $operation_key, $holder_nonce ) {
			global $wpdb;
			if ( empty( $wpdb ) || ! is_string( $holder_nonce ) || '' === $holder_nonce ) {
				return false;
			}

			$name = self::option_name_for( $operation_key );

			$sql = $wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name = %s
				   AND SUBSTRING_INDEX(option_value, '" . self::VALUE_DELIMITER . "', -1) = %s",
				$name,
				$holder_nonce
			);

			$wpdb->query( $sql );

			if ( ! empty( $wpdb->last_error ) ) {
				self::log(
					'error',
					array(
						'event'         => 'mutex_release_error',
						'operation_key' => (string) $operation_key,
						'option_name'   => $name,
						'db_error'      => $wpdb->last_error,
					)
				);
				return false;
			}

			$affected = (int) $wpdb->rows_affected;

			if ( 0 === $affected ) {
				self::log_release_skipped( $operation_key, $name );
				return false;
			}

			return true;
		}

		/**
		 * Read the current lock row without acquiring.
		 *
		 * MONITORING / UI ONLY. The result is stale by the time the caller
		 * reads it; another process can acquire or release between this
		 * call and any subsequent action. Do NOT branch control flow on
		 * peek_lock(). For control flow, call acquire_lock() — it is the
		 * only atomic primitive in this API.
		 *
		 * @param string $operation_key
		 * @return array|false  ['expires_at' => int, 'holder' => string] or false.
		 */
		public static function peek_lock( $operation_key ) {
			global $wpdb;
			if ( empty( $wpdb ) ) {
				return false;
			}

			$name = self::option_name_for( $operation_key );
			$sql  = $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			);
			$raw  = $wpdb->get_var( $sql );

			if ( null === $raw || '' === $raw ) {
				return false;
			}

			$parsed = self::parse_value( (string) $raw );
			if ( false === $parsed ) {
				return false;
			}

			// Expired locks are visible to peek but logically absent —
			// surface them as false so callers don't treat stale rows as
			// live holders.
			if ( $parsed['expires_at'] < time() ) {
				return false;
			}

			return $parsed;
		}

		/**
		 * Unconditionally delete a lock row. For admin/CLI recovery when
		 * a stuck lock can't be released by its holder (process died,
		 * holder code lost the nonce, etc.). Logged as warn with the
		 * supplied $reason and the prior holder's nonce when available.
		 *
		 * @param string $operation_key
		 * @param string $reason  Free-form string describing why we're forcing.
		 * @return bool           True if a row was deleted.
		 */
		public static function force_release( $operation_key, $reason ) {
			global $wpdb;
			if ( empty( $wpdb ) ) {
				return false;
			}

			$name  = self::option_name_for( $operation_key );
			$prior = self::peek_lock_raw( $name );

			$sql = $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s",
				$name
			);
			$wpdb->query( $sql );
			$affected = (int) $wpdb->rows_affected;

			self::log(
				'warn',
				array(
					'event'         => 'mutex_force_released',
					'operation_key' => (string) $operation_key,
					'option_name'   => $name,
					'prior_holder'  => isset( $prior['holder'] ) ? $prior['holder'] : '',
					'prior_expires' => isset( $prior['expires_at'] ) ? (int) $prior['expires_at'] : 0,
					'rows_deleted'  => $affected,
					'reason'        => (string) $reason,
					'user_id'       => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
				)
			);

			return $affected > 0;
		}

		/**
		 * Convenience: acquire, run $work, release in a finally. Returns
		 * true if $work executed, false if the lock was held. Use this
		 * for any new caller — it eliminates the "forgot to release"
		 * footgun.
		 *
		 * @param string   $operation_key
		 * @param int      $ttl
		 * @param callable $work
		 * @return bool                  True if $work ran, false if lock held.
		 */
		public static function with_lock( $operation_key, $ttl, callable $work ) {
			$nonce = self::acquire_lock( $operation_key, $ttl );
			if ( false === $nonce ) {
				return false;
			}
			try {
				call_user_func( $work );
			} finally {
				self::release_lock( $operation_key, $nonce );
			}
			return true;
		}

		// -- internals ---------------------------------------------------

		/**
		 * Internal raw read used by force_release() to log the prior
		 * holder regardless of expiry. Differs from peek_lock() which
		 * masks expired rows.
		 *
		 * @param string $option_name
		 * @return array|false
		 */
		private static function peek_lock_raw( $option_name ) {
			global $wpdb;
			$sql = $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			);
			$raw = $wpdb->get_var( $sql );
			if ( null === $raw || '' === $raw ) {
				return false;
			}
			return self::parse_value( (string) $raw );
		}

		/**
		 * Hash the caller-supplied key into a fixed-length option_name.
		 * Bounds storage at 43 chars regardless of input.
		 */
		private static function option_name_for( $operation_key ) {
			return self::OPTION_PREFIX . md5( (string) $operation_key );
		}

		/**
		 * 16-char hex nonce. Prefer random_bytes; fall back to mt_rand
		 * for hosts without an entropy source (paranoia — random_bytes
		 * is core PHP 7+ but throws if entropy is unavailable).
		 */
		private static function generate_nonce() {
			try {
				return bin2hex( random_bytes( self::NONCE_BYTES ) );
			} catch ( Exception $e ) {
				if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
					return bin2hex( openssl_random_pseudo_bytes( self::NONCE_BYTES ) );
				}
				throw $e;
			}
		}

		private static function pack_value( $expires_at, $nonce ) {
			return ( (int) $expires_at ) . self::VALUE_DELIMITER . (string) $nonce;
		}

		/**
		 * @return array{expires_at:int,holder:string}|false
		 */
		private static function parse_value( $raw ) {
			if ( false === strpos( $raw, self::VALUE_DELIMITER ) ) {
				return false;
			}
			$parts = explode( self::VALUE_DELIMITER, $raw, 2 );
			if ( 2 !== count( $parts ) ) {
				return false;
			}
			$expires_at = (int) $parts[0];
			$holder     = (string) $parts[1];
			if ( $expires_at <= 0 || '' === $holder ) {
				return false;
			}
			return array(
				'expires_at' => $expires_at,
				'holder'     => $holder,
			);
		}

		private static function log_held( $operation_key, $option_name ) {
			self::log(
				'info',
				array(
					'event'         => 'mutex_held',
					'operation_key' => (string) $operation_key,
					'option_name'   => $option_name,
					'uri'           => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
				)
			);
		}

		private static function log_release_skipped( $operation_key, $option_name ) {
			self::log(
				'info',
				array(
					'event'         => 'mutex_release_skipped',
					'operation_key' => (string) $operation_key,
					'option_name'   => $option_name,
					'reason'        => 'nonce_mismatch_or_already_gone',
				)
			);
		}

		/**
		 * Centralized log emitter. Mirrors Hypercart_Query_Guard::log()'s
		 * fallback chain (Hypercart_Logger if available, else error_log
		 * with single-line JSON for grep-ability) so this class doesn't
		 * depend on the throttle subsystem being loaded.
		 *
		 * @param string $level   'info' | 'warn' | 'error'
		 * @param array  $payload Structured fields.
		 */
		private static function log( $level, array $payload ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
			if ( ! is_string( $encoded ) ) {
				$encoded = '[hypercart_query_guard] failed to encode log payload';
			}

			if ( class_exists( 'Hypercart_Logger' ) ) {
				if ( 'error' === $level && method_exists( 'Hypercart_Logger', 'error' ) ) {
					Hypercart_Logger::error( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'error', $payload, $encoded ) );
					return;
				}
				if ( 'info' === $level && method_exists( 'Hypercart_Logger', 'info' ) ) {
					Hypercart_Logger::info( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'info', $payload, $encoded ) );
					return;
				}
				// The Hypercart Helper logger exposes warning(); older/string-only
				// loggers may expose warn(). Prefer warning(), fall back to warn().
				if ( method_exists( 'Hypercart_Logger', 'warning' ) ) {
					Hypercart_Logger::warning( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'warning', $payload, $encoded ) );
					return;
				}
				if ( method_exists( 'Hypercart_Logger', 'warn' ) ) {
					Hypercart_Logger::warn( 'query_guard', self::get_logger_payload_for_method( 'Hypercart_Logger', 'warn', $payload, $encoded ) );
					return;
				}
			}

			error_log( '[hypercart_query_guard][' . $level . '] ' . $encoded );
		}

		/**
		 * Preserve structured payloads for legacy logger signatures while
		 * serializing for string-only logger APIs.
		 *
		 * @param string $class_name
		 * @param string $method
		 * @param array  $payload
		 * @param string $message
		 * @return array|string
		 */
		private static function get_logger_payload_for_method( $class_name, $method, array $payload, $message ) {
			try {
				$reflection = new ReflectionMethod( $class_name, $method );
				$params     = $reflection->getParameters();
				if ( ! isset( $params[1] ) ) {
					return $message;
				}

				if ( self::reflection_type_allows_array( $params[1]->getType() ) ) {
					return $payload;
				}
			} catch ( ReflectionException $exception ) {
				return $message;
			}

			return $message;
		}

		/**
		 * Determine whether a reflected parameter type accepts array payloads.
		 *
		 * @param ReflectionType|null $type
		 * @return bool
		 */
		private static function reflection_type_allows_array( $type ) {
			if ( null === $type ) {
				return true;
			}

			if ( $type instanceof ReflectionNamedType ) {
				return in_array( $type->getName(), array( 'array', 'iterable', 'mixed' ), true );
			}

			if ( $type instanceof ReflectionUnionType ) {
				foreach ( $type->getTypes() as $union_type ) {
					if ( self::reflection_type_allows_array( $union_type ) ) {
						return true;
					}
				}
			}

			return false;
		}
	}
}
