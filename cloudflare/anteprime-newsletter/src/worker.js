/**
 * Anteprime newsletter Fufflix (Cloudflare Worker)
 *
 * Route: fufflix.it/anteprime*  (davanti al WordPress)
 *
 * - /anteprime            elenco bozze (login Cloudflare Access)
 * - /anteprime/d/<id>     editor della bozza (login Cloudflare Access)
 * - /anteprime/ingest     POST: l'IA carica una bozza (solo token Bearer, solo scrittura bozze)
 *
 * Tutto il resto risponde solo con un JWT Cloudflare Access valido (verificato qui,
 * oltre che da Access). Se Access non è configurato la pagina resta spenta (fail closed).
 * La chiave Admin di Ghost vive solo come secret di questo Worker.
 */

const BASE = '/anteprime';
const MAX_DRAFTS = 30;
const enc = new TextEncoder();

class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

export default {
  async fetch(request, env) {
    let res;
    try {
      res = await route(request, env);
    } catch (e) {
      res = json({ error: String((e && e.message) || e) }, (e && e.status) || 500);
    }
    const h = new Headers(res.headers);
    h.set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    h.set('Cache-Control', 'private, no-store');
    h.set('Referrer-Policy', 'no-referrer');
    h.set('X-Content-Type-Options', 'nosniff');
    h.set('X-Frame-Options', 'DENY');
    return new Response(res.body, { status: res.status, headers: h });
  },
};

/* ------------------------------ routing ------------------------------ */

async function route(request, env) {
  const url = new URL(request.url);
  const path = url.pathname.length > 1 ? url.pathname.replace(/\/+$/, '') : url.pathname;
  if (path !== BASE && !path.startsWith(BASE + '/')) throw new HttpError(404, 'Non trovato');
  if (!env.ANTEPRIME) throw new HttpError(503, 'Binding KV "ANTEPRIME" mancante');

  if (path === BASE + '/ingest') return ingest(request, env);

  await requireAccess(request, env);

  const method = request.method;
  let m;
  if (method === 'GET' && path === BASE) return listPage(env);
  if (method === 'GET' && (m = path.match(/^\/anteprime\/d\/([0-9a-f-]{36})$/))) return editorPage(env, m[1]);
  if (method === 'GET' && path === BASE + '/api/ghost-check') return ghostCheck(env);
  if (method === 'POST') {
    checkOrigin(request);
    if ((m = path.match(/^\/anteprime\/api\/(save|test|send|delete)\/([0-9a-f-]{36})$/))) {
      return action(request, env, m[1], m[2]);
    }
  }
  throw new HttpError(404, 'Non trovato');
}

function checkOrigin(request) {
  const origin = request.headers.get('Origin');
  const self = new URL(request.url).origin;
  if (!origin || origin !== self || request.headers.get('X-Requested-With') !== 'anteprime') {
    throw new HttpError(403, 'Richiesta non valida');
  }
}

/* ------------------------------ sicurezza ------------------------------ */

let jwksCache = { at: 0, keys: null, team: '' };

async function requireAccess(request, env) {
  const team = String(env.ACCESS_TEAM_DOMAIN || '').trim();
  const aud = String(env.ACCESS_AUD || '').trim();
  if (!team || !aud) {
    throw new HttpError(503, 'Accesso non configurato (ACCESS_TEAM_DOMAIN / ACCESS_AUD): pagina disattivata.');
  }
  const token = request.headers.get('Cf-Access-Jwt-Assertion');
  if (!token) throw new HttpError(401, 'Accesso richiesto.');
  const parts = token.split('.');
  if (parts.length !== 3) throw new HttpError(401, 'Token non valido.');
  let header, payload;
  try {
    header = JSON.parse(new TextDecoder().decode(b64urlToBytes(parts[0])));
    payload = JSON.parse(new TextDecoder().decode(b64urlToBytes(parts[1])));
  } catch {
    throw new HttpError(401, 'Token non valido.');
  }
  if (header.alg !== 'RS256') throw new HttpError(401, 'Token non valido.');
  const now = Math.floor(Date.now() / 1000);
  const auds = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
  if (payload.iss !== 'https://' + team || !auds.includes(aud) || !(payload.exp > now)) {
    throw new HttpError(401, 'Token non valido o scaduto.');
  }
  const keys = await getJwks(team);
  const jwk = keys.find((k) => k.kid === header.kid);
  if (!jwk) throw new HttpError(401, 'Chiave del token sconosciuta.');
  const key = await crypto.subtle.importKey(
    'jwk', jwk, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['verify']
  );
  const ok = await crypto.subtle.verify(
    'RSASSA-PKCS1-v1_5', key, b64urlToBytes(parts[2]), enc.encode(parts[0] + '.' + parts[1])
  );
  if (!ok) throw new HttpError(401, 'Firma del token non valida.');
  const allowed = String(env.EMAIL_AUTORIZZATA || '').trim().toLowerCase();
  if (allowed && String(payload.email || '').toLowerCase() !== allowed) {
    throw new HttpError(403, 'Utente non autorizzato.');
  }
}

async function getJwks(team) {
  const fresh = jwksCache.keys && jwksCache.team === team && Date.now() - jwksCache.at < 10 * 60 * 1000;
  if (fresh) return jwksCache.keys;
  const res = await fetch('https://' + team + '/cdn-cgi/access/certs');
  if (!res.ok) throw new HttpError(503, 'Impossibile leggere le chiavi di Cloudflare Access.');
  const data = await res.json();
  jwksCache = { at: Date.now(), keys: data.keys || [], team };
  return jwksCache.keys;
}

async function safeEqual(a, b) {
  const [x, y] = await Promise.all([
    crypto.subtle.digest('SHA-256', enc.encode(a)),
    crypto.subtle.digest('SHA-256', enc.encode(b)),
  ]);
  const ax = new Uint8Array(x);
  const ay = new Uint8Array(y);
  let diff = 0;
  for (let i = 0; i < ax.length; i++) diff |= ax[i] ^ ay[i];
  return diff === 0;
}

/* ------------------------------ HTML pulito ------------------------------ */

const ALLOWED_TAGS = new Set(['p', 'h2', 'h3', 'ul', 'ol', 'li', 'a', 'strong', 'em', 'blockquote', 'br', 'hr', 'img']);
const TAG_MAP = { b: 'strong', i: 'em', h1: 'h2', h4: 'h3', h5: 'h3', h6: 'h3', div: 'p' };
const VOID_TAGS = new Set(['br', 'hr', 'img']);

function escAttr(s) {
  return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function getAttr(attrs, name) {
  const m = attrs.match(new RegExp('\\b' + name + '\\s*=\\s*(?:"([^"]*)"|\'([^\']*)\')', 'i'));
  return m ? (m[1] !== undefined ? m[1] : m[2]) : '';
}

/** Allowlist minimale lato server: tag consentiti, nessun attributo tranne href/src/alt https. */
export function cleanHtml(input) {
  let html = String(input || '');
  html = html.replace(/<(script|style|iframe|object|embed)[\s\S]*?<\/\1\s*>/gi, '');
  html = html.replace(/<!--[\s\S]*?-->/g, '');
  html = html.replace(/<[^>]*>/g, (tag) => {
    const m = tag.match(/^<\s*(\/)?\s*([a-zA-Z0-9]+)([^>]*)>$/);
    if (!m) return '';
    const closing = !!m[1];
    let name = m[2].toLowerCase();
    name = TAG_MAP[name] || name;
    if (!ALLOWED_TAGS.has(name)) return '';
    if (closing) return VOID_TAGS.has(name) ? '' : '</' + name + '>';
    if (name === 'a') {
      const href = getAttr(m[3], 'href');
      return /^(https:|mailto:)/i.test(href) ? '<a href="' + escAttr(href) + '">' : '<a>';
    }
    if (name === 'img') {
      const src = getAttr(m[3], 'src');
      if (!/^https:/i.test(src)) return '';
      return '<img src="' + escAttr(src) + '" alt="' + escAttr(getAttr(m[3], 'alt')) + '">';
    }
    return '<' + name + '>';
  });
  return html.replace(/</g, (c, i) => (/^<\/?(p|h2|h3|ul|ol|li|a|strong|em|blockquote|br|hr|img)[\s>]/.test(html.slice(i, i + 14)) ? c : '&lt;'));
}

/* ------------------------------ bozze (KV) ------------------------------ */

const key = (id) => 'draft:' + id;

async function getDraft(env, id) {
  return env.ANTEPRIME.get(key(id), 'json');
}

async function putDraft(env, d) {
  await env.ANTEPRIME.put(key(d.id), JSON.stringify(d));
}

async function listDrafts(env) {
  const l = await env.ANTEPRIME.list({ prefix: 'draft:' });
  const all = await Promise.all(l.keys.map((k) => env.ANTEPRIME.get(k.name, 'json')));
  return all.filter(Boolean).sort((a, b) => (b.updated || '').localeCompare(a.updated || ''));
}

async function hashDraft(d) {
  const buf = await crypto.subtle.digest('SHA-256', enc.encode(JSON.stringify([d.title, d.html, d.summary || ''])));
  return [...new Uint8Array(buf)].map((x) => x.toString(16).padStart(2, '0')).join('');
}

async function ingest(request, env) {
  if (request.method !== 'POST') throw new HttpError(405, 'Metodo non consentito');
  if (!env.CODICE_BOZZE || String(env.CODICE_BOZZE).length < 24) throw new HttpError(503, 'Ingest non configurato');
  const h = request.headers.get('Authorization') || '';
  const given = h.startsWith('Bearer ') ? h.slice(7) : '';
  if (!(await safeEqual(given, String(env.CODICE_BOZZE)))) throw new HttpError(401, 'Non autorizzato');

  let body;
  try {
    body = await request.json();
  } catch {
    throw new HttpError(400, 'JSON non valido');
  }
  const title = typeof body.title === 'string' ? body.title.trim().slice(0, 200) : '';
  const html = typeof body.html === 'string' ? cleanHtml(body.html).slice(0, 200000) : '';
  if (!title || !html.trim()) throw new HttpError(400, 'title e html sono obbligatori');
  const sources = (Array.isArray(body.sources) ? body.sources : [])
    .slice(0, 20)
    .map((s) => ({
      title: String((s && s.title) || '').slice(0, 200),
      url: /^https:\/\//i.test(String((s && s.url) || '')) ? String(s.url).slice(0, 500) : '',
      note: String((s && s.note) || '').slice(0, 300),
    }))
    .filter((s) => s.title || s.url);
  if ((await env.ANTEPRIME.list({ prefix: 'draft:' })).keys.length >= MAX_DRAFTS) {
    throw new HttpError(429, 'Troppe bozze: cancellane qualcuna');
  }
  const now = new Date().toISOString();
  const d = {
    id: crypto.randomUUID(),
    title,
    html,
    summary: typeof body.summary === 'string' ? body.summary.trim().slice(0, 300) : '',
    sources,
    status: 'draft',
    created: now,
    updated: now,
    ghostPostId: null,
    testedHash: null,
  };
  await putDraft(env, d);
  return json({ id: d.id, url: new URL(request.url).origin + BASE + '/d/' + d.id }, 201);
}

/* ------------------------------ azioni ------------------------------ */

async function action(request, env, name, id) {
  const d = await getDraft(env, id);
  if (!d) throw new HttpError(404, 'Bozza non trovata');
  if (name === 'delete') {
    if (d.status === 'sending') throw new HttpError(409, 'Invio in corso');
    await env.ANTEPRIME.delete(key(id));
    return json({ ok: true });
  }
  if (d.status === 'sending') throw new HttpError(409, 'Invio in corso');

  let body = {};
  try {
    body = await request.json();
  } catch {
    /* corpo vuoto */
  }
  if (typeof body.title === 'string') d.title = body.title.trim().slice(0, 200);
  if (typeof body.html === 'string') d.html = cleanHtml(body.html).slice(0, 200000);
  if (typeof body.summary === 'string') d.summary = body.summary.trim().slice(0, 300);
  if (!d.title || !d.html.trim()) throw new HttpError(400, 'Titolo e testo non possono essere vuoti');
  d.updated = new Date().toISOString();
  const tested = () => d.testedHash && d.testedHash === d.__hash;
  d.__hash = await hashDraft(d);

  if (name === 'save') {
    const t = tested();
    delete d.__hash;
    await putDraft(env, d);
    return json({ ok: true, tested: !!t });
  }

  if (name === 'test') {
    if (!env.EMAIL_PROVA) throw new HttpError(503, 'EMAIL_PROVA non configurata');
    const nl = await findNewsletter(env);
    const hash = d.__hash;
    delete d.__hash;
    await syncGhostDraft(env, d);
    await putDraft(env, d); // salva subito ghostPostId: niente bozze doppie su Ghost se il test fallisce
    await ghost(env, 'POST', '/email_previews/posts/' + d.ghostPostId + '/?newsletter=' + encodeURIComponent(nl.slug), {
      emails: [String(env.EMAIL_PROVA)],
    });
    d.testedHash = hash;
    await putDraft(env, d);
    return json({ ok: true, message: 'Email di test inviata a ' + env.EMAIL_PROVA + '. Controllala, poi puoi inviare a tutti.' });
  }

  if (name === 'send') {
    if (String(env.ALLOW_SEND || '') !== 'yes') {
      throw new HttpError(403, 'Invio a tutti disattivato (ALLOW_SEND diverso da "yes"). Lo si attiva dopo i test.');
    }
    if (body.confirm !== 'INVIA') throw new HttpError(400, 'Conferma mancante');
    if (!tested()) throw new HttpError(409, 'Il testo è cambiato dopo l\'ultimo test: rinvia il test e controllalo.');
    const nl = await findNewsletter(env);
    const hash = d.__hash;
    delete d.__hash;
    d.status = 'sending';
    await putDraft(env, d);
    try {
      await syncGhostDraft(env, d);
      const cur = await ghost(env, 'GET', '/posts/' + d.ghostPostId + '/');
      const q = new URLSearchParams({ newsletter: nl.slug, email_segment: 'all' });
      await ghost(env, 'PUT', '/posts/' + d.ghostPostId + '/?' + q.toString(), {
        posts: [{
          status: 'published',
          updated_at: cur.posts[0].updated_at,
          email_only: String(env.EMAIL_ONLY || 'true') !== 'false',
        }],
      });
    } catch (e) {
      d.status = 'draft';
      d.testedHash = null;
      await putDraft(env, d);
      throw e;
    }
    await env.ANTEPRIME.put(
      'log:' + new Date().toISOString() + ':' + d.id,
      JSON.stringify({ title: d.title, sentAt: new Date().toISOString(), ghostPostId: d.ghostPostId, hash: hash.slice(0, 12) }),
      { expirationTtl: 60 * 60 * 24 * 90 }
    );
    await env.ANTEPRIME.delete(key(d.id));
    return json({ ok: true, sent: true, message: 'Newsletter inviata a «' + nl.name + '». Anteprima cancellata.' });
  }
  throw new HttpError(404, 'Azione sconosciuta');
}

/* ------------------------------ Ghost Admin API ------------------------------ */

function b64urlToBytes(s) {
  s = s.replace(/-/g, '+').replace(/_/g, '/');
  while (s.length % 4) s += '=';
  return Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
}

function bytesToB64url(b) {
  let s = '';
  for (const x of new Uint8Array(b)) s += String.fromCharCode(x);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export async function ghostToken(env) {
  const [id, secret] = String(env.CHIAVE_GHOST || '').split(':');
  if (!id || !secret || !/^[0-9a-fA-F]+$/.test(secret) || secret.length % 2) {
    throw new HttpError(503, 'CHIAVE_GHOST mancante o in formato non valido (atteso id:segreto)');
  }
  const iat = Math.floor(Date.now() / 1000);
  const head = bytesToB64url(enc.encode(JSON.stringify({ alg: 'HS256', typ: 'JWT', kid: id })));
  const pay = bytesToB64url(enc.encode(JSON.stringify({ iat, exp: iat + 300, aud: '/admin/' })));
  const k = await crypto.subtle.importKey(
    'raw', Uint8Array.from(secret.match(/.{2}/g), (h) => parseInt(h, 16)), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', k, enc.encode(head + '.' + pay));
  return head + '.' + pay + '.' + bytesToB64url(sig);
}

async function ghost(env, method, path, body) {
  if (!env.GHOST_URL) throw new HttpError(503, 'GHOST_URL mancante');
  const base = String(env.GHOST_URL).replace(/\/+$/, '');
  const res = await fetch(base + '/ghost/api/admin' + path, {
    method,
    headers: {
      Authorization: 'Ghost ' + (await ghostToken(env)),
      'Content-Type': 'application/json',
      'Accept-Version': 'v5.0',
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const txt = await res.text();
  let data = null;
  try {
    data = JSON.parse(txt);
  } catch {
    /* non JSON */
  }
  if (!res.ok) {
    const msg = (data && data.errors && data.errors[0] && data.errors[0].message) || txt.slice(0, 200);
    throw new HttpError(502, 'Ghost ' + res.status + ': ' + msg);
  }
  return data;
}

async function findNewsletter(env) {
  const wanted = String(env.NEWSLETTER_NAME || 'Fuflix la newsletter').trim().toLowerCase();
  const data = await ghost(env, 'GET', '/newsletters/?limit=all');
  const active = (data.newsletters || []).filter((n) => n.status === 'active');
  const match = active.filter((n) => String(n.name).trim().toLowerCase() === wanted);
  if (match.length !== 1) {
    throw new HttpError(
      409,
      'Newsletter "' + env.NEWSLETTER_NAME + '" non trovata o ambigua. Attive: ' +
        active.map((n) => n.name + ' (' + n.slug + ')').join(', ')
    );
  }
  return match[0];
}

async function syncGhostDraft(env, d) {
  const post = { title: d.title, html: d.html, status: 'draft' };
  if (d.summary) post.custom_excerpt = d.summary.slice(0, 300);
  if (d.ghostPostId) {
    try {
      const cur = await ghost(env, 'GET', '/posts/' + d.ghostPostId + '/');
      await ghost(env, 'PUT', '/posts/' + d.ghostPostId + '/?source=html', {
        posts: [{ ...post, updated_at: cur.posts[0].updated_at }],
      });
      return;
    } catch (e) {
      if (!/Ghost 404/.test(e.message)) throw e;
      d.ghostPostId = null;
    }
  }
  const r = await ghost(env, 'POST', '/posts/?source=html', { posts: [post] });
  d.ghostPostId = r.posts[0].id;
}

async function ghostCheck(env) {
  const data = await ghost(env, 'GET', '/newsletters/?limit=all');
  const wanted = String(env.NEWSLETTER_NAME || 'Fuflix la newsletter').trim().toLowerCase();
  return json({
    newsletters: (data.newsletters || []).map((n) => ({
      name: n.name, slug: n.slug, status: n.status, usata_per_gli_invii: String(n.name).trim().toLowerCase() === wanted,
    })),
    test_email_configurata: !!env.EMAIL_PROVA,
    invio_a_tutti_attivo: String(env.ALLOW_SEND || '') === 'yes',
    email_only: String(env.EMAIL_ONLY || 'true') !== 'false',
  });
}

/* ------------------------------ pagine ------------------------------ */

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), { status, headers: { 'Content-Type': 'application/json; charset=utf-8' } });
}

function esc(s) {
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function htmlPage(body, script) {
  const nonce = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(16))));
  const csp = "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; connect-src 'self'; " +
    "script-src 'nonce-" + nonce + "'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
  const page = '<!doctype html><html lang="it"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<meta name="robots" content="noindex,nofollow,noarchive">' +
    '<title>Anteprime newsletter</title><style>' + CSS + '</style></head><body>' + body +
    (script ? '<script nonce="' + nonce + '">' + script + '</script>' : '') + '</body></html>';
  return new Response(page, {
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': csp },
  });
}

const CSS = `
:root{--bg:#fafaf8;--fg:#1c1c1a;--mut:#6b6b66;--line:#ddddd6;--card:#fff;--acc:#0b57d0;--ok:#1a7f37;--bad:#b3261e}
@media (prefers-color-scheme:dark){:root{--bg:#161614;--fg:#ececE6;--mut:#a3a39b;--line:#34342f;--card:#1f1f1c;--acc:#7ab0ff;--ok:#56d364;--bad:#ff8a80}}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
main{max-width:820px;margin:0 auto;padding:24px 16px 80px}h1{font-size:1.4rem;margin:0 0 4px}
.mut{color:var(--mut);font-size:.9rem}a{color:var(--acc)}
table{width:100%;border-collapse:collapse;margin:16px 0}td,th{text-align:left;padding:10px 6px;border-bottom:1px solid var(--line)}
button{font:inherit;padding:9px 14px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--fg);cursor:pointer}
button.pri{background:var(--acc);color:#fff;border-color:var(--acc)}button.bad{color:var(--bad)}
button:disabled{opacity:.5;cursor:default}
input,textarea{width:100%;font:inherit;padding:10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--fg)}
label{display:block;font-size:.85rem;color:var(--mut);margin:14px 0 4px}
#ed{min-height:380px;padding:14px;border:1px solid var(--line);border-radius:8px;background:var(--card)}
#ed:focus{outline:2px solid var(--acc)}#ed img{max-width:100%}
.bar{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0}.row{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0}
.badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.78rem;border:1px solid var(--line)}
.src li{margin:4px 0}#msg{min-height:1.5em;margin-top:8px}#msg.ok{color:var(--ok)}#msg.err{color:var(--bad)}
pre{white-space:pre-wrap;background:var(--card);padding:10px;border:1px solid var(--line);border-radius:8px}
`;

async function listPage(env) {
  const drafts = await listDrafts(env);
  const rows = drafts.length
    ? drafts.map((d) =>
        '<tr><td><a href="' + BASE + '/d/' + esc(d.id) + '">' + esc(d.title) + '</a></td><td class="mut">' +
        esc((d.updated || '').slice(0, 16).replace('T', ' ')) + ' UTC</td><td><span class="badge">' +
        esc(d.testedHash ? 'testata' : 'da testare') + '</span></td></tr>').join('')
    : '<tr><td colspan="3" class="mut">Nessuna bozza in attesa.</td></tr>';
  return htmlPage(
    '<main><h1>Anteprime newsletter</h1><div class="mut">Pagina privata: non indicizzata, accesso con login.</div>' +
    '<table><tr><th>Titolo</th><th>Aggiornata</th><th>Stato</th></tr>' + rows + '</table>' +
    '<button id="chk">Controlla collegamento con Ghost</button><pre id="out" hidden></pre></main>',
    `document.getElementById('chk').onclick=function(){var o=document.getElementById('out');o.hidden=false;o.textContent='Controllo...';
fetch('/anteprime/api/ghost-check').then(function(r){return r.json()}).then(function(j){o.textContent=JSON.stringify(j,null,2)}).catch(function(e){o.textContent=String(e)})}`
  );
}

async function editorPage(env, id) {
  const d = await getDraft(env, id);
  if (!d) throw new HttpError(404, 'Bozza non trovata');
  const tested = !!d.testedHash && d.testedHash === (await hashDraft(d));
  const data = JSON.stringify({ id: d.id, html: d.html, tested }).replace(/</g, '\\u003c');
  const src = (d.sources || []).length
    ? '<h2 style="font-size:1rem">Fonti usate</h2><ul class="src">' + d.sources.map((s) =>
        '<li>' + (s.url ? '<a href="' + esc(s.url) + '" target="_blank" rel="noopener noreferrer">' + esc(s.title || s.url) + '</a>' : esc(s.title)) +
        (s.note ? ' <span class="mut">— ' + esc(s.note) + '</span>' : '') + '</li>').join('') + '</ul>'
    : '<p class="mut">Nessuna fonte allegata: non inviare.</p>';
  const body =
    '<main><a href="' + BASE + '">← Tutte le anteprime</a><h1>Bozza newsletter</h1>' +
    '<div class="mut">Destinatari all\'invio: iscritti alla newsletter «' + esc(env.NEWSLETTER_NAME || 'Fuflix la newsletter') + '».</div>' +
    '<label for="title">Titolo (è anche l\'oggetto dell\'email)</label><input id="title" maxlength="200" value="' + esc(d.title) + '">' +
    '<label for="summary">Riassunto breve (max 300 caratteri)</label><textarea id="summary" rows="2" maxlength="300">' + esc(d.summary) + '</textarea>' +
    '<label>Testo</label><div class="bar">' +
    '<button data-c="bold"><b>B</b></button><button data-c="italic"><i>I</i></button><button data-c="h2">Titoletto</button>' +
    '<button data-c="ul">Elenco</button><button data-c="link">Link</button><button data-c="unlink">Togli link</button></div>' +
    '<div id="ed" contenteditable="true"></div>' + src +
    '<div class="row"><button id="save">Salva</button><button id="test" class="pri">Invia test a me</button>' +
    '<button id="send" class="bad">Invia a tutti gli iscritti</button><button id="del" class="bad">Elimina bozza</button></div>' +
    '<div id="msg"></div><script type="application/json" id="data">' + data + '</script></main>';
  return htmlPage(body, CLIENT_JS);
}

const CLIENT_JS = `
(function(){
var data=JSON.parse(document.getElementById('data').textContent);
var $=function(i){return document.getElementById(i)};
var ed=$('ed'),title=$('title'),summary=$('summary'),msg=$('msg');
var ALLOWED={P:1,H2:1,H3:1,UL:1,OL:1,LI:1,A:1,STRONG:1,EM:1,BLOCKQUOTE:1,BR:1,HR:1,IMG:1};
var MAP={B:'STRONG',I:'EM',H1:'H2',H4:'H3',H5:'H3',H6:'H3',DIV:'P'};
var VOID={BR:1,HR:1,IMG:1};
function walk(src,dst){src.childNodes.forEach(function(n){
 if(n.nodeType===3){dst.appendChild(document.createTextNode(n.nodeValue));return}
 if(n.nodeType!==1)return;
 if(n.tagName==='SCRIPT'||n.tagName==='STYLE'||n.tagName==='IFRAME')return;
 var tag=MAP[n.tagName]||n.tagName;
 if(!ALLOWED[tag]){walk(n,dst);return}
 var el=document.createElement(tag);
 if(tag==='A'){var h=n.getAttribute('href')||'';if(/^(https:|mailto:)/i.test(h))el.setAttribute('href',h)}
 if(tag==='IMG'){var s=n.getAttribute('src')||'';if(!/^https:/i.test(s))return;el.setAttribute('src',s);el.setAttribute('alt',n.getAttribute('alt')||'')}
 if(!VOID[tag])walk(n,el);
 dst.appendChild(el)})}
function clean(html){var doc=new DOMParser().parseFromString('<body>'+html+'</body>','text/html');var out=document.createElement('div');walk(doc.body,out);return out.innerHTML}
ed.innerHTML=clean(data.html);
function say(t,c){msg.textContent=t;msg.className=c||''}
function call(name,extra){var b={title:title.value,summary:summary.value,html:clean(ed.innerHTML)};
 if(extra)for(var k in extra)b[k]=extra[k];
 return fetch('/anteprime/api/'+name+'/'+data.id,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'anteprime'},body:JSON.stringify(b)})
 .then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j}})})}
function run(name,extra,okfn){say('Un attimo...');call(name,extra).then(function(r){
 if(r.ok){okfn(r.j)}else{say(r.j.error||'Errore','err')}}).catch(function(e){say(String(e),'err')})}
document.querySelectorAll('.bar button').forEach(function(b){b.onclick=function(){
 ed.focus();var c=b.getAttribute('data-c');
 if(c==='h2')document.execCommand('formatBlock',false,'H2');
 else if(c==='ul')document.execCommand('insertUnorderedList');
 else if(c==='link'){var u=prompt('Indirizzo del link (https://...)');if(u)document.execCommand('createLink',false,u)}
 else document.execCommand(c)}});
$('save').onclick=function(){run('save',null,function(j){say(j.tested?'Salvata (test ancora valido).':'Salvata. Ricorda di inviare un test.','ok')})};
$('test').onclick=function(){run('test',null,function(j){say(j.message,'ok')})};
$('send').onclick=function(){var v=prompt('Stai per spedire la newsletter a TUTTI gli iscritti. Non si può annullare. Scrivi INVIA per confermare.');
 if(v!=='INVIA'){say('Invio annullato.');return}
 run('send',{confirm:v},function(j){say(j.message,'ok');setTimeout(function(){location.href='/anteprime'},2500)})};
$('del').onclick=function(){if(!confirm('Eliminare questa bozza?'))return;run('delete',null,function(){location.href='/anteprime'})};
})();
`;
