<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_DB {
	public $items;
	public $logs;
	public $daily;

	public function __construct() {
		global $wpdb;
		$this->items = $wpdb->prefix . 'optipress_items';
		$this->logs  = $wpdb->prefix . 'optipress_logs';
		$this->daily = $wpdb->prefix . 'optipress_daily';
	}

	public function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$sql_items = "CREATE TABLE {$this->items} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			file VARCHAR(255) NOT NULL DEFAULT '',
			mime VARCHAR(100) NOT NULL DEFAULT '',
			width INT(11) UNSIGNED NOT NULL DEFAULT 0,
			height INT(11) UNSIGNED NOT NULL DEFAULT 0,
			orig_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			current_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			saved_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error_code VARCHAR(60) NOT NULL DEFAULT '',
			error_message TEXT NULL,
			webp_status VARCHAR(20) NOT NULL DEFAULT 'none',
			webp_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			avif_status VARCHAR(20) NOT NULL DEFAULT 'none',
			avif_bytes BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			has_backup TINYINT(1) NOT NULL DEFAULT 0,
			optimized_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			meta LONGTEXT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_id (attachment_id),
			KEY status (status),
			KEY webp_status (webp_status),
			KEY avif_status (avif_status)
		) $charset;";
		$sql_logs = "CREATE TABLE {$this->logs} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			level VARCHAR(12) NOT NULL DEFAULT 'info',
			op VARCHAR(24) NOT NULL DEFAULT '',
			attachment_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			file VARCHAR(255) NOT NULL DEFAULT '',
			message TEXT NULL,
			context LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY op (op),
			KEY attachment_id (attachment_id)
		) $charset;";
		$sql_daily = "CREATE TABLE {$this->daily} (
			day DATE NOT NULL,
			optimized INT(11) UNSIGNED NOT NULL DEFAULT 0,
			failed INT(11) UNSIGNED NOT NULL DEFAULT 0,
			saved BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			webp INT(11) UNSIGNED NOT NULL DEFAULT 0,
			avif INT(11) UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (day)
		) $charset;";
		dbDelta( $sql_items );
		dbDelta( $sql_logs );
		dbDelta( $sql_daily );
	}

	/* ---------------- Items ---------------- */
	public function insert_item( $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		OptiPress_Debug::log( 'db.insert_item', 'Inserting item row', array( 'attachment_id' => $data['attachment_id'] ?? 0, 'mime' => $data['mime'] ?? '' ) );
		$wpdb->insert( $this->items, $data );
		return (int) $wpdb->insert_id;
	}

	public function update_item( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $this->items, $data, array( 'id' => (int) $id ) );
	}

	public function get_item( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->items} WHERE id = %d", (int) $id ) );
	}

	public function get_item_by_attachment( $attachment_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->items} WHERE attachment_id = %d", (int) $attachment_id ) );
	}

	public function delete_item( $id ) {
		global $wpdb;
		$wpdb->delete( $this->items, array( 'id' => (int) $id ) );
	}

	public function page_items( $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['q'] ) ) {
			$where[]  = 'file LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['q'] ) . '%';
		}
		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['webp'] ) && 'all' !== $args['webp'] ) {
			$where[]  = 'webp_status = %s';
			$params[] = $args['webp'];
		}
		if ( ! empty( $args['avif'] ) && 'all' !== $args['avif'] ) {
			$where[]  = 'avif_status = %s';
			$params[] = $args['avif'];
		}
		if ( ! empty( $args['type'] ) && 'all' !== $args['type'] ) {
			$where[]  = 'mime LIKE %s';
			$params[] = $args['type'] . '/%';
		}
		$per_page = min( 100, max( 10, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 25 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		OptiPress_Debug::log( 'db.page_items', 'Querying items', array( 'page' => $page, 'per_page' => $per_page, 'filters' => $args ) );
		$sql = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $this->items . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY attachment_id DESC LIMIT %d OFFSET %d';
		$params[] = $per_page;
		$params[] = $offset;
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		return array( 'rows' => $rows ? $rows : array(), 'total' => $total, 'pages' => (int) ceil( $total / $per_page ) );
	}

	public function bulk_candidates( $mode, $limit ) {
		global $wpdb;
		$limit = max( 1, min( 50, (int) $limit ) );
		switch ( $mode ) {
			case 'retry_failed':
				$sql = "SELECT * FROM {$this->items} WHERE status = 'failed' ORDER BY attachment_id DESC LIMIT %d";
				break;
			case 'webp':
				$sql = "SELECT * FROM {$this->items} WHERE status = 'optimized' AND webp_status IN ('none','failed','pending') ORDER BY attachment_id DESC LIMIT %d";
				break;
			case 'avif':
				$sql = "SELECT * FROM {$this->items} WHERE status = 'optimized' AND avif_status IN ('none','failed','pending') ORDER BY attachment_id DESC LIMIT %d";
				break;
			default:
				$sql = "SELECT * FROM {$this->items} WHERE status = 'pending' ORDER BY attachment_id DESC LIMIT %d";
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ) );
		return $rows ? $rows : array();
	}

	public function count_candidates( $mode ) {
		global $wpdb;
		switch ( $mode ) {
			case 'retry_failed':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->items} WHERE status = 'failed'" );
			case 'webp':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->items} WHERE status = 'optimized' AND webp_status IN ('none','failed','pending')" );
			case 'avif':
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->items} WHERE status = 'optimized' AND avif_status IN ('none','failed','pending')" );
			default:
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->items} WHERE status = 'pending'" );
		}
	}

	public function recover_stale_processing() {
		global $wpdb;
		$n = $wpdb->query( "UPDATE {$this->items} SET status = 'pending' WHERE status = 'processing' AND updated_at < (NOW() - INTERVAL 10 MINUTE)" );
		if ( $n > 0 ) {
			OptiPress_Debug::log( 'db.recover_stale', "Recovered $n stale processing rows" );
		}
	}

	public function counters() {
		global $wpdb;
		$r = $wpdb->get_row(
			"SELECT
				COUNT(*) AS total,
				COALESCE(SUM(status = 'optimized'), 0)  AS optimized,
				COALESCE(SUM(status = 'pending'), 0)    AS pending,
				COALESCE(SUM(status = 'failed'), 0)     AS failed,
				COALESCE(SUM(status = 'skipped'), 0)    AS skipped,
				COALESCE(SUM(status = 'processing'), 0) AS processing,
				COALESCE(SUM(saved_bytes), 0)           AS saved,
				COALESCE(SUM(orig_bytes), 0)            AS orig,
				COALESCE(SUM(current_bytes), 0)         AS current,
				COALESCE(SUM(IF(status='optimized', saved_bytes, 0)), 0) AS saved_optimized,
				COALESCE(SUM(IF(status='optimized', orig_bytes, 0)), 0)  AS orig_optimized,
				COALESCE(SUM(webp_status = 'done'), 0)  AS webp_done,
				COALESCE(SUM(avif_status = 'done'), 0)  AS avif_done,
				COALESCE(SUM(webp_bytes), 0)            AS webp_bytes,
				COALESCE(SUM(avif_bytes), 0)            AS avif_bytes
			FROM {$this->items}"
		);
		if ( ! $r ) {
			$r = (object) array();
		}
		$out = array();
		foreach ( array( 'total','optimized','pending','failed','skipped','processing','saved','orig','current','saved_optimized','orig_optimized','webp_done','avif_done','webp_bytes','avif_bytes' ) as $k ) {
			$out[ $k ] = isset( $r->$k ) ? (float) $r->$k : 0;
		}
		$out['avg_pct']        = $out['orig_optimized'] > 0 ? ( $out['saved_optimized'] / $out['orig_optimized'] ) * 100 : 0;
		$out['success_rate']   = ( $out['optimized'] + $out['failed'] ) > 0 ? ( $out['optimized'] / ( $out['optimized'] + $out['failed'] ) ) * 100 : 100;
		$out['library_pct']    = $out['total'] > 0 ? ( ( $out['optimized'] + $out['skipped'] ) / $out['total'] ) * 100 : 0;
		$out['converted']      = $out['webp_done'] + $out['avif_done'];
		return $out;
	}

	/* ---------------- Logs ---------------- */
	public function insert_log( $level, $op, $message, $extra = array() ) {
		global $wpdb;
		$wpdb->insert( $this->logs, array(
			'created_at'    => current_time( 'mysql' ),
			'level'         => $level,
			'op'            => $op,
			'attachment_id' => isset( $extra['attachment_id'] ) ? (int) $extra['attachment_id'] : 0,
			'file'          => isset( $extra['file'] ) ? sanitize_text_field( wp_basename( $extra['file'] ) ) : '',
			'message'       => $message,
			'context'       => ! empty( $extra['context'] ) ? wp_json_encode( $extra['context'] ) : null,
		) );
		return (int) $wpdb->insert_id;
	}

	public static function static_log( $level, $op, $message ) {
		global $wpdb;
		$table = $wpdb->prefix . 'optipress_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$wpdb->insert( $table, array(
			'created_at' => current_time( 'mysql' ),
			'level'      => $level,
			'op'         => $op,
			'message'    => $message,
		) );
	}

	public function page_logs( $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['level'] ) && 'all' !== $args['level'] ) { $where[] = 'level = %s'; $params[] = $args['level']; }
		if ( ! empty( $args['op'] ) && 'all' !== $args['op'] )       { $where[] = 'op = %s';    $params[] = $args['op']; }
		if ( ! empty( $args['q'] ) ) { $where[] = '(file LIKE %s OR message LIKE %s)'; $like = '%' . $wpdb->esc_like( $args['q'] ) . '%'; $params[] = $like; $params[] = $like; }
		if ( ! empty( $args['from'] ) ) { $where[] = 'created_at >= %s'; $params[] = $args['from'] . ' 00:00:00'; }
		if ( ! empty( $args['to'] ) )   { $where[] = 'created_at <= %s'; $params[] = $args['to'] . ' 23:59:59'; }
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 30 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$params[] = $per_page;
		$params[] = ( $page - 1 ) * $per_page;
		$sql   = 'SELECT SQL_CALC_FOUND_ROWS * FROM ' . $this->logs . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		return array( 'rows' => $rows ? $rows : array(), 'total' => $total, 'pages' => (int) ceil( $total / $per_page ) );
	}

	public function logs_for_attachment( $attachment_id, $limit = 15 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->logs} WHERE attachment_id = %d ORDER BY id DESC LIMIT %d", (int) $attachment_id, (int) $limit ) );
		return $rows ? $rows : array();
	}

	public function prune_logs( $days ) {
		global $wpdb;
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->logs} WHERE created_at < (NOW() - INTERVAL %d DAY)", $days ) );
		}
	}

	public function clear_logs() {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->logs}" );
	}

	/* ---------------- Daily aggregates ---------------- */
	public function bump_daily( $field, $amount ) {
		global $wpdb;
		$allowed = array( 'optimized', 'failed', 'saved', 'webp', 'avif' );
		if ( ! in_array( $field, $allowed, true ) || 0 == $amount ) {
			return;
		}
		$day = current_time( 'Y-m-d' );
		OptiPress_Debug::log( 'db.bump_daily', "Bumping $field by $amount on $day" );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->daily} (day, {$field}) VALUES (%s, %d)
			ON DUPLICATE KEY UPDATE {$field} = {$field} + VALUES({$field})",
			$day, (int) $amount
		) );
	}

	/**
	 * Decrement a daily aggregate (never below zero). Used by restore to roll back analytics.
	 */
	public function decrement_daily( $day, $field, $amount ) {
		global $wpdb;
		$allowed = array( 'optimized', 'failed', 'saved', 'webp', 'avif' );
		if ( ! in_array( $field, $allowed, true ) || ! $day || $amount <= 0 ) {
			return;
		}
		OptiPress_Debug::log( 'db.decrement_daily', "Decrementing $field by $amount on $day" );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$this->daily} SET {$field} = GREATEST(0, {$field} - %d) WHERE day = %s",
			(int) $amount, $day
		) );
	}

	public function daily_series( $range ) {
		global $wpdb;
		$all = ( 'all' === $range );
		$days = (int) $range;
		if ( 'today' === $range ) { $days = 1; }
		if ( ! $all && $days < 1 ) { $days = 30; }
		if ( $all ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$this->daily} ORDER BY day ASC" );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->daily} WHERE day >= DATE_SUB(%s, INTERVAL %d DAY) ORDER BY day ASC", current_time( 'Y-m-d' ), $days ) );
		}
		return $rows ? $rows : array();
	}
}