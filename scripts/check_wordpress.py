"""Read-only WordPress authentication check; never prints credentials."""

import base64
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request


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
        request = urllib.request.Request(url + path, headers={
            "Authorization": "Basic " + auth,
            "Accept": "application/json",
            "User-Agent": "Young-WordPress-Check/1.0",
        })
        with opener.open(request, timeout=30) as response:
            return json.load(response)

    try:
        profile = get("/wp-json/wp/v2/users/me?context=edit")
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
        print("Controllare credenziali, permessi o eventuali blocchi di sicurezza.")
    except (urllib.error.URLError, TimeoutError, ValueError):
        print("Verifica fallita: connessione o risposta JSON non valida.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
