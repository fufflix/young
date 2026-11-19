<?php
/**
 * Plugin Name:       Young MCP
 * Description:       Server MCP (Model Context Protocol) che permette ad assistenti AI autorizzati (Claude Code, Codex) di leggere gli articoli, creare bozze, caricare immagini e ottimizzare la SEO. Accesso solo con token revocabili, legati a un utente WordPress.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Young.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       young-mcp
 */

defined( 'ABSPATH' ) || exit;

define( 'YOUNG_MCP_VERSION', '1.1.0' );
define( 'YOUNG_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-settings.php';
require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-auth.php';
require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-log.php';
require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-seo.php';
require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-tools.php';
require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-server.php';

Young_MCP_Auth::init();
Young_MCP_Server::init();
Young_MCP_SEO::init();

if ( is_admin() ) {
	require_once YOUNG_MCP_DIR . 'includes/class-young-mcp-admin.php';
	Young_MCP_Admin::init();
}
