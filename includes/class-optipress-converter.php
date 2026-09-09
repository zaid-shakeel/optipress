<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WebP / AVIF generation with detailed failure diagnostics, engine fallback,
 * and memory headroom for large files. Produces sibling files.
 */
class OptiPress_Converter {

	/** @var OptiPress_Plugin */
	private $p;

	public function __construct( $plugin ) {
		$this->p = $plugin;
	}

	/**
	 * @param int    $item_id
	 * @param string $format webp|avif
	 * @return array {ok,message,files?,bytes?}
	 */
	public function generate( $item_id, $format ) {
		$format = 'avif' === $format ? 'avif' : 'webp';
		$item   = $this->p->db->get_item( (int) $item_id );
		if ( ! $item ) {
			return array( 'ok' => false, 'message' => __( 'Image record not found.', 'optipress' ) );
		}

		$caps = $this->p->env->capabilities();
		if ( empty( $caps[ $format . '_encode' ] ) ) {
			$msg = 'avif' === $format
				? __( 'AVIF generation is unavailable because the current server does not provide the required image-processing capability.', 'optipress' )
				: __( 'WebP generation is unavailable on this server.', 'optipress' );
			$this->p->db->update_item( (int) $item->id, array( $format . '_status' => 'failed' ) );
			$this->p->logger->error( $format, $msg, array( 'attachment_id' => (int) $item->attachment_id, 'file' => $item->file ) );
			return array( 'ok' => false, 'message' => $msg );
		}

		$uploads = OptiPress_Plugin::uploads();
		$abs     = trailingslashit( $uploads['basedir'] ) . $item->file;
		if ( ! file_exists( $abs ) ) {
			$this->p->db->update_item( (int) $item->id, array( $format . '_status' => 'failed' ) );
			return array( 'ok' => false, 'message' => __( 'Source file no longer exists.', 'optipress' ) );
		}

		$meta = wp_get_attachment_metadata( (int) $item->attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		$files = array( $abs );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $abs );
			foreach ( $meta['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$fp = $dir . '/' . $s['file'];
					if ( file_exists( $fp ) && ! in_array( $fp, $files, true ) ) {
						$files[] = $fp;
					}
				}
			}
		}

		$quality = (int) $this->p->settings->get( $format . '_quality' );
		$done    = 0;
		$failed  = 0;
		$bytes   = 0;
		$reasons = array();

		foreach ( $files as $src ) {
			$dest = preg_replace( '/\.(jpe?g|png|gif|webp)$/i', '', $src ) . '.' . $format;
			$r    = $this->encode( $src, $dest, $format, $quality, $caps );
			if ( $r['ok'] ) {
				$done++;
				$bytes += (int) @filesize( $dest );
			} else {
				$failed++;
				if ( count( $reasons ) < 4 ) {
					$reasons[] = wp_basename( $src ) . ' — ' . $r['reason'];
				}
			}
		}

		$status_col = $format . '_status';
		$bytes_col  = $format . '_bytes';
		$fmt_label  = strtoupper( $format );

		if ( 0 === $done ) {
			$reason_text = $reasons ? implode( ' | ', $reasons ) : __( 'unknown encoder error', 'optipress' );
			$this->p->db->update_item( (int) $item->id, array( $status_col => 'failed' ) );
			$this->p->logger->error( $format, sprintf(
				/* translators: 1: format, 2: reasons */
				__( '%1$s conversion failed for all sizes. Reasons: %2$s', 'optipress' ),
				$fmt_label, $reason_text
			), array(
				'attachment_id' => (int) $item->attachment_id,
				'file'          => $item->file,
				'context'       => array( 'reasons' => $reasons ),
			) );
			return array(
				'ok'      => false,
				'message' => sprintf( __( '%1$s conversion failed: %2$s', 'optipress' ), $fmt_label, $reason_text ),
			);
		}

		$this->p->db->update_item( (int) $item->id, array( $status_col => 'done', $bytes_col => $bytes ) );
		$this->p->db->bump_daily( $format, $done );

		$message = sprintf(
			/* translators: 1: format, 2: file count, 3: total size */
			__( '%1$s generated for %2$d files (%3$s).', 'optipress' ),
			$fmt_label, $done, size_format( $bytes )
		);
		if ( $failed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: 1: count, 2: reasons */
				__( '%1$d files failed: %2$s', 'optipress' ),
				$failed, implode( ' | ', $reasons )
			);
		}

		$this->p->logger->success( $format, $message, array(
			'attachment_id' => (int) $item->attachment_id,
			'file'          => $item->file,
			'context'       => array( 'files' => $done, 'failed' => $failed, 'bytes' => $bytes, 'reasons' => $reasons ),
		) );
		$this->p->stats->flush();

		return array( 'ok' => true, 'message' => $message, 'files' => $done, 'bytes' => $bytes );
	}

	/**
	 * Standalone encoder test used by the Doctor. Encodes any source file.
	 *
	 * @param string $src
	 * @param string $format webp|avif
	 * @param bool   $keep   Keep the generated file (for further testing).
	 * @return array {ok,reason,bytes?}
	 */
	public function test_encode( $src, $format, $keep = false ) {
		$format = 'avif' === $format ? 'avif' : 'webp';
		$caps   = $this->p->env->capabilities();
		if ( empty( $caps[ $format . '_encode' ] ) ) {
			return array( 'ok' => false, 'reason' => __( 'capability detection says this format is unsupported', 'optipress' ) );
		}
		$dest = preg_replace( '/\.(jpe?g|png|gif|webp)$/i', '', $src ) . '.' . $format;
		$r    = $this->encode( $src, $dest, $format, 80, $caps );
		if ( $r['ok'] ) {
			$r['bytes'] = (int) @filesize( $dest );
			if ( ! $keep ) {
				@unlink( $dest );
			}
		}
		return $r;
	}

	/**
	 * Encode one file. Tries the preferred engine, then falls back to the other.
	 *
	 * @return array {ok:bool, reason:string}
	 */
	private function encode( $src, $dest, $format, $quality, $caps ) {
		if ( ! file_exists( $src ) || ! is_readable( $src ) ) {
			return array( 'ok' => false, 'reason' => __( 'source file is missing or unreadable', 'optipress' ) );
		}
		if ( ! is_writable( dirname( $dest ) ) ) {
			return array( 'ok' => false, 'reason' => __( 'the destination folder is not writable', 'optipress' ) );
		}

		$this->raise_memory_for( $src );

		$imagick_can = ! empty( $caps['imagick'] ) && ! empty( $caps[ $format . '_imagick' ] );
		$gd_can      = ! empty( $caps['gd'] ) && ! empty( $caps[ $format . '_gd' ] );

		$order = ( 'imagick' === $caps['engine'] ) ? array( 'imagick', 'gd' ) : array( 'gd', 'imagick' );
		$order = array_values( array_filter( $order, function ( $engine ) use ( $imagick_can, $gd_can ) {
			return ( 'imagick' === $engine && $imagick_can ) || ( 'gd' === $engine && $gd_can );
		} ) );

		if ( empty( $order ) ) {
			return array( 'ok' => false, 'reason' => __( 'no encoder is actually available for this format on this server', 'optipress' ) );
		}

		$expected_mime = ( 'webp' === $format ) ? 'image/webp' : 'image/avif';
		$last_reason   = '';

				foreach ( $order as $engine ) {
			$tmp = $dest . '.tmp-' . wp_rand( 1000, 9999 );
			try {
				$written = ( 'imagick' === $engine )
					? $this->encode_imagick( $src, $tmp, $format, $quality )
					: $this->encode_gd( $src, $tmp, $format, $quality );

				if ( $written && file_exists( $tmp ) && filesize( $tmp ) > 0 ) {
					$check = @getimagesize( $tmp );
					if ( $check && isset( $check['mime'] ) && $expected_mime === $check['mime'] ) {
						if ( @rename( $tmp, $dest ) ) {
							return array( 'ok' => true, 'reason' => '' );
						}
						$last_reason = __( 'the converted file could not be moved into place (permissions)', 'optipress' );
					} else {
						$last_reason = sprintf( __( 'the %s encoder produced an invalid or empty file', 'optipress' ), $engine );
					}
					@unlink( $tmp );
				} else {
					$last_reason = sprintf( __( 'the %s encoder returned no output', 'optipress' ), $engine );
					if ( file_exists( $tmp ) ) {
						@unlink( $tmp );
					}
				}
			} catch ( \Throwable $e ) {
				if ( file_exists( $tmp ) ) {
					@unlink( $tmp );
				}
				$last_reason = $this->human_reason( $e, $src );
				// Log the full exception for debugging.
				error_log( sprintf(
					'OptiPress %s conversion failed for %s (engine: %s): %s in %s:%d',
					$format, wp_basename( $src ), $engine,
					$e->getMessage(), $e->getFile(), $e->getLine()
				) );
			}
		}

		return array(
			'ok'     => false,
			'reason' => $last_reason ? $last_reason : __( 'unknown encoder error', 'optipress' ),
		);
	}

	/** @return bool */
	private function encode_imagick( $src, $tmp, $format, $quality ) {
		if ( ! class_exists( 'Imagick' ) ) {
			return false;
		}
		$im = new \Imagick();
		try {
			$im->readImage( $src );
			$im->setFirstIterator();
			$im->setImageFormat( strtoupper( $format ) );
			if ( 'webp' === $format ) {
				$im->setImageOption( 'webp:method', '4' );
			}
			$im->setImageCompressionQuality( (int) $quality );
			$ok = $im->writeImage( $tmp );
			$im->clear();
			return (bool) $ok;
		} catch ( \Throwable $e ) {
			try { $im->clear(); } catch ( \Throwable $e2 ) { /* ignore */ }
			throw $e;
		}
	}

	/** @return bool */
	private function encode_gd( $src, $tmp, $format, $quality ) {
		$info = @getimagesize( $src );
		if ( ! $info || empty( $info['mime'] ) ) {
			return false;
		}
		$img = null;
		switch ( $info['mime'] ) {
			case 'image/jpeg':
				$img = @imagecreatefromjpeg( $src );
				break;
			case 'image/png':
				$img = @imagecreatefrompng( $src );
				if ( $img ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, false );
					imagesavealpha( $img, true );
				}
				break;
			case 'image/gif':
				$img = @imagecreatefromgif( $src );
				break;
			case 'image/webp':
				$img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $src ) : null;
				break;
		}
		if ( ! $img ) {
			return false;
		}
		$ok = false;
		if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
			$ok = imagewebp( $img, $tmp, (int) $quality );
		} elseif ( 'avif' === $format && function_exists( 'imageavif' ) ) {
			$ok = imageavif( $img, $tmp, (int) $quality );
		}
		imagedestroy( $img );
		return (bool) $ok;
	}

	/** Give large decodes more memory before attempting. @param string $src */
	private function raise_memory_for( $src ) {
		$size   = (int) @filesize( $src );
		$info   = @getimagesize( $src );
		$pixels = ( $info && ! empty( $info[0] ) && ! empty( $info[1] ) ) ? (int) $info[0] * (int) $info[1] : 0;

		if ( $size > 2 * MB_IN_BYTES || $pixels > 4000000 ) {
			wp_raise_memory_limit( 'admin' );
			if ( $pixels > 16000000 ) {
				@ini_set( 'memory_limit', '1024M' );
			} elseif ( $pixels > 8000000 || $size > 8 * MB_IN_BYTES ) {
				@ini_set( 'memory_limit', '768M' );
			} else {
				@ini_set( 'memory_limit', '512M' );
			}
		}
	}

	/** @return string */
	private function human_reason( $e, $src ) {
		$m    = strtolower( $e->getMessage() );
		$base = wp_basename( $src );

		if ( false !== strpos( $m, 'memory' ) || false !== strpos( $m, 'allocated' ) ) {
			return sprintf( __( 'the server ran out of memory decoding %s — the file is very large; raise PHP memory_limit or lower quality', 'optipress' ), $base );
		}
		if ( false !== strpos( $m, 'delegate' ) ) {
			return __( 'the image engine is missing the required encoder delegate (Imagick was compiled without it)', 'optipress' );
		}
		if ( false !== strpos( $m, 'cache' ) && ( false !== strpos( $m, 'resource' ) || false !== strpos( $m, 'policy' ) ) ) {
			return __( 'Imagick resource limits were reached (server policy.xml restricts image size/memory)', 'optipress' );
		}
		if ( false !== strpos( $m, 'permission' ) || false !== strpos( $m, 'denied' ) ) {
			return __( 'file permission problem', 'optipress' );
		}
		if ( false !== strpos( $m, 'timeout' ) ) {
			return __( 'encoding timed out', 'optipress' );
		}
		return $e->getMessage();
	}

	/** Delete all conversion siblings for an item. @param object $item */
	public function delete_conversions_for_item( $item ) {
		$uploads = OptiPress_Plugin::uploads();
		$abs     = trailingslashit( $uploads['basedir'] ) . $item->file;
		if ( ! $abs || ! file_exists( $abs ) ) {
			return;
		}
		$meta = wp_get_attachment_metadata( (int) $item->attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		$files = array( $abs );
		if ( ! empty( $meta['sizes'] ) ) {
			$dir = dirname( $abs );
			foreach ( (array) $meta['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) ) {
					$files[] = $dir . '/' . $s['file'];
				}
			}
		}
		foreach ( $files as $f ) {
			foreach ( array( 'webp', 'avif' ) as $ext ) {
				$conv = preg_replace( '/\.(jpe?g|png|gif|webp)$/i', '', $f ) . '.' . $ext;
				if ( file_exists( $conv ) ) {
					@unlink( $conv );
				}
			}
		}
	}
}