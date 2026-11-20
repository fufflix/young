<?php
/**
 * Activity log: who (which token) called which tool, on what, with which outcome.
 * Article bodies are never logged. SEO changes keep before/after values,
 * because post meta has no revisions in WordPress.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_Log {

	const OPTION = 'young_mcp_log';
	const MAX    = 300;

	public static function add( $tool, $ok, $summary ) {
		$token = Young_MCP_Auth::current();
		$user  = wp_get_current_user();
		$log   = self::all();

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'token'   => $token ? $token['label'] : '',
				'user'    => $user ? $user->user_login : '',
				'tool'    => $tool,
				'ok'      => (bool) $ok,
				'summary' => mb_substr( (string) $summary, 0, 1000 ),
			)
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	public static function all() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}
}
