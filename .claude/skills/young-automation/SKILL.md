---
name: young-automation
description: Scrivere, ottimizzare e pubblicare articoli su young.it tramite il server MCP Young (bozze, immagini, SEO). Usare per ogni articolo o intervento SEO sul sito.
---

# Young Automation

Accesso: endpoint `https://young.it/wp-json/young-mcp/v1/mcp` (token già configurato). Chiama `site_info` per primo.

## Flusso
1. Brief dall'autore → bozza (`create_post`, status `draft`) → link di anteprima all'autore.
2. Pubblica SOLO dopo un "ok pubblica" esplicito in chat. Mai pubblicare senza revisione umana.
3. Dopo la pubblicazione verifica online: `<title>`, meta description, og:image.
4. Prima di consegnare: rilettura completa per refusi, titolo e sottotitoli.

## Scrittura
- Stile: leggi `stile.md` (solo quando scrivi un articolo).
- Sarcasmo/ironia su scala 0-5: livello 2,5-3. Incisi ironici e riferimenti pop (es. Kung Fu Panda) sì; mai parolacce, mai insulti; l'ironia non sostituisce i fatti.
- Sottotitoli H3 in tondo (non MAIUSCOLO). Niente H1 nel corpo.
- Attacco con i fatti chiave nelle prime 2-3 frasi; frasi citabili con numero, data, fonte.
- Ogni dato con fonte linkata (primaria se possibile). Accuse attribuite e al condizionale.
- Nessun fatto, cifra o citazione inventata: ciò che non è verificato va segnato [DA VERIFICARE] e non si pubblica.
- La dicitura AI in fondo la aggiunge il plugin Young SEO in automatico: non scriverla nel testo.
- Categorie: solo esistenti (`list_terms`).

## SEO
Keyword, titolo SEO ≤60 caratteri, meta description 120-160, riassunto, alt di ogni immagine, `seo_audit` prima della consegna. Titoli e descrizioni sempre difendibili sul piano legale.

## Immagini
- Preferire immagini vere. Mai sostituire immagini già pubblicate senza richiesta.
- Mai immagini realistiche generate di persone reali: per loro foto con diritti o screenshot dei canali pubblici.
- Generatore (in ordine): fino al 15/01/2027 → 1) Google Vertex AI (Nano Banana), 2) Kie.ai, 3) Higgsfield. Dal 15/01/2027 → 1) Higgsfield, 2) Kie.ai.
- Copertina 16:9, almeno 1200 px di larghezza; caricare con `upload_image` (`data_base64` per file generati), alt e didascalia ("Immagine generata con AI" se generata); `set_featured`.
