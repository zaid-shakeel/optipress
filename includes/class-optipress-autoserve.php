<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Auto-serving via <picture> injection. Format selection happens in the browser
 * (via <source type>), which makes the output inherently compatible with full-page
 * caches — no Accept-header negotiation required on cached HTML.
 *
 * Original markup is preserved untouched as the fallback <img>, so dimensions,
 * srcset, sizes, classes, alt text, lazy-loading and builder JS all keep working.
 */
class OptiPress_AutoServe {

	/** @var OptiPress_Plugin */ private $p;
	/** @var array File-existence cache per request. */
	private $exists_cache = array();

	public function __construct( $plugin ) {
		$this->p = $plugin;
	}

	public function maybe_start_buffer() {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) ) {
			return;
		}
		if ( isset( $_GET['sitemap'] ) || isset( $_GET['sitemap-xml'] ) ) { // phpcs:ignore
			return;
		}
		/** Filter to disable serving per-request. */
		if ( ! apply_filters( 'optipress/should_serve', true ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			header( 'Vary: Accept', false );
		}
		ob_start( array( $this, 'transform_html' ) );
	}

	/**
	 * @param string $html
	 * @return string
	 */
	public function transform_html( $html ) {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$serve_webp = $this->p->settings->get( 'serve_webp' ) && $this->p->env->capabilities()['webp_encode'];
		$serve_avif = $this->p->settings->get( 'serve_avif' ) && $this->p->env->capabilities()['avif_encode'];
		if ( ! $serve_webp && ! $serve_avif ) {
			return $html;
		}

		$html = preg_replace_callback(
			'/<img\b[^>]*?\/?>/i',
			function ( $m ) use ( $serve_avif, $serve_webp ) {
				return $this->wrap_img( $m[0], $serve_avif, $serve_webp );
			},
			$html
		);

		// Optional: inline CSS backgrounds (off by default; safe because we only
		// swap URLs whose converted file provably exists).
		if ( $this->p->settings->get( 'serve_css_bg' ) ) {
			$html = preg_replace_callback(
				'/(<style[^>]*>.*?<\/style>|style\s*=\s*"[^"]*")/is',
				array( $this, 'replace_css_urls' ),
				$html
			);
		}

		return $html;
	}

	/**
	 * @param string $img_tag
	 * @return string
	 */
	private function wrap_img( $img_tag, $serve_avif, $serve_webp ) {
		// Never nest pictures.
		if ( false !== strpos( $img_tag, 'data-optipress' ) ) {
			return $img_tag;
		}

		if ( ! preg_match( '/\bsrc\s*=\s*("|\')([^"\']+)\\1/i', $img_tag, $src_m ) ) {
			return $img_tag;
		}
		$src = $src_m[2];
		if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $src ) ) {
			return $img_tag;
		}

		$srcset_attr = '';
		if ( preg_match( '/\bsrcset\s*=\s*("|\')([^"\']+)\\1/i', $img_tag, $ss_m ) ) {
			$srcset_attr = $ss_m[2];
		}

		$sources = '';
		if ( $serve_avif ) {
			$sources .= $this->build_source( $src, $srcset_attr, 'avif' );
		}
		if ( $serve_webp ) {
			$sources .= $this->build_source( $src, $srcset_attr, 'webp' );
		}

		if ( '' === $sources ) {
			return $img_tag;
		}

		$img = preg_replace( '/^<img\b/i', '<img data-optipress="1"', $img_tag, 1 );
		return '<picture class="optipress-picture">' . $sources . $img . '</picture>';
	}

	/**
	 * Build a <source> whose srcset mirrors the original, per-size, including
	 * width descriptors — so responsive selection still picks the right size.
	 *
	 * @return string Empty string when no converted candidate exists.
	 */
	private function build_source( $src, $srcset_attr, $format ) {
		$entries = array();

		if ( $srcset_attr ) {
			foreach ( preg_split( '/\s*,\s*/', trim( $srcset_attr ) ) as $candidate ) {
				if ( '' === $candidate ) { continue; }
				$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
				$url   = $parts[0];
				$desc  = isset( $parts[1] ) ? ' ' . $parts[1] : '';
				$conv  = $this->converted_url( $url, $format );
				if ( $conv ) {
					$entries[] = $conv . $desc;
				}
			}
		} else {
			$conv = $this->converted_url( $src, $format );
			if ( $conv ) {
				$entries[] = $conv;
			}
		}

		if ( empty( $entries ) ) {
			return '';
		}

		return sprintf(
			'<source type="image/%s" srcset="%s">',
			esc_attr( $format ),
			esc_attr( implode( ', ', $entries ) )
		);
	}

	/**
	 * Map an uploads URL to its converted sibling — only if the file exists.
	 * @return string|null
	 */
	private function converted_url( $url, $format ) {
		$url = trim( $url );
		if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $url ) ) {
			return null;
		}
		$path = OptiPress_Plugin::url_to_upload_path( $url );
		if ( ! $path ) {
			return null;
		}
		$converted = preg_replace( '/\.(jpe?g|png|gif)$/i', '', $path ) . '.' . $format;

		if ( ! isset( $this->exists_cache[ $converted ] ) ) {
			$this->exists_cache[ $converted ] = file_exists( $converted );
		}
		if ( ! $this->exists_cache[ $converted ] ) {
			return null; // Fallback stays intact — never break an image.
		}

		return preg_replace( '/\.(jpe?g|png|gif)(\?.*)?$/i', '.' . $format, $url );
	}

	/** @param array $m @return string */
	public function replace_css_urls( $m ) {
		$block = $m[0];
		$webp  = $this->p->settings->get( 'serve_webp' );
		$avif  = $this->p->settings->get( 'serve_avif' );
		$format = $avif && $this->p->env->capabilities()['avif_encode'] ? 'avif' : ( $webp ? 'webp' : null );
		if ( ! $format ) {
			return $block;
		}
		return preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]+\.(jpe?g|png))\\1\s*\)/i',
			function ( $u ) use ( $format ) {
				$conv = $this->converted_url( $u[2], $format );
				return $conv ? 'url(' . $u[1] . $conv . $u[1] . ')' : $u[0];
			},
			$block
		);
	}
}