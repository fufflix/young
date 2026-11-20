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
      if (e && e.headers) for (const [k, v] of Object.entries(e.headers)) res.headers.set(k, v);
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
  if (path === BASE + '/ghost-bozza' && request.method === 'POST') {
    await requireToken(request, env);
    return ghostBozza(request, env);
  }
  if (path === BASE + '/stato' && request.method === 'GET') {
    await requireToken(request, env);
    return ghostCheck(env);
  }

  await requireLogin(request, env);

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

/**
 * Accesso: se sono impostati ACCESS_TEAM_DOMAIN e ACCESS_AUD vale il login di Cloudflare;
 * altrimenti vale la parola d'ordine ANTEPRIME_PASSWORD (almeno 32 caratteri), con blocco
 * di 15 minuti dopo 5 errori per indirizzo IP. Senza nessuna delle due la pagina resta spenta.
 */
async function requireLogin(request, env) {
  if (String(env.ACCESS_TEAM_DOMAIN || '').trim() && String(env.ACCESS_AUD || '').trim()) {
    return requireAccess(request, env);
  }
  const pwd = String(env.ANTEPRIME_PASSWORD || '');
  if (pwd.length < 32) {
    throw new HttpError(503, 'Accesso non configurato: pagina disattivata.');
  }
  const ip = request.headers.get('CF-Connecting-IP') || 'sconosciuto';
  const failKey = 'fail:' + ip;
  const fails = parseInt((await env.ANTEPRIME.get(failKey)) || '0', 10);
  if (fails >= 5) throw new HttpError(429, 'Troppi tentativi sbagliati. Riprova tra 15 minuti.');
  const h = request.headers.get('Authorization') || '';
  let given = '';
  if (h.startsWith('Basic ')) {
    try {
      const raw = atob(h.slice(6));
      given = raw.slice(raw.indexOf(':') + 1);
    } catch {
      given = '';
    }
  }
  if (given && (await safeEqual(given, pwd))) {
    if (fails) await env.ANTEPRIME.delete(failKey);
    return;
  }
  if (given) await env.ANTEPRIME.put(failKey, String(fails + 1), { expirationTtl: 900 });
  const err = new HttpError(401, 'Accesso richiesto.');
  err.headers = { 'WWW-Authenticate': 'Basic realm="Anteprime newsletter", charset="UTF-8"' };
  throw err;
}

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

/** Stesso codice usato per caricare le bozze: serve anche a controllare il collegamento con Ghost (sola lettura). */
async function requireToken(request, env) {
  if (!env.CODICE_BOZZE || String(env.CODICE_BOZZE).length < 24) throw new HttpError(503, 'Codice non configurato');
  const h = request.headers.get('Authorization') || '';
  const given = h.startsWith('Bearer ') ? h.slice(7) : '';
  if (!(await safeEqual(given, String(env.CODICE_BOZZE)))) throw new HttpError(401, 'Non autorizzato');
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
  const wanted = String(env.NEWSLETTER_NAME || 'Fufflix - La Newsletter').trim().toLowerCase();
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

/** Carica un'immagine su Ghost e restituisce l'indirizzo. */
async function ghostUpload(env, bytes, name, type) {
  if (!/^image\/(jpeg|png|webp)$/.test(type)) throw new HttpError(400, 'Formato immagine non ammesso');
  const fd = new FormData();
  fd.append('file', new Blob([bytes], { type }), String(name).replace(/[^\w.-]/g, '_'));
  fd.append('purpose', 'image');
  const base = String(env.GHOST_URL).replace(/\/+$/, '');
  const res = await fetch(base + '/ghost/api/admin/images/upload/', {
    method: 'POST',
    headers: { Authorization: 'Ghost ' + (await ghostToken(env)), 'Accept-Version': 'v5.0' },
    body: fd,
  });
  const txt = await res.text();
  let data = null;
  try { data = JSON.parse(txt); } catch { /* non JSON */ }
  if (!res.ok) {
    throw new HttpError(502, 'Ghost ' + res.status + ': ' + ((data && data.errors && data.errors[0] && data.errors[0].message) || txt.slice(0, 200)));
  }
  return data.images[0].url;
}

/** Crea una BOZZA su Ghost (mai invio), con immagine di copertina e categoria facoltative. */
async function ghostBozza(request, env) {
  let b;
  try { b = await request.json(); } catch { throw new HttpError(400, 'JSON non valido'); }
  const title = String(b.title || '').trim().slice(0, 255);
  const html = cleanHtml(b.html || '');
  if (!title || !html.trim()) throw new HttpError(400, 'title e html obbligatori');
  const post = { title, html, status: 'draft' };
  if (b.summary) post.custom_excerpt = String(b.summary).slice(0, 300);
  let tag = null;
  if (b.tag === 'casuale') {
    const t = await ghost(env, 'GET', '/tags/?limit=all&filter=' + encodeURIComponent('visibility:public'));
    const list = t.tags || [];
    if (list.length) {
      tag = list[crypto.getRandomValues(new Uint32Array(1))[0] % list.length];
      post.tags = [{ name: tag.name }];
    }
  }
  if (b.image_base64) {
    const bytes = Uint8Array.from(atob(String(b.image_base64)), (c) => c.charCodeAt(0));
    if (bytes.length > 5000000) throw new HttpError(413, 'Immagine troppo grande');
    post.feature_image = await ghostUpload(env, bytes, b.image_name || 'copertina.jpg', b.image_type || 'image/jpeg');
    if (b.image_alt) post.feature_image_alt = String(b.image_alt).slice(0, 191);
    if (b.image_caption) post.feature_image_caption = String(b.image_caption).slice(0, 500);
  }
  const r = await ghost(env, 'POST', '/posts/?source=html', { posts: [post] });
  const p = r.posts[0];
  if (b.draftId && /^[0-9a-f-]{36}$/.test(String(b.draftId))) {
    const d = await getDraft(env, String(b.draftId));
    if (d) { d.ghostPostId = p.id; await putDraft(env, d); }
  }
  return json({
    ok: true, id: p.id, status: p.status,
    editor: String(env.GHOST_URL).replace(/\/+$/, '') + '/ghost/#/editor/post/' + p.id,
    categoria_scelta: tag ? tag.name : null,
    categorie: (p.tags || []).map((x) => x.name),
    immagine: p.feature_image || null,
  }, 201);
}

async function ghostCheck(env) {
  const data = await ghost(env, 'GET', '/newsletters/?limit=all');
  const wanted = String(env.NEWSLETTER_NAME || 'Fufflix - La Newsletter').trim().toLowerCase();
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

function htmlPage(titolo, body, script) {
  const nonce = btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(16))));
  const csp = "default-src 'none'; style-src 'unsafe-inline'; img-src https: data:; connect-src 'self'; " +
    "script-src 'nonce-" + nonce + "'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
  const page = '<!doctype html><html lang="it"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<meta name="robots" content="noindex,nofollow,noarchive">' +
    '<meta name="color-scheme" content="light dark">' +
    '<title>' + esc(titolo) + '</title><style>' + CSS + '</style></head><body>' + body +
    (script ? '<script nonce="' + nonce + '">' + script + '</script>' : '') + '</body></html>';
  return new Response(page, {
    headers: { 'Content-Type': 'text/html; charset=utf-8', 'Content-Security-Policy': csp },
  });
}

const CSS = String.raw`
:root{
 --bg:#f4f3ee;--panel:#ffffff;--ink:#1c1b18;--mut:#6a675f;--line:#dedbd1;--line2:#ebe8df;
 --acc:#1f4fd8;--acc-ink:#ffffff;--acc-soft:#e8eefc;--ok:#1a7f37;--ok-soft:#e3f4e8;
 --warn:#8a5200;--warn-soft:#fff1d6;--bad:#b3261e;--bad-soft:#fde7e5;
 --sh:0 1px 2px rgba(20,18,10,.06),0 8px 24px rgba(20,18,10,.06);
 --serif:Georgia,'Iowan Old Style','Palatino Linotype',serif;
 --sans:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
}
@media (prefers-color-scheme:dark){:root{
 --bg:#141413;--panel:#1d1d1b;--ink:#ecebe5;--mut:#a09d94;--line:#34332e;--line2:#2a2925;
 --acc:#7aa2ff;--acc-ink:#0d1220;--acc-soft:#1b2540;--ok:#5fd37e;--ok-soft:#16301f;
 --warn:#f2b44d;--warn-soft:#3a2c10;--bad:#ff8a80;--bad-soft:#3a1816;
 --sh:0 1px 2px rgba(0,0,0,.4),0 8px 24px rgba(0,0,0,.35)}}
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 var(--sans)}
a{color:var(--acc)}
button,input,select,textarea{font:inherit;color:inherit}
:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
.mut{color:var(--mut)}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:9px;border:1px solid var(--line);background:var(--panel);cursor:pointer;font-weight:550;white-space:nowrap}
.btn:hover{border-color:var(--mut)}
.btn.pri{background:var(--acc);border-color:var(--acc);color:var(--acc-ink)}
.btn.dan{color:var(--bad);border-color:var(--bad)}
.btn.dan.pieno{background:var(--bad);color:#fff}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.chip{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:99px;font-size:.78rem;border:1px solid var(--line);background:var(--panel);white-space:nowrap}
.chip.ok{background:var(--ok-soft);color:var(--ok);border-color:transparent}
.chip.warn{background:var(--warn-soft);color:var(--warn);border-color:transparent}
.chip.bad{background:var(--bad-soft);color:var(--bad);border-color:transparent}
/* elenco */
.wrap{max-width:880px;margin:0 auto;padding:36px 16px 80px}
.wrap h1{font:700 1.7rem/1.2 var(--serif);margin:0 0 4px}
.lista{display:grid;gap:12px;margin:26px 0}
.voce{display:block;text-decoration:none;color:inherit;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px 20px;box-shadow:var(--sh)}
.voce:hover{border-color:var(--acc)}
.voce h2{font:700 1.2rem/1.3 var(--serif);margin:0 0 6px}
.voce .riga{display:flex;flex-wrap:wrap;gap:8px;align-items:center;color:var(--mut);font-size:.85rem}
.vuoto{padding:34px;text-align:center;border:1px dashed var(--line);border-radius:14px;color:var(--mut)}
.pannello{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:18px 20px}
.pannello h3{margin:0 0 8px;font-size:1rem}
.ghost-ris{margin-top:12px;display:grid;gap:6px;font-size:.92rem}
/* editor */
.top{position:sticky;top:0;z-index:30;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;padding:10px 16px;background:var(--panel);border-bottom:1px solid var(--line)}
.top .sx{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;min-width:0}
.top .dx{display:flex;gap:8px;flex-wrap:wrap}
.stato{font-size:.85rem;color:var(--mut)}
.stato.ok{color:var(--ok)}.stato.warn{color:var(--warn)}.stato.err{color:var(--bad)}
.seg{display:inline-flex;border:1px solid var(--line);border-radius:9px;overflow:hidden}
.seg button{border:0;background:transparent;padding:8px 12px;cursor:pointer;font-weight:550}
.seg button.on{background:var(--acc-soft);color:var(--acc)}
.layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:24px;max-width:1180px;margin:0 auto;padding:22px 16px 90px}
@media (max-width:960px){.layout{grid-template-columns:minmax(0,1fr)}}
.campo label{display:block;font-size:.8rem;font-weight:650;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;margin:0 0 6px}
#titolo{width:100%;resize:none;overflow:hidden;font:700 1.9rem/1.25 var(--serif);padding:10px 12px;border:1px solid transparent;border-radius:10px;background:transparent}
#titolo:hover,#titolo:focus{border-color:var(--line);background:var(--panel)}
.conta{font-size:.8rem;color:var(--mut);margin:4px 2px 14px;display:flex;gap:14px;flex-wrap:wrap}
.conta .alert{color:var(--warn);font-weight:600}
.tb{position:sticky;top:calc(var(--alto,62px) + 8px);z-index:20;display:flex;flex-wrap:wrap;gap:4px;align-items:center;padding:8px;background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:var(--sh)}
.tb button,.tb select{height:34px;padding:0 10px;border:1px solid transparent;border-radius:8px;background:transparent;cursor:pointer}
.tb button:hover,.tb select:hover{background:var(--acc-soft)}
.tb button.on{background:var(--acc-soft);color:var(--acc);border-color:var(--acc)}
.tb .sep{width:1px;height:22px;background:var(--line);margin:0 4px}
.foglio{margin-top:14px;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:34px 40px;box-shadow:var(--sh)}
@media (max-width:640px){.foglio{padding:20px 16px}}
#ed{min-height:420px;outline:0;font:19px/1.75 var(--serif);max-width:680px;margin:0 auto}
#ed p{margin:0 0 1.1em}
#ed h2{font:700 1.5rem/1.3 var(--serif);margin:1.5em 0 .5em}
#ed h3{font:700 1.2rem/1.35 var(--serif);margin:1.4em 0 .4em}
#ed blockquote{margin:1.2em 0;padding:.2em 1.1em;border-left:4px solid var(--acc);color:var(--mut)}
#ed ul,#ed ol{margin:0 0 1.1em;padding-left:1.4em}
#ed hr{border:0;border-top:1px solid var(--line);margin:1.8em 0}
#ed img{max-width:100%;height:auto;border-radius:8px}
#ed a{color:var(--acc)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:16px}
.card h3{margin:0 0 10px;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;color:var(--mut)}
#riassunto{width:100%;min-height:84px;resize:vertical;padding:10px;border:1px solid var(--line);border-radius:10px;background:var(--bg)}
.contr{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.contr li{display:flex;gap:10px;align-items:flex-start;font-size:.9rem}
.punto{flex:none;width:10px;height:10px;border-radius:50%;margin-top:6px;background:var(--ok)}
.punto.avviso{background:var(--warn)}.punto.blocca{background:var(--bad)}.punto.info{background:var(--mut)}
.contr .fix{margin-left:6px;padding:1px 8px;border:1px solid var(--acc);border-radius:99px;background:transparent;color:var(--acc);cursor:pointer;font-size:.78rem}
.stat{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.stat div{background:var(--bg);border-radius:10px;padding:10px}
.stat b{display:block;font-size:1.3rem}
.stat span{font-size:.78rem;color:var(--mut)}
.fonti{margin:0;padding-left:18px;font-size:.9rem}.fonti li{margin:6px 0}
/* anteprima email */
.dispositivi{display:flex;justify-content:center;margin:10px 0 16px}
.sfondo-mail{background:var(--line2);border-radius:14px;padding:26px 12px;display:flex;justify-content:center}
.mail{background:#fff;color:#15171a;width:100%;max-width:640px;border-radius:10px;overflow:hidden;box-shadow:var(--sh)}
.mail.telefono{max-width:390px;border-radius:26px;border:8px solid #1c1c1c}
.mail .tes{padding:14px 22px;border-bottom:1px solid #e5e5e0;font:600 .85rem var(--sans);color:#555}
.mail .og{padding:20px 22px 4px;font:700 1.35rem/1.3 var(--serif)}
.mail .pre{padding:0 22px 12px;color:#777;font-size:.82rem}
.mail .co{padding:6px 22px 28px;font:17px/1.7 var(--serif)}
.mail .co p{margin:0 0 1em}.mail .co h2{font:700 1.3rem/1.3 var(--serif);margin:1.3em 0 .4em}
.mail .co h3{font:700 1.1rem/1.3 var(--serif);margin:1.2em 0 .4em}
.mail .co blockquote{margin:1em 0;padding:.1em 1em;border-left:4px solid #1f4fd8;color:#555}
.mail .co a{color:#1f4fd8}.mail .co img{max-width:100%}
/* finestre */
dialog{border:1px solid var(--line);border-radius:16px;background:var(--panel);color:var(--ink);padding:0;width:min(520px,92vw);box-shadow:var(--sh)}
dialog::backdrop{background:rgba(10,10,8,.55)}
dialog form,dialog .dl{padding:22px}
dialog h2{margin:0 0 10px;font:700 1.25rem/1.3 var(--serif)}
dialog input[type=text],dialog input[type=url]{width:100%;padding:10px;border:1px solid var(--line);border-radius:9px;background:var(--bg);margin:4px 0 12px}
dialog .az{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
.lista-inv{list-style:none;margin:10px 0;padding:0;display:grid;gap:8px}
.lista-inv li{display:flex;gap:10px;align-items:flex-start}
.toast{position:fixed;left:50%;bottom:22px;transform:translate(-50%,20px);opacity:0;pointer-events:none;background:var(--ink);color:var(--bg);padding:11px 18px;border-radius:10px;max-width:92vw;transition:.2s;z-index:60}
.toast.show{opacity:1;transform:translate(-50%,0)}
.toast.err{background:var(--bad);color:#fff}.toast.ok{background:var(--ok);color:#fff}
#ed>:first-child{margin-top:0}
@media (max-width:640px){
 .top{position:static;padding:10px 12px}
 .top .dx{width:100%}
 .top .dx .btn{flex:1 1 auto;justify-content:center}
 .tb{top:0;flex-wrap:nowrap;overflow-x:auto;border-radius:0;margin:0 -16px;border-left:0;border-right:0}
 .tb button,.tb select{flex:none}
 #titolo{font-size:1.5rem}
 #ed{font-size:18px}
}
[hidden]{display:none!important}
`;

function parole(html) {
  const t = String(html || '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim();
  return t ? t.split(/\s+/).length : 0;
}

async function listPage(env) {
  const drafts = await listDrafts(env);
  const rows = drafts.length
    ? drafts.map((d) => {
        const tested = !!d.testedHash;
        return '<a class="voce" href="' + BASE + '/d/' + esc(d.id) + '"><h2>' + esc(d.title) + '</h2><div class="riga">' +
          '<span class="chip ' + (tested ? 'ok' : 'warn') + '">' + (tested ? 'Prova inviata' : 'Prova da inviare') + '</span>' +
          '<span>' + parole(d.html) + ' parole</span><span>Aggiornata il ' +
          esc(new Date(d.updated || Date.now()).toLocaleString('it-IT', { timeZone: 'Europe/Rome', day: '2-digit', month: 'long', hour: '2-digit', minute: '2-digit' })) +
          '</span></div></a>';
      }).join('')
    : '<div class="vuoto">Nessuna bozza in attesa. Quando ne preparo una, la trovi qui.</div>';
  return htmlPage(
    'Anteprime newsletter',
    '<div class="wrap"><h1>Anteprime newsletter</h1><div class="mut">Pagina privata: non indicizzata, protetta da accesso. Le bozze si cancellano dopo l’invio.</div>' +
    '<div class="lista">' + rows + '</div>' +
    '<div class="pannello"><h3>Collegamento con Ghost</h3><div class="mut">Controlla se Ghost risponde e quale newsletter viene usata per gli invii.</div>' +
    '<p><button class="btn" id="chk">Controlla ora</button></p><div class="ghost-ris" id="out"></div></div></div>',
    String.raw`
document.getElementById('chk').onclick=function(){
 var o=document.getElementById('out');o.textContent='Controllo in corso…';
 fetch('/anteprime/api/ghost-check').then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j}})}).then(function(r){
  o.textContent='';
  function riga(t,c){var d=document.createElement('div');if(c){var s=document.createElement('span');s.className='chip '+c;s.textContent=t;d.appendChild(s)}else d.textContent=t;o.appendChild(d)}
  if(!r.ok){riga(r.j.error||'Errore','bad');return}
  riga('Ghost risponde','ok');
  r.j.newsletters.forEach(function(n){riga(n.name+(n.usata_per_gli_invii?' — usata per gli invii':'')+' ('+n.status+')',n.usata_per_gli_invii?'ok':'')});
  riga(r.j.test_email_configurata?'Email di prova configurata':'Email di prova non configurata',r.j.test_email_configurata?'ok':'warn');
  riga(r.j.invio_a_tutti_attivo?'Invio a tutti attivo':'Invio a tutti spento (si attiva dopo la prova)',r.j.invio_a_tutti_attivo?'warn':'ok');
 }).catch(function(e){o.textContent=String(e)})};`
  );
}

async function editorPage(env, id) {
  const d = await getDraft(env, id);
  if (!d) throw new HttpError(404, 'Bozza non trovata');
  const tested = !!d.testedHash && d.testedHash === (await hashDraft(d));
  const data = JSON.stringify({
    id: d.id,
    title: d.title,
    summary: d.summary || '',
    html: d.html,
    tested,
    sources: d.sources || [],
    newsletter: String(env.NEWSLETTER_NAME || 'Fufflix - La Newsletter'),
    provaPronta: !!env.EMAIL_PROVA,
    invioAttivo: String(env.ALLOW_SEND || '') === 'yes',
    ghostPronto: !!env.CHIAVE_GHOST,
  }).replace(/</g, '\\u003c');
  const body = `
<header class="top">
 <div class="sx"><a href="${BASE}">← Anteprime</a><span class="stato" id="stato" role="status">Tutto salvato</span>
  <span class="chip" id="chip-ghost"></span><span class="chip" id="chip-prova"></span></div>
 <div class="dx">
  <div class="seg" role="group" aria-label="Vista"><button id="v-mod" class="on" type="button">Modifica</button><button id="v-ant" type="button">Anteprima email</button></div>
  <button class="btn" id="b-salva" type="button">Salva</button>
  <button class="btn pri" id="b-prova" type="button">Invia prova a me</button>
  <button class="btn dan" id="b-invia" type="button">Invia a tutti…</button>
 </div>
</header>
<div class="layout">
 <main>
  <section id="vista-mod">
   <div class="campo"><label for="titolo">Titolo (è anche l’oggetto dell’email)</label>
   <textarea id="titolo" rows="1" maxlength="200" spellcheck="true"></textarea></div>
   <div class="conta"><span id="c-titolo"></span></div>
   <div class="tb" id="tb" role="toolbar" aria-label="Formattazione">
    <button type="button" data-c="annulla" title="Annulla (Ctrl+Z)">Annulla</button>
    <button type="button" data-c="ripeti" title="Ripeti (Ctrl+Maiusc+Z)">Ripeti</button><span class="sep"></span>
    <select id="blocco" aria-label="Stile del paragrafo"><option value="p">Paragrafo</option><option value="h2">Titoletto grande</option><option value="h3">Titoletto piccolo</option></select><span class="sep"></span>
    <button type="button" data-c="grassetto" title="Grassetto (Ctrl+B)"><b>Grassetto</b></button>
    <button type="button" data-c="corsivo" title="Corsivo (Ctrl+I)"><i>Corsivo</i></button><span class="sep"></span>
    <button type="button" data-c="link" title="Inserisci link (Ctrl+K)">Link</button>
    <button type="button" data-c="toglilink" title="Togli il link">Togli link</button><span class="sep"></span>
    <button type="button" data-c="elenco" title="Elenco puntato">Elenco</button>
    <button type="button" data-c="numerato" title="Elenco numerato">Numerato</button>
    <button type="button" data-c="citazione" title="Citazione">Citazione</button>
    <button type="button" data-c="linea" title="Linea di separazione">Linea</button>
    <button type="button" data-c="immagine" title="Inserisci immagine da indirizzo">Immagine</button><span class="sep"></span>
    <button type="button" data-c="pulisci" title="Togli la formattazione">Pulisci</button>
   </div>
   <div class="foglio"><article id="ed" contenteditable="true" spellcheck="true" aria-label="Testo della newsletter"></article></div>
  </section>
  <section id="vista-ant" hidden>
   <div class="dispositivi"><div class="seg" role="group" aria-label="Dispositivo"><button id="d-tel" class="on" type="button">Telefono</button><button id="d-pc" type="button">Computer</button></div></div>
   <div class="sfondo-mail"><div class="mail telefono" id="mail">
    <div class="tes" id="m-newsletter"></div><div class="og" id="m-oggetto"></div><div class="pre" id="m-pre"></div><div class="co" id="m-corpo"></div></div></div>
  </section>
 </main>
 <aside>
  <div class="card"><h3>Riassunto breve</h3><textarea id="riassunto" maxlength="300" placeholder="Due righe che spiegano di cosa parla la newsletter."></textarea><div class="conta"><span id="c-riass"></span></div></div>
  <div class="card"><h3>Controlli prima dell’invio</h3><ul class="contr" id="contr"></ul></div>
  <div class="card"><h3>Il testo in numeri</h3><div class="stat"><div><b id="s-par">0</b><span>parole</span></div><div><b id="s-let">0</b><span>minuti di lettura</span></div></div></div>
  <div class="card"><h3>Fonti controllate</h3><ol class="fonti" id="fonti"></ol><p class="mut" style="font-size:.8rem;margin:10px 0 0">Servono a te per verificare: non vengono inviate agli iscritti.</p></div>
 </aside>
</div>
<dialog id="dl-link"><form method="dialog" class="dl"><h2>Inserisci un link</h2><label for="l-url" class="mut">Indirizzo (https://… oppure mailto:…)</label><input type="url" id="l-url" placeholder="https://"><div class="az"><button class="btn" value="annulla" id="l-no" type="button">Annulla</button><button class="btn pri" id="l-ok" type="button">Applica</button></div></form></dialog>
<dialog id="dl-img"><form method="dialog" class="dl"><h2>Inserisci un’immagine</h2><label for="i-url" class="mut">Indirizzo dell’immagine (solo https://)</label><input type="url" id="i-url" placeholder="https://"><label for="i-alt" class="mut">Descrizione per chi non la vede</label><input type="text" id="i-alt"><div class="az"><button class="btn" id="i-no" type="button">Annulla</button><button class="btn pri" id="i-ok" type="button">Inserisci</button></div></form></dialog>
<dialog id="dl-invio"><div class="dl"><h2>Invia a tutti gli iscritti</h2><div class="mut" id="inv-dest"></div><ul class="lista-inv" id="inv-lista"></ul>
 <label for="inv-conf" class="mut">Per confermare scrivi INVIA (non si può annullare)</label><input type="text" id="inv-conf" autocomplete="off">
 <div class="az"><button class="btn" id="inv-no" type="button">Annulla</button><button class="btn dan pieno" id="inv-ok" type="button" disabled>Invia ora</button></div></div></dialog>
<dialog id="dl-del"><div class="dl"><h2>Eliminare la bozza?</h2><div class="mut">Sparisce da questa pagina. Su Ghost non viene toccato nulla.</div><div class="az"><button class="btn" id="del-no" type="button">Annulla</button><button class="btn dan pieno" id="del-ok" type="button">Elimina</button></div></div></dialog>
<div style="max-width:1180px;margin:0 auto;padding:0 16px 40px"><button class="btn dan" id="b-elimina" type="button">Elimina bozza</button></div>
<div class="toast" id="toast" role="status"></div>
<script type="application/json" id="data">${data}</script>`;
  return htmlPage('Bozza newsletter', body, CLIENT_JS);
}

const CLIENT_JS = String.raw`
(function(){
'use strict';
var data=JSON.parse(document.getElementById('data').textContent);
var $=function(i){return document.getElementById(i)};
var ed=$('ed'),titolo=$('titolo'),riass=$('riassunto');
var sporco=false,salvando=false,timer=null,provato=!!data.tested,sel=null,ultimoSalvataggio='';
try{document.execCommand('defaultParagraphSeparator',false,'p')}catch(e){}

/* ---------- pulizia del testo (stessa lista del server) ---------- */
var CONSENTITI={P:1,H2:1,H3:1,UL:1,OL:1,LI:1,A:1,STRONG:1,EM:1,BLOCKQUOTE:1,BR:1,HR:1,IMG:1};
var MAPPA={B:'STRONG',I:'EM',H1:'H2',H4:'H3',H5:'H3',H6:'H3',DIV:'P'};
var VUOTI={BR:1,HR:1,IMG:1};
function cammina(src,dst){src.childNodes.forEach(function(n){
 if(n.nodeType===3){dst.appendChild(document.createTextNode(n.nodeValue));return}
 if(n.nodeType!==1)return;
 if(n.tagName==='SCRIPT'||n.tagName==='STYLE'||n.tagName==='IFRAME')return;
 var tag=MAPPA[n.tagName]||n.tagName;
 if(!CONSENTITI[tag]){cammina(n,dst);return}
 var el=document.createElement(tag);
 if(tag==='A'){var h=n.getAttribute('href')||'';if(/^(https:|mailto:)/i.test(h))el.setAttribute('href',h)}
 if(tag==='IMG'){var s=n.getAttribute('src')||'';if(!/^https:/i.test(s))return;el.setAttribute('src',s);el.setAttribute('alt',n.getAttribute('alt')||'')}
 if(!VUOTI[tag])cammina(n,el);
 dst.appendChild(el)})}
function pulisci(html){var doc=new DOMParser().parseFromString('<body>'+html+'</body>','text/html');var out=document.createElement('div');cammina(doc.body,out);return out.innerHTML}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}

/* ---------- messaggi e stato ---------- */
function stato(t,c){var e=$('stato');e.textContent=t;e.className='stato '+(c||'')}
function ora(){return new Date().toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'})}
function avviso(t,tipo){var e=$('toast');e.textContent=t;e.className='toast show '+(tipo||'');clearTimeout(avviso.t);avviso.t=setTimeout(function(){e.className='toast'},tipo==='err'?8000:3200)}

/* ---------- chiamate al server ---------- */
function carico(){return {title:titolo.value,summary:riass.value,html:pulisci(ed.innerHTML)}}
function chiama(nome,extra){var b=carico();if(extra)for(var k in extra)b[k]=extra[k];
 return fetch('/anteprime/api/'+nome+'/'+data.id,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'anteprime'},body:JSON.stringify(b)})
 .then(function(r){return r.json().then(function(j){return {ok:r.ok,j:j}})})}
function salva(manuale){
 if(salvando)return Promise.resolve();
 salvando=true;stato('Salvataggio…','');
 return chiama('save').then(function(r){salvando=false;
  if(r.ok){sporco=false;provato=!!r.j.tested;stato('Salvato alle '+ora(),'ok');aggiorna();if(manuale)avviso('Bozza salvata','ok')}
  else{stato('Errore nel salvataggio','err');avviso(r.j.error||'Errore','err')}
 }).catch(function(e){salvando=false;stato('Nessuna connessione','err');avviso('Non riesco a salvare: '+e,'err')})}
function cambiato(){sporco=true;provato=false;stato('Modifiche non salvate','warn');clearTimeout(timer);timer=setTimeout(function(){salva(false)},4000);aggiorna()}
window.addEventListener('beforeunload',function(e){if(sporco){e.preventDefault();e.returnValue=''}});

/* ---------- barra degli strumenti ---------- */
function salvaSel(){var s=getSelection();if(s.rangeCount&&ed.contains(s.anchorNode))sel=s.getRangeAt(0).cloneRange()}
function ripristinaSel(){ed.focus();if(!sel)return;var s=getSelection();s.removeAllRanges();s.addRange(sel)}
function blocco(){try{return String(document.queryCommandValue('formatBlock')).toLowerCase().replace(/[<>]/g,'')}catch(e){return 'p'}}
function stati(){
 try{
  document.querySelectorAll('#tb button[data-c]').forEach(function(b){
   var c=b.getAttribute('data-c'),on=false;
   if(c==='grassetto')on=document.queryCommandState('bold');
   if(c==='corsivo')on=document.queryCommandState('italic');
   if(c==='elenco')on=document.queryCommandState('insertUnorderedList');
   if(c==='numerato')on=document.queryCommandState('insertOrderedList');
   if(c==='citazione')on=blocco()==='blockquote';
   b.classList.toggle('on',on)});
  var b=blocco();$('blocco').value=(b==='h2'||b==='h3')?b:'p';
 }catch(e){}}
document.addEventListener('selectionchange',function(){salvaSel();if(ed.contains(getSelection().anchorNode))stati()});
ed.addEventListener('mouseup',salvaSel);ed.addEventListener('keyup',salvaSel);
$('blocco').addEventListener('mousedown',salvaSel);$('blocco').addEventListener('focus',salvaSel);
function altezza(){var t=document.querySelector('.top');document.documentElement.style.setProperty('--alto',(getComputedStyle(t).position==='sticky'?t.offsetHeight:0)+'px')}
window.addEventListener('resize',altezza);altezza();
$('tb').addEventListener('mousedown',function(e){if(e.target.tagName!=='SELECT')e.preventDefault()});
function comando(c){
 ripristinaSel();
 if(c==='annulla')document.execCommand('undo');
 else if(c==='ripeti')document.execCommand('redo');
 else if(c==='grassetto')document.execCommand('bold');
 else if(c==='corsivo')document.execCommand('italic');
 else if(c==='elenco')document.execCommand('insertUnorderedList');
 else if(c==='numerato')document.execCommand('insertOrderedList');
 else if(c==='citazione')document.execCommand('formatBlock',false,blocco()==='blockquote'?'P':'BLOCKQUOTE');
 else if(c==='linea')document.execCommand('insertHorizontalRule');
 else if(c==='toglilink')document.execCommand('unlink');
 else if(c==='pulisci'){document.execCommand('removeFormat');document.execCommand('unlink');document.execCommand('formatBlock',false,'P')}
 else if(c==='link')apriLink();
 else if(c==='immagine')apriImmagine();
 cambiato();stati()}
document.querySelectorAll('#tb button[data-c]').forEach(function(b){b.addEventListener('click',function(){comando(b.getAttribute('data-c'))})});
$('blocco').addEventListener('change',function(){ripristinaSel();document.execCommand('formatBlock',false,$('blocco').value.toUpperCase());cambiato();stati()});
ed.addEventListener('input',cambiato);
ed.addEventListener('paste',function(e){
 e.preventDefault();var cd=e.clipboardData,h=cd.getData('text/html'),t=cd.getData('text/plain');
 var html=h?pulisci(h):('<p>'+esc(t).split(/\n{2,}/).join('</p><p>').split(/\n/).join('<br>')+'</p>');
 document.execCommand('insertHTML',false,html);cambiato()});
document.addEventListener('keydown',function(e){
 var k=(e.ctrlKey||e.metaKey)&&!e.shiftKey?e.key.toLowerCase():'';
 if(k==='s'){e.preventDefault();salva(true)}
 else if(k==='k'&&ed.contains(getSelection().anchorNode)){e.preventDefault();salvaSel();apriLink()}});

/* ---------- finestre: link e immagine ---------- */
function apriLink(){
 var a=null,n=getSelection().anchorNode;while(n&&n!==ed){if(n.nodeType===1&&n.tagName==='A'){a=n;break}n=n.parentNode}
 $('l-url').value=a?a.getAttribute('href'):'';$('dl-link').showModal();$('l-url').focus()}
$('l-no').onclick=function(){$('dl-link').close()};
$('l-ok').onclick=function(){var u=$('l-url').value.trim();
 if(!/^(https:\/\/|mailto:)/i.test(u)){avviso('Il link deve iniziare con https:// oppure mailto:','err');return}
 $('dl-link').close();ripristinaSel();document.execCommand('createLink',false,u);cambiato()};
function apriImmagine(){$('i-url').value='';$('i-alt').value='';$('dl-img').showModal();$('i-url').focus()}
$('i-no').onclick=function(){$('dl-img').close()};
$('i-ok').onclick=function(){var u=$('i-url').value.trim();
 if(!/^https:\/\//i.test(u)){avviso('L’indirizzo deve iniziare con https://','err');return}
 $('dl-img').close();ripristinaSel();document.execCommand('insertHTML',false,'<img src="'+esc(u)+'" alt="'+esc($('i-alt').value)+'">');cambiato()};

/* ---------- correzioni automatiche nello stile dell'autore ---------- */
function perNodi(fn){var w=document.createTreeWalker(ed,NodeFilter.SHOW_TEXT,null),n,l=[],tot=0;while(n=w.nextNode())l.push(n);
 l.forEach(function(x){var v=fn(x.nodeValue,x);if(v!==x.nodeValue){x.nodeValue=v;tot++}});return tot}
function sistemaVirgolette(){var prev='';
 return perNodi(function(v,nodo){if(!nodo.previousSibling)prev='';var out='';
  for(var i=0;i<v.length;i++){var c=v.charAt(i);
   if(c==='"'||c==='«'||c==='»'){out+=(c==='«'||(c==='"'&&(prev===''||/[\s(\[—]/.test(prev))))?'“':'”'}
   else if(c==="'"){out+='’'}
   else out+=c;
   prev=out.charAt(out.length-1)}
  return out})}
function togliVirgolaPrimaDiE(){return perNodi(function(v){return v.replace(/,(\s+)(ed|e)(?=\s)/g,'$1$2')})}
function sistemaEd(){return perNodi(function(v){return v.replace(/\b(ed|ad)(\s+)(?=[bcdfgjklmnpqrstvwxyz])/gi,function(m,a,sp){return a.charAt(0)+sp})})}
var CORREZIONI={virgolette:sistemaVirgolette,virgola:togliVirgolaPrimaDiE,ed:sistemaEd};

/* ---------- controlli ---------- */
var VIETATE=/(il punto è|non è un dettaglio|sembra una sfumatura|peccato che non sia vero|niente male|complimenti|un capolavoro di|roba da manuale)/gi;
function testo(){return (ed.innerText||ed.textContent||'').replace(/ /g,' ')}
function controlli(){
 var t=testo(),r=[];
 var segn=(t.match(/\[DA VERIFICARE[^\]]*\]/gi)||[]).length;
 r.push(segn?{t:'blocca',x:'Ci sono '+segn+' segnaposto [DA VERIFICARE]: vanno risolti o tolti.'}:{t:'ok',x:'Nessun segnaposto [DA VERIFICARE].'});
 r.push(data.sources.length?{t:'ok',x:data.sources.length+' fonti allegate per il controllo.'}:{t:'avviso',x:'Nessuna fonte allegata: controlla a mano prima di inviare.'});
 var nv=(t.match(/,\s+(?:ed|e)(?=\s)/g)||[]).length;
 if(nv)r.push({t:'avviso',x:nv+' virgole prima di “e”.',f:'virgola'});
 var ne=(t.match(/\b(?:ed|ad)\s+(?=[bcdfgjklmnpqrstvwxyz])/gi)||[]).length;
 if(ne)r.push({t:'avviso',x:ne+' “ed/ad” davanti a consonante.',f:'ed'});
 var nq=(t.match(/["«»']/g)||[]).length;
 if(nq)r.push({t:'avviso',x:nq+' virgolette o apostrofi dritti (nel tuo stile: “ ” e ’).',f:'virgolette'});
 var vi=[],m;VIETATE.lastIndex=0;while((m=VIETATE.exec(t)))vi.push(m[1].toLowerCase());
 if(vi.length)r.push({t:'avviso',x:'Formule da evitare: '+vi.filter(function(x,i){return vi.indexOf(x)===i}).map(function(x){return '“'+x+'”'}).join(', ')+'.'});
 if(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(t)||/(^|\s)#\w+/.test(t))r.push({t:'avviso',x:'Emoji o hashtag nel testo (non nel tuo stile).'});
 if(titolo.value.length>80)r.push({t:'avviso',x:'Titolo lungo ('+titolo.value.length+' caratteri): sul telefono si taglia.'});
 if(!riass.value.trim())r.push({t:'info',x:'Riassunto breve vuoto.'});
 r.push(provato?{t:'ok',x:'Prova inviata sul testo attuale.'}:{t:'blocca',x:'Prova da inviare sul testo attuale (obbligatoria prima dell’invio a tutti).'});
 return r}
function aggiorna(){
 var t=testo().trim(),p=t?t.split(/\s+/).length:0;
 $('s-par').textContent=p;$('s-let').textContent=Math.max(1,Math.round(p/200));
 $('c-titolo').textContent=titolo.value.length+' caratteri nel titolo';
 $('c-riass').textContent=riass.value.length+' / 300';
 var ul=$('contr');ul.textContent='';
 controlli().forEach(function(c){var li=document.createElement('li'),pt=document.createElement('span'),tx=document.createElement('span');
  pt.className='punto '+c.t;tx.textContent=c.x;
  if(c.f){var b=document.createElement('button');b.type='button';b.className='fix';b.textContent='Sistema';
   b.onclick=function(){var n=CORREZIONI[c.f]();cambiato();avviso(n?'Fatto: '+n+' parti corrette.':'Niente da correggere.','ok')};tx.appendChild(b)}
  li.appendChild(pt);li.appendChild(tx);ul.appendChild(li)});
 var cg=$('chip-ghost');cg.textContent=data.ghostPronto?'Ghost collegato':'Ghost non collegato';cg.className='chip '+(data.ghostPronto?'ok':'warn');
 var cp=$('chip-prova');cp.textContent=provato?'Prova inviata':'Prova da inviare';cp.className='chip '+(provato?'ok':'warn');
 titolo.style.height='auto';titolo.style.height=titolo.scrollHeight+'px';
 if(!$('vista-ant').hidden)anteprima()}
titolo.addEventListener('input',cambiato);riass.addEventListener('input',cambiato);
titolo.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();ed.focus()}});

/* ---------- anteprima dell'email ---------- */
function anteprima(){
 $('m-newsletter').textContent=data.newsletter;$('m-oggetto').textContent=titolo.value;$('m-pre').textContent=riass.value;
 $('m-corpo').innerHTML=pulisci(ed.innerHTML)}
function vista(v){var a=v==='ant';$('vista-mod').hidden=a;$('vista-ant').hidden=!a;
 $('v-mod').classList.toggle('on',!a);$('v-ant').classList.toggle('on',a);if(a)anteprima()}
$('v-mod').onclick=function(){vista('mod')};$('v-ant').onclick=function(){vista('ant')};
$('d-tel').onclick=function(){$('mail').className='mail telefono';$('d-tel').classList.add('on');$('d-pc').classList.remove('on')};
$('d-pc').onclick=function(){$('mail').className='mail';$('d-pc').classList.add('on');$('d-tel').classList.remove('on')};

/* ---------- salvataggio, prova, invio, eliminazione ---------- */
$('b-salva').onclick=function(){salva(true)};
$('b-prova').onclick=function(){
 if(!data.provaPronta){avviso('Email di prova non configurata (manca EMAIL_PROVA).','err');return}
 stato('Invio della prova…','');$('b-prova').disabled=true;
 chiama('test').then(function(r){$('b-prova').disabled=false;
  if(r.ok){sporco=false;provato=true;stato('Prova inviata alle '+ora(),'ok');avviso(r.j.message,'ok');aggiorna()}
  else{stato('Prova non riuscita','err');avviso(r.j.error||'Errore','err')}
 }).catch(function(e){$('b-prova').disabled=false;avviso(String(e),'err')})};
function apriInvio(){
 var c=controlli(),ul=$('inv-lista');ul.textContent='';
 var blocchi=c.filter(function(x){return x.t==='blocca'}).length;
 function voce(tipo,x){var li=document.createElement('li'),p=document.createElement('span'),s=document.createElement('span');p.className='punto '+tipo;s.textContent=x;li.appendChild(p);li.appendChild(s);ul.appendChild(li)}
 c.forEach(function(x){voce(x.t,x.x)});
 if(!data.invioAttivo){voce('blocca','L’invio a tutti è ancora spento: si attiva dopo la prova con Ghost.');blocchi++}
 if(!data.ghostPronto){voce('blocca','Ghost non è collegato (manca la chiave).');blocchi++}
 $('inv-dest').textContent='Destinatari: iscritti a «'+data.newsletter+'». Non si può annullare.';
 $('inv-conf').value='';$('inv-ok').disabled=true;$('dl-invio').dataset.blocchi=blocchi;$('dl-invio').showModal();$('inv-conf').focus()}
$('inv-conf').addEventListener('input',function(){$('inv-ok').disabled=!($('inv-conf').value==='INVIA'&&$('dl-invio').dataset.blocchi==='0')});
$('b-invia').onclick=apriInvio;$('inv-no').onclick=function(){$('dl-invio').close()};
$('inv-ok').onclick=function(){$('inv-ok').disabled=true;$('inv-ok').textContent='Invio in corso…';
 chiama('send',{confirm:$('inv-conf').value}).then(function(r){
  $('inv-ok').textContent='Invia ora';
  if(r.ok){sporco=false;$('dl-invio').close();avviso(r.j.message,'ok');setTimeout(function(){location.href='/anteprime'},2500)}
  else{$('dl-invio').close();avviso(r.j.error||'Errore','err')}
 }).catch(function(e){$('inv-ok').textContent='Invia ora';avviso(String(e),'err')})};
$('b-elimina').onclick=function(){$('dl-del').showModal()};$('del-no').onclick=function(){$('dl-del').close()};
$('del-ok').onclick=function(){chiama('delete').then(function(r){if(r.ok){sporco=false;location.href='/anteprime'}else avviso(r.j.error||'Errore','err')})};

/* ---------- avvio ---------- */
titolo.value=data.title;riass.value=data.summary;
ed.innerHTML=pulisci(data.html)||'<p><br></p>';
var fo=$('fonti');
if(!data.sources.length){var l0=document.createElement('li');l0.textContent='Nessuna fonte allegata.';fo.appendChild(l0)}
data.sources.forEach(function(s){var li=document.createElement('li');
 if(s.url){var a=document.createElement('a');a.href=s.url;a.target='_blank';a.rel='noopener noreferrer';a.textContent=s.title||s.url;li.appendChild(a)}else li.textContent=s.title;
 if(s.note){var n=document.createElement('span');n.className='mut';n.textContent=' — '+s.note;li.appendChild(n)}
 fo.appendChild(li)});
aggiorna();stato('Tutto salvato','ok');
})();
`;
