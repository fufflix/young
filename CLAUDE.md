# young.it

Ogni lavoro su young.it segue la skill young-automation (caricata qui sotto). Prima di scrivere o correggere un testo leggi `.claude/skills/young-automation/stile.md`.

@.claude/skills/young-automation/SKILL.md

## Infrastruttura Fufflix (dati forniti dall'autore il 20/11/2026)
- Sito: WordPress completo su `fufflix.it` (ortografia confermata dall'autore con due "f").
- Dominio registrato su Register. Hosting: VPS su OVH. DNS e gestione del dominio: interamente su Cloudflare.
- Newsletter: Ghost 6 (ghost.io) su `newsletter.fufflix.it` (verificato online il 20/11/2026: generator Ghost, CNAME `fufflix.ghost.io`), DNS su Cloudflare. `fuflix.it` (una "f") non esiste: usare sempre `fufflix.it`.
- Newsletter attive in Ghost: "Fuflix la newsletter" (910 iscritti, quella sempre usata: unica per gli invii) e "Anteprime" (526 iscritti, mai usata: non toccare). Slug interni: [DA VERIFICARE], si leggono dall'API Admin (`GET /newsletters/`) quando la chiave è configurata. Sovrapposizione tra i due elenchi di iscritti: [DA VERIFICARE].
- Anteprima privata delle bozze: `fufflix.it/anteprime` (confermato dall'autore).
- Connettore Cloudflare disponibile (deprecato): legge Worker/codice e crea KV, D1, R2. Non può pubblicare Worker, impostare route o secret, configurare Access o toccare il DNS: queste operazioni le fa l'autore dalla dashboard.
- Account Cloudflare (stato al 20/11/2026): Worker `partner`, `alpegno`, `fragrant-cherry-2d6f`, `abtg26`, `inofografica`, `sponsor`; R2 `dividispesa-video`, `fufflix-video`, `indesign`; nessun KV né D1.
- Newsletter Ghost, flusso concordato: l'IA cerca le fonti e scrive la bozza, la carica in un'anteprima privata (protetta da login, `noindex`, fuori da sitemap e `robots.txt`), l'autore la legge, la modifica e dà l'OK esplicito in chat; solo dopo si crea il post su Ghost e si invia (prima un invio di prova a un segmento di test), poi si cancella l'anteprima. Mai invii senza OK.
- Segreti: la Admin API key di Ghost non va mai in chat, nel repository o in questo file; vive come secret su Cloudflare (`GHOST_ADMIN_KEY`).
