"""Read-only WordPress authentication check; never prints credentials."""

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def main():
    names = ("WP_URL", "WP_USER", "WP_APP_PASSWORD")
    missing = [name for name in names if not os.environ.get(name, "").strip()]
    if missing:
        print("Secrets mancanti: " + ", ".join(missing))
        return 1

    url = os.environ["WP_URL"].strip().rstrip("/")
    parsed = urllib.parse.urlsplit(url)
    if parsed.scheme != "https" or parsed.netloc != "young.it" or parsed.path or parsed.query or parsed.fragment:
        print("WP_URL deve essere https://young.it")
        return 1

    user = os.environ["WP_USER"].strip()
    password = os.environ["WP_APP_PASSWORD"].strip()
    auth = base64.b64encode((user + ":" + password).encode()).decode()
    opener = urllib.request.build_opener(NoRedirect())

    def get(path):
        separator = "&" if "?" in path else "?"
        target = url + path + separator + "young_check=" + uuid.uuid4().hex
        request = urllib.request.Request(target, headers={
            "Authorization": "Basic " + auth,
            "Accept": "application/json",
            "User-Agent": "Young-WordPress-Check/1.0",
            "Cache-Control": "no-cache, no-store",
            "Pragma": "no-cache",
        })
        with opener.open(request, timeout=30) as response:
            cache = response.headers.get("CF-Cache-Status", "").upper()
            if cache in {"HIT", "MISS", "DYNAMIC", "BYPASS", "EXPIRED", "REVALIDATED", "STALE", "UPDATING"}:
                print("Cache Cloudflare: " + cache)
            return json.load(response)

    try:
        index = get("/wp-json/")
        print("Indice API con credenziali: raggiungibile.")
        enabled = "application-passwords" in index.get("authentication", {})
        print("Password applicazioni dichiarate disponibili: " + ("si" if enabled else "no"))
        try:
            profile = get("/wp-json/wp/v2/users/me?context=edit")
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise
            try:
                get("/wp-json/wp/v2/posts?context=edit&per_page=1&_fields=id")
            except urllib.error.HTTPError as editorial_error:
                if editorial_error.code != 401:
                    raise
                print("API standard: HTTP 401; verifica percorso alternativo WordPress.")
                get("/?rest_route=/wp/v2/posts&context=edit&per_page=1&_fields=id")
                print("Percorso alternativo autenticato: configurare il client con rest_route.")
                print("Nessun contenuto creato o modificato.")
                return 0
            get("/wp-json/wp/v2/media?context=edit&per_page=1&_fields=id")
            print("Autenticazione WordPress riuscita: accesso editoriale ad articoli e media confermato.")
            print("Endpoint utenti disabilitato; permessi di pubblicazione e caricamento non ancora verificati.")
            print("Nessun contenuto creato o modificato.")
            return 0
        if not profile.get("id"):
            print("Risposta inattesa: autenticazione non confermata.")
            return 1
        capabilities = profile.get("capabilities", {})
        required = ("edit_posts", "publish_posts", "upload_files")
        lacking = [cap for cap in required if not capabilities.get(cap)]
        print("Autenticazione WordPress riuscita.")
        if lacking:
            print("Permessi mancanti: " + ", ".join(lacking))
            return 1
        print("Permessi per articoli e immagini confermati; nessun contenuto creato.")
        index = get("/wp-json/")
        yoast = any(n.startswith("yoast/") for n in index.get("namespaces", []))
        print("Yoast: " + ("rilevato" if yoast else "non rilevato nelle API"))
        print("La scrittura dei campi SEO richiede una verifica separata.")
        return 0
    except urllib.error.HTTPError as error:
        print("Verifica fallita: HTTP " + str(error.code) + ".")
        try:
            detail = json.load(error)
            code = detail.get("code", "")
            if isinstance(code, str) and code.replace("_", "").isalnum():
                print("Codice errore WordPress: " + code[:100])
        except (ValueError, AttributeError):
            print("La risposta di errore non proviene dalle API JSON WordPress.")
        print("Controllare credenziali, permessi o eventuali blocchi di sicurezza.")
    except (urllib.error.URLError, TimeoutError, ValueError):
        print("Verifica fallita: connessione o risposta JSON non valida.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
