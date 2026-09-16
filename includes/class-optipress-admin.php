<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Admin UI: menu, views, assets, media column.
 * All markup is built via string concatenation and helpers — no inline
 * function calls inside HTML templates.
 */
class OptiPress_Admin {

	/** @var OptiPress_Plugin */
	private $p;

	public function __construct( $plugin ) {
		$this->p = $plugin;
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'manage_upload_columns', array( $this, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'media_column_content' ), 10, 2 );
	}

	public function register_menu() {
		add_menu_page( 'OptiPress', 'OptiPress', 'manage_options', 'optipress', array( $this, 'render_dashboard' ), 'dashicons-format-image', 80 );
		add_submenu_page( 'optipress', __( 'Dashboard', 'optipress' ), __( 'Dashboard', 'optipress' ), 'manage_options', 'optipress', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'optipress', __( 'Bulk Optimize', 'optipress' ), __( 'Bulk Optimize', 'optipress' ), 'manage_options', 'optipress-bulk', array( $this, 'render_bulk' ) );
		add_submenu_page( 'optipress', __( 'Media Library', 'optipress' ), __( 'Media Library', 'optipress' ), 'manage_options', 'optipress-media', array( $this, 'render_media' ) );
		add_submenu_page( 'optipress', __( 'Analytics', 'optipress' ), __( 'Analytics', 'optipress' ), 'manage_options', 'optipress-analytics', array( $this, 'render_analytics' ) );
		add_submenu_page( 'optipress', __( 'Logs', 'optipress' ), __( 'Logs', 'optipress' ), 'manage_options', 'optipress-logs', array( $this, 'render_logs' ) );
		add_submenu_page( 'optipress', __( 'Settings', 'optipress' ), __( 'Settings', 'optipress' ), 'manage_options', 'optipress-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'optipress', __( 'System & Integrations', 'optipress' ), __( 'System', 'optipress' ), 'manage_options', 'optipress-system', array( $this, 'render_system' ) );
	}

	private function is_plugin_page( $hook ) {
		return false !== strpos( (string) $hook, 'optipress' );
	}

	public function assets( $hook ) {
		if ( ! $this->is_plugin_page( $hook ) ) {
			return;
		}
		
		$css_ver = file_exists( OPTIPRESS_PATH . 'assets/css/optipress-admin.css' ) ? (string) filemtime( OPTIPRESS_PATH . 'assets/css/optipress-admin.css' ) : OPTIPRESS_VERSION;
		$js_ver  = file_exists( OPTIPRESS_PATH . 'assets/js/optipress-admin.js' ) ? (string) filemtime( OPTIPRESS_PATH . 'assets/js/optipress-admin.js' ) : OPTIPRESS_VERSION;
		wp_enqueue_style( 'optipress-admin', OPTIPRESS_URL . 'assets/css/optipress-admin.css', array(), $css_ver );
		wp_enqueue_script( 'optipress-admin', OPTIPRESS_URL . 'assets/js/optipress-admin.js', array(), $js_ver, true );

		$caps = $this->p->env->capabilities();
		wp_localize_script( 'optipress-admin', 'OptiPressData', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'optipress' ),
			'caps'  => $caps,
			'i18n'  => array(
				'optimizing'      => __( 'Optimizing…', 'optipress' ),
				'generating'      => __( 'Generating…', 'optipress' ),
				'restoring'       => __( 'Restoring…', 'optipress' ),
				'processing'      => __( 'Processing', 'optipress' ),
				'confirm_restore' => __( 'Restore this image from backup? Optimized files and WebP/AVIF versions will be removed and the original files put back in place.', 'optipress' ),
				'confirm_clear'   => __( 'Delete all logs? This cannot be undone.', 'optipress' ),
				'retry'           => __( 'Retry', 'optipress' ),
				'loading'         => __( 'Loading…', 'optipress' ),
				'scan_found'      => __( 'Scanning media library…', 'optipress' ),
				'network'         => __( 'Network error. Check your connection — the queue will resume where it left off.', 'optipress' ),
			),
		) );
	}

	/* ---------------- Media Library column ---------------- */

	public function media_column( $cols ) {
		$cols['optipress'] = __( 'OptiPress', 'optipress' );
		return $cols;
	}

	public function media_column_content( $column, $id ) {
		if ( 'optipress' !== $column ) {
			return;
		}
		$item = $this->p->db->get_item_by_attachment( (int) $id );
		if ( ! $item ) {
			echo '—';
			return;
		}
		echo $this->badge_html( $item->status ); // phpcs:ignore
		if ( 'optimized' === $item->status && (int) $item->saved_bytes > 0 && (int) $item->orig_bytes > 0 ) {
			$pct = number_format_i18n( ( $item->saved_bytes / $item->orig_bytes ) * 100, 1 );
			echo '<br><small>-' . esc_html( size_format( $item->saved_bytes ) ) . ' (' . esc_html( $pct ) . '%)</small>';
		}
	}

	/* ---------------- Shared builders ---------------- */

	private function shell( $page, $title, $subtitle, $content_cb ) {
		echo '<div class="op-page">';
		echo '<header class="op-header"><div><h1 class="op-header__title">' . esc_html( $title ) . '</h1>';
		echo '<p class="op-header__sub">' . esc_html( $subtitle ) . '</p></div></header>';
		echo '<div class="op-layout">';
		echo $this->nav_html( $page ); // phpcs:ignore
		echo '<main class="op-main">';
		call_user_func( $content_cb );
		echo '</main></div></div>';
	}

	private function nav_html( $current ) {
		$items = array(
			'optipress'           => array( __( 'Dashboard', 'optipress' ), 'dashicons-dashboard' ),
			'optipress-bulk'      => array( __( 'Bulk Optimize', 'optipress' ), 'dashicons-controls-fastforward' ),
			'optipress-media'     => array( __( 'Media Library', 'optipress' ), 'dashicons-format-gallery' ),
			'optipress-analytics' => array( __( 'Analytics', 'optipress' ), 'dashicons-chart-area' ),
			'optipress-logs'      => array( __( 'Logs', 'optipress' ), 'dashicons-list-view' ),
			'optipress-settings'  => array( __( 'Settings', 'optipress' ), 'dashicons-admin-generic' ),
			'optipress-system'    => array( __( 'System & Integrations', 'optipress' ), 'dashicons-info' ),
		);
		$html = '<nav class="op-nav">';
		foreach ( $items as $slug => $item ) {
			$cls   = ( $current === $slug ) ? 'op-nav__item is-active' : 'op-nav__item';
			$html .= '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" class="' . $cls . '">';
			$html .= '<span class="dashicons ' . esc_attr( $item[1] ) . '"></span>' . esc_html( $item[0] ) . '</a>';
		}
		$html .= '</nav>';
		return $html;
	}

	private function badge_html( $status ) {
		$map = array(
			'optimized'  => __( 'Optimized', 'optipress' ),
			'pending'    => __( 'Pending', 'optipress' ),
			'failed'     => __( 'Failed', 'optipress' ),
			'skipped'    => __( 'Skipped', 'optipress' ),
			'processing' => __( 'Processing', 'optipress' ),
			'done'       => __( 'Done', 'optipress' ),
			'none'       => '—',
			'na'         => __( 'N/A', 'optipress' ),
		);
		$label = isset( $map[ $status ] ) ? $map[ $status ] : $status;
		return '<span class="op-pill op-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	private function stat_card( $label, $value, $sub = '', $accent = false, $extra_attr = '' ) {
		$val_cls = 'op-stat__value' . ( $accent ? ' op-stat__value--accent' : '' );
		$html    = '<div class="op-card op-stat">';
		$html   .= '<div class="op-stat__label">' . esc_html( $label ) . '</div>';
		$html   .= '<div class="' . $val_cls . '" ' . $extra_attr . '>' . esc_html( $value ) . '</div>';
		if ( '' !== $sub ) {
			$html .= '<div class="op-stat__sub">' . $sub . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	private function kv_row( $label, $value, $danger = false ) {
		$cls = $danger ? ' class="op-danger"' : '';
		return '<li><span>' . esc_html( $label ) . '</span><b' . $cls . '>' . esc_html( $value ) . '</b></li>';
	}

	private function empty_state_html( $icon, $title, $body ) {
		$html  = '<div class="op-empty">';
		$html .= '<span class="dashicons ' . esc_attr( $icon ) . '"></span>';
		$html .= '<h3>' . esc_html( $title ) . '</h3>';
		$html .= '<p>' . esc_html( $body ) . '</p>';
		$html .= '</div>';
		return $html;
	}

	private function toggle_html( $name, $label, $desc, $checked ) {
		$html  = '<label class="op-toggle-row">';
		$html .= '<span class="op-toggle"><input type="checkbox" name="' . esc_attr( $name ) . '"' . ( $checked ? ' checked' : '' ) . '><span class="op-toggle__track"></span></span>';
		$html .= '<span class="op-toggle-row__text"><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $desc ) . '</small></span>';
		$html .= '</label>';
		return $html;
	}

	/* ---------------- Dashboard ---------------- */

	public function render_dashboard() {
		$this->shell( 'optipress', __( 'Dashboard', 'optipress' ), __( 'Your image library at a glance.', 'optipress' ), array( $this, 'dashboard_content' ) );
	}

	private function dashboard_content() {
		$c = $this->p->stats->counters();

		$cards  = '<div class="op-cards op-cards--4" id="op-dash-cards">';
		$cards .= $this->stat_card(
			__( 'Images Optimized', 'optipress' ),
			number_format_i18n( $c['optimized'] ),
			esc_html( sprintf( __( 'of %s total', 'optipress' ), number_format_i18n( $c['total'] ) ) ),
			false,
			'data-counter="optimized"'
		);
		$cards .= $this->stat_card(
			__( 'Space Saved', 'optipress' ),
			size_format( $c['saved'] ),
			esc_html( sprintf( __( 'from %1$s down to %2$s', 'optipress' ), size_format( $c['orig'] ), size_format( $c['current'] ) ) ),
			true,
			'data-counter="saved"'
		);
		$cards .= $this->stat_card(
			__( 'Average Savings', 'optipress' ),
			number_format_i18n( $c['avg_pct'], 1 ) . '%',
			esc_html__( 'across optimized images', 'optipress' ),
			false,
			'data-counter="avg"'
		);
		$cards .= $this->stat_card(
			__( 'Images Converted', 'optipress' ),
			number_format_i18n( $c['converted'] ),
			esc_html( sprintf( __( '%1$s WebP / %2$s AVIF', 'optipress' ), number_format_i18n( $c['webp_done'] ), number_format_i18n( $c['avif_done'] ) ) ),
			false,
			'data-counter="converted"'
		);
		$cards .= '</div>';
		echo $cards; // phpcs:ignore

		// Library coverage card.
		$pct       = number_format_i18n( $c['library_pct'], 1 );
		$coverage  = '<div class="op-card"><div class="op-card__head"><h2>' . esc_html__( 'Library Coverage', 'optipress' ) . '</h2></div>';
		$coverage .= '<div class="op-coverage">';
		$coverage .= '<div class="op-ring" id="op-dash-ring" data-pct="' . esc_attr( round( $c['library_pct'], 1 ) ) . '">';
		$coverage .= '<svg viewBox="0 0 120 120"><circle class="op-ring__bg" cx="60" cy="60" r="52"/><circle class="op-ring__fg" cx="60" cy="60" r="52"/></svg>';
		$coverage .= '<div class="op-ring__label"><strong>' . esc_html( $pct ) . '%</strong><span>' . esc_html__( 'complete', 'optipress' ) . '</span></div>';
		$coverage .= '</div>';
		$coverage .= '<ul class="op-kv">';
		$coverage .= $this->kv_row( __( 'Total images', 'optipress' ), number_format_i18n( $c['total'] ) );
		$coverage .= $this->kv_row( __( 'Pending', 'optipress' ), number_format_i18n( $c['pending'] ) );
		$coverage .= $this->kv_row( __( 'Failed', 'optipress' ), number_format_i18n( $c['failed'] ), $c['failed'] > 0 );
		$coverage .= $this->kv_row( __( 'Skipped', 'optipress' ), number_format_i18n( $c['skipped'] ) );
		$coverage .= $this->kv_row( __( 'Success rate', 'optipress' ), number_format_i18n( $c['success_rate'], 1 ) . '%' );
		$coverage .= $this->kv_row( __( 'WebP storage used', 'optipress' ), size_format( $c['webp_bytes'] ) );
		$coverage .= $this->kv_row( __( 'AVIF storage used', 'optipress' ), size_format( $c['avif_bytes'] ) );
		$coverage .= '</ul>';
		$coverage .= '<div class="op-coverage__cta">';
		if ( $c['pending'] > 0 || $c['failed'] > 0 ) {
			$label     = $c['pending'] > 0 ? __( 'Optimize Remaining Images', 'optipress' ) : __( 'Retry Failed Images', 'optipress' );
			$coverage .= '<a class="op-btn op-btn--primary" href="' . esc_url( admin_url( 'admin.php?page=optipress-bulk' ) ) . '">' . esc_html( $label ) . '</a>';
		} else {
			$coverage .= '<span class="op-pill op-pill--optimized">' . esc_html__( 'All images processed', 'optipress' ) . '</span>';
		}
		$coverage .= '</div></div></div>';

		// Recent activity card.
		$logs     = $this->p->db->page_logs( array( 'per_page' => 8 ) );
		$activity = '<div class="op-card"><div class="op-card__head"><h2>' . esc_html__( 'Recent Activity', 'optipress' ) . '</h2>';
		$activity .= '<a class="op-link" href="' . esc_url( admin_url( 'admin.php?page=optipress-logs' ) ) . '">' . esc_html__( 'View all', 'optipress' ) . '</a></div>';
		$activity .= '<div id="op-activity" class="op-activity">';
		if ( empty( $logs['rows'] ) ) {
			$activity .= $this->empty_state_html(
				'dashicons-clock',
				__( 'No activity yet', 'optipress' ),
				__( 'Operations like optimizations, conversions and restores will appear here as they happen.', 'optipress' )
			);
		} else {
			$activity .= '<ul>';
			foreach ( $logs['rows'] as $l ) {
				$file_label = $l->file ? $l->file : ucfirst( $l->op );
				$activity  .= '<li class="op-activity__row">';
				$activity  .= '<span class="op-dot op-dot--' . esc_attr( $l->level ) . '"></span>';
				$activity  .= '<div class="op-activity__body"><strong>' . esc_html( $file_label ) . '</strong>';
				$activity  .= '<span class="op-activity__msg">' . esc_html( wp_trim_words( $l->message, 14 ) ) . '</span></div>';
				$activity  .= '<time>' . esc_html( mysql2date( 'M j, H:i', $l->created_at ) ) . '</time></li>';
			}
			$activity .= '</ul>';
		}
		$activity .= '</div></div>';

		echo '<div class="op-grid op-grid--2-1">' . $coverage . $activity . '</div>'; // phpcs:ignore

		// Savings chart card.
			$analytics = $this->p->stats->analytics( '30' );
			$chart     = '<div class="op-card"><div class="op-card__head"><h2>' . esc_html__( 'Savings — Last 30 Days', 'optipress' ) . '</h2>';
			$chart    .= '<span class="op-muted">' . esc_html( sprintf( __( '%s saved in this period', 'optipress' ), size_format( $analytics['totals']['saved'] ) ) ) . '</span></div>';
		if ( empty( $analytics['series'] ) ) {
			$chart .= $this->empty_state_html(
				'dashicons-chart-area',
				__( 'No analytics data yet', 'optipress' ),
				__( 'Once you optimize images, your daily savings trend will be charted here from real operation data.', 'optipress' )
			);
		} else {
			$chart .= '<div class="op-chart" id="op-dash-chart"></div>';
			$chart .= '<script type="application/json" id="op-dash-chart-data">' . wp_json_encode( $analytics['series'] ) . '</script>';
		}
		$chart .= '</div>';
		echo $chart; // phpcs:ignore
	}

	/* ---------------- Bulk ---------------- */

	public function render_bulk() {
		$this->shell( 'optipress-bulk', __( 'Bulk Optimize', 'optipress' ), __( 'Process your whole library safely in small batches.', 'optipress' ), array( $this, 'bulk_content' ) );
	}

	private function bulk_content() {
		$c    = $this->p->stats->counters();
		$caps = $this->p->env->capabilities();

		if ( 'none' === $caps['engine'] ) {
			echo '<div class="op-alert op-alert--error"><strong>' . esc_html__( 'No image engine available.', 'optipress' ) . '</strong> ';
			echo esc_html__( 'Your server has neither Imagick nor GD. Optimization cannot run until one is enabled — see the System page.', 'optipress' ) . '</div>';
		}

		// Summary cards.
		echo '<div class="op-cards op-cards--4">';
		echo $this->stat_card( __( 'Total Images', 'optipress' ), number_format_i18n( $c['total'] ), esc_html__( 'in your Media Library', 'optipress' ), false, 'data-bstat="total"' );
		echo $this->stat_card( __( 'Optimized', 'optipress' ), number_format_i18n( $c['optimized'] ), esc_html( sprintf( __( '%s%% of library processed', 'optipress' ), number_format_i18n( $c['library_pct'], 1 ) ) ), false, 'data-bstat="optimized"' );
		echo $this->stat_card( __( 'Needs Attention', 'optipress' ), number_format_i18n( $c['pending'] + $c['failed'] ), esc_html( sprintf( __( '%1$s pending · %2$s failed', 'optipress' ), number_format_i18n( $c['pending'] ), number_format_i18n( $c['failed'] ) ) ), false, 'data-bstat="attention"' );
		echo $this->stat_card( __( 'Space Saved', 'optipress' ), size_format( $c['saved'] ), esc_html( sprintf( __( 'avg %s%% per image', 'optipress' ), number_format_i18n( $c['avg_pct'], 1 ) ) ), true, 'data-bstat="saved"' );
		echo '</div>';

		$q_opt  = $this->p->db->count_candidates( 'optimize' );
		$q_ret  = $this->p->db->count_candidates( 'retry_failed' );
		$q_webp = $this->p->db->count_candidates( 'webp' );
		$q_avif = $this->p->db->count_candidates( 'avif' );

		echo '<div class="op-grid op-grid--2-1">';

		// Runner card.
		echo '<div class="op-card op-bulk" id="op-bulk" data-view="bulk">';
		echo '<div class="op-card__head"><h2>' . esc_html__( 'Bulk Optimization', 'optipress' ) . '</h2>';
		echo '<span class="op-muted" id="op-bulk-headnote">' . esc_html( sprintf( __( '%s images total', 'optipress' ), number_format_i18n( $c['total'] ) ) ) . '</span></div>';

		$caught_hidden = ( ( $q_opt + $q_ret ) > 0 ) ? ' hidden' : '';
		if ( true ) {
			echo '<div class="op-empty op-empty--sm" id="op-bulk-caughtup"' . $caught_hidden . '><span class="dashicons dashicons-yes-alt"></span>';
			echo '<h3>' . esc_html__( 'All caught up!', 'optipress' ) . '</h3>';
			echo '<p>' . esc_html__( 'Every eligible image has been optimized. New uploads are processed automatically — or start a conversion run below.', 'optipress' ) . '</p></div>';
		}

		echo '<div class="op-queue">';
		echo '<div class="op-queue__row"><span>' . esc_html__( 'Optimization queue', 'optipress' ) . '</span><b id="op-q-optimize">' . number_format_i18n( $q_opt ) . '</b></div>';
		echo '<div class="op-queue__row"><span>' . esc_html__( 'Failed retries', 'optipress' ) . '</span><b id="op-q-retry" class="' . ( $q_ret ? 'op-danger' : '' ) . '">' . number_format_i18n( $q_ret ) . '</b></div>';
		echo '<div class="op-queue__row"><span>' . esc_html__( 'WebP conversions pending', 'optipress' ) . '</span><b id="op-q-webp">' . number_format_i18n( $q_webp ) . '</b></div>';
		echo '<div class="op-queue__row"><span>' . esc_html__( 'AVIF conversions pending', 'optipress' ) . '</span><b id="op-q-avif">' . number_format_i18n( $q_avif ) . '</b></div>';
		echo '</div>';

		echo '<div class="op-bulk__actions">';
		echo '<button class="op-btn op-btn--ghost" data-bulk-scan>' . esc_html__( 'Scan Library', 'optipress' ) . '</button>';
		echo '<button class="op-btn op-btn--ghost" data-bulk-mode="retry_failed"' . ( $q_ret < 1 ? ' disabled' : '' ) . '>' . esc_html__( 'Retry Failed', 'optipress' ) . '</button>';
		if ( $caps['webp_encode'] ) {
			echo '<button class="op-btn op-btn--ghost" data-bulk-mode="webp"' . ( $q_webp < 1 ? ' disabled' : '' ) . '>' . esc_html__( 'Convert to WebP', 'optipress' ) . '</button>';
		}
		if ( $caps['avif_encode'] ) {
			echo '<button class="op-btn op-btn--ghost" data-bulk-mode="avif"' . ( $q_avif < 1 ? ' disabled' : '' ) . '>' . esc_html__( 'Convert to AVIF', 'optipress' ) . '</button>';
		} else {
			echo '<p class="op-muted op-bulk__avif-note">' . esc_html( $caps['avif_reason'] ) . '</p>';
		}
		$start_disabled = ( $q_opt < 1 ) ? ' disabled' : '';
		echo '<button class="op-btn op-btn--primary op-btn--lg" data-bulk-mode="optimize"' . $start_disabled . '>' . esc_html__( 'Start Optimization', 'optipress' ) . '</button>';
		echo '</div>';

		echo '<div class="op-bulk__progress" id="op-bulk-progress" hidden>';
		echo '<div class="op-bulk__stats">';
		echo '<div class="op-bulk__big"><span id="op-bulk-pct">0%</span><small id="op-bulk-fraction">0 / 0</small></div>';
		echo '<ul class="op-kv op-kv--grid">';
		echo '<li><span>' . esc_html__( 'Current image', 'optipress' ) . '</span><b id="op-bulk-current" class="op-ellipsis">—</b></li>';
		echo '<li><span>' . esc_html__( 'Optimized', 'optipress' ) . '</span><b id="op-bulk-done">0</b></li>';
		echo '<li><span>' . esc_html__( 'Remaining', 'optipress' ) . '</span><b id="op-bulk-remaining">0</b></li>';
		echo '<li><span>' . esc_html__( 'Failed', 'optipress' ) . '</span><b id="op-bulk-failed">0</b></li>';
		echo '<li><span>' . esc_html__( 'Space saved', 'optipress' ) . '</span><b id="op-bulk-saved">0 B</b></li>';
		echo '</ul></div>';
		echo '<div class="op-progress"><div class="op-progress__bar" id="op-bulk-bar" style="width:0%"></div></div>';
		echo '<div class="op-bulk__controls">';
		echo '<span class="op-spinner" id="op-bulk-spinner"></span>';
		echo '<span id="op-bulk-status">' . esc_html__( 'Optimizing images…', 'optipress' ) . '</span>';
		echo '<button class="op-btn op-btn--ghost" id="op-bulk-cancel">' . esc_html__( 'Cancel', 'optipress' ) . '</button>';
		echo '</div></div>';

		echo '<div id="op-bulk-log" class="op-bulk__log" hidden></div>';
		echo '</div>';

		// Recent bulk runs card.
		echo '<div class="op-card"><div class="op-card__head"><h2>' . esc_html__( 'Recent Bulk Runs', 'optipress' ) . '</h2>';
		echo '<a class="op-link" href="' . esc_url( admin_url( 'admin.php?page=optipress-logs' ) ) . '">' . esc_html__( 'All logs', 'optipress' ) . '</a></div>';

		global $wpdb;
		$runs = $wpdb->get_results( $wpdb->prepare(
			"SELECT created_at, level, message FROM {$this->p->db->logs} WHERE op = 'bulk' ORDER BY id DESC LIMIT %d", 6
		) );
		if ( empty( $runs ) ) {
			echo '<div class="op-empty op-empty--sm"><span class="dashicons dashicons-clock"></span><h3>' . esc_html__( 'No bulk runs yet', 'optipress' ) . '</h3><p>' . esc_html__( 'Start your first bulk optimization and the history will appear here.', 'optipress' ) . '</p></div>';
		} else {
			echo '<ul class="op-kv">';
			foreach ( $runs as $run ) {
				echo '<li><span>' . esc_html( mysql2date( 'M j, H:i', $run->created_at ) ) . '</span><b class="' . ( 'error' === $run->level ? 'op-danger' : '' ) . '">' . esc_html( wp_trim_words( $run->message, 12 ) ) . '</b></li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		echo '</div>';
	}

	/* ---------------- Media ---------------- */

	public function render_media() {
		$this->shell( 'optipress-media', __( 'Media Library', 'optipress' ), __( 'Inspect, optimize, convert and restore individual images.', 'optipress' ), array( $this, 'media_content' ) );
	}

	private function media_content() {
		echo '<div class="op-card">';
		echo '<div class="op-toolbar">';
		echo '<input type="search" id="op-media-q" class="op-input" placeholder="' . esc_attr__( 'Search by filename…', 'optipress' ) . '">';

		echo '<select id="op-media-status" class="op-input">';
		echo '<option value="all">' . esc_html__( 'All statuses', 'optipress' ) . '</option>';
		echo '<option value="pending">' . esc_html__( 'Pending', 'optipress' ) . '</option>';
		echo '<option value="optimized">' . esc_html__( 'Optimized', 'optipress' ) . '</option>';
		echo '<option value="failed">' . esc_html__( 'Failed', 'optipress' ) . '</option>';
		echo '<option value="skipped">' . esc_html__( 'Skipped', 'optipress' ) . '</option>';
		echo '</select>';

		echo '<select id="op-media-webp" class="op-input">';
		echo '<option value="all">' . esc_html__( 'WebP: any', 'optipress' ) . '</option>';
		echo '<option value="done">' . esc_html__( 'WebP: done', 'optipress' ) . '</option>';
		echo '<option value="none">' . esc_html__( 'WebP: none', 'optipress' ) . '</option>';
		echo '<option value="failed">' . esc_html__( 'WebP: failed', 'optipress' ) . '</option>';
		echo '</select>';

		echo '<select id="op-media-type" class="op-input">';
		echo '<option value="all">' . esc_html__( 'All types', 'optipress' ) . '</option>';
		echo '<option value="image/jpeg">JPEG</option>';
		echo '<option value="image/png">PNG</option>';
		echo '<option value="image/gif">GIF</option>';
		echo '<option value="image/webp">WebP</option>';
		echo '</select>';

		echo '<select id="op-media-perpage" class="op-input" aria-label="' . esc_attr__( 'Items per page', 'optipress' ) . '">';
		echo '<option value="25">25 / page</option>';
		echo '<option value="50">50 / page</option>';
		echo '<option value="100">100 / page</option>';
		echo '</select>';

		echo '</div>';

		echo '<div class="op-bulkbar" id="op-media-bulkbar" hidden>';
		echo '<span id="op-media-selcount">0 selected</span>';
		echo '<button class="op-btn op-btn--sm op-btn--primary" data-media-bulk="optimize">' . esc_html__( 'Optimize Selected', 'optipress' ) . '</button>';
		echo '<button class="op-btn op-btn--sm op-btn--ghost" data-media-bulk="webp">' . esc_html__( 'Generate WebP', 'optipress' ) . '</button>';
		echo '<button class="op-btn op-btn--sm op-btn--ghost" data-media-bulk="avif">' . esc_html__( 'Generate AVIF', 'optipress' ) . '</button>';
		echo '<button class="op-btn op-btn--sm op-btn--ghost" data-media-bulk="restore">' . esc_html__( 'Restore', 'optipress' ) . '</button>';
		echo '<span class="op-toolbar__spacer"></span>';
		echo '<button class="op-btn op-btn--sm op-btn--ghost" data-media-bulk="clear">' . esc_html__( 'Clear Selection', 'optipress' ) . '</button>';
		echo '</div>';

		echo '<div id="op-media-table" class="op-table-wrap" data-view="media"><div class="op-skeleton"><div></div><div></div><div></div><div></div><div></div></div></div>';
		echo '<div class="op-pager" id="op-media-pager"></div>';
		echo '</div>';
	}

	/* ---------------- Analytics ---------------- */

	public function render_analytics() {
		$this->shell( 'optipress-analytics', __( 'Analytics', 'optipress' ), __( 'Real historical data from completed operations.', 'optipress' ), array( $this, 'analytics_content' ) );
	}

	private function analytics_content() {
		echo '<div class="op-card" data-view="analytics">';
		echo '<div class="op-toolbar"><div class="op-seg" id="op-range">';
		echo '<button data-range="today">' . esc_html__( 'Today', 'optipress' ) . '</button>';
		echo '<button data-range="7">' . esc_html__( '7 days', 'optipress' ) . '</button>';
		echo '<button data-range="30" class="is-active">' . esc_html__( '30 days', 'optipress' ) . '</button>';
		echo '<button data-range="90">' . esc_html__( '90 days', 'optipress' ) . '</button>';
		echo '<button data-range="365">' . esc_html__( '1 year', 'optipress' ) . '</button>';
		echo '<button data-range="all">' . esc_html__( 'All time', 'optipress' ) . '</button>';
		echo '</div></div>';

		echo '<div class="op-cards op-cards--4" id="op-an-totals">';
		echo $this->stat_card( __( 'Images Optimized', 'optipress' ), '—', '', false, 'data-an="optimized"' ); // phpcs:ignore
		echo $this->stat_card( __( 'Space Saved', 'optipress' ), '—', '', true, 'data-an="saved"' ); // phpcs:ignore
		echo $this->stat_card( __( 'WebP / AVIF Conversions', 'optipress' ), '—', '', false, 'data-an="converted"' ); // phpcs:ignore
		echo $this->stat_card( __( 'Success Rate', 'optipress' ), '—', '', false, 'data-an="success"' ); // phpcs:ignore
		echo '</div>';

		echo '<div id="op-an-body"><div class="op-skeleton"><div></div><div></div><div></div></div></div>';
		echo '</div>';
	}

	/* ---------------- Logs ---------------- */

	public function render_logs() {
		$this->shell( 'optipress-logs', __( 'Logs', 'optipress' ), __( 'Every operation is recorded here with full context.', 'optipress' ), array( $this, 'logs_content' ) );
	}

	private function logs_content() {
		echo '<div class="op-card">';
		echo '<div class="op-toolbar">';
		echo '<input type="search" id="op-logs-q" class="op-input" placeholder="' . esc_attr__( 'Search logs…', 'optipress' ) . '">';

		echo '<select id="op-logs-level" class="op-input">';
		echo '<option value="all">' . esc_html__( 'All levels', 'optipress' ) . '</option>';
		echo '<option value="success">' . esc_html__( 'Success', 'optipress' ) . '</option>';
		echo '<option value="info">' . esc_html__( 'Info', 'optipress' ) . '</option>';
		echo '<option value="warning">' . esc_html__( 'Warning', 'optipress' ) . '</option>';
		echo '<option value="error">' . esc_html__( 'Error', 'optipress' ) . '</option>';
		echo '</select>';

		echo '<select id="op-logs-op" class="op-input">';
		echo '<option value="all">' . esc_html__( 'All operations', 'optipress' ) . '</option>';
		echo '<option value="optimize">' . esc_html__( 'Optimize', 'optipress' ) . '</option>';
		echo '<option value="webp">WebP</option>';
		echo '<option value="avif">AVIF</option>';
		echo '<option value="bulk">' . esc_html__( 'Bulk', 'optipress' ) . '</option>';
		echo '<option value="restore">' . esc_html__( 'Restore', 'optipress' ) . '</option>';
		echo '<option value="system">' . esc_html__( 'System', 'optipress' ) . '</option>';
		echo '</select>';

		echo '<input type="date" id="op-logs-from" class="op-input" title="' . esc_attr__( 'From date', 'optipress' ) . '">';
		echo '<input type="date" id="op-logs-to" class="op-input" title="' . esc_attr__( 'To date', 'optipress' ) . '">';
		echo '<span class="op-toolbar__spacer"></span>';
		echo '<button class="op-btn op-btn--danger-ghost" id="op-logs-clear">' . esc_html__( 'Clear Logs', 'optipress' ) . '</button>';
		echo '</div>';

		echo '<div id="op-logs-table" class="op-table-wrap" data-view="logs"><div class="op-skeleton"><div></div><div></div><div></div><div></div></div></div>';
		echo '<div class="op-pager" id="op-logs-pager"></div>';
		echo '</div>';
	}

	/* ---------------- Settings ---------------- */

	public function render_settings() {
		$this->shell( 'optipress-settings', __( 'Settings', 'optipress' ), __( 'Organized by area. Changes apply after saving.', 'optipress' ), array( $this, 'settings_content' ) );
	}

	private function settings_content() {
		$s    = $this->p->settings->all();
		$caps = $this->p->env->capabilities();

		echo '<form id="op-settings" data-view="settings">';
		echo '<div class="op-grid op-grid--2">';

		// General.
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'General', 'optipress' ) . '</h2>';
		echo $this->toggle_html( 'auto_optimize', __( 'Automatically optimize new uploads', 'optipress' ), __( 'Images are optimized shortly after upload (via WP-Cron), so uploads stay fast.', 'optipress' ), ! empty( $s['auto_optimize'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'backup_originals', __( 'Backup original files', 'optipress' ), __( 'Originals are copied before the first optimization so you can restore them at any time. Backups use additional disk space.', 'optipress' ), ! empty( $s['backup_originals'] ) ); // phpcs:ignore
		echo '<div class="op-field"><label for="op-set-maxfile"><strong>' . esc_html__( 'Skip files larger than (MB)', 'optipress' ) . '</strong>';
		echo '<small>' . esc_html__( 'Very large images are the most likely to hit server memory limits.', 'optipress' ) . '</small></label>';
		echo '<input id="op-set-maxfile" type="number" min="1" max="512" class="op-input op-input--sm" name="max_file_mb" value="' . esc_attr( $s['max_file_mb'] ) . '"></div>';
		echo '</div>';

		// Compression.
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Compression', 'optipress' ) . '</h2>';
		echo '<div class="op-field"><label><strong>' . esc_html__( 'Mode', 'optipress' ) . '</strong></label>';
		echo '<select class="op-input" name="compression_mode">';
		$modes = array(
			'lossless' => __( 'Lossless — maximum quality, smaller savings', 'optipress' ),
			'balanced' => __( 'Balanced — recommended for most sites', 'optipress' ),
			'lossy'    => __( 'Lossy — maximum savings, visible quality reduction on large photos', 'optipress' ),
		);
		foreach ( $modes as $mval => $mlabel ) {
			$sel = selected( $s['compression_mode'], $mval, false );
			echo '<option value="' . esc_attr( $mval ) . '"' . $sel . '>' . esc_html( $mlabel ) . '</option>'; // phpcs:ignore
		}
		echo '</select></div>';
		echo '<div class="op-field"><label for="op-set-quality"><strong>' . esc_html__( 'JPEG/WebP quality', 'optipress' ) . ' <span id="op-quality-out">' . esc_html( $s['quality'] ) . '</span></strong></label>';
		echo '<input id="op-set-quality" type="range" min="50" max="95" name="quality" value="' . esc_attr( $s['quality'] ) . '"></div>';
		echo $this->toggle_html( 'strip_meta', __( 'Strip EXIF & metadata', 'optipress' ), __( 'Removes camera info, GPS data and color profiles for extra savings.', 'optipress' ), ! empty( $s['strip_meta'] ) ); // phpcs:ignore
		echo '</div>';

		// WebP.
		echo '<div class="op-card"><h2 class="op-card__title">WebP</h2>';
		if ( ! $caps['webp_encode'] ) {
			echo '<div class="op-alert op-alert--warning">' . esc_html( $caps['avif_reason'] ) . '</div>';
		}
		echo $this->toggle_html( 'webp_enabled', __( 'Enable WebP generation', 'optipress' ), __( 'Creates WebP copies of JPEG/PNG/GIF images.', 'optipress' ), ! empty( $s['webp_enabled'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'webp_auto', __( 'Generate automatically during optimization', 'optipress' ), __( 'Applies to both new uploads and bulk runs.', 'optipress' ), ! empty( $s['webp_auto'] ) ); // phpcs:ignore
		echo '<div class="op-field"><label for="op-set-webpq"><strong>' . esc_html__( 'WebP quality', 'optipress' ) . ' <span id="op-webpq-out">' . esc_html( $s['webp_quality'] ) . '</span></strong></label>';
		echo '<input id="op-set-webpq" type="range" min="30" max="100" name="webp_quality" value="' . esc_attr( $s['webp_quality'] ) . '"></div>';
		echo '</div>';

		// AVIF.
		echo '<div class="op-card"><h2 class="op-card__title">AVIF</h2>';
		if ( ! $caps['avif_encode'] ) {
			echo '<div class="op-alert op-alert--warning">' . esc_html( $caps['avif_reason'] ) . '</div>';
		}
		echo $this->toggle_html( 'avif_enabled', __( 'Enable AVIF generation', 'optipress' ), __( 'Requires server support (Imagick with libavif, or PHP 8.1+ GD with libavif).', 'optipress' ), ! empty( $s['avif_enabled'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'avif_auto', __( 'Generate automatically during optimization', 'optipress' ), __( 'AVIF encoding is slower than WebP — bulk runs will take longer.', 'optipress' ), ! empty( $s['avif_auto'] ) ); // phpcs:ignore
		echo '<div class="op-field"><label for="op-set-avifq"><strong>' . esc_html__( 'AVIF quality', 'optipress' ) . ' <span id="op-avifq-out">' . esc_html( $s['avif_quality'] ) . '</span></strong></label>';
		echo '<input id="op-set-avifq" type="range" min="20" max="100" name="avif_quality" value="' . esc_attr( $s['avif_quality'] ) . '"></div>';
		echo '</div>';

		// Auto-Serving.
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Auto-Serving', 'optipress' ) . '</h2>';
		echo $this->toggle_html( 'serve_enabled', __( 'Serve optimized formats automatically', 'optipress' ), __( 'Wraps images in picture tags with AVIF/WebP sources on the frontend. Original markup is kept as the fallback, so nothing can break.', 'optipress' ), ! empty( $s['serve_enabled'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'serve_avif', __( 'Serve AVIF when available', 'optipress' ), __( 'Browsers without AVIF support fall back to WebP, then the original.', 'optipress' ), ! empty( $s['serve_avif'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'serve_webp', __( 'Serve WebP when available', 'optipress' ), __( 'Browsers without WebP support receive the original image.', 'optipress' ), ! empty( $s['serve_webp'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'serve_css_bg', __( 'Also replace CSS background images (inline styles)', 'optipress' ), __( 'Advanced: replaces background-image URLs in inline styles and style blocks when a converted file exists. Off by default.', 'optipress' ), ! empty( $s['serve_css_bg'] ) ); // phpcs:ignore
		echo '</div>';

		// Bulk.
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Bulk Optimization', 'optipress' ) . '</h2>';
		echo '<div class="op-field"><label><strong>' . esc_html__( 'Images per batch', 'optipress' ) . '</strong><small>' . esc_html__( 'Lower this on slow servers.', 'optipress' ) . '</small></label>';
		echo '<input type="number" min="1" max="25" class="op-input op-input--sm" name="batch_size" value="' . esc_attr( $s['batch_size'] ) . '"></div>';
		echo '<div class="op-field"><label><strong>' . esc_html__( 'Time budget per request (seconds)', 'optipress' ) . '</strong>';
		echo '<small>' . esc_html( sprintf( __( 'Your server allows %ss max execution.', 'optipress' ), $caps['max_execution'] ) ) . '</small></label>';
		echo '<input type="number" min="5" max="60" class="op-input op-input--sm" name="time_limit" value="' . esc_attr( $s['time_limit'] ) . '"></div>';
		echo $this->toggle_html( 'auto_retry', __( 'Automatically retry failed images once', 'optipress' ), __( 'After a bulk run, failures are retried a single time (helps with transient memory issues).', 'optipress' ), ! empty( $s['auto_retry'] ) ); // phpcs:ignore
		echo '</div>';

		// Logs.
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Logs', 'optipress' ) . '</h2>';
		echo $this->toggle_html( 'logs_enabled', __( 'Enable logging', 'optipress' ), __( 'Errors are always logged, even when logging is disabled.', 'optipress' ), ! empty( $s['logs_enabled'] ) ); // phpcs:ignore
		echo '<div class="op-field"><label><strong>' . esc_html__( 'Keep logs for (days)', 'optipress' ) . '</strong></label>';
		echo '<input type="number" min="1" max="365" class="op-input op-input--sm" name="log_retention" value="' . esc_attr( $s['log_retention'] ) . '"></div>';
		echo '</div>';

		// Advanced.
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Advanced', 'optipress' ) . '</h2>';
		echo $this->toggle_html( 'purge_caches', __( 'Purge page caches after operations', 'optipress' ), __( 'Uses the official APIs of WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache and Autoptimize when installed.', 'optipress' ), ! empty( $s['purge_caches'] ) ); // phpcs:ignore
		echo $this->toggle_html( 'delete_on_uninstall', __( 'Delete all plugin data on uninstall', 'optipress' ), __( 'Removes statistics, logs and settings. Backup files inside uploads are also removed.', 'optipress' ), ! empty( $s['delete_on_uninstall'] ) ); // phpcs:ignore
		echo '<div class="op-field"><button type="button" class="op-btn op-btn--ghost" id="op-purge-btn">' . esc_html__( 'Purge Page Caches Now', 'optipress' ) . '</button>';
		echo '<small>' . esc_html__( 'If images look unchanged after optimizing, clear your page cache, CDN cache and browser cache. Optimization never fails silently — check Logs for real errors.', 'optipress' ) . '</small></div>';
		echo '</div>';

		echo '</div>'; // end grid

		echo '<div class="op-savebar" id="op-savebar" hidden>';
		echo '<span>' . esc_html__( 'You have unsaved changes.', 'optipress' ) . '</span>';
		echo '<button type="button" class="op-btn op-btn--ghost" id="op-settings-reset">' . esc_html__( 'Discard', 'optipress' ) . '</button>';
		echo '<button type="submit" class="op-btn op-btn--primary" id="op-settings-save">' . esc_html__( 'Save Settings', 'optipress' ) . '</button>';
		echo '</div>';
		echo '</form>';
	}

	/* ---------------- System ---------------- */

	public function render_system() {
		$this->shell( 'optipress-system', __( 'System & Integrations', 'optipress' ), __( 'Server capabilities and ecosystem compatibility.', 'optipress' ), array( $this, 'system_content' ) );
	}

	private function system_content() {
		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Server Compatibility', 'optipress' ) . '</h2>';
		echo '<table class="op-table op-table--system"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'optipress' ) . '</th>';
		echo '<th>' . esc_html__( 'Value', 'optipress' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'optipress' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'optipress' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $this->p->env->report() as $row ) {
			echo '<tr><td><strong>' . esc_html( $row['label'] ) . '</strong></td>';
			echo '<td>' . esc_html( $row['value'] ) . '</td><td>';
			if ( 'ok' === $row['status'] ) {
				echo '<span class="op-pill op-pill--optimized">' . esc_html__( 'Supported', 'optipress' ) . '</span>';
			} elseif ( 'limited' === $row['status'] ) {
				echo '<span class="op-pill op-pill--skipped">' . esc_html__( 'Limited', 'optipress' ) . '</span>';
			} else {
				echo '<span class="op-pill op-pill--failed">' . esc_html__( 'Unavailable', 'optipress' ) . '</span>';
			}
			echo '</td><td class="op-muted">' . esc_html( $row['note'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<div class="op-card"><h2 class="op-card__title">' . esc_html__( 'Integrations', 'optipress' ) . '</h2>';
		echo '<div class="op-integrations">';
		foreach ( $this->p->integrations->detect() as $i ) {
			$cls = $i['active'] ? 'op-integration is-active' : 'op-integration';
			echo '<div class="' . $cls . '"><div class="op-integration__head"><strong>' . esc_html( $i['label'] ) . '</strong>';
			if ( $i['active'] ) {
				echo '<span class="op-pill op-pill--optimized">&#10003; ' . esc_html__( 'Compatible', 'optipress' ) . '</span>';
			} else {
				echo '<span class="op-pill op-pill--skipped">' . esc_html__( 'Not installed', 'optipress' ) . '</span>';
			}
			echo '</div><p>' . esc_html( $i['note'] ) . '</p></div>';
		}
		echo '</div></div>';
	}
}