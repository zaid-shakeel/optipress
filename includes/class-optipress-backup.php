<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_Backup {
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function base_dir() {
		$uploads = OptiPress_Plugin::uploads();
		return trailingslashit( $uploads['basedir'] ) . 'optipress-backups';
	}

	public function backup_files( $item, $files ) {
		$base = $this->base_dir() . '/' . (int) $item->attachment_id;
		if ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) {
			return false;
		}
		$map = array();
		foreach ( $files as $abs ) {
			if ( ! file_exists( $abs ) ) { continue; }
			$dest = $base . '/' . wp_basename( $abs );
			if ( ! file_exists( $dest ) ) {
				if ( ! @copy( $abs, $dest ) ) {
					return false;
				}
			}
			$map[ $abs ] = $dest;
		}
		return $map;
	}

	public function restore( $item_id ) {
		OptiPress_Debug::log( 'restore.start', "Restoring item $item_id" );

		$item = $this->plugin->db->get_item( (int) $item_id );
		if ( ! $item ) {
			return array( 'ok' => false, 'message' => __( 'Image record not found.', 'optipress' ) );
		}
		if ( ! (int) $item->has_backup ) {
			return array( 'ok' => false, 'message' => __( 'No backup exists for this image.', 'optipress' ) );
		}

		// Capture pre-restore state so analytics can be rolled back exactly.
		$was_optimized = ( 'optimized' === $item->status );
		$had_webp      = ( 'done' === $item->webp_status );
		$had_avif      = ( 'done' === $item->avif_status );
		$saved_bytes   = (int) $item->saved_bytes;
		$opt_day       = $item->optimized_at ? substr( (string) $item->optimized_at, 0, 10 ) : '';
		$meta_arr      = json_decode( (string) $item->meta, true );
		$meta_arr      = is_array( $meta_arr ) ? $meta_arr : array();

		$uploads = OptiPress_Plugin::uploads();
		$abs     = trailingslashit( $uploads['basedir'] ) . $item->file;
		$meta    = wp_get_attachment_metadata( (int) $item->attachment_id );
		$meta    = is_array( $meta ) ? $meta : array();

		$files = array();
		if ( file_exists( $abs ) ) { $files[] = $abs; }
		if ( ! empty( $meta['sizes'] ) ) {
			$dir = dirname( $abs );
			foreach ( $meta['sizes'] as $s ) {
				if ( ! empty( $s['file'] ) && file_exists( $dir . '/' . $s['file'] ) ) {
					$files[] = $dir . '/' . $s['file'];
				}
			}
		}

		$backup_dir = $this->base_dir() . '/' . (int) $item->attachment_id;
		$restored   = 0;
		foreach ( $files as $abs_file ) {
			$src = $backup_dir . '/' . wp_basename( $abs_file );
			if ( file_exists( $src ) && @copy( $src, $abs_file ) ) {
				$restored++;
			}
		}
		if ( 0 === $restored ) {
			return array( 'ok' => false, 'message' => __( 'Backup files could not be found on disk.', 'optipress' ) );
		}

		$this->plugin->converter->delete_conversions_for_item( $item );
		$new_bytes = $this->plugin->media->compute_bytes( $abs, $meta );

		$this->plugin->db->update_item( (int) $item->id, array(
			'status'        => 'pending',
			'orig_bytes'    => $new_bytes,
			'current_bytes' => $new_bytes,
			'saved_bytes'   => 0,
			'error_code'    => '',
			'error_message' => null,
			'webp_status'   => 'none',
			'webp_bytes'    => 0,
			'avif_status'   => 'none',
			'avif_bytes'    => 0,
			'optimized_at'  => null,
		) );

		// Roll back daily analytics so history reflects reality again.
		OptiPress_Debug::log( 'restore.rollback', "Rolling back analytics: opt=$was_optimized webp=$had_webp avif=$had_avif saved=$saved_bytes", array(
			'opt_day' => $opt_day, 'webp_day' => $meta_arr['webp_day'] ?? '', 'avif_day' => $meta_arr['avif_day'] ?? '',
		) );

		if ( $was_optimized && $opt_day ) {
			$this->plugin->db->decrement_daily( $opt_day, 'optimized', 1 );
			$this->plugin->db->decrement_daily( $opt_day, 'saved', $saved_bytes );
		}
		if ( $had_webp ) {
			$d = ! empty( $meta_arr['webp_day'] ) ? $meta_arr['webp_day'] : $opt_day;
			if ( $d ) {
				$this->plugin->db->decrement_daily( $d, 'webp', 1 );
			}
		}
		if ( $had_avif ) {
			$d = ! empty( $meta_arr['avif_day'] ) ? $meta_arr['avif_day'] : $opt_day;
			if ( $d ) {
				$this->plugin->db->decrement_daily( $d, 'avif', 1 );
			}
		}

		$this->plugin->stats->flush();
		$this->plugin->logger->success( 'restore', __( 'Image restored from backup.', 'optipress' ), array(
			'attachment_id' => (int) $item->attachment_id,
			'file'          => $item->file,
			'context'       => array( 'restored_files' => $restored ),
		) );
		$this->plugin->purge->maybe_purge();

		return array(
			'ok'      => true,
			'message' => sprintf( __( 'Restored %d files from backup.', 'optipress' ), $restored ),
		);
	}

	public function delete_backups( $attachment_id ) {
		$dir = $this->base_dir() . '/' . (int) $attachment_id;
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $f ) {
				@unlink( $f );
			}
			@rmdir( $dir );
		}
	}
}