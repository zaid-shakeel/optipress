<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_Queue {
	const LOCK_OPTION = 'optipress_bulk_lock';
	private $p;

	public function __construct( $plugin ) {
		$this->p = $plugin;
	}

	public function start( $mode ) {
		$this->recover_stale_items();
		$lock = $this->lock();
		if ( $lock && 'running' === $lock['state'] && ( time() - $lock['updated'] ) < 120 ) {
			return array( 'ok' => false, 'busy' => true, 'token' => $lock['token'], 'message' => __( 'A bulk operation is already running.', 'optipress' ) );
		}

		$total = $this->p->db->count_candidates( $mode );
		$token = wp_generate_password( 16, false );
		$this->set_lock( array(
			'state'     => 'running',
			'mode'      => $mode,
			'token'     => $token,
			'started'   => time(),
			'updated'   => time(),
			'processed' => 0,
			'failed'    => 0,
			'attempts'  => 0,
			'last_file' => '',
		) );

		// Breakdown for webp/avif modes (already-converted / N/A / unsupported).
		$breakdown = array();
		if ( 'webp' === $mode || 'avif' === $mode ) {
			global $wpdb;
			$col = $mode . '_status'; // safe: mode is whitelisted above
			$breakdown = array(
				'already'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p->db->items} WHERE {$col} = 'done'" ),
				'na'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p->db->items} WHERE {$col} = 'na'" ),
				'unsupported' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p->db->items} WHERE status = 'skipped'" ),
			);
			OptiPress_Debug::log( 'queue.start_breakdown', "Mode $mode: queued=$total " . wp_json_encode( $breakdown ) );
		}

		$this->p->logger->info( 'bulk', sprintf(
			__( 'Bulk %1$s started with %2$d images queued.', 'optipress' ),
			$this->mode_label( $mode ), $total
		), array( 'context' => array( 'mode' => $mode, 'total' => $total, 'breakdown' => $breakdown ) ) );

		return array( 'ok' => true, 'token' => $token, 'total' => $total, 'breakdown' => $breakdown );
	}

	public function run_batch( $token ) {
		$lock = $this->lock();
		if ( ! $lock || 'running' !== $lock['state'] || $lock['token'] !== $token ) {
			return array( 'ok' => false, 'finished' => true, 'message' => __( 'No active bulk operation.', 'optipress' ) );
		}
		@set_time_limit( max( 30, (int) $this->p->settings->get( 'time_limit' ) + 15 ) );
		$mode      = $lock['mode'];
		$budget    = max( 5, (int) $this->p->settings->get( 'time_limit' ) );
		$batch_max = max( 1, (int) $this->p->settings->get( 'batch_size' ) );
		$start     = microtime( true );
		$results   = array();
		$processed = 0;

		while ( $processed < $batch_max ) {
			$candidates = $this->p->db->bulk_candidates( $mode, 1 );
			if ( empty( $candidates ) ) { break; }
			$item = $candidates[0];
			$this->p->db->update_item( (int) $item->id, array( 'status' => 'processing' ) );
			$r = $this->process_one( $item, $mode );
			$results[] = $r;
			$processed++;
			$lock['processed'] = (int) $lock['processed'] + 1;
			if ( ! empty( $r['failed'] ) ) {
				$lock['failed'] = (int) $lock['failed'] + 1;
			}
			$lock['last_file'] = isset( $r['file'] ) ? $r['file'] : '';
			$lock['updated']   = time();
			$this->set_lock( $lock );
			if ( ( microtime( true ) - $start ) > ( $budget - 2 ) ) { break; }
		}

		$remaining = $this->p->db->count_candidates( $mode );
		$finished  = 0 === $remaining;
		if ( $finished ) { $this->finish( $lock ); }

		return array(
			'ok'        => true,
			'finished'  => $finished,
			'batch'     => $results,
			'remaining' => $remaining,
			'lock'      => array(
				'processed' => (int) $lock['processed'],
				'failed'    => (int) $lock['failed'],
				'last_file' => $lock['last_file'],
			),
			'counters'  => $this->p->stats->counters(),
		);
	}

	private function process_one( $item, $mode ) {
		switch ( $mode ) {
			case 'webp':
			case 'avif':
				$r = $this->p->converter->generate( (int) $item->id, $mode );
				return array(
					'file'    => wp_basename( $item->file ),
					'ok'      => ! empty( $r['ok'] ),
					'failed'  => ( empty( $r['ok'] ) && empty( $r['skipped'] ) ),
					'skipped' => ! empty( $r['skipped'] ),
					'message' => $r['message'],
					'saved'   => 0,
				);
			default:
				if ( 'retry_failed' === $mode || 'failed' === $item->status ) {
					$this->p->db->update_item( (int) $item->id, array( 'status' => 'pending', 'error_code' => '', 'error_message' => null ) );
				}
				$r = $this->p->processor->process( (int) $item->id, array( 'origin' => 'bulk' ) );
				return array(
					'file'    => isset( $r['file'] ) ? $r['file'] : wp_basename( $item->file ),
					'ok'      => ! empty( $r['ok'] ),
					'failed'  => empty( $r['ok'] ),
					'skipped' => isset( $r['status'] ) && 'skipped' === $r['status'],
					'message' => isset( $r['message'] ) ? $r['message'] : '',
					'saved'   => isset( $r['saved'] ) ? (int) $r['saved'] : 0,
				);
		}
	}

	private function finish( $lock ) {
		$mode    = $lock['mode'];
		$failed  = (int) $lock['failed'];
		$attempts = (int) $lock['attempts'];

		if ( $failed > 0 && 'optimize' === $mode && $attempts < 1 && $this->p->settings->get( 'auto_retry' ) ) {
			$lock['mode']     = 'retry_failed';
			$lock['attempts'] = $attempts + 1;
			$lock['failed']   = 0;
			$lock['updated']  = time();
			$this->set_lock( $lock );
			$this->p->logger->info( 'bulk', sprintf( __( '%d images failed — retrying once.', 'optipress' ), $failed ) );
			return;
		}

		$counters = $this->p->stats->counters();
		$this->set_lock( array(
			'state'     => 'idle',
			'mode'      => $mode,
			'token'     => '',
			'started'   => $lock['started'],
			'updated'   => time(),
			'processed' => $lock['processed'],
			'failed'    => $lock['failed'],
			'attempts'  => $lock['attempts'],
			'last_file' => $lock['last_file'],
		) );
		$this->p->logger->success( 'bulk', sprintf(
			__( 'Bulk %1$s completed: %2$d processed, %3$d failed.', 'optipress' ),
			$this->mode_label( $mode ), (int) $lock['processed'], (int) $lock['failed']
		), array( 'context' => array( 'processed' => (int) $lock['processed'], 'failed' => (int) $lock['failed'] ) ) );
		set_transient( 'optipress_bulk_done', array(
			'optimized' => (int) $lock['processed'] - (int) $lock['failed'],
			'failed'    => (int) $lock['failed'],
			'saved'     => (int) $counters['saved'],
		), 300 );
		$this->p->purge->maybe_purge( true );
	}

	public function cancel( $token ) {
		$lock = $this->lock();
		if ( $lock && $lock['token'] === $token ) {
			$lock['state'] = 'idle';
			$lock['token'] = '';
			$this->set_lock( $lock );
			$this->p->db->recover_stale_processing();
			$this->p->logger->info( 'bulk', __( 'Bulk operation cancelled.', 'optipress' ) );
			$this->p->stats->flush();
			return true;
		}
		return false;
	}

	public function lock() {
		$lock = get_option( self::LOCK_OPTION );
		return is_array( $lock ) ? $lock : null;
	}

	private function set_lock( $lock ) {
		update_option( self::LOCK_OPTION, $lock, false );
	}

	public function recover_stale_items() {
		$this->p->db->recover_stale_processing();
		$lock = $this->lock();
		if ( $lock && 'running' === $lock['state'] && ( time() - (int) $lock['updated'] ) > 300 ) {
			$lock['state'] = 'idle';
			$lock['token'] = '';
			$this->set_lock( $lock );
		}
	}

	private function mode_label( $mode ) {
		switch ( $mode ) {
			case 'webp': return __( 'WebP conversion', 'optipress' );
			case 'avif': return __( 'AVIF conversion', 'optipress' );
			case 'retry_failed': return __( 'retry of failed images', 'optipress' );
			default: return __( 'optimization', 'optipress' );
		}
	}
}