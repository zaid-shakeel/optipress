<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cached aggregate stats. Counters are derived from the items table only.
 */
class OptiPress_Stats {

	/** @var OptiPress_DB */ private $db;
	const CACHE_KEY = 'optipress_counters';

	public function __construct( $db ) {
		$this->db = $db;
	}

	/** @return array */
	public function counters() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$counters = $this->db->counters();
		set_transient( self::CACHE_KEY, $counters, 10 );
		return $counters;
	}

	public function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Analytics payload for a range.
	 *
	 * @param string $range
	 * @return array
	 */
	public function analytics( $range ) {
		$rows = $this->db->daily_series( $range );
		$totals = array( 'optimized' => 0, 'failed' => 0, 'saved' => 0, 'webp' => 0, 'avif' => 0 );
		$series = array();

		foreach ( $rows as $r ) {
			$totals['optimized'] += (int) $r->optimized;
			$totals['failed']    += (int) $r->failed;
			$totals['saved']     += (int) $r->saved;
			$totals['webp']      += (int) $r->webp;
			$totals['avif']      += (int) $r->avif;
			$series[] = array(
				'day'       => $r->day,
				'optimized' => (int) $r->optimized,
				'failed'    => (int) $r->failed,
				'saved'     => (int) $r->saved,
				'webp'      => (int) $r->webp,
				'avif'      => (int) $r->avif,
			);
		}
		$totals['operations']  = $totals['optimized'] + $totals['failed'] + $totals['webp'] + $totals['avif'];
		$totals['success_rate'] = ( $totals['optimized'] + $totals['failed'] ) > 0
			? round( ( $totals['optimized'] / ( $totals['optimized'] + $totals['failed'] ) ) * 100, 1 )
			: 100;

		return array( 'totals' => $totals, 'series' => $series );
	}
}