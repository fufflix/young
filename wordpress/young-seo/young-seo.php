<?php
/**
 * Plugin Name:       Young SEO
 * Description:       Piccole aggiunte editoriali e SEO per young.it, compatibili con Yoast SEO: nota sull'uso dell'intelligenza artificiale, pulsante "fonte preferita su Google", data di aggiornamento visibile, box autore, correzione del vecchio dominio e /llms.txt.
 * Version:           1.3.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Young.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       young-seo
 */

defined( 'ABSPATH' ) || exit;

define( 'YOUNG_SEO_VERSION', '1.3.0' );
define( 'YOUNG_SEO_DIR', plugin_dir_path( __FILE__ ) );

require_once YOUNG_SEO_DIR . 'includes/class-young-seo.php';
require_once YOUNG_SEO_DIR . 'includes/class-young-seo-content.php';
require_once YOUNG_SEO_DIR . 'includes/class-young-seo-domain.php';
require_once YOUNG_SEO_DIR . 'includes/class-young-seo-llms.php';
require_once YOUNG_SEO_DIR . 'includes/class-young-seo-news.php';

Young_SEO::init();
Young_SEO_Content::init();
Young_SEO_Domain::init();
Young_SEO_Llms::init();
Young_SEO_News::init();

register_activation_hook( __FILE__, array( 'Young_SEO_Llms', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Young_SEO_Llms', 'deactivate' ) );

if ( is_admin() ) {
	require_once YOUNG_SEO_DIR . 'includes/class-young-seo-admin.php';
	Young_SEO_Admin::init();
}
