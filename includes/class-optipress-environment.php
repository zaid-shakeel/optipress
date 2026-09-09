<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Server capability detection. Results are cached per request + short transient.
 */
class OptiPress_Environment {

	/** @return array */
	public function capabilities() {
		$cached = get_transient( 'optipress_caps' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$gd_ok      = extension_loaded( 'gd' ) && function_exists( 'gd_info' );
		$imagick_ok = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );

		$gd_webp      = $gd_ok && function_exists( 'imagewebp' ) && function_exists( 'imagetypes' ) && ( imagetypes() & IMG_WEBP );
		$imagick_webp = false;
		$gd_avif      = $gd_ok && function_exists( 'imageavif' ) && defined( 'IMG_AVIF' ) && function_exists( 'imagetypes' ) && ( imagetypes() & IMG_AVIF );
		$imagick_avif = false;

		if ( $imagick_ok ) {
			try {
				$formats = \Imagick::queryFormats( 'WEBP' );
				$imagick_webp = ! empty( $formats );
			} catch ( \Throwable $e ) { $imagick_webp = false; }
			try {
				$formats = \Imagick::queryFormats( 'AVIF' );
				$imagick_avif = ! empty( $formats );
			} catch ( \Throwable $e ) { $imagick_avif = false; }
		}

		$uploads = wp_get_upload_dir();

		$caps = array(
			'php_version'      => PHP_VERSION,
			'php_ok'           => version_compare( PHP_VERSION, '7.4', '>=' ),
			'wp_version'       => get_bloginfo( 'version' ),
			'gd'               => (bool) $gd_ok,
			'imagick'          => (bool) $imagick_ok,
			'imagick_version'  => $imagick_ok ? phpversion( 'imagick' ) : '',
			'engine'           => $imagick_ok ? 'imagick' : ( $gd_ok ? 'gd' : 'none' ),
			'webp_gd'          => (bool) $gd_webp,
			'webp_imagick'     => (bool) $imagick_webp,
			'webp_encode'      => (bool) ( $gd_webp || $imagick_webp ),
			'avif_gd'          => (bool) $gd_avif,
			'avif_imagick'     => (bool) $imagick_avif,
			'avif_encode'      => (bool) ( $gd_avif || $imagick_avif ),
			'avif_reason'      => '',
			'memory_limit'     => ini_get( 'memory_limit' ),
			'max_execution'    => ini_get( 'max_execution_time' ),
			'upload_max'       => ini_get( 'upload_max_filesize' ),
			'post_max'         => ini_get( 'post_max_size' ),
			'uploads_writable' => is_writable( $uploads['basedir'] ),
			'disk_free'        => @disk_free_space( $uploads['basedir'] ),
		);

		if ( ! $caps['avif_encode'] ) {
			$caps['avif_reason'] = __( 'AVIF generation is unavailable because the current server does not provide the required image-processing capability. OptiPress needs either Imagick compiled with an AVIF encoder (libavif / rav1e / aom) or PHP 8.1+ GD compiled with libavif. Neither was detected.', 'optipress' );
		}
		if ( ! $caps['webp_encode'] ) {
			$caps['avif_reason'] .= ' ' . __( 'WebP encoding is also unavailable on this server.', 'optipress' );
		}

		set_transient( 'optipress_caps', $caps, 15 * MINUTE_IN_SECONDS );
		return $caps;
	}

	public function flush() {
		delete_transient( 'optipress_caps' );
	}

	/**
	 * Human-readable status rows for the System screen.
	 * @return array[]
	 */
	public function report() {
		$c = $this->capabilities();
		$rows = array();

		$rows[] = array(
			'label' => __( 'PHP Version', 'optipress' ),
			'value' => $c['php_version'],
			'status' => $c['php_ok'] ? 'ok' : 'bad',
			'note' => $c['php_ok'] ? '' : __( 'PHP 7.4 or newer is required.', 'optipress' ),
		);
		$rows[] = array(
			'label' => __( 'WordPress Version', 'optipress' ),
			'value' => $c['wp_version'],
			'status' => version_compare( $c['wp_version'], '6.0', '>=' ) ? 'ok' : 'bad',
			'note' => '',
		);
		$rows[] = array(
			'label' => __( 'Image Engine', 'optipress' ),
			'value' => 'imagick' === $c['engine'] ? ( 'Imagick ' . $c['imagick_version'] ) : ( 'gd' === $c['engine'] ? 'GD' : __( 'None', 'optipress' ) ),
			'status' => 'none' === $c['engine'] ? 'bad' : ( 'imagick' === $c['engine'] ? 'ok' : 'limited' ),
			'note' => 'gd' === $c['engine'] ? __( 'GD works, but Imagick produces better compression and strips metadata more reliably.', 'optipress' ) : ( 'none' === $c['engine'] ? __( 'No image library found. Optimization cannot run.', 'optipress' ) : '' ),
		);
		$rows[] = array(
			'label' => __( 'WebP Encoding', 'optipress' ),
			'value' => $c['webp_encode'] ? ( $c['webp_imagick'] ? __( 'Supported (Imagick)', 'optipress' ) : __( 'Supported (GD)', 'optipress' ) ) : __( 'Unavailable', 'optipress' ),
			'status' => $c['webp_encode'] ? 'ok' : 'bad',
			'note' => $c['webp_encode'] ? '' : __( 'Install/enable libwebp support in GD or Imagick to unlock WebP.', 'optipress' ),
		);
		$rows[] = array(
			'label' => __( 'AVIF Encoding', 'optipress' ),
			'value' => $c['avif_encode'] ? ( $c['avif_imagick'] ? __( 'Supported (Imagick)', 'optipress' ) : __( 'Supported (GD)', 'optipress' ) ) : __( 'Unavailable', 'optipress' ),
			'status' => $c['avif_encode'] ? 'ok' : 'limited',
			'note' => $c['avif_encode'] ? '' : $c['avif_reason'],
		);
		$rows[] = array(
			'label' => __( 'Uploads Directory', 'optipress' ),
			'value' => $c['uploads_writable'] ? __( 'Writable', 'optipress' ) : __( 'Not writable', 'optipress' ),
			'status' => $c['uploads_writable'] ? 'ok' : 'bad',
			'note' => $c['uploads_writable'] ? '' : __( 'OptiPress cannot write optimized files. Fix directory permissions (usually 755).', 'optipress' ),
		);
		$rows[] = array(
			'label' => __( 'PHP Memory Limit', 'optipress' ),
			'value' => $c['memory_limit'],
			'status' => ( $this->to_bytes( $c['memory_limit'] ) >= 128 * MB_IN_BYTES || -1 === $this->to_bytes( $c['memory_limit'] ) ) ? 'ok' : 'limited',
			'note' => __( 'Very large images may fail below 256M. OptiPress raises the limit per-file when possible.', 'optipress' ),
		);
		$rows[] = array(
			'label' => __( 'Max Execution Time', 'optipress' ),
			'value' => $c['max_execution'] . 's',
			'status' => 'ok',
			'note' => __( 'OptiPress processes in small batches sized to this limit.', 'optipress' ),
		);
		$rows[] = array(
			'label' => __( 'Upload Limits', 'optipress' ),
			'value' => sprintf( 'upload_max_filesize %s / post_max_size %s', $c['upload_max'], $c['post_max'] ),
			'status' => 'ok',
			'note' => '',
		);
		$rows[] = array(
			'label' => __( 'Free Disk Space', 'optipress' ),
			'value' => $c['disk_free'] ? size_format( $c['disk_free'] ) : __( 'Unknown', 'optipress' ),
			'status' => ( $c['disk_free'] && $c['disk_free'] < 100 * MB_IN_BYTES ) ? 'bad' : 'ok',
			'note' => ( $c['disk_free'] && $c['disk_free'] < 100 * MB_IN_BYTES ) ? __( 'Low disk space. Backups and conversions may fail.', 'optipress' ) : '',
		);

		return $rows;
	}

	/** @param string $val @return int */
	private function to_bytes( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) { return 0; }
		if ( '-1' === $val ) { return -1; }
		$last = strtolower( substr( $val, -1 ) );
		$num  = (int) $val;
		switch ( $last ) {
			case 'g': $num *= GB_IN_BYTES; break;
			case 'm': $num *= MB_IN_BYTES; break;
			case 'k': $num *= KB_IN_BYTES; break;
		}
		return $num;
	}
}