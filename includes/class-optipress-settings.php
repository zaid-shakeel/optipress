<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Settings registry: defaults, sanitization, persistence.
 */
class OptiPress_Settings {

	const OPTION = 'optipress_settings';

	/** @var array|null */
	private $cache = null;

	/** @return array */
	public function defaults() {
		return array(
			// General.
			'auto_optimize'     => 1,
			'backup_originals'  => 1,
			'max_file_mb'       => 25,
			// Compression.
			'compression_mode'  => 'balanced', // lossless | balanced | lossy
			'quality'           => 82,
			'strip_meta'        => 1,
			// WebP.
			'webp_enabled'      => 1,
			'webp_quality'      => 80,
			'webp_auto'         => 1,
			// AVIF.
			'avif_enabled'      => 0,
			'avif_quality'      => 65,
			'avif_auto'         => 0,
			// Serving.
			'serve_enabled'     => 1,
			'serve_avif'        => 1,
			'serve_webp'        => 1,
			'serve_css_bg'      => 0,
			// Bulk.
			'batch_size'        => 5,
			'time_limit'        => 20,
			'auto_retry'        => 1,
			// Logs.
			'logs_enabled'      => 1,
			'log_retention'     => 30,
			// Advanced.
			'purge_caches'      => 1,
			'delete_on_uninstall' => 0,
		);
	}

	/** @return array */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), $this->defaults() );
		}
		return $this->cache;
	}

	/**
	 * @param string $key
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function ensure_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $this->defaults() );
		}
	}

	/**
	 * Sanitize + save an incoming settings array.
	 *
	 * @param array $input
	 * @return array {settings: array, warnings: string[]}
	 */
	public function save( $input ) {
		$cur      = $this->all();
		$out      = $cur;
		$warnings = array();
		$in       = is_array( $input ) ? $input : array();

		$bool = function ( $v ) { return ! empty( $v ) ? 1 : 0; };
		$int  = function ( $v, $min, $max, $fallback ) {
			$v = (int) $v;
			return ( $v < $min || $v > $max ) ? $fallback : $v;
		};

		$out['auto_optimize']    = $bool( isset( $in['auto_optimize'] ) ? $in['auto_optimize'] : 0 );
		$out['backup_originals'] = $bool( isset( $in['backup_originals'] ) ? $in['backup_originals'] : 0 );
		$out['max_file_mb']      = $int( isset( $in['max_file_mb'] ) ? $in['max_file_mb'] : 25, 1, 512, 25 );

		$mode = isset( $in['compression_mode'] ) ? (string) $in['compression_mode'] : 'balanced';
		$out['compression_mode'] = in_array( $mode, array( 'lossless', 'balanced', 'lossy' ), true ) ? $mode : 'balanced';
		$out['quality']          = $int( isset( $in['quality'] ) ? $in['quality'] : 82, 50, 95, 82 );
		$out['strip_meta']       = $bool( isset( $in['strip_meta'] ) ? $in['strip_meta'] : 0 );

		$env  = new OptiPress_Environment();
		$caps = $env->capabilities();

		$out['webp_enabled'] = $bool( isset( $in['webp_enabled'] ) ? $in['webp_enabled'] : 0 );
		if ( $out['webp_enabled'] && ! $caps['webp_encode'] ) {
			$out['webp_enabled'] = 0;
			$warnings[] = __( 'WebP was disabled: this server cannot encode WebP (requires GD with libwebp or Imagick with WebP support).', 'optipress' );
		}
		$out['webp_quality'] = $int( isset( $in['webp_quality'] ) ? $in['webp_quality'] : 80, 30, 100, 80 );
		$out['webp_auto']    = $bool( isset( $in['webp_auto'] ) ? $in['webp_auto'] : 0 );

		$out['avif_enabled'] = $bool( isset( $in['avif_enabled'] ) ? $in['avif_enabled'] : 0 );
		if ( $out['avif_enabled'] && ! $caps['avif_encode'] ) {
			$out['avif_enabled'] = 0;
			$warnings[] = __( 'AVIF was disabled: this server cannot encode AVIF. Ask your host for Imagick compiled with libavif/RAV1E, or PHP 8.1+ GD with libavif.', 'optipress' );
		}
		$out['avif_quality'] = $int( isset( $in['avif_quality'] ) ? $in['avif_quality'] : 65, 20, 100, 65 );
		$out['avif_auto']    = $bool( isset( $in['avif_auto'] ) ? $in['avif_auto'] : 0 );

		$out['serve_enabled'] = $bool( isset( $in['serve_enabled'] ) ? $in['serve_enabled'] : 0 );
		$out['serve_avif']    = $bool( isset( $in['serve_avif'] ) ? $in['serve_avif'] : 0 );
		$out['serve_webp']    = $bool( isset( $in['serve_webp'] ) ? $in['serve_webp'] : 0 );
		$out['serve_css_bg']  = $bool( isset( $in['serve_css_bg'] ) ? $in['serve_css_bg'] : 0 );

		$out['batch_size']  = $int( isset( $in['batch_size'] ) ? $in['batch_size'] : 5, 1, 25, 5 );
		$out['time_limit']  = $int( isset( $in['time_limit'] ) ? $in['time_limit'] : 20, 5, 60, 20 );
		$out['auto_retry']  = $bool( isset( $in['auto_retry'] ) ? $in['auto_retry'] : 0 );

		$out['logs_enabled']  = $bool( isset( $in['logs_enabled'] ) ? $in['logs_enabled'] : 0 );
		$out['log_retention'] = $int( isset( $in['log_retention'] ) ? $in['log_retention'] : 30, 1, 365, 30 );

		$out['purge_caches']       = $bool( isset( $in['purge_caches'] ) ? $in['purge_caches'] : 0 );
		$out['delete_on_uninstall'] = $bool( isset( $in['delete_on_uninstall'] ) ? $in['delete_on_uninstall'] : 0 );

		update_option( self::OPTION, $out );
		$this->cache = $out;

		return array( 'settings' => $out, 'warnings' => $warnings );
	}
}