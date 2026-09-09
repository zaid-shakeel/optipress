<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'optipress_settings', array() );

// Remove plugin data only if the user explicitly opted in.
if ( ! empty( $settings['delete_on_uninstall'] ) ) {
	global $wpdb;

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optipress_items" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optipress_logs" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}optipress_daily" );

	// Remove backups and any generated conversions.
	$uploads = wp_get_upload_dir();
	$backup_dir = trailingslashit( $uploads['basedir'] ) . 'optipress-backups';
	if ( is_dir( $backup_dir ) ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $backup_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() );
		}
		@rmdir( $backup_dir );
	}

	// Remove sibling webp/avif files next to attachments.
	$files = get_posts( array(
		'post_type' => 'attachment', 'post_mime_type' => 'image/%',
		'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
	) );
	foreach ( $files as $id ) {
		$abs = get_attached_file( $id );
		if ( ! $abs ) { continue; }
		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$conv = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $abs ) . '.' . $ext;
			if ( file_exists( $conv ) ) { @unlink( $conv ); }
		}
		$meta = wp_get_attachment_metadata( $id );
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( (array) $meta['sizes'] as $s ) {
				if ( empty( $s['file'] ) ) { continue; }
				$p = dirname( $abs ) . '/' . $s['file'];
				foreach ( array( 'webp', 'avif' ) as $ext ) {
					$conv = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $p ) . '.' . $ext;
					if ( file_exists( $conv ) ) { @unlink( $conv ); }
				}
			}
		}
	}

	delete_option( 'optipress_settings' );
	delete_option( 'optipress_bulk_lock' );
	delete_option( 'optipress_db_version' );
	delete_option( 'optipress_pending_notice_dismissed' );
	delete_transient( 'optipress_counters' );
	delete_transient( 'optipress_caps' );
	delete_transient( 'optipress_purge_throttle' );
	delete_transient( 'optipress_bulk_done' );
}