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
- Punteggiatura: MAI la virgola prima della congiunzione "e" (no "No, e non è…"): riformula o usa il punto.
- Virgolette: usa le alte tipografiche “ ” (34 testi su 40 dell'archivio; le basse « » in 2). Mai virgolette annidate («”…”», “"…"”) e mai le virgolette dritte " nel testo: WordPress le converte e può aprire con ”. Se un titolo citato contiene già virgolette, riformula (il titolo virgoletta “Riciclaggio con aggravante mafiosa” e aggiunge che Revolut è commissariata) oppure usa le singole all'interno: “‘Riciclaggio con aggravante mafiosa’, commissariata Revolut”. Prima di consegnare cerca nel testo «”, ”» e ” attaccato all'inizio di una parola.
- Vietate le formule da AI: "Sembra una sfumatura. Non lo è.", "Non è un dettaglio", "Il punto", riassunti a tre elementi ("Tre distinzioni…"), frasi-slogan corte in serie, la battuta di chiusura paragrafo che ripete il concetto appena detto con un'etichetta ironica dopo i due punti ("Un termine sbagliato ed un'associazione fuorviante in sei parole: niente male, come rapporto qualità-prezzo."; anche "niente male", "complimenti", "un capolavoro di…", "roba da manuale"). Mai metafore che il lettore deve rovesciare per capire. Se la battuta va spiegata, si taglia. Scrivi come l'autore: incisi, domande ai lettori, ironia nei fatti.
- Sottotitoli: precisi nel soggetto (es. "come molti giornali l'hanno raccontata", non "dove i lettori l'hanno vista").
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
- Qualità: immagini nitide, mai sgranate né piccole (sorgente ≥1600 px di lato lungo, niente upscaling di immagini piccole), peso finale ≤400 kB: comprimi JPEG (qualità 80-88) o riduci a 1600 px finché resta sotto soglia, controllando a vista la nitidezza.
- Copertina 16:9, almeno 1200 px di larghezza; caricare con `upload_image` (`data_base64` per file generati), alt e didascalia ("Immagine generata con AI" se generata); `set_featured`.
