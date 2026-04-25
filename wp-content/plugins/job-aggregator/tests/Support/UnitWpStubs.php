<?php

namespace JobAggregator\Tests\Support;

class UnitWpState {
	public static $options = array();
	public static $schedules = array();

	public static function reset() {
		self::$options   = array();
		self::$schedules = array();
	}
}

namespace JobAggregator\Support;

use JobAggregator\Tests\Support\UnitWpState;

if ( ! function_exists( __NAMESPACE__ . '\\get_option' ) ) {
	function get_option( $option, $default = false ) {
		if ( array_key_exists( $option, UnitWpState::$options ) ) {
			return UnitWpState::$options[ $option ];
		}

		return $default;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\update_option' ) ) {
	function update_option( $option, $value ) {
		UnitWpState::$options[ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		$args = is_array( $args ) ? $args : array();

		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_get_schedules' ) ) {
	function wp_get_schedules() {
		return UnitWpState::$schedules;
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );

		return is_string( $key ) ? $key : '';
	}
}

namespace JobAggregator;

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );

		return is_string( $key ) ? $key : '';
	}
}

namespace JobAggregator\Jobs;

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		$key = preg_replace( '/[^a-z0-9_\-]/', '', $key );

		return is_string( $key ) ? $key : '';
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/' );
	}
}
