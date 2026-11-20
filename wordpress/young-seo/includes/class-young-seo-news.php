<?php
/**
 * Google News sitemap at /news-sitemap.xml: posts of the last 48 hours.
 * Replaces the News module of old Yoast Premium; free Yoast has none.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_News {

	const TRANSIENT = 'young_seo_news';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_redirect' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'invalidate' ), 10, 3 );
		if ( Young_SEO::enabled( 'news_sitemap' ) ) {
			// Late priority: anything else that already adds the news sitemap runs first, then we skip.
			if ( false === has_filter( 'wpseo_sitemap_index', array( __CLASS__, 'yoast_index' ) ) ) {
				add_filter( 'wpseo_sitemap_index', array( __CLASS__, 'yoast_index' ), 99 );
			}
			add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );
		}
	}

	public static function rewrite() {
		add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?young_news_sitemap=1', 'top' );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'young_news_sitemap';
		return $vars;
	}

	public static function no_redirect( $redirect ) {
		return get_query_var( 'young_news_sitemap' ) ? false : $redirect;
	}

	public static function invalidate( $new_status, $old_status, $post ) {
		if ( 'post' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	public static function url() {
		return home_url( '/news-sitemap.xml' );
	}

	private static function posts() {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 1000,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'has_password'     => false,
				'suppress_filters' => false,
				'date_query'       => array(
					array(
						'column' => 'post_date_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
					),
				),
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'OR',
					array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_yoast_wpseo_meta-robots-noindex', 'value' => '1', 'compare' => '!=' ),
				),
			)
		);
		// Sponsored content stays out of Google News: drop posts carrying an excluded category or tag.
		$excluded = array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) Young_SEO::get( 'news_exclude' ) ) ) );
		if ( $excluded ) {
			$posts = array_values(
				array_filter(
					$posts,
					static function ( $post ) use ( $excluded ) {
						return ! has_term( $excluded, 'category', $post ) && ! has_term( $excluded, 'post_tag', $post );
					}
				)
			);
		}
		return $posts;
	}

	public static function build() {
		$name  = trim( (string) Young_SEO::get( 'news_name' ) );
		$name  = '' !== $name ? $name : get_bloginfo( 'name' );
		$xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml  .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";
		foreach ( self::posts() as $post ) {
			$title = html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' );
			$xml  .= "<url>\n\t<loc>" . esc_xml( get_permalink( $post ) ) . "</loc>\n\t<news:news>\n";
			$xml  .= "\t\t<news:publication>\n\t\t\t<news:name>" . esc_xml( $name ) . "</news:name>\n\t\t\t<news:language>it</news:language>\n\t\t</news:publication>\n";
			$xml  .= "\t\t<news:publication_date>" . esc_xml( get_post_time( 'c', true, $post ) ) . "</news:publication_date>\n";
			$xml  .= "\t\t<news:title>" . esc_xml( $title ) . "</news:title>\n\t</news:news>\n</url>\n";
		}
		return $xml . "</urlset>\n";
	}

	public static function serve() {
		if ( ! get_query_var( 'young_news_sitemap' ) ) {
			return;
		}
		if ( ! Young_SEO::enabled( 'news_sitemap' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$xml = get_transient( self::TRANSIENT );
		if ( false === $xml ) {
			$xml = self::build();
			set_transient( self::TRANSIENT, $xml, 10 * MINUTE_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in build().
		exit;
	}

	public static function yoast_index( $index ) {
		// Idempotent: never add a second entry (second pass, old snippet, other plugin).
		if ( false !== strpos( (string) $index, 'news-sitemap.xml' ) ) {
			return $index;
		}
		$latest  = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids' ) );
		$lastmod = $latest ? get_post_time( 'c', true, $latest[0] ) : gmdate( 'c' );
		return $index . "<sitemap>\n\t<loc>" . esc_xml( self::url() ) . "</loc>\n\t<lastmod>" . esc_xml( $lastmod ) . "</lastmod>\n</sitemap>\n";
	}

	public static function robots( $output, $public ) {
		if ( ! $public || defined( 'WPSEO_VERSION' ) ) {
			return $output;
		}
		return rtrim( $output ) . "\nSitemap: " . self::url() . "\n";
	}
}
