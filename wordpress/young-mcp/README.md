# Young MCP — plugin WordPress

Server [MCP](https://modelcontextprotocol.io) dentro WordPress: permette a Claude Code e Codex di leggere gli articoli, creare bozze, caricare immagini e ottimizzare la SEO di young.it.

## Installazione

1. Scarica `young-mcp.zip` (artifact del workflow **Plugin Young MCP** su GitHub Actions, oppure zippa la cartella `wordpress/young-mcp`).
2. WordPress → Plugin → Aggiungi nuovo → Carica plugin → attiva.
3. Utenti → Aggiungi nuovo: crea un utente dedicato, es. `redazione-ai`, con ruolo **Editore** (o **Autore** se vuoi che tocchi solo i propri articoli). Gli amministratori sono rifiutati.
4. Impostazioni → Young MCP → **Nuovo token**: uno per Claude Code e uno per Codex. Il token compare **una sola volta**.

## Collegamento

**Claude Code**

```bash
claude mcp add --transport http --scope user young https://young.it/wp-json/young-mcp/v1/mcp --header "Authorization: Bearer ymcp_..."
```

**Codex**: esporta `YOUNG_MCP_TOKEN=ymcp_...` e aggiungi a `~/.codex/config.toml`:

```toml
[mcp_servers.young]
url = "https://young.it/wp-json/young-mcp/v1/mcp"
bearer_token_env_var = "YOUNG_MCP_TOKEN"
```

## Novità 1.2

- Nuovo strumento `update_media`: aggiorna testo alternativo, titolo, didascalia e descrizione di un'immagine già in libreria (almeno un campo). Se l'immagine è in evidenza o allegata a un articolo pubblicato vale l'interruttore "modifica di articoli già pubblicati". Il log registra l'alt prima/dopo.
- `get_post` restituisce anche il titolo dell'immagine in evidenza.
- `site_info` indica con `young_seo_active` se il plugin Young SEO è attivo.

## Novità 1.1

- `upload_image` accetta anche il file stesso: parametro `data_base64` (byte dell'immagine in base64, anche con prefisso `data:image/...;base64,`) in alternativa a `url`. L'origine registrata è "upload-diretto".
- Nuovo campo SEO `og_image` (immagine per anteprime social) in `update_seo` e nell'oggetto `seo` di `create_post`/`update_post`: ID di un'immagine della libreria o URL; stringa vuota per ripristinare. Scritto nei campi Open Graph e Twitter di Yoast o Rank Math; senza plugin SEO il plugin stampa i meta `og:image`, `og:title` e `og:description`.
- `seo_audit` conta come sottotitoli sia gli H2 sia gli H3 (stile della redazione).

## Strumenti

| Strumento | Cosa fa | Scrive? |
|---|---|---|
| `site_info` | Sito, fuso orario, plugin SEO, utente e permessi | no |
| `list_posts` | Ricerca articoli/pagine per testo, stato, categoria, tag | no |
| `get_post` | Articolo completo: corpo, tassonomie, immagine, SEO | no |
| `list_terms` | Categorie e tag esistenti | no |
| `list_media` | Immagini già in libreria | no |
| `seo_audit` | Analisi SEO euristica (titolo, description, keyword, H2/H3, alt, link) | no |
| `create_post` | Crea articolo/pagina (predefinito: bozza) | sì |
| `update_post` | Modifica i campi indicati | sì |
| `upload_image` | Carica immagine da URL o da file (base64) con alt e didascalia, opz. in evidenza | sì |
| `update_media` | Alt, titolo, didascalia e descrizione di un'immagine in libreria | sì |
| `update_seo` | Titolo SEO, description, keyword, canonical, noindex, Open Graph, immagine social | sì |

Nessuno strumento elimina contenuti.

## Sicurezza e controllo editoriale

- **Due interruttori, spenti di default**: "pubblicazione" e "modifica di articoli già pubblicati". Da spenti l'AI lavora solo su bozze e "in attesa di revisione".
- I permessi di WordPress valgono comunque: un token legato a un Collaboratore non pubblica nemmeno con l'interruttore acceso.
- Il token vale **solo** sull'endpoint MCP, non sul resto delle API; nel database ne è salvato solo l'hash SHA-256; si revoca con un clic.
- Prima di ogni modifica al testo viene salvata una revisione dello stato precedente: tutto è ripristinabile da WordPress (se le revisioni sono attive).
- Log attività (ultime 300 azioni di scrittura ed errori): token, strumento, esito; per la SEO i valori prima/dopo, perché i meta SEO non hanno revisioni.
- Gli articoli creati dall'AI hanno il meta `_young_mcp_created_by`; le immagini importate conservano l'URL di origine in `_young_mcp_source_url`.
- Il download delle immagini usa le funzioni sicure di WordPress (blocca indirizzi interni/privati).

## SEO

Rileva automaticamente **Yoast SEO** o **Rank Math** e scrive nei loro campi. Con Yoast ricostruisce l'"indexable" del post, così i nuovi valori compaiono subito sul sito. Senza plugin SEO salva i campi in proprio e stampa meta description, titolo, canonical, noindex e meta Open Graph (titolo, descrizione, immagine).

## Problemi frequenti

- **401 con token corretto**: alcuni hosting (Apache/CGI) eliminano l'header `Authorization`. Usa l'header `X-Young-MCP-Token: ymcp_...` oppure aggiungi a `.htaccess`: `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`.
- **404 su /wp-json/**: usa `https://young.it/?rest_route=/young-mcp/v1/mcp`.
- **403 / pagina HTML invece di JSON**: firewall (Cloudflare WAF, Wordfence, plugin di sicurezza) che blocca client non-browser o le REST API: crea un'eccezione per il percorso `/wp-json/young-mcp/`.
- Verifica in sola lettura: workflow **Plugin Young MCP** → *Run workflow* con "check_live" (secrets `WP_URL` e `YOUNG_MCP_TOKEN`), oppure `python3 scripts/check_mcp.py`.

## Requisiti

WordPress 6.2+, PHP 7.4+. Trasporto MCP "Streamable HTTP" senza stato (solo risposte JSON), versioni del protocollo 2024-11-05 → 2025-11-25.
