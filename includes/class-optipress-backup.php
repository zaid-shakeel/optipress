<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Original-file backups + restoration. Backups live outside year/month folders
 * for clarity: uploads/optipress-backups/{attachment_id}/...
 */
class OptiPress_Backup {

	/** @var OptiPress_Plugin */ private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/** @return string */
	public function base_dir() {
		$uploads = OptiPress_Plugin::uploads();
		return trailingslashit( $uploads['basedir'] ) . 'optipress-backups';
	}

	/**
	 * Back up the full file + existing sizes before first destructive write.
	 *
	 * @param object $item
	 * @param array  $files Absolute paths.
	 * @return array|false Map absolute => backup path, or false on failure.
	 */
	public function backup_files( $item, $files ) {
		$base = $this->base_dir() . '/' . (int) $item->attachment_id;
		if ( ! file_exists( $base ) && ! wp_mkdir_p( $base ) ) {
			return false;
		}
		$map = array();
		foreach ( $files as $abs ) {
			if ( ! file_exists( $abs ) ) {
				continue;
			}
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

	/**
	 * Restore an item from backup. Removes conversions, resets stats.
	 *
	 * @param int $item_id
	 * @return array {ok:bool,message:string}
	 */
	public function restore( $item_id ) {
		$item = $this->plugin->db->get_item( (int) $item_id );
		if ( ! $item ) {
			return array( 'ok' => false, 'message' => __( 'Image record not found.', 'optipress' ) );
		}
		if ( ! (int) $item->has_backup ) {
			return array( 'ok' => false, 'message' => __( 'No backup exists for this image. Backups are only created when “Backup original files” is enabled before optimization.', 'optipress' ) );
		}

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

		// Remove generated conversions — they no longer match the restored files.
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

		$this->plugin->stats->flush();
		$this->plugin->logger->success( 'restore', __( 'Image restored from backup.', 'optipress' ), array(
			'attachment_id' => (int) $item->attachment_id,
			'file'          => $item->file,
			'context'       => array( 'restored_files' => $restored ),
		) );
		$this->plugin->purge->maybe_purge();

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %d: number of files restored */
				__( 'Restored %d files from backup. The image is now pending re-optimization.', 'optipress' ),
				$restored
			),
		);
	}

	/** @param int $attachment_id */
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