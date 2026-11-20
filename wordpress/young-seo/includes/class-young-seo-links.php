<?php
/**
 * External links in post content: opened in a new tab and marked nofollow, at render time.
 * The database is never modified; internal links (site host and its subdomains) are left follow.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Links {

	public static function init() {
		// After the old-domain fix (20), so rewritten you-ng.it links count as internal.
		add_filter( 'the_content', array( __CLASS__, 'filter' ), 30 );
	}

	public static function filter( $content ) {
		if ( ! Young_SEO::enabled( 'external_links' ) || is_admin() || false === stripos( (string) $content, '<a' )
			|| ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$site       = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$site       = preg_replace( '/^www\./', '', $site );
		$exceptions = Young_SEO::parse_hosts( Young_SEO::get( 'follow_hosts' ) );

		$tags = new WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( 'a' ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( ! is_string( $href ) || ! preg_match( '#^(https?:)?//#i', $href ) ) {
				continue; // Relative, anchor, mailto, tel: internal or not a web link.
			}
			$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $host || self::matches( $host, $site ) ) {
				continue;
			}

			$tags->set_attribute( 'target', '_blank' );
			$rel = preg_split( '/\s+/', strtolower( trim( (string) $tags->get_attribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
			$rel[] = 'noopener';
			if ( ! self::listed( $host, $exceptions ) ) {
				$rel[] = 'nofollow';
			}
			$tags->set_attribute( 'rel', implode( ' ', array_unique( $rel ) ) );
		}
		return $tags->get_updated_html();
	}

	/** True if $host is $domain or one of its subdomains. */
	private static function matches( $host, $domain ) {
		$host = preg_replace( '/^www\./', '', $host );
		return $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain;
	}

	private static function listed( $host, array $domains ) {
		foreach ( $domains as $domain ) {
			if ( self::matches( $host, preg_replace( '/^www\./', '', $domain ) ) ) {
				return true;
			}
		}
		return false;
	}
}
