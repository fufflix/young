<?php
/**
 * Plugin Name: Young Editor Fix
 * Description: Corregge il blocco dell'editor sugli schermi touch: dopo uno scorrimento con il dito il cursore spariva e il testo non si poteva più scrivere né cancellare. È un bug di WordPress 7.1 (Gutenberg #67986), corretto in WordPress 7.2: da quella versione il plugin non fa più niente e si può eliminare.
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: YOUng
 * License: GPL-2.0-or-later
 *
 * @package YoungEditorFix
 */

defined( 'ABSPATH' ) || exit;

/*
 * Il problema, nel codice di WordPress 7.1 (wp-includes/js/dist/rich-text.js, preventFocusCapture):
 * quando si preme sullo spazio intorno al testo, l'editor mette contenteditable="false" sui campi di testo
 * e lo rimette com'era al "pointerup". Sugli schermi touch, se il dito fa scorrere la pagina, il browser
 * manda "pointercancel" invece di "pointerup": il valore non torna mai, e tutto il testo resta bloccato
 * finché non si ricarica l'editor. WordPress 7.2 aggiunge l'ascolto di "pointercancel" (Gutenberg PR #82598).
 *
 * Qui si fa la stessa cosa dall'esterno: a ogni "pointercancel" si manda un "pointerup" equivalente,
 * così l'editor rimette da sé i valori che aveva salvato. Lo script va nell'area di scrittura
 * (enqueue_block_assets la carica anche dentro l'iframe dell'editor), solo in wp-admin.
 */
add_action( 'enqueue_block_assets', 'young_editor_fix_touch_scroll' );

function young_editor_fix_touch_scroll() {
	if ( ! is_admin() ) {
		return;
	}
	// Da WordPress 7.2 la correzione è nel nucleo.
	if ( version_compare( get_bloginfo( 'version' ), '7.2-alpha', '>=' ) ) {
		return;
	}
	$js = <<<'JS'
(function () {
	if (window.__youngTouchFix) { return; }
	window.__youngTouchFix = true;
	document.addEventListener('pointercancel', function (e) {
		var t = (e.target && e.target.nodeType === 1) ? e.target : document.body;
		var init = { bubbles: true, cancelable: true, pointerId: e.pointerId, pointerType: e.pointerType, isPrimary: e.isPrimary };
		try {
			t.dispatchEvent(window.PointerEvent ? new PointerEvent('pointerup', init) : new Event('pointerup', init));
		} catch (err) {}
	}, true);
})();
JS;
	wp_register_script( 'young-editor-fix', false, array(), '1.0.0', false );
	wp_enqueue_script( 'young-editor-fix' );
	wp_add_inline_script( 'young-editor-fix', $js );
}
