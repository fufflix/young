<?php
/**
 * Indice "In questo articolo" (widget Indice di Elementor Pro, classe yg-toc) nella colonna laterale fissa.
 * Negli articoli molto lunghi la colonna è più alta dello schermo e le ultime voci restavano tagliate:
 * qui la colonna prende al massimo l'altezza dello schermo e scorre da sola, solo quando serve.
 * Uno script tiene in vista la voce del capitolo che si sta leggendo.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Toc {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'styles' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'script' ), 60 );
	}

	private static function applies() {
		return is_singular( array( 'post', 'cantera' ) );
	}

	public static function styles() {
		if ( ! self::applies() ) {
			return;
		}
		echo '<style id="young-toc-css">@media (min-width:1025px){.e-con:has(> .yg-toc){max-height:calc(100vh - 124px);overflow-y:auto;overscroll-behavior:contain;scrollbar-width:thin;scrollbar-color:#C9C4BD transparent;padding-right:6px}.e-con:has(> .yg-toc) > *{flex-shrink:0}body.admin-bar .e-con:has(> .yg-toc){max-height:calc(100vh - 156px)}.e-con:has(> .yg-toc)::-webkit-scrollbar{width:6px}.e-con:has(> .yg-toc)::-webkit-scrollbar-thumb{background:#C9C4BD;border-radius:3px}}</style>' . "\n";
	}

	public static function script() {
		if ( ! self::applies() ) {
			return;
		}
		?>
<script id="young-toc-js">
(function () {
	var toc = document.querySelector('.yg-toc');
	if (!toc || !window.MutationObserver) { return; }
	var box = toc.parentElement;
	function follow() {
		if (box.scrollHeight <= box.clientHeight + 2) { return; }
		var a = toc.querySelector('.elementor-item-active');
		if (!a) { return; }
		var b = box.getBoundingClientRect(), r = a.getBoundingClientRect();
		if (r.top < b.top + 40) { box.scrollTop -= (b.top + 40 - r.top); }
		else if (r.bottom > b.bottom - 40) { box.scrollTop += (r.bottom - b.bottom + 40); }
	}
	new MutationObserver(follow).observe(toc, { subtree: true, attributes: true, attributeFilter: ['class'] });
})();
</script>
		<?php
	}
}
