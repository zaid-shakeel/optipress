<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_Processor {
	private $p;

	public function __construct( $plugin ) {
		$this->p = $plugin;
	}

	public function process( $item_id, $opts = array() ) {
		OptiPress_Debug::log( 'processor.start', "Processing item $item_id", array( 'opts' => $opts ) );

		$item = $this->p->db->get_item( (int) $item_id );
		if ( ! $item ) {
			return $this->fail_result( 0, '', 'not_found', __( 'Image record not found.', 'optipress' ) );
		}

		$is_bulk = isset( $opts['origin'] ) && 'bulk' === $opts['origin'];
		if ( ! $is_bulk && 'processing' === $item->status && $item->updated_at && strtotime( $item->updated_at ) > ( time() - 600 ) ) {
			OptiPress_Debug::log( 'processor.skip_processing', "Item $item_id already being processed" );
			return array(
				'ok'      => true,
				'status'  => 'skipped',
				'file'    => wp_basename( $item->file ),
				'saved'   => 0,
				'message' => __( 'Skipped: this image is already being processed by another operation.', 'optipress' ),
			);
		}

		$uploads = OptiPress_Plugin::uploads();
		$abs     = trailingslashit( $uploads['basedir'] ) . $item->file;
		$name    = wp_basename( $item->file );

		if ( ! $item->file || ! file_exists( $abs ) ) {
			$this->mark_failed( $item, 'missing_file', __( 'The file no longer exists on disk.', 'optipress' ) );
			return $this->fail_result( (int) $item->id, $name, 'missing_file', __( 'The file no longer exists on disk.', 'optipress' ) );
		}
		if ( ! is_readable( $abs ) || ! is_writable( $abs ) ) {
			$this->mark_failed( $item, 'permissions', __( 'File permissions prevent reading or writing this image.', 'optipress' ) );
			return $this->fail_result( (int) $item->id, $name, 'permissions', __( 'Permission problem: file is not readable/writable.', 'optipress' ) );
		}

		// Hard-gate unsupported formats (SVG, BMP, TIFF etc.) — never try to optimize.
		if ( ! in_array( $item->mime, OptiPress_Media::supported_mimes(), true ) ) {
			OptiPress_Debug::log( 'processor.unsupported', "Skipping {$item->mime} as unsupported", array( 'item_id' => $item_id ) );
			$msg = sprintf(
				__( 'Not supported: %s files cannot be optimized by OptiPress. Only JPEG, PNG, GIF and WebP are supported.', 'optipress' ),
				$item->mime
			);
			$this->p->db->update_item( (int) $item->id, array(
				'status' => 'skipped', 'error_code' => 'unsupported_format', 'error_message' => $msg,
				'webp_status' => 'na', 'avif_status' => 'na',
			) );
			return array( 'ok' => true, 'status' => 'skipped', 'message' => $msg, 'file' => $name, 'saved' => 0 );
		}

		$caps = $this->p->env->capabilities();
		if ( 'none' === $caps['engine'] ) {
			$this->mark_failed( $item, 'no_engine', __( 'No image processing library (Imagick or GD) is available.', 'optipress' ) );
			return $this->fail_result( (int) $item->id, $name, 'no_engine', __( 'No image engine available on this server.', 'optipress' ) );
		}

		$max_bytes = (int) $this->p->settings->get( 'max_file_mb' ) * MB_IN_BYTES;
		if ( filesize( $abs ) > $max_bytes ) {
			$msg = sprintf(
				__( 'Skipped: file is larger than the configured limit (%1$s > %2$s).', 'optipress' ),
				size_format( filesize( $abs ) ), size_format( $max_bytes )
			);
			OptiPress_Debug::log( 'processor.too_large', $msg );
			$this->p->db->update_item( (int) $item->id, array( 'status' => 'skipped', 'error_code' => 'too_large', 'error_message' => $msg ) );
			$this->p->logger->info( 'optimize', $msg, array( 'attachment_id' => (int) $item->attachment_id, 'file' => $name ) );
			$this->p->stats->flush();
			return array( 'ok' => true, 'status' => 'skipped', 'message' => $msg, 'file' => $name, 'saved' => 0 );
		}

		$this->p->db->update_item( (int) $item->id, array( 'status' => 'processing' ) );
		$meta = wp_get_attachment_metadata( (int) $item->attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		$files = array( 'full' => $abs );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $abs );
			foreach ( $meta['sizes'] as $key => $s ) {
				if ( empty( $s['file'] ) ) { continue; }
				$p = $dir . '/' . $s['file'];
				if ( file_exists( $p ) && $p !== $abs ) {
					$files[ $key ] = $p;
				}
			}
		}

		if ( $this->p->settings->get( 'backup_originals' ) && ! (int) $item->has_backup ) {
			$map = $this->p->backup->backup_files( $item, array_values( $files ) );
			if ( false === $map ) {
				OptiPress_Debug::log( 'processor.backup_fail', 'Backup directory not writable' );
				$this->p->logger->warning( 'optimize', __( 'Backup directory is not writable; continuing without backup.', 'optipress' ), array( 'attachment_id' => (int) $item->attachment_id, 'file' => $name ) );
			} else {
				$this->p->db->update_item( (int) $item->id, array( 'has_backup' => 1 ) );
			}
		}

		$orig_total = 0;
		foreach ( $files as $f ) { $orig_total += (int) @filesize( $f ); }
		$this->raise_memory( $orig_total );

		$mode    = (string) $this->p->settings->get( 'compression_mode' );
		$quality = (int) $this->p->settings->get( 'quality' );
		$strip   = (bool) $this->p->settings->get( 'strip_meta' );

		$total_saved = 0;
		$new_total   = 0;
		$per_size    = array();
		$errors      = array();
		$notes       = array();

		foreach ( $files as $key => $path ) {
			$r = $this->optimize_file( $path, $mode, $quality, $strip, $caps );
			if ( $r['ok'] ) {
				$total_saved += $r['saved'];
				$new_total   += $r['new'];
				$per_size[ wp_basename( $path ) ] = array(
					'orig' => $r['orig'], 'new' => $r['new'], 'saved' => $r['saved'],
				);
				if ( ! empty( $r['note'] ) ) { $notes[] = $r['note']; }
			} else {
				if ( 'skipped' === $r['code'] ) {
					$new_total += $r['orig'];
					$per_size[ wp_basename( $path ) ] = array( 'orig' => $r['orig'], 'new' => $r['orig'], 'saved' => 0, 'note' => $r['message'] );
					$notes[] = wp_basename( $path ) . ': ' . $r['message'];
				} else {
					$errors[ wp_basename( $path ) ] = $r['message'];
					$new_total += $r['orig'];
				}
			}
		}

		$processed_ok = count( $files ) - count( $errors );
		if ( 0 === $processed_ok && ! empty( $errors ) ) {
			$first = reset( $errors );
			$this->mark_failed( $item, 'process_error', $first );
			return $this->fail_result( (int) $item->id, $name, 'process_error', $first );
		}

		$orig_bytes = max( (int) $item->orig_bytes, $orig_total );
		$now        = current_time( 'mysql' );
		$now_day    = current_time( 'Y-m-d' );

		$this->p->db->update_item( (int) $item->id, array(
			'status'        => 'optimized',
			'orig_bytes'    => $orig_bytes,
			'current_bytes' => $new_total,
			'saved_bytes'   => max( 0, $orig_bytes - $new_total ),
			'error_code'    => '',
			'error_message' => null,
			'optimized_at'  => $now,
			'meta'          => wp_json_encode( array(
				'engine'   => $caps['engine'],
				'mode'     => $mode,
				'opt_day'  => $now_day,
				'sizes'    => $per_size,
				'warnings' => array_values( $errors ),
			) ),
		) );

		$saved = max( 0, $orig_bytes - $new_total );
		$this->p->db->bump_daily( 'optimized', 1 );
		$this->p->db->bump_daily( 'saved', $saved );
		if ( ! empty( $errors ) ) {
			$this->p->db->bump_daily( 'failed', count( $errors ) );
		}

		$pct = $orig_bytes > 0 ? round( ( $saved / $orig_bytes ) * 100, 1 ) : 0;
		OptiPress_Debug::log( 'processor.done', "Optimized $name: saved " . size_format( $saved ) . " ($pct%)" );

		$this->p->logger->success( 'optimize', sprintf(
			__( 'Optimization completed. Original: %1$s, Optimized: %2$s, Saved: %3$s (%4$s%%).', 'optipress' ),
			size_format( $orig_bytes ), size_format( $new_total ), size_format( $saved ), number_format_i18n( $pct, 1 )
		), array(
			'attachment_id' => (int) $item->attachment_id,
			'file'          => $name,
			'context'       => array(
				'orig' => $orig_bytes, 'new' => $new_total, 'saved' => $saved, 'pct' => $pct,
				'files' => count( $files ), 'size_errors' => $errors, 'origin' => isset( $opts['origin'] ) ? $opts['origin'] : 'manual',
			),
		) );

		$want_webp = $this->p->settings->get( 'webp_enabled' ) && ( $this->p->settings->get( 'webp_auto' ) || ! empty( $opts['webp'] ) );
		$want_avif = $this->p->settings->get( 'avif_enabled' ) && ( $this->p->settings->get( 'avif_auto' ) || ! empty( $opts['avif'] ) );
		if ( ! $want_webp && 'done' === $item->webp_status && $this->p->settings->get( 'webp_enabled' ) ) { $want_webp = true; }
		if ( ! $want_avif && 'done' === $item->avif_status && $this->p->settings->get( 'avif_enabled' ) ) { $want_avif = true; }
		if ( $want_webp ) { $this->p->converter->generate( (int) $item->id, 'webp' ); }
		if ( $want_avif ) { $this->p->converter->generate( (int) $item->id, 'avif' ); }

		$this->p->stats->flush();
		$this->p->purge->maybe_purge( 'bulk' !== ( isset( $opts['origin'] ) ? $opts['origin'] : 'manual' ) );

		return array(
			'ok'      => true,
			'status'  => 'optimized',
			'file'    => $name,
			'orig'    => $orig_bytes,
			'new'     => $new_total,
			'saved'   => $saved,
			'pct'     => $pct,
			'message' => sprintf( __( 'Saved %1$s (%2$s%%).', 'optipress' ), size_format( $saved ), number_format_i18n( $pct, 1 ) ),
			'warnings' => array_values( $errors ),
			'notes'    => $notes,
		);
	}

	private function optimize_file( $path, $mode, $quality, $strip, $caps ) {
		$orig = (int) @filesize( $path );
		if ( $orig <= 0 ) {
			return array( 'ok' => false, 'code' => 'empty_file', 'message' => __( 'File is empty or unreadable.', 'optipress' ), 'orig' => 0, 'new' => 0, 'saved' => 0 );
		}
		$info = @getimagesize( $path );
		if ( ! $info ) {
			return array( 'ok' => false, 'code' => 'corrupt', 'message' => __( 'This file is not a valid image.', 'optipress' ), 'orig' => $orig, 'new' => $orig, 'saved' => 0 );
		}
		$mime = $info['mime'];
		if ( 'image/gif' === $mime && $this->is_animated_gif( $path ) ) {
			return array( 'ok' => false, 'code' => 'skipped', 'message' => __( 'Animated GIF skipped (frames would be lost).', 'optipress' ), 'orig' => $orig, 'new' => $orig, 'saved' => 0 );
		}
		$tmp = $path . '.optipress-' . wp_rand( 1000, 9999 ) . '.tmp';
		try {
			$written = false;
			if ( 'imagick' === $caps['engine'] ) {
				$written = $this->encode_imagick( $path, $tmp, $mime, $mode, $quality, $strip );
			}
			if ( ! $written ) {
				$written = $this->encode_gd( $path, $tmp, $mime, $mode, $quality );
			}
			if ( ! $written || ! file_exists( $tmp ) ) {
				@unlink( $tmp );
				return array( 'ok' => false, 'code' => 'encode_failed', 'message' => __( 'The image encoder could not process this file.', 'optipress' ), 'orig' => $orig, 'new' => $orig, 'saved' => 0 );
			}
			$new = (int) filesize( $tmp );
			if ( $new <= 0 || $new >= ( $orig - max( 10, $orig * 0.002 ) ) ) {
				@unlink( $tmp );
				return array( 'ok' => true, 'code' => 'ok', 'message' => '', 'orig' => $orig, 'new' => $orig, 'saved' => 0, 'note' => __( 'already optimal', 'optipress' ) );
			}
			if ( ! @rename( $tmp, $path ) ) {
				@unlink( $tmp );
				return array( 'ok' => false, 'code' => 'permissions', 'message' => __( 'Could not write the optimized file (permissions).', 'optipress' ), 'orig' => $orig, 'new' => $orig, 'saved' => 0 );
			}
			return array( 'ok' => true, 'code' => 'ok', 'message' => '', 'orig' => $orig, 'new' => $new, 'saved' => $orig - $new );
		} catch ( \Throwable $e ) {
			OptiPress_Debug::log( 'processor.file_error', "Error optimizing " . wp_basename( $path ) . ": " . $e->getMessage() );
			@unlink( $tmp );
			return array(
				'ok'      => false,
				'code'    => $this->classify_error( $e ),
				'message' => $this->human_error( $e, $orig ),
				'orig'    => $orig,
				'new'     => $orig,
				'saved'   => 0,
			);
		}
	}

	private function encode_imagick( $path, $tmp, $mime, $mode, $quality, $strip ) {
		if ( ! class_exists( 'Imagick' ) ) { return false; }
		$im = new \Imagick();
		try {
			$im->readImage( $path );
			$im->setFirstIterator();
			if ( $strip ) {
				try { $im->stripImage(); } catch ( \Throwable $e ) {}
			}
			switch ( $mime ) {
				case 'image/jpeg':
					$q = $this->mode_quality( $mode, $quality, array( 'lossless' => min( 95, $quality + 8 ), 'balanced' => $quality, 'lossy' => max( 50, $quality - 10 ) ) );
					$im->setImageFormat( 'JPEG' );
					$im->setImageCompression( \Imagick::COMPRESSION_JPEG );
					$im->setImageCompressionQuality( $q );
					break;
				case 'image/png':
					$im->setImageFormat( 'PNG' );
					$im->setOption( 'png:compression-level', '9' );
					$im->setOption( 'png:compression-filter', '5' );
					if ( 'lossy' === $mode ) {
						$im->quantizeImage( 256, \Imagick::COLORSPACE_SRGB, 0, true, false );
					} elseif ( 'balanced' === $mode && $this->looks_photographic( $path ) ) {
						$im->quantizeImage( 256, \Imagick::COLORSPACE_SRGB, 0, true, false );
					}
					break;
				case 'image/gif':
					$im->setImageFormat( 'GIF' );
					break;
				case 'image/webp':
					$im->setImageFormat( 'WEBP' );
					$im->setImageCompressionQuality( $this->mode_quality( $mode, $quality, array( 'lossless' => 95, 'balanced' => $quality, 'lossy' => max( 50, $quality - 10 ) ) ) );
					break;
				default:
					$im->clear();
					return false;
			}
			$ok = $im->writeImage( $tmp );
			$im->clear();
			return (bool) $ok;
		} catch ( \Throwable $e ) {
			try { $im->clear(); } catch ( \Throwable $e2 ) {}
			throw $e;
		}
	}

	private function encode_gd( $path, $tmp, $mime, $mode, $quality ) {
		$img = null;
		switch ( $mime ) {
			case 'image/jpeg': $img = @imagecreatefromjpeg( $path ); break;
			case 'image/png':  $img = @imagecreatefrompng( $path );  break;
			case 'image/gif':  $img = @imagecreatefromgif( $path );  break;
			case 'image/webp': $img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : null; break;
		}
		if ( ! $img ) {
			return false;
		}
		$result = false;
		switch ( $mime ) {
			case 'image/jpeg':
				$q = $this->mode_quality( $mode, $quality, array( 'lossless' => min( 95, $quality + 8 ), 'balanced' => $quality, 'lossy' => max( 50, $quality - 10 ) ) );
				$result = imagejpeg( $img, $tmp, $q );
				break;
			case 'image/png':
				if ( 'lossless' !== $mode && $this->looks_photographic( $path ) ) {
					imagetruecolortopalette( $img, true, 'lossy' === $mode ? 192 : 256 );
				}
				$result = imagepng( $img, $tmp, 9 );
				break;
			case 'image/gif':
				$result = imagegif( $img, $tmp );
				break;
			case 'image/webp':
				$result = function_exists( 'imagewebp' ) ? imagewebp( $img, $tmp, $quality ) : false;
				break;
		}
		imagedestroy( $img );
		return (bool) $result;
	}

	private function mode_quality( $mode, $quality, $map ) {
		return isset( $map[ $mode ] ) ? (int) $map[ $mode ] : (int) $quality;
	}
	private function looks_photographic( $path ) {
		$info = @getimagesize( $path );
		if ( ! $info ) { return false; }
		return ( $info[0] * $info[1] ) > 500000;
	}
	private function is_animated_gif( $path ) {
		$fh = @fopen( $path, 'rb' );
		if ( ! $fh ) { return false; }
		$data = fread( $fh, 1024 * 1024 );
		fclose( $fh );
		return preg_match_all( '/\x00\x21\xF9\x04/', $data ) > 1;
	}
	private function raise_memory( $orig_bytes ) {
		if ( $orig_bytes > 3 * MB_IN_BYTES ) {
			wp_raise_memory_limit( 'admin' );
			$limit = ini_get( 'memory_limit' );
			if ( $limit && '-1' !== $limit ) {
				$want = '256M';
				if ( $orig_bytes > 10 * MB_IN_BYTES ) { $want = '512M'; }
				@ini_set( 'memory_limit', $want );
			}
		}
	}
	private function classify_error( $e ) {
		$m = strtolower( $e->getMessage() );
		if ( false !== strpos( $m, 'memory' ) || false !== strpos( $m, 'allocated' ) ) { return 'memory'; }
		if ( false !== strpos( $m, 'permission' ) || false !== strpos( $m, 'denied' ) ) { return 'permissions'; }
		if ( false !== strpos( $m, 'no space' ) || false !== strpos( $m, 'disk' ) ) { return 'disk_full'; }
		if ( false !== strpos( $m, 'timeout' ) || false !== strpos( $m, 'execution time' ) ) { return 'timeout'; }
		return 'corrupt';
	}
	private function human_error( $e, $orig_bytes ) {
		$code = $this->classify_error( $e );
		switch ( $code ) {
			case 'memory': return sprintf( __( 'Server ran out of memory while processing this %s image.', 'optipress' ), size_format( $orig_bytes ) );
			case 'permissions': return __( 'File permission problem.', 'optipress' );
			case 'disk_full': return __( 'Insufficient disk space.', 'optipress' );
			case 'timeout': return __( 'Processing exceeded the server time limit.', 'optipress' );
			default: return __( 'The image could not be decoded — the file may be corrupt.', 'optipress' ) . ' (' . $e->getMessage() . ')';
		}
	}
	private function mark_failed( $item, $code, $message ) {
		OptiPress_Debug::log( 'processor.failed', "Marking item {$item->id} as failed: $code", array( 'message' => $message ) );
		$this->p->db->update_item( (int) $item->id, array(
			'status' => 'failed', 'error_code' => $code, 'error_message' => $message,
		) );
		$this->p->db->bump_daily( 'failed', 1 );
		$this->p->logger->error( 'optimize', __( 'Optimization failed: ', 'optipress' ) . $message, array(
			'attachment_id' => (int) $item->attachment_id,
			'file'          => $item->file,
			'context'       => array( 'code' => $code ),
		) );
		$this->p->stats->flush();
	}
	private function fail_result( $item_id, $name, $code, $message ) {
		return array( 'ok' => false, 'status' => 'failed', 'file' => $name, 'code' => $code, 'message' => $message, 'saved' => 0 );
	}
}