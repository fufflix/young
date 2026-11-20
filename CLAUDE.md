# young.it

Ogni lavoro su young.it segue la skill young-automation (caricata qui sotto). Prima di scrivere o correggere un testo leggi `.claude/skills/young-automation/stile.md`.

@.claude/skills/young-automation/SKILL.md

## Infrastruttura Fufflix (dati forniti dall'autore il 20/11/2026)
- Sito: WordPress completo su `fufflix.it` (ortografia confermata dall'autore con due "f").
- Dominio registrato su Register. Hosting: VPS su OVH. DNS e gestione del dominio: interamente su Cloudflare.
- Newsletter: Ghost 6 (ghost.io) su `newsletter.fufflix.it` (verificato online il 20/11/2026: generator Ghost, CNAME `fufflix.ghost.io`), DNS su Cloudflare. `fuflix.it` (una "f") non esiste: usare sempre `fufflix.it`.
- Newsletter attive in Ghost: "Fuflix la newsletter" (910 iscritti, quella sempre usata: unica per gli invii) e "Anteprime" (526 iscritti, mai usata: non toccare). Slug interni: [DA VERIFICARE], si leggono dall'API Admin (`GET /newsletters/`) quando la chiave è configurata. Sovrapposizione tra i due elenchi di iscritti: [DA VERIFICARE].
- Anteprima privata delle bozze: `fufflix.it/anteprime` (confermato dall'autore).
- Cloudflare, due vie: il connettore MCP (deprecato) legge Worker/codice e crea KV, D1, R2, ma non pubblica Worker. Per caricare Worker, route e variabili si usa l'API `https://api.cloudflare.com/client/v4` con `Authorization: Bearer dummy` (il proxy inserisce il token vero, salvato nell'ambiente: permessi Workers Scripts, Workers KV, Workers Routes sulla zona `fufflix.it`). Account id `bff31dc76b5c97e35c98e12aaea31dc2`, zona `fufflix.it` id `125988f04c24b2869ec9f1fcea3603c2`. Il token non ha accesso ad Access né al DNS. I `secret_text` si conservano con `keep_bindings` a ogni upload.
- **La dashboard Cloudflare dell'autore è in italiano. Mai nomi di menu, pulsanti o voci in inglese, né inventati.** Se non conosci la voce italiana esatta, non citarne nessuna: dai il link diretto e descrivi a parole tue cosa cercare, oppure fai l'operazione via API. L'autore non vuole screenshot (troppo lenti): non chiederli.
- Account Cloudflare (stato al 20/11/2026): Worker `partner` (route `fufflix.it/*`), `alpegno`, `fragrant-cherry-2d6f`, `abtg26`, `inofografica`, `sponsor` e `anteprime-newsletter` (route `fufflix.it/anteprime*`, codice in `cloudflare/anteprime-newsletter/`, KV `anteprime-newsletter`, workers.dev disattivato, `ALLOW_SEND=no`); R2 `dividispesa-video`, `fufflix-video`, `indesign`.
- Newsletter Ghost, flusso concordato: l'IA cerca le fonti e scrive la bozza, la carica in un'anteprima privata (protetta da login, `noindex`, fuori da sitemap e `robots.txt`), l'autore la legge, la modifica e dà l'OK esplicito in chat; solo dopo si crea il post su Ghost e si invia (prima un invio di prova a un segmento di test), poi si cancella l'anteprima. Mai invii senza OK.
- Segreti: la Admin API key di Ghost non va mai in chat, nel repository o in questo file; vive come secret su Cloudflare (`GHOST_ADMIN_KEY`).
