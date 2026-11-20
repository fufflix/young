<?php
/**
 * Removes plugin options on uninstall. Posts, media and SEO values are kept;
 * only tokens, settings and the activity log are deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'young_mcp_tokens' );
delete_option( 'young_mcp_settings' );
delete_option( 'young_mcp_log' );
