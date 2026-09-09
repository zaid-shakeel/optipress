<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Core container: instantiates services and registers WordPress hooks.
 */
final class OptiPress_Plugin {

	const VERSION = OPTIPRESS_VERSION;

	/** @var OptiPress_Plugin|null */
	private static $instance = null;

	/** @var OptiPress_Settings */    public $settings;
	/** @var OptiPress_Environment */ public $env;
	/** @var OptiPress_DB */          public $db;
	/** @var OptiPress_Logger */      public $logger;
	/** @var OptiPress_Stats */       public $stats;
	/** @var OptiPress_Media */       public $media;
	/** @var OptiPress_Backup */      public $backup;
	/** @var OptiPress_Processor */   public $processor;
	/** @var OptiPress_Converter */   public $converter;
	/** @var OptiPress_Queue */       public $queue;
	/** @var OptiPress_AutoServe */   public $autoserve;
	/** @var OptiPress_Cache_Purge */ public $purge;
	/** @var OptiPress_Integrations */ public $integrations;

	/** @var array|null */
	private static $uploads = null;

	/** @return OptiPress_Plugin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		load_plugin_textdomain( 'optipress', false, dirname( plugin_basename( OPTIPRESS_FILE ) ) . '/languages' );

		$this->settings      = new OptiPress_Settings();
		$this->env           = new OptiPress_Environment();
		$this->db            = new OptiPress_DB();
		$this->logger        = new OptiPress_Logger( $this->db, $this->settings );
		$this->stats         = new OptiPress_Stats( $this->db );
		$this->media         = new OptiPress_Media( $this->db );
		$this->backup        = new OptiPress_Backup( $this );
		$this->processor     = new OptiPress_Processor( $this );
		$this->converter     = new OptiPress_Converter( $this );
		$this->queue         = new OptiPress_Queue( $this );
		$this->autoserve     = new OptiPress_AutoServe( $this );
		$this->purge         = new OptiPress_Cache_Purge( $this );
		$this->integrations  = new OptiPress_Integrations();

		new OptiPress_Ajax( $this );
		new OptiPress_Admin( $this );

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_upload_generated' ), 20, 2 );
		add_action( 'optipress_auto_optimize', array( $this, 'cron_auto_optimize' ) );
		add_action( 'optipress_daily_cleanup', array( $this, 'cron_daily_cleanup' ) );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );

		if ( $this->settings->get( 'serve_enabled' ) && ! is_admin() ) {
			add_action( 'template_redirect', array( $this->autoserve, 'maybe_start_buffer' ), 1 );
		}

		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
	}

	/**
	 * Activation: self-verifying, with readable errors.
	 */
	public static function activate() {
		try {
			if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
				throw new Exception( sprintf( 'OptiPress requires PHP 7.4 or newer. This server is running PHP %s.', PHP_VERSION ) );
			}

			$required_classes = array(
				'OptiPress_Settings', 'OptiPress_Environment', 'OptiPress_DB', 'OptiPress_Logger',
				'OptiPress_Stats', 'OptiPress_Media', 'OptiPress_Backup', 'OptiPress_Processor',
				'OptiPress_Converter', 'OptiPress_Queue', 'OptiPress_AutoServe', 'OptiPress_Cache_Purge',
				'OptiPress_Integrations', 'OptiPress_Ajax', 'OptiPress_Admin',
			);
			foreach ( $required_classes as $cls ) {
				if ( ! class_exists( $cls ) ) {
					throw new Exception( sprintf(
						'Required class %s could not be found. The plugin upload appears incomplete or a file was corrupted — delete the optipress folder and re-upload it in full.',
						$cls
					) );
				}
			}

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			global $wpdb;
			$db = new OptiPress_DB();
			$db->create_tables();

			foreach ( array( $db->items, $db->logs, $db->daily ) as $table ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
					$db_error = $wpdb->last_error;
					throw new Exception( sprintf(
						'Database table %s could not be created.%s Check that the database user has CREATE/ALTER privileges.',
						$table,
						$db_error ? ' MySQL said: ' . $db_error : ''
					) );
				}
			}

			$settings = new OptiPress_Settings();
			$settings->ensure_defaults();

			if ( ! wp_next_scheduled( 'optipress_daily_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'optipress_daily_cleanup' );
			}

			update_option( 'optipress_db_version', OPTIPRESS_DB_VERSION );
			set_transient( 'optipress_activation_redirect', 1, 120 );

			$uploads = wp_get_upload_dir();
			if ( empty( $uploads['basedir'] ) || ! is_writable( $uploads['basedir'] ) ) {
				OptiPress_DB::static_log( 'warning', 'system', sprintf(
					'Uploads directory is not writable: %s',
					empty( $uploads['basedir'] ) ? '(unknown path)' : $uploads['basedir']
				) );
			}

			$env  = new OptiPress_Environment();
			$caps = $env->capabilities();
			if ( ! $caps['webp_encode'] ) {
				OptiPress_DB::static_log( 'warning', 'system', 'WebP encoding is not available on this server. WebP features were disabled.' );
			}
			if ( ! $caps['avif_encode'] ) {
				OptiPress_DB::static_log( 'info', 'system', 'AVIF encoding is not available on this server. AVIF features were disabled.' );
			}
		} catch ( \Throwable $e ) {
			error_log( 'OptiPress activation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			// Rethrow as Exception so WordPress displays the message on the Plugins screen.
			throw new Exception( 'OptiPress activation failed — ' . $e->getMessage() );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'optipress_daily_cleanup' );
		wp_clear_scheduled_hook( 'optipress_auto_optimize' );
		delete_option( 'optipress_bulk_lock' );
	}

	/** @return array */
	public static function uploads() {
		if ( null === self::$uploads ) {
			self::$uploads = wp_get_upload_dir();
		}
		return self::$uploads;
	}

	/**
	 * Convert a URL inside uploads/ to an absolute path (or null).
	 *
	 * @param string $url
	 * @return string|null
	 */
	public static function url_to_upload_path( $url ) {
		$uploads = self::uploads();
		$u_path  = wp_parse_url( $url, PHP_URL_PATH );
		$b_path  = wp_parse_url( set_url_scheme( $uploads['baseurl'], 'relative' ), PHP_URL_PATH );
		if ( ! $u_path || ! $b_path || 0 !== strpos( $u_path, $b_path ) ) {
			$base_full = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
			if ( ! $base_full || ! $u_path || 0 !== strpos( $u_path, $base_full ) ) {
				return null;
			}
			$b_path = $base_full;
		}
		$rel  = substr( $u_path, strlen( $b_path ) );
		$path = untrailingslashit( $uploads['basedir'] ) . $rel;
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * @param array $metadata
	 * @param int   $attachment_id
	 * @return array
	 */
	public function on_upload_generated( $metadata, $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}
		$item_id = $this->media->ensure_item( $attachment_id, true );

		if ( $item_id && $this->settings->get( 'auto_optimize' ) ) {
			$engine = $this->env->capabilities();
			if ( $engine['gd'] || $engine['imagick'] ) {
				wp_schedule_single_event( time() + 2, 'optipress_auto_optimize', array( (int) $attachment_id ) );
			}
		}
		return $metadata;
	}

	/** @param int $attachment_id */
	public function cron_auto_optimize( $attachment_id ) {
		$item_id = $this->media->ensure_item( (int) $attachment_id, true );
		if ( ! $item_id ) {
			return;
		}
		$this->processor->process( $item_id, array( 'origin' => 'auto' ) );
	}

	public function cron_daily_cleanup() {
		if ( $this->settings->get( 'logs_enabled' ) ) {
			$this->db->prune_logs( (int) $this->settings->get( 'log_retention' ) );
		}
		$this->queue->recover_stale_items();
	}

	/** @param int $attachment_id */
	public function on_delete_attachment( $attachment_id ) {
		$item = $this->db->get_item_by_attachment( (int) $attachment_id );
		if ( ! $item ) {
			return;
		}
		$this->converter->delete_conversions_for_item( $item );
		$this->backup->delete_backups( (int) $attachment_id );
		$this->db->delete_item( (int) $item->id );
		$this->stats->flush();
	}

	public function admin_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'optipress' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( 'optipress_bulk_done' ) ) {
			$data = get_transient( 'optipress_bulk_done' );
			delete_transient( 'optipress_bulk_done' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'OptiPress:', 'optipress' ),
				esc_html( sprintf(
					/* translators: 1: optimized count, 2: failed count, 3: saved */
					__( 'Bulk optimization finished. %1$d images optimized, %2$d failed, %3$s saved.', 'optipress' ),
					(int) $data['optimized'], (int) $data['failed'], size_format( $data['saved'] )
				) )
			);
		}

		if ( ! get_option( 'optipress_pending_notice_dismissed' ) ) {
			$c = $this->stats->counters();
			if ( $c['pending'] > 0 ) {
				$url = admin_url( 'admin.php?page=optipress-bulk' );
				printf(
					'<div class="notice notice-info is-dismissible"><p>%s <a class="button button-primary" href="%s">%s</a></p></div>',
					esc_html( sprintf(
						/* translators: %d: pending image count */
						__( 'OptiPress: %d images in your Media Library have not been optimized yet.', 'optipress' ),
						(int) $c['pending']
					) ),
					esc_url( $url ),
					esc_html__( 'Start Bulk Optimization', 'optipress' )
				);
			}
		}
	}

	public function maybe_redirect_after_activation() {
		if ( get_transient( 'optipress_activation_redirect' ) && ! wp_doing_ajax() ) {
			delete_transient( 'optipress_activation_redirect' );
			if ( current_user_can( 'manage_options' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=optipress' ) );
				exit;
			}
		}
	}
}