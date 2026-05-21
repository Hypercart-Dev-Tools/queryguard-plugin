<?php
/**
 * Tests for the Hypercart_Query_Guard logging bridge.
 *
 * @package Hypercart
 */

use PHPUnit\Framework\TestCase;

final class HCQG_String_Logger {
	public static function info( string $channel, string $message ): void {}
}

final class LoggingTest extends TestCase {

	protected function setUp(): void {
		WP_Stub_State::reset();
		Hypercart_Logger::reset();
	}

	/**
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
	 * @return array<string,mixed>
	 */
	private function run_fixture( string $fixture ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/' . $fixture ) . ' 2>&1';
		$output  = array();
		$exit    = 0;

		exec( $command, $output, $exit );

		$this->assertSame( 0, $exit, implode( "\n", $output ) );

		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result, implode( "\n", $output ) );

		return $result;
	}

	public function test_log_serializes_payload_for_hypercart_logger(): void {
		$payload = array(
			'event' => 'as_throttle_observed',
			'level' => 'critical',
			'probe' => 12,
		);

		$this->call( 'log', 'info', $payload );

		$this->assertCount( 1, Hypercart_Logger::$calls );
		$this->assertSame( 'query_guard', Hypercart_Logger::$calls[0]['channel'] );
		$this->assertSame( 'info', Hypercart_Logger::$calls[0]['level'] );
		$this->assertSame( $payload, Hypercart_Logger::$calls[0]['payload'] );
	}

	public function test_string_only_logger_methods_receive_json_payload(): void {
		$payload = array(
			'event' => 'as_throttle_observed',
			'level' => 'critical',
			'probe' => 12,
		);

		$result = $this->call(
			'get_logger_payload_for_method',
			'HCQG_String_Logger',
			'info',
			$payload,
			wp_json_encode( $payload )
		);

		$this->assertSame( wp_json_encode( $payload ), $result );
	}

	public function test_log_dispatches_json_to_string_only_logger_end_to_end(): void {
		$result = $this->run_fixture( 'string-logger-integration.php' );

		$this->assertCount( 1, $result['calls'] );
		$this->assertSame( 'query_guard', $result['calls'][0]['channel'] );
		$this->assertSame( 'info', $result['calls'][0]['level'] );
		$this->assertSame( wp_json_encode( $result['payload'] ), $result['calls'][0]['payload'] );
	}
}