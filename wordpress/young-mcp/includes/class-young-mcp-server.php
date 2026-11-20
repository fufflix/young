<?php
/**
 * MCP server over the "Streamable HTTP" transport, stateless, JSON responses only
 * (no SSE stream). Implements: initialize, ping, tools/list, tools/call.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_Server {

	const NS    = 'young-mcp/v1';
	const ROUTE = '/mcp';

	/** Newest first: used when the client asks for a version we do not know. */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_empty_accepted' ), 10, 4 );
	}

	public static function endpoint_url() {
		return rest_url( self::NS . self::ROUTE );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_post' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( __CLASS__, 'handle_unsupported' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
	}

	public static function permission() {
		if ( Young_MCP_Auth::current() && is_user_logged_in() ) {
			return true;
		}
		$message = Young_MCP_Auth::was_rejected()
			? 'Token MCP non valido o revocato.'
			: 'Token MCP mancante. Usa l\'header "Authorization: Bearer <token>" (o "X-Young-MCP-Token" se il server elimina Authorization).';
		return new WP_Error( 'young_mcp_unauthorized', $message, array( 'status' => 401 ) );
	}

	/** No server-initiated SSE stream and no sessions to delete: 405 per spec. */
	public static function handle_unsupported() {
		$response = new WP_REST_Response( array( 'error' => 'Method not allowed: usa POST.' ), 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	public static function handle_post( WP_REST_Request $request ) {
		$message = json_decode( $request->get_body(), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $message ) || array() === $message ) {
			return self::respond( self::error( null, -32700, 'Parse error' ), 400 );
		}

		$is_batch  = array_keys( $message ) === range( 0, count( $message ) - 1 );
		$messages  = $is_batch ? $message : array( $message );
		$responses = array();

		foreach ( $messages as $msg ) {
			$reply = self::handle_message( $msg );
			if ( null !== $reply ) {
				$responses[] = $reply;
			}
		}

		if ( ! $responses ) {
			// Only notifications / client responses: 202 Accepted, empty body.
			return self::respond( null, 202 );
		}
		return self::respond( $is_batch ? $responses : $responses[0], 200 );
	}

	private static function respond( $data, $status ) {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/** Lets 202 responses go out with no body (WordPress would print "null"). */
	public static function serve_empty_accepted( $served, $result, $request, $server ) {
		if ( ! $served && 202 === $result->get_status() && '/' . self::NS . self::ROUTE === $request->get_route() ) {
			return true;
		}
		return $served;
	}

	/** Returns a JSON-RPC response array, or null for notifications. */
	private static function handle_message( $msg ) {
		if ( ! is_array( $msg ) || ( $msg['jsonrpc'] ?? '' ) !== '2.0' ) {
			return self::error( null, -32600, 'Invalid Request' );
		}
		$has_id = array_key_exists( 'id', $msg );
		$id     = $has_id ? $msg['id'] : null;

		if ( ! isset( $msg['method'] ) ) {
			// A response from the client (we never send requests): ignore.
			return $has_id && ( isset( $msg['result'] ) || isset( $msg['error'] ) ) ? null : self::error( $id, -32600, 'Invalid Request' );
		}
		if ( ! $has_id ) {
			return null; // Notification (e.g. notifications/initialized).
		}

		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		switch ( $msg['method'] ) {
			case 'initialize':
				return self::result( $id, self::initialize( $params ) );

			case 'ping':
				return self::result( $id, new stdClass() );

			case 'tools/list':
				return self::result( $id, array( 'tools' => Young_MCP_Tools::definitions() ) );

			case 'tools/call':
				$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
				if ( ! Young_MCP_Tools::exists( $name ) ) {
					return self::error( $id, -32602, 'Tool sconosciuto: ' . $name );
				}
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				return self::result( $id, Young_MCP_Tools::call( $name, $args ) );

			default:
				return self::error( $id, -32601, 'Method not found: ' . $msg['method'] );
		}
	}

	private static function initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
			'serverInfo'      => array(
				'name'    => 'young-mcp',
				'title'   => get_bloginfo( 'name' ) . ' (WordPress)',
				'version' => YOUNG_MCP_VERSION,
			),
			'instructions'    => self::instructions(),
		);
	}

	private static function instructions() {
		$lines = array(
			'Server MCP del sito WordPress ' . home_url() . ' (testata giornalistica, tutela dei consumatori).',
			'Regole editoriali:',
			'- Crea sempre BOZZE (status "draft"), salvo richiesta esplicita della redazione.',
			'- Non inventare fatti, cifre, citazioni o fonti: ogni affermazione deve essere verificabile; in caso di dubbio segnalalo nel testo come [DA VERIFICARE].',
			'- Scrivi il contenuto in markup a blocchi Gutenberg (es. <!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->, <!-- wp:heading --><h2 class="wp-block-heading">…</h2><!-- /wp:heading -->). Non usare H1 nel corpo: il titolo è già H1.',
			'- Prima di creare categorie o tag consulta list_terms e riusa quelli esistenti; le categorie inesistenti vengono rifiutate.',
			'- Per la SEO: usa seo_audit, poi update_seo; le modifiche SEO sono registrate nel log con valori prima/dopo.',
			'- Le eliminazioni non sono disponibili: per ritirare un contenuto chiedi alla redazione.',
			'Permessi attuali: pubblicazione ' . ( Young_MCP_Settings::get( 'allow_publish' ) ? 'CONSENTITA' : 'NON consentita' )
				. '; modifica di articoli già pubblicati ' . ( Young_MCP_Settings::get( 'allow_edit_published' ) ? 'CONSENTITA' : 'NON consentita' ) . '.',
		);
		return implode( "\n", $lines );
	}

	private static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
