<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cache invalidation via official plugin APIs only. Throttled so bulk runs
 * purge once at the end rather than per image.
 */
class OptiPress_Cache_Purge {

	/** @var OptiPress_Plugin */ private $p;

	public function __construct( $plugin ) {
		$this->p = $plugin;
	}

	/** @param bool $force */
	public function maybe_purge( $force = false ) {
		if ( ! $this->p->settings->get( 'purge_caches' ) ) {
			return array();
		}
		if ( ! $force && get_transient( 'optipress_purge_throttle' ) ) {
			return array();
		}
		set_transient( 'optipress_purge_throttle', 1, 60 );

		$purged = array();

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			try { rocket_clean_domain(); $purged[] = 'WP Rocket'; } catch ( \Throwable $e ) {}
		}
		// LiteSpeed Cache.
		if ( defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_all' );
			$purged[] = 'LiteSpeed Cache';
		}
		// W3 Total Cache.
		if ( defined( 'W3TC' ) ) {
			do_action( 'w3tc_flush_all' );
			$purged[] = 'W3 Total Cache';
		}
		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$purged[] = 'WP Super Cache';
		}
		// Autoptimize.
		if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
			do_action( 'autoptimize_action_cachepurge' );
			$purged[] = 'Autoptimize';
		}

		if ( $purged ) {
			$this->p->logger->info( 'system', sprintf(
				/* translators: %s: comma-separated plugin names */
				__( 'Page caches purged after image operations: %s.', 'optipress' ), implode( ', ', $purged )
			) );
		}
		return $purged;
	}
}