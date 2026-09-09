<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Keeps the items table in sync with the Media Library, lazily and in chunks.
 */
class OptiPress_Media {

	/** @var OptiPress_DB */ private $db;

	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Ensure an item row exists for an attachment. Refreshes byte counts.
	 *
	 * @param int  $attachment_id
	 * @param bool $refresh_bytes
	 * @return int|null Item ID, or null when not an image.
	 */
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

		$uploads = OptiPress_Plugin::uploads();
		$abs     = (string) get_attached_file( $attachment_id );
		$rel     = '';
		if ( $abs && 0 === strpos( $abs, $uploads['basedir'] ) ) {
			$rel = ltrim( str_replace( $uploads['basedir'], '', $abs ), '/' );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();

		$existing = $this->db->get_item_by_attachment( $attachment_id );

		// Unsupported formats are recorded as skipped — never silently dropped.
		$supported = in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true );
		$status    = 'pending';
		$error     = '';
		$errmsg    = '';
		if ( ! $supported ) {
			$status = 'skipped';
			$error  = 'unsupported_format';
			/* translators: %s: mime type */
			$errmsg = sprintf( __( 'Unsupported format (%s). OptiPress optimizes JPEG, PNG, GIF and WebP files.', 'optipress' ), $mime );
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
			if ( $refresh_bytes ) {
				$bytes = $this->compute_bytes( $abs, $meta );
				if ( 'pending' === $existing->status || 'skipped' !== $status ) {
					$update['orig_bytes']    = ( 'pending' === $existing->status || 0 == $existing->orig_bytes ) ? $bytes : ( max( $existing->orig_bytes, $bytes ) );
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
		) );
	}

	/**
	 * Sum of full-size + all registered intermediate sizes (only files that exist).
	 *
	 * @param string $abs_path Full file path.
	 * @param array  $meta
	 * @return int
	 */
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

	/**
	 * Find image attachments missing from the items table and insert them.
	 *
	 * @param int $limit
	 * @return array {synced:int, remaining:int}
	 */
	public function sync_missing( $limit = 300 ) {
		global $wpdb;
		$limit = max( 50, min( 1000, (int) $limit ) );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$this->db->items} i ON i.attachment_id = p.ID
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'
			   AND i.id IS NULL
			 ORDER BY p.ID DESC
			 LIMIT %d",
			$limit
		) );

		foreach ( $ids as $id ) {
			$this->ensure_item( (int) $id, true );
		}

		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$this->db->items} i ON i.attachment_id = p.ID
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type LIKE 'image/%'
			   AND i.id IS NULL"
		);

		return array( 'synced' => count( $ids ), 'remaining' => $remaining );
	}
}