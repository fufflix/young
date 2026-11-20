# Anteprime newsletter (fufflix.it/anteprime)

Worker Cloudflare davanti al WordPress di fufflix.it. Serve a rivedere e approvare le bozze della newsletter Ghost prima dell'invio.

Flusso: l'IA carica la bozza con `POST /anteprime/ingest` → l'autore la legge e la modifica su `fufflix.it/anteprime` (login Cloudflare Access) → "Invia test a me" → controlla l'email → "Invia a tutti gli iscritti" (conferma scrivendo INVIA) → la bozza viene cancellata.

Sicurezza (fail closed):
- ogni pagina risponde solo con un JWT Cloudflare Access valido (verificato dal Worker); senza `ACCESS_TEAM_DOMAIN` e `ACCESS_AUD` la pagina resta spenta;
- `X-Robots-Tag: noindex`, nessuna sitemap, nessuna voce in `robots.txt`;
- l'unico endpoint senza login è `/anteprime/ingest`: richiede un token Bearer e può solo creare bozze, mai inviare;
- l'invio a tutti richiede `ALLOW_SEND=yes`, un test inviato sul testo identico a quello finale e la conferma "INVIA";
- la chiave Admin di Ghost è un secret del Worker, mai nel repository.

## Messa online (dashboard Cloudflare, nessun software da installare)

1. **Worker**: Workers & Pages → Create → Create Worker → nome `anteprime-newsletter` → Deploy → Edit code → cancella il contenuto, incolla tutto `src/worker.js` → Deploy.
2. **KV** (già creato, `anteprime-newsletter`): Worker → Settings → Bindings → Add → KV namespace → variable name `ANTEPRIME` → scegli `anteprime-newsletter`.
3. **Route**: Settings → Domains & Routes → Add → Route → `fufflix.it/anteprime*`, zona `fufflix.it`. Disattiva l'indirizzo `workers.dev` e le preview.
4. **Access** (Zero Trust → Access → Applications → Add → Self-hosted):
   - app "Anteprime": destinazione `fufflix.it` percorso `anteprime*`; policy Allow con la tua email; metodo codice via email;
   - copia l'**Application Audience (AUD) tag** dell'app;
   - il **team domain** è `nome.cloudflareaccess.com` (Zero Trust → Settings → General);
   - seconda app "Anteprime ingest": destinazione `fufflix.it` percorso `anteprime/ingest`; policy **Bypass** per Everyone (il Worker la protegge col token).
5. **Variabili e secret** (Worker → Settings → Variables and Secrets):
   - Secret: `GHOST_ADMIN_KEY` (chiave Admin di Ghost, formato `id:segreto`), `INGEST_TOKEN` (stringa casuale lunga, almeno 40 caratteri), `TEST_EMAIL` (dove ricevere il test), `ALLOWED_EMAIL` (la tua email di login Access).
   - Testo: `GHOST_URL` = `https://newsletter.fufflix.it`, `NEWSLETTER_NAME` = `Fuflix la newsletter`, `EMAIL_ONLY` = `true`, `ALLOW_SEND` = `no`, `ACCESS_TEAM_DOMAIN`, `ACCESS_AUD`.
6. **Prova**: apri `fufflix.it/anteprime`, fai il login, premi "Controlla collegamento con Ghost": deve elencare le due newsletter con i loro slug e dire quale viene usata.
7. **Primo test reale**: carico una bozza finta, premi "Invia test a me" e controlli l'email. Solo se arriva e va bene, cambi `ALLOW_SEND` in `yes`.

## Da verificare al primo uso (non confermato dalla documentazione pubblica)
- L'endpoint del test (`POST /email_previews/posts/<id>/`) è quello usato dall'admin di Ghost, non è documentato pubblicamente.
- Con `EMAIL_ONLY=true` il post non compare sul sito e l'invio passa dalla chiamata di pubblicazione con `newsletter` e `email_segment=all`; i forum Ghost riportano comportamenti discordanti. Se Ghost rifiuta, il Worker riporta l'errore e non invia nulla.
- Gli invii vanno solo agli iscritti della newsletter indicata in `NEWSLETTER_NAME` (non agli iscritti a "Anteprime", salvo che siano iscritti anche all'altra).

## Prove automatiche
Il Worker è stato provato con simulazioni locali di KV, Access e Ghost (firma JWT Ghost, verifica Access, pulizia HTML, blocchi di invio). Non è stato provato contro il vero Ghost né il vero Cloudflare.
