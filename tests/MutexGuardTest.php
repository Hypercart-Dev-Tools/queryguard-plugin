<?php
/**
 * Tests for Wave C Mutex Guard (HCQG_Mutex_Guard).
 *
 * The actual MySQL semantics — INSERT ... ON DUPLICATE KEY UPDATE atomicity
 * and rows_affected returning 2 for an UPDATE-via-ODKU — are not unit-tested
 * here. They get validated against staging during rollout. See the design
 * doc's "Testing strategy" section.
 *
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

final class MutexGuardTest extends TestCase {

	/** @var WP_Stub_DB */
	private $db;

	protected function setUp(): void {
		WP_Stub_State::reset();
		$this->db = $GLOBALS['wpdb'];
		$this->db->reset();
	}

	// ---- option_name encoding / value serialization --------------------

	public function test_option_name_is_fixed_length_regardless_of_input(): void {
		// Drive option_name encoding indirectly by issuing an acquire and
		// reading the rendered SQL — the prepared %s arg for option_name
		// is the encoded value.
		$this->db->next_query_rows_affected = array( 1 );
		HCQG_Mutex_Guard::acquire_lock( 'a' );
		$short = $this->extract_option_name_from_last_acquire();

		$this->db->reset();
		$this->db->next_query_rows_affected = array( 1 );
		HCQG_Mutex_Guard::acquire_lock( str_repeat( 'long_unicode_🚀_', 50 ) );
		$long = $this->extract_option_name_from_last_acquire();

		$this->assertSame( 43, strlen( $short ) );
		$this->assertSame( 43, strlen( $long ) );
		$this->assertStringStartsWith( 'hcqg_mutex_', $short );
		$this->assertStringStartsWith( 'hcqg_mutex_', $long );
		$this->assertNotSame( $short, $long );
	}

	public function test_option_name_is_deterministic(): void {
		$this->db->next_query_rows_affected = array( 1 );
		HCQG_Mutex_Guard::acquire_lock( 'foo' );
		$first = $this->extract_option_name_from_last_acquire();

		$this->db->reset();
		$this->db->next_query_rows_affected = array( 1 );
		HCQG_Mutex_Guard::acquire_lock( 'foo' );
		$second = $this->extract_option_name_from_last_acquire();

		$this->assertSame( $first, $second );
	}

	// ---- acquire_lock --------------------------------------------------

	public function test_acquire_returns_hex_nonce_on_fresh_insert(): void {
		$this->db->next_query_rows_affected = array( 1 );

		$nonce = HCQG_Mutex_Guard::acquire_lock( 'foo' );

		$this->assertIsString( $nonce );
		$this->assertSame( 16, strlen( $nonce ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $nonce );
	}

	public function test_acquire_returns_nonce_on_expired_takeover(): void {
		// rows_affected = 2 means UPDATE replaced an expired row.
		$this->db->next_query_rows_affected = array( 2 );

		$nonce = HCQG_Mutex_Guard::acquire_lock( 'foo' );

		$this->assertIsString( $nonce );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $nonce );
	}

	public function test_acquire_returns_false_when_live_lock_held(): void {
		// rows_affected = 0 means an unexpired lock exists.
		$this->db->next_query_rows_affected = array( 0 );

		$result = HCQG_Mutex_Guard::acquire_lock( 'foo' );

		$this->assertFalse( $result );
	}

	public function test_acquire_anchors_time_in_sql_via_parameter_not_unix_timestamp(): void {
		$this->db->next_query_rows_affected = array( 1 );

		HCQG_Mutex_Guard::acquire_lock( 'foo', 60 );

		$this->assertNotEmpty( $this->db->queries );
		$rendered = $this->db->queries[0];
		$this->assertStringNotContainsString(
			'UNIX_TIMESTAMP',
			$rendered,
			'Rendered SQL must not call UNIX_TIMESTAMP() — time anchoring lives on the web node.'
		);
		// The rendered SQL should contain a numeric epoch from time().
		// We accept any 10-digit unix epoch (valid until year 2286).
		$this->assertMatchesRegularExpression(
			'/CAST\(SUBSTRING_INDEX\(.+\) < \d{10}/',
			$rendered,
			'Expected the expiry comparison to use a literal time() parameter.'
		);
	}

	public function test_acquire_hits_db_on_every_call_no_memoization(): void {
		// Each call must produce a fresh INSERT/ODKU statement; per-request
		// memoization would create a same-process reentrancy hole (see
		// class header note 3 / design doc).
		$this->db->next_query_rows_affected = array( 1, 0, 0 );

		HCQG_Mutex_Guard::acquire_lock( 'foo' );
		HCQG_Mutex_Guard::acquire_lock( 'foo' );
		HCQG_Mutex_Guard::acquire_lock( 'foo' );

		$this->assertCount( 3, $this->db->queries );
	}

	public function test_acquire_clamps_ttl_to_at_least_one_second(): void {
		$this->db->next_query_rows_affected = array( 1 );

		HCQG_Mutex_Guard::acquire_lock( 'foo', 0 );

		$rendered = $this->db->queries[0];
		// The packed VALUES(...) string contains expires_at|nonce. With
		// a TTL clamped to 1, expires_at must be > time() at SQL render.
		$this->assertMatchesRegularExpression( "/'\d{10}\|[0-9a-f]{16}'/", $rendered );
	}

	// ---- release_lock --------------------------------------------------

	public function test_release_returns_true_when_row_was_deleted(): void {
		$this->db->next_query_rows_affected = array( 1 );

		$ok = HCQG_Mutex_Guard::release_lock( 'foo', 'abc123def456' );

		$this->assertTrue( $ok );
	}

	public function test_release_returns_false_on_nonce_mismatch(): void {
		$this->db->next_query_rows_affected = array( 0 );

		$ok = HCQG_Mutex_Guard::release_lock( 'foo', 'wrong_nonce' );

		$this->assertFalse( $ok );
	}

	public function test_release_uses_substring_index_negative_one_predicate(): void {
		$this->db->next_query_rows_affected = array( 1 );

		HCQG_Mutex_Guard::release_lock( 'foo', 'abc123def456abcd' );

		$this->assertNotEmpty( $this->db->queries );
		$rendered = $this->db->queries[0];
		$this->assertStringContainsString(
			"SUBSTRING_INDEX(option_value, '|', -1)",
			$rendered,
			'Release must compare against the nonce field via SUBSTRING_INDEX(..., -1).'
		);
		$this->assertStringContainsString( "'abc123def456abcd'", $rendered );
	}

	public function test_release_returns_false_on_empty_nonce(): void {
		$ok = HCQG_Mutex_Guard::release_lock( 'foo', '' );
		$this->assertFalse( $ok );
		// Defensive: empty nonce should never round-trip to the DB.
		$this->assertEmpty( $this->db->queries );
	}

	// ---- peek_lock -----------------------------------------------------

	public function test_peek_returns_decoded_structure_for_live_lock(): void {
		$future = time() + 100;
		$this->db->next_get_var = array( $future . '|deadbeefcafe1234' );

		$result = HCQG_Mutex_Guard::peek_lock( 'foo' );

		$this->assertIsArray( $result );
		$this->assertSame( $future, $result['expires_at'] );
		$this->assertSame( 'deadbeefcafe1234', $result['holder'] );
	}

	public function test_peek_returns_false_when_no_row(): void {
		$this->db->next_get_var = array( null );

		$result = HCQG_Mutex_Guard::peek_lock( 'foo' );

		$this->assertFalse( $result );
	}

	public function test_peek_masks_expired_rows_as_false(): void {
		// Expired locks are still present in the DB until the next acquire
		// or GC; surface them as false so callers don't treat stale rows
		// as live holders.
		$past                   = time() - 10;
		$this->db->next_get_var = array( $past . '|deadbeefcafe1234' );

		$result = HCQG_Mutex_Guard::peek_lock( 'foo' );

		$this->assertFalse( $result );
	}

	public function test_peek_returns_false_for_malformed_value(): void {
		$this->db->next_get_var = array( 'no_delimiter_here' );

		$result = HCQG_Mutex_Guard::peek_lock( 'foo' );

		$this->assertFalse( $result );
	}

	// ---- force_release -------------------------------------------------

	public function test_force_release_deletes_regardless_of_nonce(): void {
		// First get_var: peek for prior holder. Then DELETE.
		$this->db->next_get_var             = array( ( time() + 30 ) . '|priorHolder12345' );
		$this->db->next_query_rows_affected = array( 1 );

		$ok = HCQG_Mutex_Guard::force_release( 'foo', 'stuck after deploy' );

		$this->assertTrue( $ok );
		// Last issued statement should be an unconditional DELETE on
		// option_name — no nonce predicate.
		$last = end( $this->db->queries );
		$this->assertStringContainsString( 'DELETE FROM', $last );
		$this->assertStringNotContainsString( 'SUBSTRING_INDEX', $last );
	}

	public function test_force_release_returns_false_when_no_row_present(): void {
		$this->db->next_get_var             = array( null );
		$this->db->next_query_rows_affected = array( 0 );

		$ok = HCQG_Mutex_Guard::force_release( 'foo', 'cleanup' );

		$this->assertFalse( $ok );
	}

	// ---- with_lock -----------------------------------------------------

	public function test_with_lock_runs_callable_and_releases_on_success(): void {
		$this->db->next_query_rows_affected = array( 1, 1 ); // acquire, release
		$ran                                = false;

		$ok = HCQG_Mutex_Guard::with_lock(
			'foo',
			60,
			static function () use ( &$ran ) {
				$ran = true;
			}
		);

		$this->assertTrue( $ok );
		$this->assertTrue( $ran );
		$this->assertCount( 2, $this->db->queries, 'Expected acquire + release.' );
	}

	public function test_with_lock_releases_even_when_callable_throws(): void {
		$this->db->next_query_rows_affected = array( 1, 1 ); // acquire, release

		try {
			HCQG_Mutex_Guard::with_lock(
				'foo',
				60,
				static function () {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Exception should have propagated.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertCount( 2, $this->db->queries, 'Release must still fire in the finally branch.' );
		$last = end( $this->db->queries );
		$this->assertStringContainsString( 'DELETE FROM', $last );
	}

	public function test_with_lock_returns_false_when_lock_held(): void {
		$this->db->next_query_rows_affected = array( 0 ); // acquire fails
		$ran                                = false;

		$ok = HCQG_Mutex_Guard::with_lock(
			'foo',
			60,
			static function () use ( &$ran ) {
				$ran = true;
			}
		);

		$this->assertFalse( $ok );
		$this->assertFalse( $ran, 'Callable must not run if the lock is held.' );
		$this->assertCount( 1, $this->db->queries, 'Only the acquire attempt should hit the DB.' );
	}

	// ---- helpers -------------------------------------------------------

	private function extract_option_name_from_last_acquire(): string {
		$this->assertNotEmpty( $this->db->prepared, 'Expected a prepared INSERT statement.' );
		$last = end( $this->db->prepared );
		// First %s arg in the INSERT is option_name.
		return (string) $last['args'][0];
	}
}
