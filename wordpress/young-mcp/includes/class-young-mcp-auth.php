<?php
/**
 * Bearer-token authentication, valid ONLY on the MCP endpoint.
 *
 * Each token is bound to one WordPress user: the AI acts with exactly that
 * user's capabilities. Only a SHA-256 hash of the token is stored; the clear
 * token is shown once, at creation time.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_Auth {

	const OPTION = 'young_mcp_tokens';
	const PREFIX = 'ymcp_';

	/** @var array|null Token record that authenticated the current request. */
	private static $current = null;

	/** @var bool A token was presented but did not match any record. */
	private static $rejected = false;

	public static function init() {
		// After core cookie / application-password handlers (priority 10-20).
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_user' ), 30 );
	}

	/**
	 * True when the request targets the MCP route, with pretty permalinks
	 * (/wp-json/young-mcp/v1/mcp) or the fallback (?rest_route=/young-mcp/v1/mcp).
	 */
	public static function is_mcp_request() {
		$needle = '/' . Young_MCP_Server::NS . Young_MCP_Server::ROUTE;

		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$route = untrailingslashit( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore
			if ( $route === $needle ) {
				return true;
			}
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path = untrailingslashit( $path );

		return '' !== $path && substr( $path, -strlen( $needle ) ) === $needle;
	}

	/**
	 * Reads the token from "Authorization: Bearer ..." or, for hosts that strip
	 * the Authorization header, from "X-Young-MCP-Token".
	 */
	public static function presented_token() {
		$headers = array();
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$headers[] = wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					$headers[] = $value;
				}
			}
		}
		foreach ( $headers as $header ) {
			if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', (string) $header, $m ) ) {
				return $m[1];
			}
		}
		if ( ! empty( $_SERVER['HTTP_X_YOUNG_MCP_TOKEN'] ) ) {
			return trim( wp_unslash( $_SERVER['HTTP_X_YOUNG_MCP_TOKEN'] ) ); // phpcs:ignore
		}
		return null;
	}

	public static function determine_user( $user_id ) {
		if ( ! self::is_mcp_request() ) {
			return $user_id;
		}
		$token = self::presented_token();
		if ( null === $token || 0 !== strpos( $token, self::PREFIX ) ) {
			return $user_id;
		}

		$record = self::find( $token );
		$user   = $record ? get_userdata( (int) $record['user_id'] ) : false;
		if ( ! $user ) {
			self::$rejected = true;
			return $user_id;
		}

		self::$current = $record;
		self::touch( $record['id'] );
		return (int) $user->ID;
	}

	public static function current() {
		return self::$current;
	}

	public static function was_rejected() {
		return self::$rejected;
	}

	public static function all() {
		$tokens = get_option( self::OPTION, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	private static function hash( $token ) {
		return hash( 'sha256', $token );
	}

	private static function find( $token ) {
		$hash = self::hash( $token );
		foreach ( self::all() as $record ) {
			if ( isset( $record['hash'] ) && hash_equals( $record['hash'], $hash ) ) {
				return $record;
			}
		}
		return null;
	}

	/** Creates a token and returns it in clear text (the only time it exists in clear). */
	public static function create( $label, $user_id ) {
		$token  = self::PREFIX . bin2hex( random_bytes( 24 ) );
		$id     = bin2hex( random_bytes( 6 ) );
		$tokens = self::all();

		$tokens[ $id ] = array(
			'id'        => $id,
			'label'     => $label,
			'user_id'   => (int) $user_id,
			'hash'      => self::hash( $token ),
			'hint'      => substr( $token, 0, 9 ) . '…' . substr( $token, -4 ),
			'created'   => time(),
			'last_used' => 0,
		);
		update_option( self::OPTION, $tokens, false );
		return $token;
	}

	public static function revoke( $id ) {
		$tokens = self::all();
		unset( $tokens[ $id ] );
		update_option( self::OPTION, $tokens, false );
	}

	/** Records last use, at most once every 5 minutes to avoid a DB write per call. */
	private static function touch( $id ) {
		$tokens = self::all();
		if ( isset( $tokens[ $id ] ) && time() - (int) $tokens[ $id ]['last_used'] > 300 ) {
			$tokens[ $id ]['last_used'] = time();
			update_option( self::OPTION, $tokens, false );
		}
	}
}
