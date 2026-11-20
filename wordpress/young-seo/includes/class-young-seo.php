<?php
/**
 * Settings storage and shared helpers.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO {

	const OPTION = 'young_seo_settings';

	const DEFAULT_DISCLOSURE = "Questo articolo è stato realizzato con il supporto dell'intelligenza artificiale, che opera secondo protocolli rigorosi di controllo e verifica delle fonti, ed è stato revisionato dalla redazione prima della pubblicazione.";

	const DEFAULT_POLICY = "YOUng è un giornale indipendente: ogni articolo cita le fonti su cui si basa.\nGli articoli realizzati con il supporto dell'intelligenza artificiale sono dichiarati come tali e revisionati dalla redazione prima della pubblicazione.";

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'wp_head', array( __CLASS__, 'remove_theme_description' ), 0 );
	}

	public static function defaults() {
		return array(
			'ai_disclosure'   => true,
			'disclosure_text' => self::DEFAULT_DISCLOSURE,
			'updated_date'    => true,
			'author_box'      => false,
			'domain_fix'      => true,
			'old_hosts'       => 'you-ng.it, www.you-ng.it',
			'llms'            => true,
			'llms_policy'     => self::DEFAULT_POLICY,
			'news_sitemap'    => true,
			'news_name'       => 'YOUng',
			'theme_meta_fix'  => true,
			'news_exclude'    => 'sponsorizzato, pubbliredazionale, partner',
		);
	}

	public static function all() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function enabled( $key ) {
		return ! empty( self::all()[ $key ] );
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		$out   = array();
		foreach ( array( 'ai_disclosure', 'updated_date', 'author_box', 'domain_fix', 'llms', 'news_sitemap', 'theme_meta_fix' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] );
		}
		$out['disclosure_text'] = sanitize_textarea_field( $input['disclosure_text'] ?? '' );
		if ( '' === $out['disclosure_text'] ) {
			$out['disclosure_text'] = self::DEFAULT_DISCLOSURE;
		}
		$out['old_hosts']   = implode( ', ', self::parse_hosts( $input['old_hosts'] ?? '' ) );
		$out['llms_policy'] = sanitize_textarea_field( $input['llms_policy'] ?? '' );
		$out['news_name']   = sanitize_text_field( $input['news_name'] ?? '' );
		$out['news_exclude'] = implode( ', ', array_filter( array_map( 'sanitize_title', preg_split( '/[\s,]+/', (string) ( $input['news_exclude'] ?? '' ) ) ) ) );
		delete_transient( 'young_seo_llms' );
		delete_transient( 'young_seo_news' );
		return $out;
	}

	/** @return string[] lowercase host names, without the current site host. */
	public static function parse_hosts( $value ) {
		$current = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$hosts   = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) $value ) ) as $host ) {
			$host = preg_replace( '#^https?://#', '', $host );
			$host = trim( preg_replace( '#[/:].*$#', '', $host ), '.' );
			if ( '' !== $host && preg_match( '/^[a-z0-9.-]+$/', $host ) && $host !== $current ) {
				$hosts[] = $host;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/** hello-elementor prints its own meta description: drop it when an SEO plugin does the job. */
	public static function remove_theme_description() {
		if ( self::enabled( 'theme_meta_fix' ) && ( defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) ) {
			remove_action( 'wp_head', 'hello_elementor_add_description_meta_tag' );
		}
	}

	public static function register_meta() {
		register_post_meta(
			'post',
			'_young_ai_disclosure',
			array(
				'type'          => 'string',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
				'sanitize_callback' => static function ( $value ) {
					return in_array( (string) $value, array( '0', '1' ), true ) ? (string) $value : '';
				},
			)
		);
	}
}
