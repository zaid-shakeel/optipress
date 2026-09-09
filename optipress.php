<?php
/**
 * Plugin Name:       OptiPress
 * Plugin URI:        https://websitesmechanic.com
 * Description:       Premium image optimization, WebP/AVIF conversion, auto-serving, analytics and logging for WordPress.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Websites Mechanic
 * License:           GPL-2.0-or-later
 * Text Domain:       optipress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OPTIPRESS_VERSION', '1.0.1' );
define( 'OPTIPRESS_FILE', __FILE__ );
define( 'OPTIPRESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'OPTIPRESS_URL', plugin_dir_url( __FILE__ ) );
define( 'OPTIPRESS_DB_VERSION', '1.0.1' );

/**
 * Hard gate: PHP too old → readable notice + self-deactivate. Never a fatal.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	function optipress_php_too_old_notice() {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p></div>',
			esc_html( sprintf(
				/* translators: %s: current PHP version */
				__( 'OptiPress requires PHP 7.4 or newer. This server is running PHP %s. Ask your host to upgrade PHP, then activate the plugin again.', 'optipress' ),
				PHP_VERSION
			) )
		);
	}
	function optipress_self_deactivate() {
		if ( function_exists( 'deactivate_plugins' ) ) {
			deactivate_plugins( plugin_basename( OPTIPRESS_FILE ) );
		}
	}
	add_action( 'admin_notices', 'optipress_php_too_old_notice' );
	add_action( 'admin_init', 'optipress_self_deactivate' );
	return;
}

/**
 * Load core files with existence checks, so a broken/incomplete upload produces
 * a readable activation error instead of a white-screen fatal.
 */
$GLOBALS['optipress_missing_files'] = array();

$optipress_core_files = array(
	'includes/class-optipress-plugin.php',
	'includes/class-optipress-settings.php',
	'includes/class-optipress-environment.php',
	'includes/class-optipress-db.php',
	'includes/class-optipress-logger.php',
	'includes/class-optipress-stats.php',
	'includes/class-optipress-media.php',
	'includes/class-optipress-backup.php',
	'includes/class-optipress-processor.php',
	'includes/class-optipress-converter.php',
	'includes/class-optipress-queue.php',
	'includes/class-optipress-autoserve.php',
	'includes/class-optipress-cache-purge.php',
	'includes/class-optipress-integrations.php',
	'includes/class-optipress-ajax.php',
	'includes/class-optipress-admin.php',
);

foreach ( $optipress_core_files as $optipress_file ) {
	if ( ! file_exists( OPTIPRESS_PATH . $optipress_file ) ) {
		$GLOBALS['optipress_missing_files'][] = $optipress_file;
		continue;
	}
	require_once OPTIPRESS_PATH . $optipress_file;
}

if ( ! empty( $GLOBALS['optipress_missing_files'] ) ) {
	add_action( 'admin_notices', function () {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong><br><code>%s</code></p></div>',
			esc_html__( 'OptiPress installation is incomplete — the following files are missing:', 'optipress' ),
			esc_html( implode( ', ', $GLOBALS['optipress_missing_files'] ) )
		);
	} );
}

/**
 * Activation with self-checks. Any failure is thrown as an Exception so
 * WordPress prints the actual reason on the Plugins screen.
 */
function optipress_activate() {
	if ( ! empty( $GLOBALS['optipress_missing_files'] ) ) {
		throw new Exception( sprintf(
			'OptiPress cannot activate because files are missing from the upload: %s. Delete the optipress folder and re-upload the complete plugin.',
			implode( ', ', $GLOBALS['optipress_missing_files'] )
		) );
	}
	if ( ! class_exists( 'OptiPress_Plugin' ) ) {
		throw new Exception( 'The OptiPress core class failed to load. Re-upload the plugin files (the copy in includes/class-optipress-plugin.php may be corrupted).' );
	}
	OptiPress_Plugin::activate();
}
register_activation_hook( __FILE__, 'optipress_activate' );

register_deactivation_hook( __FILE__, function () {
	if ( class_exists( 'OptiPress_Plugin' ) ) {
		OptiPress_Plugin::deactivate();
		return;
	}
	wp_clear_scheduled_hook( 'optipress_daily_cleanup' );
	wp_clear_scheduled_hook( 'optipress_auto_optimize' );
	delete_option( 'optipress_bulk_lock' );
} );

/**
 * Boot with error capture: a runtime problem becomes an admin notice,
 * never a site-wide fatal.
 */
function optipress_boot() {
	if ( ! empty( $GLOBALS['optipress_missing_files'] ) ) {
		return;
	}
	try {
		optipress();
	} catch ( \Throwable $optipress_error ) {
		$GLOBALS['optipress_boot_error'] = $optipress_error;
		add_action( 'admin_notices', 'optipress_boot_error_notice' );
	}
}
function optipress_boot_error_notice() {
	if ( empty( $GLOBALS['optipress_boot_error'] ) ) {
		return;
	}
	$e = $GLOBALS['optipress_boot_error'];
	printf(
		'<div class="notice notice-error"><p><strong>%s</strong><br><code>%s</code></p></div>',
		esc_html__( 'OptiPress failed to start and paused itself for this request:', 'optipress' ),
		esc_html( get_class( $e ) . ': ' . $e->getMessage() . ' in ' . wp_basename( $e->getFile() ) . ' on line ' . $e->getLine() )
	);
}
add_action( 'plugins_loaded', 'optipress_boot', 5 );

if ( ! function_exists( 'optipress' ) ) {
	/**
	 * Main plugin accessor.
	 *
	 * @return OptiPress_Plugin
	 */
	function optipress() {
		return OptiPress_Plugin::instance();
	}
}