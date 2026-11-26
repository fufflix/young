<?php
/**
 * Popup discreto per l'iscrizione alla newsletter (gestita su Ghost, newsletter.fufflix.it).
 * Solo negli articoli; compare a metà lettura o dopo 30 secondi, mai sopra il banner dei cookie.
 * Chi lo chiude non lo rivede per 30 giorni, chi clicca "Iscriviti" per un anno.
 * Nessun cookie: la scelta resta nel localStorage del browser (young_nl_popup).
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Popup {

	const URL = 'https://newsletter.fufflix.it/';

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 50 );
	}

	private static function applies() {
		return Young_SEO::enabled( 'newsletter_popup' ) && is_singular( 'post' ) && ! is_preview() && ! is_customize_preview();
	}

	public static function render() {
		if ( ! self::applies() ) {
			return;
		}
		$url  = apply_filters( 'young_seo_newsletter_url', self::URL );
		$text = 'Resta aggiornato sulle ultime notizie del nostro progetto editoriale indipendente. Verrai reindirizzato alla newsletter di Fufflix, da dove gestiamo le iscrizioni. Ti disiscrivi con un click quando vuoi, zero spam';
		?>
<div id="young-nl" class="young-nl" role="dialog" aria-modal="false" aria-labelledby="young-nl-title" hidden>
	<button type="button" class="young-nl__close" aria-label="Chiudi">&times;</button>
	<p id="young-nl-title" class="young-nl__title">Newsletter</p>
	<p class="young-nl__text"><?php echo esc_html( $text ); ?></p>
	<a class="young-nl__btn" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Iscriviti alla newsletter</a>
</div>
<style id="young-nl-css">
#young-nl{--ynl-ink:#171615;--ynl-text:#24221F;--ynl-paper:#FBFBFA;--ynl-sup:#F1F0ED;--ynl-line:#E1DED9;position:fixed;z-index:99990;right:16px;bottom:16px;width:380px;max-width:calc(100vw - 32px);box-sizing:border-box;background:var(--ynl-paper);color:var(--ynl-text);border:1px solid var(--ynl-line);border-radius:12px;box-shadow:0 12px 40px rgba(23,22,21,.18);padding:22px 24px 20px;font-family:"Schibsted Grotesk",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;opacity:0;transform:translateY(16px);transition:opacity .25s ease,transform .25s ease}
#young-nl *{box-sizing:border-box}
#young-nl.is-open{opacity:1;transform:none}
#young-nl[hidden]{display:none}
#young-nl .young-nl__title{margin:0 40px 8px 0;font-family:"Newsreader",Georgia,serif;font-size:22px;font-weight:600;line-height:1.2;letter-spacing:-.2px;color:var(--ynl-ink)}
#young-nl .young-nl__text{margin:0 0 16px;font-family:inherit;font-size:15px;line-height:1.55;color:var(--ynl-text)}
#young-nl .young-nl__btn,#young-nl .young-nl__btn:visited{display:flex;align-items:center;justify-content:center;min-height:44px;padding:12px 18px;border:0;border-radius:8px;background:#F49814;color:#171615;font-family:inherit;font-size:15px;font-weight:600;line-height:1.2;text-decoration:none;text-align:center}
#young-nl .young-nl__btn:hover,#young-nl .young-nl__btn:focus{background:#F49814;color:#171615;opacity:.88;text-decoration:none}
#young-nl .young-nl__btn:focus-visible,#young-nl .young-nl__close:focus-visible{outline:3px solid #F49814;outline-offset:2px}
#young-nl .young-nl__close,#young-nl .young-nl__close:hover,#young-nl .young-nl__close:focus{position:absolute;top:10px;right:10px;width:40px;height:40px;min-height:0;margin:0;padding:0;border:0;border-radius:8px;background:transparent;color:var(--ynl-text);font-family:inherit;font-size:26px;font-weight:400;line-height:1;cursor:pointer;box-shadow:none}
#young-nl .young-nl__close:hover{background:var(--ynl-sup)}
@media (max-width:600px){#young-nl{left:0;right:0;bottom:0;width:auto;max-width:none;border-radius:12px 12px 0 0;border-bottom:0;padding:18px 16px calc(16px + env(safe-area-inset-bottom))}#young-nl .young-nl__title{font-size:20px}#young-nl .young-nl__text{font-size:14px;line-height:1.5;margin-bottom:14px}}
@media (prefers-color-scheme:dark){html:not([data-theme="light"]) #young-nl{--ynl-ink:#F2F0EC;--ynl-text:#F2F0EC;--ynl-paper:#1C1B19;--ynl-sup:#131211;--ynl-line:#34312D}}
html[data-theme="dark"] #young-nl{--ynl-ink:#F2F0EC;--ynl-text:#F2F0EC;--ynl-paper:#1C1B19;--ynl-sup:#131211;--ynl-line:#34312D}
@media (prefers-reduced-motion:reduce){#young-nl{transition:none}}
html.young-nl-open .yc-fab,html.young-nl-open body .yc-fab{display:none!important}
</style>
<script id="young-nl-js">
(function () {
	var KEY = 'young_nl_popup', DAY = 864e5, box = document.getElementById('young-nl');
	if (!box) { return; }
	function get() { try { return JSON.parse(window.localStorage.getItem(KEY) || 'null'); } catch (e) { return null; } }
	function set(v) { try { window.localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} }
	var st = get();
	if (st && st.until && st.until > Date.now()) { return; }
	var shown = false, timer;
	function cookieOpen() { return !!document.querySelector('.yc-banner.is-open, .yc-panel.is-open'); }
	function onScroll() {
		var h = document.documentElement;
		if ((h.scrollTop + window.innerHeight) / h.scrollHeight > 0.45) { show(); }
	}
	function show() {
		if (shown) { return; }
		if (cookieOpen()) { clearTimeout(timer); timer = setTimeout(show, 3000); return; }
		shown = true;
		window.removeEventListener('scroll', onScroll);
		clearTimeout(timer);
		box.hidden = false;
		document.documentElement.classList.add('young-nl-open');
		window.requestAnimationFrame(function () { box.classList.add('is-open'); });
	}
	function close(days) {
		box.classList.remove('is-open');
		document.documentElement.classList.remove('young-nl-open');
		setTimeout(function () { box.hidden = true; }, 260);
		set({ until: Date.now() + days * DAY });
	}
	window.addEventListener('scroll', onScroll, { passive: true });
	timer = setTimeout(show, 30000);
	box.querySelector('.young-nl__close').addEventListener('click', function () { close(30); });
	box.querySelector('.young-nl__btn').addEventListener('click', function () { close(365); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && shown && !box.hidden) { close(30); } });
})();
</script>
		<?php
	}
}
