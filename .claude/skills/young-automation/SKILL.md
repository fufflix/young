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

## SEO
Keyword, titolo SEO ≤60 caratteri, meta description 120-160, riassunto, alt di ogni immagine, `seo_audit` prima della consegna. Titoli e descrizioni sempre difendibili sul piano legale.

## Immagini
- Preferire immagini vere; mai sostituire quelle già pubblicate senza richiesta. Mai immagini realistiche generate di persone reali (per loro foto con diritti o screenshot dei canali pubblici).
- Generatore: fino al 15/01/2027 Vertex AI (Nano Banana) → Kie.ai → Higgsfield; dopo, Higgsfield → Kie.ai.
- Sorgente ≥1600 px sul lato lungo, niente upscaling; file finale ≤400 kB (JPEG 80-88 o lato 1600 px) controllando la nitidezza.
- Copertina 16:9, ≥1200 px; `upload_image` (`data_base64` per i file generati), alt e didascalia ("Immagine generata con AI" se generata), `set_featured`.
