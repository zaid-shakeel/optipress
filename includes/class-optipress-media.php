<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_Media {
	private $db;

	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * MIME types OptiPress can optimize/convert.
	 */
	public static function supported_mimes() {
		return array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
	}

	public function ensure_item( $attachment_id, $refresh_bytes = false ) {
		$attachment_id = (int) $attachment_id;
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return null;
		}
		$mime = (string) $post->post_mime_type;
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}

		OptiPress_Debug::log( 'media.ensure_item', "Checking attachment $attachment_id ($mime)" );

		$uploads = OptiPress_Plugin::uploads();
		$abs     = (string) get_attached_file( $attachment_id );
		$rel     = '';
		if ( $abs && 0 === strpos( $abs, $uploads['basedir'] ) ) {
			$rel = ltrim( str_replace( $uploads['basedir'], '', $abs ), '/' );
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		$existing = $this->db->get_item_by_attachment( $attachment_id );
		$supported = in_array( $mime, self::supported_mimes(), true );
		$status    = 'pending';
		$error     = '';
		$errmsg    = '';

		if ( ! $supported ) {
			$status = 'skipped';
			$error  = 'unsupported_format';
			$errmsg = sprintf( __( 'Not supported: %s files cannot be optimized by OptiPress. Only JPEG, PNG, GIF and WebP are supported.', 'optipress' ), $mime );
			OptiPress_Debug::log( 'media.unsupported', "Marking $attachment_id as unsupported ($mime)" );
		} elseif ( $rel && ! file_exists( $abs ) ) {
			$status = 'failed';
			$error  = 'missing_file';
			$errmsg = __( 'The file no longer exists on disk.', 'optipress' );
		}

		if ( $existing ) {
			$update = array(
				'file'   => $rel,
				'mime'   => $mime,
				'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			);
			if ( 'skipped' === $status ) {
				$update['status'] = 'skipped';
				$update['error_code'] = $error;
				$update['error_message'] = $errmsg;
			}
			// Normalize conversion columns so existing rows don't lie.
			if ( in_array( $existing->webp_status, array( 'none', 'failed' ), true ) && ( ! $supported || 'image/webp' === $mime ) ) {
				$update['webp_status'] = 'na';
			}
			if ( in_array( $existing->avif_status, array( 'none', 'failed' ), true ) && ! $supported ) {
				$update['avif_status'] = 'na';
			}

			if ( $refresh_bytes ) {
				$bytes = $this->compute_bytes( $abs, $meta );
				if ( 'pending' === $existing->status || 'skipped' !== $status ) {
					$update['orig_bytes']    = ( 'pending' === $existing->status || 0 == $existing->orig_bytes ) ? $bytes : max( $existing->orig_bytes, $bytes );
					$update['current_bytes'] = $bytes;
					if ( 'pending' === $existing->status ) {
						$update['saved_bytes'] = 0;
					}
				}
			}
			$this->db->update_item( (int) $existing->id, $update );
			return (int) $existing->id;
		}

		$bytes = ( $rel && file_exists( $abs ) ) ? $this->compute_bytes( $abs, $meta ) : 0;
		$webp_init = ( ! $supported || 'image/webp' === $mime ) ? 'na' : 'none';
		$avif_init = ( ! $supported ) ? 'na' : 'none';

		return $this->db->insert_item( array(
			'attachment_id' => $attachment_id,
			'file'          => $rel,
			'mime'          => $mime,
			'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'orig_bytes'    => $bytes,
			'current_bytes' => $bytes,
			'saved_bytes'   => 0,
			'status'        => $status,
			'error_code'    => $error,
			'error_message' => $errmsg,
			'webp_status'   => $webp_init,
			'avif_status'   => $avif_init,
		) );
	}

	public function compute_bytes( $abs_path, $meta ) {
		$total = 0;
		if ( $abs_path && file_exists( $abs_path ) ) {
			$total += (int) filesize( $abs_path );
		}
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = dirname( $abs_path );
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) { continue; }
				$p = $dir . '/' . $size['file'];
				if ( file_exists( $p ) ) {
					$total += (int) filesize( $p );
				}
			}
		}
		return $total;
	}

	public function sync_missing( $limit = 300 ) {
		global $wpdb;
		$limit = max( 50, min( 1000, (int) $limit ) );
		OptiPress_Debug::log( 'media.sync_missing.start', "Scanning for up to $limit missing items" );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID
			FROM {$wpdb->posts} p
			LEFT JOIN {$this->db->items} i ON i.attachment_id = p.ID
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%%'
			AND i.id IS NULL
			ORDER BY p.ID DESC
			LIMIT %d",
			$limit
		) );
		OptiPress_Debug::log( 'media.sync_missing.found', "Found " . count( $ids ) . " missing attachments to sync" );

		foreach ( $ids as $id ) {
			$this->ensure_item( (int) $id, true );
		}
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->posts} p
			LEFT JOIN {$this->db->items} i ON i.attachment_id = p.ID
			WHERE p.post_type = 'attachment'
			AND p.post_mime_type LIKE 'image/%%'
			AND i.id IS NULL"
		);
		return array( 'synced' => count( $ids ), 'remaining' => $remaining );
	}

	/**
	 * One-pass cleanup: flag conversion columns as N/A for formats that can
	 * never be converted (SVG etc.) or are already WebP. Never touches 'done'.
	 */
	public function normalize_conversion_statuses() {
		global $wpdb;
		$t = $this->db->items;
		OptiPress_Debug::log( 'media.normalize', 'Normalizing conversion statuses' );
		$n1 = $wpdb->query( "UPDATE {$t} SET webp_status = 'na' WHERE mime = 'image/webp' AND webp_status IN ('none','failed')" );
		$n2 = $wpdb->query( "UPDATE {$t} SET webp_status = 'na' WHERE status = 'skipped' AND webp_status IN ('none','failed')" );
		$n3 = $wpdb->query( "UPDATE {$t} SET avif_status = 'na' WHERE status = 'skipped' AND avif_status IN ('none','failed')" );
		OptiPress_Debug::log( 'media.normalize.done', "Normalized $n1 + $n2 + $n3 rows" );
	}
}