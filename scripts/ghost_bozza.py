#!/usr/bin/env python3
"""Crea una BOZZA su Ghost (mai un invio). La chiave Admin si legge dalla variabile CHIAVE_GHOST (formato id:segreto).

Uso:
  CHIAVE_GHOST=... python3 scripts/ghost_bozza.py controlla
  CHIAVE_GHOST=... python3 scripts/ghost_bozza.py crea "Titolo" file.html ["riassunto breve"]

L'invio agli iscritti lo fa solo l'autore, dal pannello di Ghost.
"""
import base64
import hashlib
import hmac
import json
import os
import sys
import time
import urllib.error
import urllib.request

URL = os.environ.get("GHOST_URL", "https://newsletter.fufflix.it").rstrip("/")


def b64(b):
    return base64.urlsafe_b64encode(b).rstrip(b"=").decode()


def token(chiave):
    kid, segreto = chiave.split(":")
    ora = int(time.time())
    testa = b64(json.dumps({"alg": "HS256", "typ": "JWT", "kid": kid}).encode())
    corpo = b64(json.dumps({"iat": ora, "exp": ora + 300, "aud": "/admin/"}).encode())
    firma = hmac.new(bytes.fromhex(segreto), f"{testa}.{corpo}".encode(), hashlib.sha256).digest()
    return f"{testa}.{corpo}.{b64(firma)}"


def chiama(metodo, percorso, corpo=None):
    chiave = os.environ.get("CHIAVE_GHOST", "")
    if ":" not in chiave:
        sys.exit("CHIAVE_GHOST mancante o in formato non valido (atteso id:segreto)")
    r = urllib.request.Request(
        f"{URL}/ghost/api/admin{percorso}",
        data=json.dumps(corpo).encode() if corpo else None,
        method=metodo,
        headers={"Authorization": "Ghost " + token(chiave), "Content-Type": "application/json", "Accept-Version": "v5.0"},
    )
    try:
        with urllib.request.urlopen(r, timeout=30) as f:
            return json.load(f)
    except urllib.error.HTTPError as e:
        sys.exit(f"Ghost ha risposto {e.code}: {e.read().decode()[:300]}")


def main():
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    if sys.argv[1] == "controlla":
        for n in chiama("GET", "/newsletters/?limit=all")["newsletters"]:
            print(n["name"], "|", n["slug"], "|", n["status"])
    elif sys.argv[1] == "crea" and len(sys.argv) >= 4:
        html = open(sys.argv[3], encoding="utf-8").read()
        post = {"title": sys.argv[2], "html": html, "status": "draft"}
        if len(sys.argv) > 4:
            post["custom_excerpt"] = sys.argv[4][:300]
        r = chiama("POST", "/posts/?source=html", {"posts": [post]})["posts"][0]
        print("Bozza creata:", f"{URL}/ghost/#/editor/post/{r['id']}")
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
