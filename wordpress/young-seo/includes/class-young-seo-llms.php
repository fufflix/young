<?php
/**
 * Serves /llms.txt (https://llmstxt.org) with sections and latest articles.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Llms {

	const TRANSIENT = 'young_seo_llms';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_trailing_slash' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'invalidate' ), 10, 3 );
		add_action( 'edited_category', array( __CLASS__, 'flush_cache' ) );
	}

	public static function rewrite() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?young_llms=1', 'top' );
	}

	public static function activate() {
		self::rewrite();
		Young_SEO_News::rewrite();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
		delete_transient( self::TRANSIENT );
		delete_transient( Young_SEO_News::TRANSIENT );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'young_llms';
		return $vars;
	}

	public static function no_trailing_slash( $redirect, $requested ) {
		return get_query_var( 'young_llms' ) ? false : $redirect;
	}

	public static function invalidate( $new_status, $old_status, $post ) {
		if ( 'post' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			self::flush_cache();
		}
	}

	public static function flush_cache() {
		delete_transient( self::TRANSIENT );
	}

	public static function serve() {
		if ( ! get_query_var( 'young_llms' ) ) {
			return;
		}
		if ( ! Young_SEO::enabled( 'llms' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		$text = get_transient( self::TRANSIENT );
		if ( false === $text ) {
			$text = self::build();
			set_transient( self::TRANSIENT, $text, HOUR_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text.
		exit;
	}

	private static function line( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	public static function build() {
		$out   = array();
		$out[] = '# ' . self::line( get_bloginfo( 'name' ) );
		$desc  = self::line( get_bloginfo( 'description' ) );
		if ( '' !== $desc ) {
			$out[] = '';
			$out[] = '> ' . $desc;
		}
		$policy = trim( (string) Young_SEO::get( 'llms_policy' ) );
		if ( '' !== $policy ) {
			$out[] = '';
			$out[] = $policy;
		}

		$cats = get_terms(
			array(
				'taxonomy'   => 'category',
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 10,
				'hide_empty' => true,
			)
		);
		if ( $cats && ! is_wp_error( $cats ) ) {
			$out[] = '';
			$out[] = '## Sezioni';
			$out[] = '';
			foreach ( $cats as $cat ) {
				$out[] = '- [' . self::line( $cat->name ) . '](' . get_term_link( $cat ) . ')';
			}
		}

		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 30,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'has_password'     => false,
				'suppress_filters' => false,
			)
		);
		if ( $posts ) {
			$out[] = '';
			$out[] = '## Ultimi articoli';
			$out[] = '';
			foreach ( $posts as $post ) {
				$summary = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 25, '…' );
				$summary = self::line( $summary );
				$out[]   = '- [' . self::line( get_the_title( $post ) ) . '](' . get_permalink( $post ) . ')' . ( '' !== $summary ? ': ' . $summary : '' );
			}
		}
		return implode( "\n", $out ) . "\n";
	}
}
