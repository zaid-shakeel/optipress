<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Detects installed plugins/themes relevant to image workflows and cache purging.
 */
class OptiPress_Integrations {

	/** @return array[] */
	public function detect() {
		$out = array();

		$add = function ( $label, $active, $note_active, $note_inactive = '' ) use ( &$out ) {
			$out[] = array( 'label' => $label, 'active' => (bool) $active, 'note' => $active ? $note_active : $note_inactive );
		};

		$add( 'WordPress', true,
			__( 'Fully compatible. Media Library, responsive srcset and Gutenberg markup are supported.', 'optipress' ) );

		$add( 'WooCommerce', class_exists( 'WooCommerce' ),
			__( 'Detected. Product images, galleries, variations and Woo image sizes are optimized as normal Media Library attachments. Galleries, zoom and lightboxes are unaffected — originals keep their URLs.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'Elementor', defined( 'ELEMENTOR_VERSION' ),
			__( 'Detected. Image widgets, carousels and lazy-loaded markup are handled by the auto-serving layer; Elementor’s rendering is not modified.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'Divi', defined( 'ET_BUILDER_VERSION' ) || function_exists( 'et_setup_builder' ),
			__( 'Detected. Image modules and background images work via auto-serving; Divi’s frontend rendering is untouched.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'Avada', defined( 'AVADA_VERSION' ),
			__( 'Detected. Avada elements are served through the same <picture> layer; theme markup is not rewritten destructively.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'Oxygen', defined( 'CT_VERSION' ),
			__( 'Detected. Oxygen-rendered images are covered by auto-serving; builder output is not interfered with.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'WP Rocket', function_exists( 'rocket_clean_domain' ) || defined( 'WP_ROCKET_VERSION' ),
			__( 'Detected. OptiPress purges WP Rocket automatically after optimization via rocket_clean_domain(). Since format selection is client-side (<picture>), cached HTML stays valid for all browsers.', 'optipress' ),
			__( 'Not installed. Enable “Purge caches” in Settings if you add it later.', 'optipress' ) );

		$add( 'LiteSpeed Cache', defined( 'LSCWP_V' ),
			__( 'Detected. OptiPress triggers litespeed_purge_all after operations and coexists with LiteSpeed’s own caching.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'W3 Total Cache', defined( 'W3TC' ),
			__( 'Detected. Cache flush is triggered via the official w3tc_flush_all action.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'WP Super Cache', defined( 'WPCACHEHOME' ),
			__( 'Detected. OptiPress clears the static cache via wp_cache_clear_cache().', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		$add( 'Autoptimize', defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
			__( 'Detected. Cache purge is triggered via Autoptimize’s official action.', 'optipress' ),
			__( 'Not installed.', 'optipress' ) );

		return $out;
	}
}