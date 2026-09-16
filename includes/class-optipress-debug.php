<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Centralized debug logger. Silent unless OPTIPRESS_DEBUG is true.
 */
class OptiPress_Debug {
	private static $enabled = null;

	public static function enabled() {
		if ( null === self::$enabled ) {
			self::$enabled = ( defined( 'OPTIPRESS_DEBUG' ) && OPTIPRESS_DEBUG );
		}
		return self::$enabled;
	}

	/**
	 * @param string $phase   e.g. 'processor.start', 'converter.skip', 'restore.rollback'
	 * @param string $message Human-readable
	 * @param array  $context Data to log
	 */
	public static function log( $phase, $message, $context = array() ) {
		if ( ! self::enabled() ) {
			return;
		}
		$line = sprintf(
			'[OptiPress:%s] %s | ctx=%s',
			$phase,
			$message,
			wp_json_encode( $context )
		);
		error_log( $line );

		// Also write to the OptiPress logs table if plugin is loaded.
		if ( function_exists( 'optipress' ) && isset( $GLOBALS['optipress_plugin_ready'] ) ) {
			try {
				optipress()->logger->info( 'debug', $message, array(
					'context' => array( 'phase' => $phase ) + (array) $context,
				) );
			} catch ( \Throwable $e ) {
				// Silent — debug logging must never break the plugin.
			}
		}
	}
}