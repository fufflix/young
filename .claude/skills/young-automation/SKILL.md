---
name: young-automation
description: Scrivere, correggere, ottimizzare e pubblicare articoli su young.it (bozze, immagini, SEO) tramite il server MCP Young. Usare per ogni lavoro sul sito.
---

# Young Automation

## Accesso
MCP Young: `https://young.it/wp-json/young-mcp/v1/mcp` (JSON-RPC, `tools/call`). Se non è tra i tool, chiamalo con curl: header `Authorization: Bearer dummy` (il proxy inserisce il token vero), `Content-Type: application/json`, `Accept: application/json, text/event-stream`. Primo: `site_info`. Lettura `get_post` (HTML grezzo), modifica `update_post`, immagini `upload_image`/`update_media`.

## Regole fisse
1. Scrivere o correggere un testo: prima leggi `stile.md`.
2. Bozza (`create_post`, `draft`) → link di anteprima all'autore. Pubblica o modifica pezzi online SOLO dopo un ok esplicito in chat.
3. Prima di modificare un pezzo esistente salva l'HTML originale; sostituzioni mirate, mai rigenerare il contenuto.
4. Nessun fatto, cifra o citazione inventata: il non verificato si segna [DA VERIFICARE] e non si pubblica. Ogni dato con fonte attribuita nel testo; link solo se necessari (regole in `stile.md`).
5. Dopo la pubblicazione verifica online `<title>`, meta description, og:image.
6. La dicitura AI la aggiunge il plugin Young SEO: non scriverla. Categorie solo esistenti (`list_terms`).

## Contenuti sponsorizzati
- Tag `sponsorizzato` obbligatorio su ogni pezzo sponsorizzato (lo riconosce Young SEO ≥1.2.0 e lo toglie dalla sitemap Google News; restano indicizzati su Google e motori AI: niente noindex). Vale anche `pubbliredazionale` o `partner`; elenco in Impostazioni → Young SEO.
- Primo blocco del corpo: etichetta "Sponsored post" in alto a sinistra, badge ambrato del pulsante "Sostienici" (#F49814) con testo #171615 (contrasto ~9:1), piccolo ma leggibile. Markup identico:
  ```html
  <!-- wp:paragraph {"className":"sponsored-label"} -->
  <p class="sponsored-label" style="margin:0 0 14px 0;text-align:left"><span style="display:inline-block;background:#F49814;color:#171615;font-size:11px;font-weight:700;line-height:1;text-transform:uppercase;letter-spacing:0.08em;padding:5px 9px;border-radius:4px">Sponsored post</span></p>
  <!-- /wp:paragraph -->
  ```
- Link allo sponsor: pochi (di norma uno, alla pagina che converte), `rel` follow o `sponsored` lo decide l'autore: chiedilo ogni volta (vedi `stile.md`). Deciso finora: Al Pegno 1977, e-commerce `/acquista` follow (`rel="noopener"`). UTM `utm_source=young.it&utm_medium=referral&utm_campaign=fufflix_young&utm_content=<slug>`.
- Le affermazioni dello sponsor su sé stesso vanno attribuite ("secondo l'azienda", "dichiara"); i dati non verificati restano fuori dal testo pubblicato.
- Dopo la pubblicazione verifica che il pezzo non compaia in `/news-sitemap.xml` (cache fino a 10 minuti; la modifica dei soli tag non la svuota).

## SEO
Keyword, titolo SEO ≤60 caratteri, meta description 120-160, riassunto, alt di ogni immagine, `seo_audit` prima della consegna. Titoli e descrizioni sempre difendibili sul piano legale.

## Immagini
- Preferire immagini vere; mai sostituire quelle già pubblicate senza richiesta. Mai immagini realistiche generate di persone reali (per loro foto con diritti o screenshot dei canali pubblici).
- Generatore: fino al 15/01/2027 Vertex AI (Nano Banana) → Kie.ai → Higgsfield; dopo, Higgsfield → Kie.ai.
- Sorgente ≥1600 px sul lato lungo, niente upscaling; file finale ≤400 kB (JPEG 80-88 o lato 1600 px) controllando la nitidezza.
- Copertina 16:9, ≥1200 px; `upload_image` (`data_base64` per i file generati), alt e didascalia ("Immagine generata con AI" se generata), `set_featured`.
