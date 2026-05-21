<?php
/**
 * End-to-end fixture for the string-only Hypercart_Logger code path.
 *
 * @package Hypercart
 */

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'HYPERCART_QUERY_GUARD_MODE', 'off' );
define( 'HYPERCART_QUERY_GUARD_THROTTLE_MODE', 'off' );

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

final class Hypercart_Logger {
	/** @var array<int,array{level:string,channel:string,payload:string}> */
	public static $calls = array();

	public static function error( string $channel, string $payload ): void {
		self::$calls[] = array(
			'level'   => 'error',
			'channel' => $channel,
			'payload' => $payload,
		);
	}

	public static function info( string $channel, string $payload ): void {
		self::$calls[] = array(
			'level'   => 'info',
			'channel' => $channel,
			'payload' => $payload,
		);
	}

	public static function warn( string $channel, string $payload ): void {
		self::$calls[] = array(
			'level'   => 'warn',
			'channel' => $channel,
			'payload' => $payload,
		);
	}
}

require_once dirname( __DIR__, 2 ) . '/hypercart-query-guard.php';

$payload = array(
	'event' => 'as_throttle_observed',
	'level' => 'critical',
	'probe' => 12,
);

$invoke = Closure::bind(
	static function ( string $level, array $record ): void {
		Hypercart_Query_Guard::log( $level, $record );
	},
	null,
	Hypercart_Query_Guard::class
);

$invoke( 'info', $payload );

echo json_encode(
	array(
		'calls'   => Hypercart_Logger::$calls,
		'payload' => $payload,
	)
), PHP_EOL;