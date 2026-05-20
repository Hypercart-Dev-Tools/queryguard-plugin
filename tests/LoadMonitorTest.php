<?php
/**
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

final class LoadMonitorTest extends TestCase {

	protected function setUp(): void {
		WP_Stub_State::reset();
		$this->resetLoadMonitorStatics();
	}

	private function resetLoadMonitorStatics(): void {
		$reflection = new ReflectionClass( HCQG_Load_Monitor::class );
		foreach ( array( 'state_cache', 'cache_backend' ) as $name ) {
			$prop = $reflection->getProperty( $name );
			if ( PHP_VERSION_ID < 80100 ) {
				$prop->setAccessible( true );
			}
			$prop->setValue( null, null );
		}
	}

	private function thresholds( array $overrides = array() ): array {
		return array_merge( HCQG_Load_Monitor::get_thresholds(), $overrides );
	}

	private function metrics( $threads, $queue, string $detector_mode = 'mixed' ): array {
		return array(
			'threads_running' => $threads,
			'queue_depth'     => $queue,
			'probe_ms'        => array(
				'threads_running' => 0,
				'queue_depth'     => 0,
			),
			'errors'          => array(
				'threads_running' => '',
				'queue_depth'     => '',
			),
			'detector_mode'   => $detector_mode,
		);
	}

	// ---- get_thresholds --------------------------------------------------

	public function test_get_thresholds_returns_defaults(): void {
		$thresholds = HCQG_Load_Monitor::get_thresholds();
		$this->assertSame( 5, $thresholds['threads_running_elevated'] );
		$this->assertSame( 15, $thresholds['threads_running_critical'] );
		$this->assertSame( 30, $thresholds['level_min_dwell_seconds'] );
	}

	public function test_get_thresholds_filter_overrides_and_clamps(): void {
		add_filter(
			'hypercart_query_guard_load_thresholds',
			static function ( $defaults ) {
				return array_merge(
					$defaults,
					array(
						'threads_running_elevated' => 7,
						'threads_running_critical' => -3, // clamped to 0
					)
				);
			}
		);
		$thresholds = HCQG_Load_Monitor::get_thresholds();
		$this->assertSame( 7, $thresholds['threads_running_elevated'] );
		$this->assertSame( 0, $thresholds['threads_running_critical'] );
	}

	public function test_get_thresholds_rejects_non_array_filter(): void {
		add_filter(
			'hypercart_query_guard_load_thresholds',
			static function () {
				return 'garbage';
			}
		);
		$thresholds = HCQG_Load_Monitor::get_thresholds();
		$this->assertSame( 5, $thresholds['threads_running_elevated'] );
	}

	// ---- evaluate_level: enter ------------------------------------------

	public function test_normal_to_elevated_when_threads_cross_elevated(): void {
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 6, 0 ),
			$this->thresholds(),
			'normal',
			0
		);
		$this->assertSame( 'elevated', $result['level'] );
		$this->assertSame( 'elevated', $result['raw_level'] );
	}

	public function test_normal_to_critical_when_threads_cross_critical(): void {
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 20, 0 ),
			$this->thresholds(),
			'normal',
			0
		);
		$this->assertSame( 'critical', $result['level'] );
	}

	public function test_picks_higher_severity_across_metrics(): void {
		// threads_running is normal, queue_depth is critical → critical wins.
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 1, 600 ),
			$this->thresholds(),
			'normal',
			0
		);
		$this->assertSame( 'critical', $result['level'] );
	}

	public function test_no_signals_forces_elevated_failsafe(): void {
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( null, null, 'none' ),
			$this->thresholds(),
			'normal',
			0
		);
		$this->assertSame( 'elevated', $result['level'] );
		$this->assertSame( 'elevated', $result['raw_level'] );
	}

	// ---- evaluate_level: hysteresis exits --------------------------------

	public function test_critical_holds_above_critical_exit(): void {
		// threads_running 12 is below critical (15) but above critical_exit (10).
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 12, 0 ),
			$this->thresholds(),
			'critical',
			time() - 1000 // dwell already expired so we know hysteresis (not dwell) is doing the work
		);
		$this->assertSame( 'critical', $result['level'] );
	}

	public function test_critical_drops_to_elevated_when_below_critical_exit_but_above_elevated(): void {
		// threads_running 6 is below critical_exit (10) but >= elevated (5).
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 6, 0 ),
			$this->thresholds(),
			'critical',
			time() - 1000
		);
		$this->assertSame( 'elevated', $result['level'] );
	}

	public function test_elevated_drops_to_normal_when_below_elevated_exit(): void {
		// threads_running 2 is below elevated_exit (3).
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 2, 0 ),
			$this->thresholds(),
			'elevated',
			time() - 1000
		);
		$this->assertSame( 'normal', $result['level'] );
	}

	// ---- evaluate_level: minimum dwell -----------------------------------

	public function test_dwell_blocks_severity_decrease_within_window(): void {
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 0, 0 ),
			$this->thresholds(),
			'critical',
			time() - 5 // 5s ago, well under 30s dwell
		);
		// Stays critical because dwell hasn't elapsed; raw level is what we'd
		// have transitioned to without dwell.
		$this->assertSame( 'critical', $result['level'] );
		$this->assertSame( 'normal', $result['raw_level'] );
	}

	public function test_dwell_does_not_block_severity_increase(): void {
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 20, 0 ),
			$this->thresholds(),
			'normal',
			time() - 1
		);
		$this->assertSame( 'critical', $result['level'] );
	}

	public function test_dwell_expired_allows_drop(): void {
		$result = HCQG_Load_Monitor::evaluate_level(
			$this->metrics( 0, 0 ),
			$this->thresholds(),
			'critical',
			time() - 60 // dwell elapsed
		);
		$this->assertSame( 'normal', $result['level'] );
	}

	// ---- cache backend selection ----------------------------------------

	public function test_cache_backend_db_fallback_when_no_ext_cache(): void {
		WP_Stub_State::$ext_object_cache = false;
		$this->assertSame( 'db_fallback', HCQG_Load_Monitor::get_cache_backend() );
	}

	public function test_cache_backend_persistent_object_cache_when_available(): void {
		WP_Stub_State::$ext_object_cache = true;
		$this->assertSame( 'persistent_object_cache', HCQG_Load_Monitor::get_cache_backend() );
	}

	public function test_cache_backend_memoized_per_request(): void {
		WP_Stub_State::$ext_object_cache = false;
		$this->assertSame( 'db_fallback', HCQG_Load_Monitor::get_cache_backend() );
		// Flip the global; a memoized call should still report the original.
		WP_Stub_State::$ext_object_cache = true;
		$this->assertSame( 'db_fallback', HCQG_Load_Monitor::get_cache_backend() );
	}

	// ---- read_state / persist_state -------------------------------------

	public function test_read_state_default_when_nothing_persisted(): void {
		$state = HCQG_Load_Monitor::read_state();
		$this->assertSame( 'normal', $state['level'] );
		$this->assertSame( 0, $state['changed_at'] );
	}

	public function test_persist_then_read_db_fallback(): void {
		WP_Stub_State::$ext_object_cache = false;
		HCQG_Load_Monitor::persist_state(
			array(
				'level'      => 'critical',
				'changed_at' => 12345,
			)
		);
		// Reset the in-process memo so the read goes back through the backend.
		$this->resetLoadMonitorStatics();
		WP_Stub_State::$ext_object_cache = false;

		$state = HCQG_Load_Monitor::read_state();
		$this->assertSame( 'critical', $state['level'] );
		$this->assertSame( 12345, $state['changed_at'] );
	}

	public function test_persist_then_read_object_cache(): void {
		WP_Stub_State::$ext_object_cache = true;
		HCQG_Load_Monitor::persist_state(
			array(
				'level'      => 'elevated',
				'changed_at' => 99,
			)
		);
		$this->resetLoadMonitorStatics();
		WP_Stub_State::$ext_object_cache = true;

		$state = HCQG_Load_Monitor::read_state();
		$this->assertSame( 'elevated', $state['level'] );
		$this->assertSame( 99, $state['changed_at'] );
	}

	public function test_persist_state_normalizes_missing_fields(): void {
		WP_Stub_State::$ext_object_cache = false;
		HCQG_Load_Monitor::persist_state( array() );
		$this->resetLoadMonitorStatics();

		$state = HCQG_Load_Monitor::read_state();
		$this->assertSame( 'normal', $state['level'] );
		$this->assertSame( 0, $state['changed_at'] );
	}
}
