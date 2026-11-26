<?php
/**
 * Front-end additions to single posts: update date, author box, AI disclosure.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Content {

	/** Yoast's contact method keys, reused so the data feeds its author schema too. */
	const CONTACTS = array(
		'twitter'   => 'X (Twitter) – nome utente o URL',
		'facebook'  => 'Facebook – URL del profilo',
		'linkedin'  => 'LinkedIn – URL del profilo',
		'instagram' => 'Instagram – URL del profilo',
		'youtube'   => 'YouTube – URL del canale',
	);

	private static $done = array();

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 99 );
		add_filter( 'user_contactmethods', array( __CLASS__, 'contact_methods' ), 99 );
		add_action( 'wp_head', array( __CLASS__, 'styles' ) );
	}

	public static function contact_methods( $methods ) {
		foreach ( self::CONTACTS as $key => $label ) {
			if ( ! isset( $methods[ $key ] ) ) {
				$methods[ $key ] = $label;
			}
		}
		return $methods;
	}

	private static function applies() {
		return is_singular( 'post' ) && in_the_loop() && is_main_query() && ! is_feed()
			&& ! doing_filter( 'get_the_excerpt' ) && ! doing_filter( 'wp_trim_excerpt' );
	}

	public static function filter_content( $content ) {
		if ( ! self::applies() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || isset( self::$done[ $post->ID ] ) || false !== strpos( $content, 'young-ai-disclosure' ) ) {
			return $content;
		}
		self::$done[ $post->ID ] = true;

		$before  = Young_SEO::enabled( 'preferred_source' ) ? self::preferred_source() : '';
		$before .= Young_SEO::enabled( 'updated_date' ) ? self::updated_line( $post ) : '';
		$after  = Young_SEO::enabled( 'author_box' ) ? self::author_box( $post ) : '';
		if ( Young_SEO::enabled( 'ai_disclosure' ) && self::needs_disclosure( $post->ID ) ) {
			$after .= '<p class="young-ai-disclosure">' . esc_html( Young_SEO::get( 'disclosure_text' ) ) . '</p>';
		}
		return $before . $content . $after;
	}

	public static function needs_disclosure( $post_id ) {
		$flag = get_post_meta( $post_id, '_young_ai_disclosure', true );
		if ( '0' === $flag ) {
			return false;
		}
		return '1' === $flag || metadata_exists( 'post', $post_id, '_young_mcp_created_by' );
	}

	/**
	 * Pulsante "fonte preferita su Google": link diretto allo strumento di Google
	 * (https://developers.google.com/search/docs/appearance/preferred-sources), senza script esterni.
	 */
	private static function preferred_source() {
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) {
			return '';
		}
		$g = '<svg class="young-pref-g" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true" focusable="false">'
			. '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
			. '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
			. '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
			. '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
			. '</svg>';
		return sprintf(
			'<p class="young-pref-wrap"><a class="young-pref" href="%s" target="_blank" rel="noopener">%s<span>Scegli YOUng come fonte preferita su Google</span></a></p>',
			esc_url( 'https://www.google.com/preferences/source?q=' . rawurlencode( $host ) ),
			$g
		);
	}

	private static function updated_line( WP_Post $post ) {
		$published = get_post_timestamp( $post, 'date' );
		$modified  = get_post_timestamp( $post, 'modified' );
		if ( ! $published || ! $modified || $modified - $published <= DAY_IN_SECONDS ) {
			return '';
		}
		return sprintf(
			'<p class="young-updated"><time datetime="%s">%s</time></p>',
			esc_attr( wp_date( 'c', $modified ) ),
			esc_html( sprintf( 'Aggiornato il %s alle %s', wp_date( 'd/m/Y', $modified ), wp_date( 'H:i', $modified ) ) )
		);
	}

	private static function author_box( WP_Post $post ) {
		$user = get_userdata( $post->post_author );
		if ( ! $user ) {
			return '';
		}
		$links = array();
		if ( $user->user_url ) {
			$links[] = array( 'Sito web', $user->user_url );
		}
		$labels = array( 'twitter' => 'X', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'instagram' => 'Instagram', 'youtube' => 'YouTube' );
		foreach ( $labels as $key => $label ) {
			$value = trim( (string) get_user_meta( $user->ID, $key, true ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'twitter' === $key && ! preg_match( '#^https?://#i', $value ) ) {
				$value = 'https://x.com/' . ltrim( $value, '@' );
			}
			$links[] = array( $label, $value );
		}

		$html  = '<div class="young-author-box">';
		$html .= get_avatar( $user->ID, 80, '', $user->display_name, array( 'class' => 'young-author-avatar' ) );
		$html .= '<div class="young-author-info"><p class="young-author-name"><a href="' . esc_url( get_author_posts_url( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a></p>';
		if ( $user->description ) {
			$html .= '<p class="young-author-bio">' . wp_kses_post( $user->description ) . '</p>';
		}
		if ( $links ) {
			$items = array();
			foreach ( $links as $link ) {
				$items[] = '<a href="' . esc_url( $link[1] ) . '" rel="noopener me" target="_blank">' . esc_html( $link[0] ) . '</a>';
			}
			$html .= '<p class="young-author-links">' . implode( ' · ', $items ) . '</p>';
		}
		return $html . '</div></div>';
	}

	public static function styles() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}
		echo "<style id=\"young-seo-css\">.young-pref-wrap{margin:0 0 1.2em}.young-pref-wrap .young-pref{display:inline-flex;align-items:center;gap:.55em;padding:.45em .9em;border:1px solid #c9ced6;border-radius:10px;font-size:.85em;line-height:1.3;color:#1f3a4d;text-decoration:none;background:transparent}.young-pref-wrap .young-pref:hover,.young-pref-wrap .young-pref:focus-visible{border-color:#1f3a4d;text-decoration:none}.young-pref-wrap .young-pref-g{flex:none;width:18px;height:18px}@media (prefers-color-scheme:dark){.young-pref-wrap .young-pref{color:inherit;border-color:#555}}.young-updated{font-size:.85em;color:#6b6b6b;margin:0 0 1em}.young-ai-disclosure{font-size:.8em;color:#6b6b6b;border-top:1px solid #ddd;padding-top:.8em;margin-top:2em}.young-author-box{display:flex;gap:1em;align-items:flex-start;border-top:1px solid #ddd;padding-top:1em;margin-top:2em}.young-author-avatar{border-radius:50%}.young-author-name{font-weight:700;margin:0 0 .3em}.young-author-bio,.young-author-links{font-size:.9em;margin:0 0 .3em}</style>\n";
	}
}
