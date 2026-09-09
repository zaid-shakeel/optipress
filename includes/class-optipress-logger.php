<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OptiPress_Logger {

	/** @var OptiPress_DB */ private $db;
	/** @var OptiPress_Settings */ private $settings;

	public function __construct( $db, $settings ) {
		$this->db       = $db;
		$this->settings = $settings;
	}

	/**
	 * @param string $level success|info|warning|error
	 * @param string $op    optimize|webp|avif|bulk|restore|system|serve
	 * @param string $message
	 * @param array  $extra
	 */
	public function log( $level, $op, $message, $extra = array() ) {
		if ( ! $this->settings->get( 'logs_enabled' ) && 'error' !== $level ) {
			return;
		}
		$this->db->insert_log( $level, $op, $message, $extra );
	}

	public function success( $op, $message, $extra = array() ) { $this->log( 'success', $op, $message, $extra ); }
	public function info( $op, $message, $extra = array() )    { $this->log( 'info', $op, $message, $extra ); }
	public function warning( $op, $message, $extra = array() ) { $this->log( 'warning', $op, $message, $extra ); }
	public function error( $op, $message, $extra = array() )   { $this->log( 'error', $op, $message, $extra ); }
}