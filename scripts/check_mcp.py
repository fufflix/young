"""Read-only check of the Young MCP endpoint; never prints the token.

Calls only initialize, tools/list and the read-only site_info tool:
no content is created or modified.
"""

import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

ALLOWED_HOSTS = {"young.it", "www.young.it"}
EXPECTED_TOOLS = {
    "site_info", "list_posts", "get_post", "create_post", "update_post",
    "list_terms", "list_media", "upload_image", "update_seo", "seo_audit",
}


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def main():
    missing = [n for n in ("WP_URL", "YOUNG_MCP_TOKEN") if not os.environ.get(n, "").strip()]
    if missing:
        print("Secrets mancanti: " + ", ".join(missing))
        return 1

    base = os.environ["WP_URL"].strip().rstrip("/")
    parsed = urllib.parse.urlsplit(base)
    if parsed.scheme != "https" or parsed.netloc not in ALLOWED_HOSTS or parsed.path:
        print("WP_URL deve essere https://young.it")
        return 1

    token = os.environ["YOUNG_MCP_TOKEN"].strip()
    use_fallback = os.environ.get("YOUNG_MCP_FALLBACK", "") == "1"
    endpoint = base + ("/?rest_route=/young-mcp/v1/mcp" if use_fallback else "/wp-json/young-mcp/v1/mcp")
    opener = urllib.request.build_opener(NoRedirect())

    def rpc(method, params=None, msg_id=1):
        body = {"jsonrpc": "2.0", "id": msg_id, "method": method}
        if params is not None:
            body["params"] = params
        request = urllib.request.Request(endpoint, data=json.dumps(body).encode(), method="POST", headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "MCP-Protocol-Version": "2025-06-18",
            "User-Agent": "Young-MCP-Check/1.0",
        })
        with opener.open(request, timeout=30) as response:
            data = json.load(response)
        if "error" in data:
            raise RuntimeError("Errore JSON-RPC " + str(data["error"].get("code")))
        return data["result"]

    try:
        init = rpc("initialize", {
            "protocolVersion": "2025-06-18",
            "capabilities": {},
            "clientInfo": {"name": "young-mcp-check", "version": "1.0"},
        })
        info = init.get("serverInfo", {})
        print("Endpoint MCP raggiungibile: " + str(info.get("name")) + " " + str(info.get("version")))
        print("Protocollo negoziato: " + str(init.get("protocolVersion")))

        tools = {t["name"] for t in rpc("tools/list", msg_id=2).get("tools", [])}
        absent = EXPECTED_TOOLS - tools
        print("Strumenti disponibili: " + str(len(tools)))
        if absent:
            print("Strumenti mancanti: " + ", ".join(sorted(absent)))
            return 1

        result = rpc("tools/call", {"name": "site_info", "arguments": {}}, msg_id=3)
        if result.get("isError"):
            print("site_info ha restituito un errore.")
            return 1
        site = json.loads(result["content"][0]["text"])
        acting = site.get("acting_as", {})
        print("Agisce come utente con ruoli: " + ", ".join(acting.get("roles", [])))
        if "administrator" in acting.get("roles", []):
            print("ATTENZIONE: il token è collegato a un amministratore; usare un utente Editore dedicato.")
        print("Plugin SEO rilevato: " + str(site.get("seo_plugin")))
        print("Permessi: " + json.dumps(site.get("permissions", {}), sort_keys=True))
        print("Verifica completata; nessun contenuto creato o modificato.")
        return 0
    except urllib.error.HTTPError as error:
        print("Verifica fallita: HTTP " + str(error.code) + ".")
        try:
            code = json.load(error).get("code", "")
            if isinstance(code, str) and code.replace("_", "").isalnum():
                print("Codice errore WordPress: " + code[:100])
        except (ValueError, AttributeError):
            print("La risposta non proviene dalle API JSON di WordPress (firewall, cache o blocco di sicurezza?).")
        if error.code == 404 and not use_fallback:
            print("Riprovare con YOUNG_MCP_FALLBACK=1 (percorso ?rest_route=).")
    except (urllib.error.URLError, TimeoutError, ValueError, KeyError, RuntimeError) as error:
        print("Verifica fallita: " + type(error).__name__ + ".")
    return 1


if __name__ == "__main__":
    sys.exit(main())
