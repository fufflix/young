<?php
/**
 * Rewrites URLs on the old domain (you-ng.it) to the current host at output
 * time. Nothing is changed in the database; the scan only counts.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Domain {

	public static function init() {
		if ( ! Young_SEO::enabled( 'domain_fix' ) ) {
			return;
		}
		add_filter( 'the_content', array( __CLASS__, 'replace' ), 20 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'replace' ) );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'image_src' ) );
		add_filter( 'wp_calculate_image_srcset', array( __CLASS__, 'srcset' ) );
		foreach ( array( 'wpseo_opengraph_image', 'wpseo_twitter_image', 'wpseo_canonical', 'wpseo_opengraph_url' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'replace' ) );
		}
	}

	public static function replace( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}
		$hosts = Young_SEO::parse_hosts( Young_SEO::get( 'old_hosts' ) );
		if ( ! $hosts ) {
			return $value;
		}
		$current = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme  = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
		$port    = wp_parse_url( home_url(), PHP_URL_PORT );
		$target  = $scheme . '://' . $current . ( $port ? ':' . $port : '' );
		$quoted  = implode( '|', array_map( 'preg_quote', $hosts ) );
		// Absolute (http/https) and protocol-relative URLs, also JSON-escaped (https:\/\/).
		$result = preg_replace_callback(
			'#(https?:)?(\\\\?/\\\\?/)(' . $quoted . ')(?=[/"\'\s?:\\\\<)]|$)#i',
			static function ( $m ) use ( $target ) {
				$t = $target;
				if ( false !== strpos( $m[2], '\\' ) ) {
					$t = str_replace( '/', '\\/', $t );
				}
				return $t;
			},
			$value
		);
		return null === $result ? $value : $result;
	}

	public static function image_src( $image ) {
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$image[0] = self::replace( $image[0] );
		}
		return $image;
	}

	public static function srcset( $sources ) {
		if ( is_array( $sources ) ) {
			foreach ( $sources as $key => $source ) {
				if ( isset( $source['url'] ) ) {
					$sources[ $key ]['url'] = self::replace( $source['url'] );
				}
			}
		}
		return $sources;
	}

	/** Read-only: occurrences (rows) per host and table. */
	public static function scan() {
		global $wpdb;
		$targets = array(
			'wp_posts.post_content'  => array( $wpdb->posts, 'post_content' ),
			'wp_posts.guid'          => array( $wpdb->posts, 'guid' ),
			'wp_postmeta.meta_value' => array( $wpdb->postmeta, 'meta_value' ),
			'wp_options.option_value' => array( $wpdb->options, 'option_value' ),
		);
		$result = array();
		foreach ( Young_SEO::parse_hosts( Young_SEO::get( 'old_hosts' ) ) as $host ) {
			foreach ( $targets as $label => $t ) {
				$like = '%' . $wpdb->esc_like( '//' . $host ) . '%';
				// Table/column names are fixed above, not user input.
				$result[ $host ][ $label ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t[0]} WHERE {$t[1]} LIKE %s", $like ) ); // phpcs:ignore WordPress.DB
			}
		}
		return $result;
	}
}
