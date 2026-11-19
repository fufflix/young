# Young SEO — plugin WordPress

Piccolo componente aggiuntivo per young.it, pensato per affiancare **Yoast SEO** (sia la vecchia 7.x Premium sia la versione gratuita attuale) o per funzionare senza plugin SEO. Non duplica nulla di ciò che fa Yoast: niente meta tag, dati strutturati o sitemap.

## Installazione

1. Zippa la cartella `wordpress/young-seo` (o usa `young-seo-1.0.0.zip`).
2. WordPress → Plugin → Aggiungi nuovo → Carica plugin → attiva.
3. Impostazioni → Young SEO: controlla gli interruttori e salva.
4. Se `/llms.txt` risponde 404: Impostazioni → Permalink → **Salva** (rigenera le regole di riscrittura). Funziona comunque `https://young.it/?young_llms=1`.
5. Con Cloudflare/cache di pagina: svuota la cache dopo l'attivazione.

## Funzioni (ognuna con il suo interruttore)

| Funzione | Predefinito | Cosa fa |
|---|---|---|
| Nota sull'intelligenza artificiale | attiva | In fondo agli articoli creati con Young MCP (meta `_young_mcp_created_by`) o con la spunta "Intelligenza artificiale" nell'editor, un paragrafo in corpo piccolo `young-ai-disclosure`. Togliendo la spunta la nota sparisce anche dagli articoli MCP. Testo modificabile. |
| Data di aggiornamento | attiva | "Aggiornato il gg/mm/aaaa alle hh:mm" in cima all'articolo se modificato più di 24 ore dopo la pubblicazione (fuso del sito). |
| Box autore | spento | Avatar, nome, biografia e link del profilo (sito, X, Facebook, LinkedIn, Instagram, YouTube). I campi social usano le stesse chiavi di Yoast (`twitter`, `facebook`, …), quindi alimentano anche lo schema autore di Yoast; vengono aggiunti al profilo solo se mancano. |
| Vecchio dominio | attiva | Sostituisce al volo `you-ng.it` / `www.you-ng.it` con il dominio attuale nel testo, negli URL delle immagini (anche `srcset`) e in immagine social, canonical e URL Open Graph di Yoast. Il pulsante **Scansione** conta le righe del database che contengono ancora il vecchio dominio, senza modificarle. |
| llms.txt | attiva | `/llms.txt` in formato [llmstxt.org](https://llmstxt.org): nome e descrizione del sito, nota sulla linea editoriale, le 10 categorie principali e gli ultimi 30 articoli. In cache per un'ora, rigenerato a ogni pubblicazione/modifica. |

## Note

- Compatibile con Yoast SEO 7.6.1 Premium e con la versione gratuita attuale; usa solo filtri presenti in entrambe.
- Nel database scrive solo la propria opzione (`young_seo_settings`), un transient di cache e il meta per articolo `_young_ai_disclosure`. La disinstallazione elimina l'opzione.
- Stili minimi in linea (classi `young-updated`, `young-ai-disclosure`, `young-author-box`): si possono sovrascrivere da Elementor → CSS personalizzato.

## Requisiti

WordPress 6.2+, PHP 7.4+.
