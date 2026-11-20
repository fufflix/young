# Anteprime newsletter (fufflix.it/anteprime)

Programma Cloudflare davanti al WordPress di fufflix.it. Serve a rivedere e approvare le bozze della newsletter Ghost prima dell'invio.

Flusso: l'IA carica la bozza (`POST /anteprime/ingest`) → l'autore la legge e la modifica su `fufflix.it/anteprime` → "Invia test a me" → controlla l'email → "Invia a tutti gli iscritti" (conferma scrivendo INVIA) → la bozza viene cancellata.

Sicurezza (se manca qualcosa la pagina resta spenta):
- ogni pagina chiede una password (`ANTEPRIME_PASSWORD`, almeno 16 caratteri), con blocco di 15 minuti dopo 5 errori; in alternativa si può usare la protezione di Cloudflare con login, impostando `ACCESS_TEAM_DOMAIN` e `ACCESS_AUD`;
- intestazione `X-Robots-Tag: noindex`, nessuna sitemap, nessuna riga in `robots.txt`;
- l'unico indirizzo senza password è `/anteprime/ingest`: richiede il codice `INGEST_TOKEN` e può solo creare bozze, mai inviare;
- l'invio a tutti richiede `ALLOW_SEND=yes`, un test inviato sul testo identico a quello finale e la conferma "INVIA";
- la chiave Admin di Ghost è un segreto del programma, mai nel repository.

## Segreti da inserire (valori cifrati, non si rivedono)
- `GHOST_ADMIN_KEY`: chiave Admin di Ghost (formato `id:segreto`);
- `INGEST_TOKEN`: stringa casuale di almeno 40 caratteri (serve all'IA per caricare le bozze);
- `ANTEPRIME_PASSWORD`: la password per entrare nelle anteprime (almeno 16 caratteri);
- `TEST_EMAIL`: l'indirizzo che riceve l'email di prova.

Pagina del programma, dove si aggiungono (la sezione delle variabili e dei segreti):
https://dash.cloudflare.com/?to=/bff31dc76b5c97e35c98e12aaea31dc2/workers/services/view/anteprime-newsletter/production/settings

## Variabili già impostate (testo normale)
`GHOST_URL` = `https://newsletter.fufflix.it`, `NEWSLETTER_NAME` = `Fuflix la newsletter`, `EMAIL_ONLY` = `true`, `ALLOW_SEND` = `no`.

## Da verificare al primo uso (non confermato dalla documentazione pubblica)
- L'indirizzo per l'email di prova (`POST /email_previews/posts/<id>/`) è quello usato dal pannello di Ghost, non è documentato pubblicamente.
- Con `EMAIL_ONLY=true` il post non compare sul sito e l'invio passa dalla chiamata di pubblicazione con `newsletter` e `email_segment=all`; i forum di Ghost riportano comportamenti discordanti. Se Ghost rifiuta, il programma mostra l'errore e non invia nulla.
- Gli invii vanno solo agli iscritti della newsletter indicata in `NEWSLETTER_NAME` (non a quelli di "Anteprime", salvo che siano iscritti anche all'altra).

## Prove automatiche
Il programma è stato provato con simulazioni locali di archivio, login e Ghost (firma, controllo password e blocco dopo gli errori, pulizia del testo, blocchi di invio). Non è stato provato contro il vero Ghost.
