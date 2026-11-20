<?php
/**
 * Editorial policy switches. Both default to off: out of the box the AI can
 * only read content and work on drafts; a human publishes.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_Settings {

	const OPTION = 'young_mcp_settings';

	public static function defaults() {
		return array(
			'allow_publish'        => false,
			'allow_edit_published' => false,
		);
	}

	public static function get( $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return ! empty( $settings[ $key ] );
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		return array(
			'allow_publish'        => ! empty( $input['allow_publish'] ),
			'allow_edit_published' => ! empty( $input['allow_edit_published'] ),
		);
	}
}
