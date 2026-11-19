<?php
/**
 * Removes the plugin settings. Post meta (per-post AI disclosure choice) and
 * user profile fields are kept: they are editorial data.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'young_seo_settings' );
delete_transient( 'young_seo_llms' );
