<?php

defined( 'ABSPATH' ) || exit;

class Versi_Container {

	private static array $services = array();

	/**
	 * Register a service with an optional factory callable.
	 * If no factory is given, the class must have a public constructor.
	 */
	public static function register( string $class, ?callable $factory = null ): void {
		if ( ! isset( self::$services[ $class ] ) ) {
			self::$services[ $class ] = $factory ? $factory() : new $class();
		}
	}

	/**
	 * Retrieve a registered service instance.
	 */
	public static function get( string $class ): object {
		if ( ! isset( self::$services[ $class ] ) ) {
			throw new \RuntimeException( esc_html( "Service not registered: {$class}" ) );
		}
		return self::$services[ $class ];
	}
}
