# Young SEO — plugin WordPress

Piccolo componente aggiuntivo per young.it, pensato per affiancare **Yoast SEO** (sia la vecchia 7.x Premium sia la versione gratuita attuale) o per funzionare senza plugin SEO. Non duplica nulla di ciò che fa Yoast: niente meta tag, dati strutturati o sitemap.

## Installazione

1. Zippa la cartella `wordpress/young-seo` (o usa `young-seo-1.4.1.zip`).
2. WordPress → Plugin → Aggiungi nuovo → Carica plugin → attiva.
3. Impostazioni → Young SEO: controlla gli interruttori e salva.
4. Se `/llms.txt` o `/news-sitemap.xml` rispondono 404: Impostazioni → Permalink → **Salva** (rigenera le regole di riscrittura). Funziona comunque `https://young.it/?young_llms=1`.
5. Con Cloudflare/cache di pagina: svuota la cache dopo l'attivazione.

## Novità 1.4

- 1.4.1: l'indice «In questo articolo» nella colonna laterale scorre da solo quando è più alto dello schermo (articoli con molti sottotitoli) e tiene in vista la voce del capitolo che si sta leggendo.

- Popup della newsletter negli articoli: testo della redazione e pulsante verso newsletter.fufflix.it (Ghost). Compare a metà lettura o dopo 30 secondi, mai sopra il banner dei cookie di YOUng Core; chiuso, torna dopo 30 giorni; dopo il clic su «Iscriviti», dopo un anno. Nessun cookie (localStorage `young_nl_popup`). Interruttore «Popup newsletter» (attivo).

## Novità 1.3

- Pulsante «Scegli YOUng come fonte preferita su Google» in cima a ogni articolo: link diretto allo [strumento delle fonti preferite](https://developers.google.com/search/docs/appearance/preferred-sources) (`google.com/preferences/source?q=young.it`), con il logo G in SVG. Nessuno script di Google e nessun cookie, quindi non serve il consenso. Interruttore «Fonte preferita su Google» (attivo).

## Novità 1.2

- Sitemap Google News: esclusi gli articoli con le categorie o i tag indicati (predefiniti `sponsorizzato`, `pubbliredazionale`, `partner`), così i contenuti sponsorizzati non vengono proposti a Google News.

## Novità 1.1

- Sitemap Google News `/news-sitemap.xml` (sostituisce il modulo News di Yoast Premium).
- Rimozione della meta description duplicata del tema Hello Elementor quando è attivo un plugin SEO.
- 1.1.1: `news-sitemap.xml` compare una sola volta in `sitemap_index.xml` (il filtro salta se la voce c'è già, per esempio aggiunta da un vecchio snippet).

## Funzioni (ognuna con il suo interruttore)

| Funzione | Predefinito | Cosa fa |
|---|---|---|
| Nota sull'intelligenza artificiale | attiva | In fondo agli articoli creati con Young MCP (meta `_young_mcp_created_by`) o con la spunta "Intelligenza artificiale" nell'editor, un paragrafo in corpo piccolo `young-ai-disclosure`. Togliendo la spunta la nota sparisce anche dagli articoli MCP. Testo modificabile. |
| Fonte preferita su Google | attiva | In cima all'articolo, prima del testo, un pulsante discreto (classe `young-pref`) che apre la pagina di Google per aggiungere il sito alle fonti preferite. |
| Data di aggiornamento | attiva | "Aggiornato il gg/mm/aaaa alle hh:mm" in cima all'articolo se modificato più di 24 ore dopo la pubblicazione (fuso del sito). |
| Box autore | spento | Avatar, nome, biografia e link del profilo (sito, X, Facebook, LinkedIn, Instagram, YouTube). I campi social usano le stesse chiavi di Yoast (`twitter`, `facebook`, …), quindi alimentano anche lo schema autore di Yoast; vengono aggiunti al profilo solo se mancano. |
| Vecchio dominio | attiva | Sostituisce al volo `you-ng.it` / `www.you-ng.it` con il dominio attuale nel testo, negli URL delle immagini (anche `srcset`) e in immagine social, canonical e URL Open Graph di Yoast. Il pulsante **Scansione** conta le righe del database che contengono ancora il vecchio dominio, senza modificarle. |
| Sitemap Google News | attiva | `/news-sitemap.xml` secondo le specifiche Google News: articoli pubblicati nelle ultime 48 ore (max 1000), nome testata (predefinito "YOUng"), lingua `it`, data e titolo. Esclusi gli articoli con noindex in Yoast e quelli con le categorie o i tag di esclusione (predefiniti `sponsorizzato`, `pubbliredazionale`, `partner`). Aggiunta all'indice delle sitemap di Yoast (`sitemap_index.xml`); senza Yoast compare in robots.txt. Cache 10 minuti, rigenerata a ogni pubblicazione. Sostituisce il modulo News di Yoast Premium: disinstallalo prima per non avere due sitemap News. |
| Meta description del tema | attiva | Con Yoast o Rank Math attivo rimuove la seconda `<meta name="description">` stampata dal tema Hello Elementor (`hello_elementor_add_description_meta_tag`). |
| llms.txt | attiva | `/llms.txt` in formato [llmstxt.org](https://llmstxt.org): nome e descrizione del sito, nota sulla linea editoriale, le 10 categorie principali e gli ultimi 30 articoli. In cache per un'ora, rigenerato a ogni pubblicazione/modifica. |

## Note

- Compatibile con Yoast SEO 7.6.1 Premium e con la versione gratuita attuale; usa solo filtri presenti in entrambe.
- Nel database scrive solo la propria opzione (`young_seo_settings`), due transient di cache e il meta per articolo `_young_ai_disclosure`. La disinstallazione elimina l'opzione.
- Stili minimi in linea (classi `young-updated`, `young-ai-disclosure`, `young-author-box`): si possono sovrascrivere da Elementor → CSS personalizzato.

## Requisiti

WordPress 6.2+, PHP 7.4+.
