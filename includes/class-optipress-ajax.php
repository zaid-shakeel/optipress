<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_Ajax {
	private $p;

	public function __construct( $plugin ) {
		$this->p = $plugin;
		$actions = array(
			'stats', 'scan', 'bulk_start', 'bulk_run', 'bulk_cancel', 'bulk_status',
			'media', 'item_detail', 'action', 'logs', 'logs_clear', 'analytics',
			'settings_save', 'purge_cache', 'dismiss_notice',
		);
		foreach ( $actions as $a ) {
			add_action( 'wp_ajax_optipress_' . $a, array( $this, 'h_' . $a ) );
		}
	}

	private function guard() {
		check_ajax_referer( 'optipress', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission.', 'optipress' ) ), 403 );
		}
	}

	public function h_stats() {
		$this->guard();
		OptiPress_Debug::log( 'ajax.stats', 'Stats requested' );
		wp_send_json_success( array(
			'counters' => $this->p->stats->counters(),
			'activity' => $this->activity_rows( 8 ),
			'bulk'     => $this->p->queue->lock(),
		) );
	}

	private function activity_rows( $limit ) {
		$logs = $this->p->db->page_logs( array( 'per_page' => $limit, 'page' => 1 ) );
		$rows = array();
		foreach ( $logs['rows'] as $l ) {
			$rows[] = array(
				'id'       => (int) $l->id,
				'level'    => $l->level,
				'op'       => $l->op,
				'file'     => $l->file,
				'message'  => $l->message,
				'time'     => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $l->created_at ),
				'attachment' => (int) $l->attachment_id,
			);
		}
		return $rows;
	}

	public function h_scan() {
		$this->guard();
		OptiPress_Debug::log( 'ajax.scan.start', 'Scan requested' );
		$r = $this->p->media->sync_missing( 300 );
		$this->p->media->normalize_conversion_statuses();
		$this->p->stats->flush();
		OptiPress_Debug::log( 'ajax.scan.done', 'Scan complete', array( 'synced' => $r['synced'], 'remaining' => $r['remaining'] ) );
		wp_send_json_success( $r );
	}

	public function h_bulk_start() {
		$this->guard();
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'optimize';
		if ( ! in_array( $mode, array( 'optimize', 'retry_failed', 'webp', 'avif' ), true ) ) {
			$mode = 'optimize';
		}
		OptiPress_Debug::log( 'ajax.bulk_start', "Starting bulk mode: $mode" );
		$r = $this->p->queue->start( $mode );
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( $r );
		}
		wp_send_json_success( $r );
	}

	public function h_bulk_run() {
		$this->guard();
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$r = $this->p->queue->run_batch( $token );
		if ( empty( $r['ok'] ) ) {
			wp_send_json_error( $r );
		}
		wp_send_json_success( $r );
	}

	public function h_bulk_cancel() {
		$this->guard();
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$this->p->queue->cancel( $token );
		wp_send_json_success( array( 'cancelled' => true ) );
	}

	public function h_bulk_status() {
		$this->guard();
		$this->p->queue->recover_stale_items();
		$lock = $this->p->queue->lock();
		wp_send_json_success( array(
			'lock'     => $lock,
			'counters' => $this->p->stats->counters(),
			'remaining' => array(
				'optimize'     => $this->p->db->count_candidates( 'optimize' ),
				'retry_failed' => $this->p->db->count_candidates( 'retry_failed' ),
				'webp'         => $this->p->db->count_candidates( 'webp' ),
				'avif'         => $this->p->db->count_candidates( 'avif' ),
			),
		) );
	}

	public function h_media() {
		$this->guard();
		$args = array(
			'q'        => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
			'status'   => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all',
			'webp'     => isset( $_GET['webp'] ) ? sanitize_key( $_GET['webp'] ) : 'all',
			'avif'     => isset( $_GET['avif'] ) ? sanitize_key( $_GET['avif'] ) : 'all',
			'type'     => isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'all',
			'page'     => isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1,
			'per_page' => isset( $_GET['per_page'] ) ? max( 10, min( 100, (int) $_GET['per_page'] ) ) : 25,
		);
		OptiPress_Debug::log( 'ajax.media', 'Media list requested', array( 'filters' => $args ) );
		$res  = $this->p->db->page_items( $args );
		$rows = array();
		foreach ( $res['rows'] as $item ) {
			$rows[] = $this->item_to_row( $item );
		}
		wp_send_json_success( array( 'rows' => $rows, 'total' => $res['total'], 'pages' => $res['pages'], 'page' => $args['page'] ) );
	}

	private function item_to_row( $item ) {
		$thumb = wp_get_attachment_image_url( (int) $item->attachment_id, 'thumbnail' );
		return array(
			'id'           => (int) $item->id,
			'attachment'   => (int) $item->attachment_id,
			'file'         => wp_basename( $item->file ),
			'thumb'        => $thumb ? $thumb : '',
			'mime'         => $item->mime,
			'dimensions'   => $item->width && $item->height ? $item->width . '×' . $item->height : '—',
			'orig'         => (int) $item->orig_bytes,
			'current'      => (int) $item->current_bytes,
			'saved'        => (int) $item->saved_bytes,
			'pct'          => $item->orig_bytes > 0 ? round( ( $item->saved_bytes / $item->orig_bytes ) * 100, 1 ) : 0,
			'status'       => $item->status,
			'error'        => $item->error_message,
			'webp'         => $item->webp_status,
			'avif'         => $item->avif_status,
			'backup'       => (bool) $item->has_backup,
			'optimized_at' => $item->optimized_at ? mysql2date( get_option( 'date_format' ), $item->optimized_at ) : '',
			'edit_url'     => get_edit_post_link( (int) $item->attachment_id, 'raw' ),
		);
	}

	public function h_item_detail() {
		$this->guard();
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$item = $this->p->db->get_item( $id );
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Image not found.', 'optipress' ) ) );
		}
		$meta_json = json_decode( (string) $item->meta, true );
		wp_send_json_success( array(
			'row'     => $this->item_to_row( $item ),
			'meta'    => is_array( $meta_json ) ? $meta_json : array(),
			'history' => array_map( function ( $l ) {
				return array(
					'level'   => $l->level,
					'op'      => $l->op,
					'message' => $l->message,
					'time'    => mysql2date( get_option( 'date_format' ) . ' H:i', $l->created_at ),
				);
			}, $this->p->db->logs_for_attachment( (int) $item->attachment_id ) ),
			'preview' => wp_get_attachment_image_url( (int) $item->attachment_id, 'medium' ),
			'caps'    => $this->p->env->capabilities(),
		) );
	}

	public function h_action() {
		$this->guard();
		$op  = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		if ( empty( $ids ) || ! in_array( $op, array( 'optimize', 'reoptimize', 'webp', 'avif', 'restore', 'retry' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'optipress' ) ) );
		}
		OptiPress_Debug::log( 'ajax.action', "Action $op on " . count( $ids ) . " item(s)" );

		$results = array();
		foreach ( array_slice( $ids, 0, 50 ) as $id ) {
			switch ( $op ) {
				case 'webp':
					$r = $this->p->converter->generate( $id, 'webp' );
					$results[] = array(
						'id' => $id,
						'ok' => ! empty( $r['ok'] ),
						'skipped' => ! empty( $r['skipped'] ),
						'status' => ! empty( $r['skipped'] ) ? 'skipped' : ( ! empty( $r['ok'] ) ? 'done' : 'failed' ),
						'message' => $r['message'],
					);
					break;
				case 'avif':
					$r = $this->p->converter->generate( $id, 'avif' );
					$results[] = array(
						'id' => $id,
						'ok' => ! empty( $r['ok'] ),
						'skipped' => ! empty( $r['skipped'] ),
						'status' => ! empty( $r['skipped'] ) ? 'skipped' : ( ! empty( $r['ok'] ) ? 'done' : 'failed' ),
						'message' => $r['message'],
					);
					break;
				case 'restore':
					$r = $this->p->backup->restore( $id );
					$results[] = array( 'id' => $id, 'ok' => ! empty( $r['ok'] ), 'message' => $r['message'], 'status' => 'restored' );
					break;
				default:
					if ( 'retry' === $op ) {
						$this->p->db->update_item( $id, array( 'status' => 'pending', 'error_code' => '', 'error_message' => null ) );
					}
					$r = $this->p->processor->process( $id, array( 'origin' => 'manual', 'force' => 'reoptimize' === $op ) );
					$results[] = array(
						'id'      => $id,
						'ok'      => ! empty( $r['ok'] ),
						'message' => isset( $r['message'] ) ? $r['message'] : '',
						'status'  => isset( $r['status'] ) ? $r['status'] : 'failed',
					);
			}
			$item = $this->p->db->get_item( $id );
			if ( $item ) {
				$results[ count( $results ) - 1 ]['row'] = $this->item_to_row( $item );
			}
		}
		$this->p->stats->flush();
		wp_send_json_success( array( 'results' => $results ) );
	}

	public function h_logs() {
		$this->guard();
		$args = array(
			'level' => isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : 'all',
			'op'    => isset( $_GET['op'] ) ? sanitize_key( $_GET['op'] ) : 'all',
			'q'     => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
			'from'  => isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : '',
			'to'    => isset( $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : '',
			'page'  => isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1,
			'per_page' => 30,
		);
		$res = $this->p->db->page_logs( $args );
		$rows = array();
		foreach ( $res['rows'] as $l ) {
			$ctx = json_decode( (string) $l->context, true );
			$rows[] = array(
				'id'         => (int) $l->id,
				'level'      => $l->level,
				'op'         => $l->op,
				'file'       => $l->file,
				'message'    => $l->message,
				'time'       => mysql2date( get_option( 'date_format' ) . ' H:i:s', $l->created_at ),
				'attachment' => (int) $l->attachment_id,
				'context'    => is_array( $ctx ) ? $ctx : null,
				'retryable'  => ( 'error' === $l->level && in_array( $l->op, array( 'optimize', 'webp', 'avif' ), true ) && $l->attachment_id > 0 ),
			);
		}
		wp_send_json_success( array( 'rows' => $rows, 'total' => $res['total'], 'pages' => $res['pages'], 'page' => $args['page'] ) );
	}

	public function h_logs_clear() {
		$this->guard();
		$this->p->db->clear_logs();
		wp_send_json_success( array( 'message' => __( 'All logs cleared.', 'optipress' ) ) );
	}

	public function h_analytics() {
		$this->guard();
		$range = isset( $_GET['range'] ) ? sanitize_key( $_GET['range'] ) : '30';
		if ( ! in_array( $range, array( 'today', '7', '30', '90', '365', 'all' ), true ) ) {
			$range = '30';
		}
		wp_send_json_success( $this->p->stats->analytics( $range ) );
	}

	public function h_settings_save() {
		$this->guard();
		$input = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : array();
		$res   = $this->p->settings->save( $input );
		$this->p->env->flush();
		wp_send_json_success( array(
			'settings' => $res['settings'],
			'warnings' => $res['warnings'],
			'message'  => __( 'Settings saved.', 'optipress' ),
		) );
	}

	public function h_purge_cache() {
		$this->guard();
		$purged = $this->p->purge->maybe_purge( true );
		wp_send_json_success( array(
			'purged'  => $purged,
			'message' => $purged
				? sprintf( __( 'Caches purged: %s.', 'optipress' ), implode( ', ', $purged ) )
				: __( 'No supported cache plugin responded.', 'optipress' ),
		) );
	}

	public function h_dismiss_notice() {
		$this->guard();
		update_option( 'optipress_pending_notice_dismissed', 1 );
		wp_send_json_success();
	}
}