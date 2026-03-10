'use strict';

/**
 * CPA Mock Server — Admitad + EPN API emulator
 *
 * Реализует точные форматы запросов/ответов обеих сетей,
 * используемые адаптерами cashback-plugin:
 *   - class-admitad-adapter.php
 *   - class-epn-adapter.php
 *
 * Запуск: node server.js  или  docker compose up
 */

const express    = require('express');
const fs         = require('fs');
const path       = require('path');
const crypto     = require('crypto');
const { v4: uuidv4 } = require('uuid');

const app  = express();
const PORT = process.env.PORT || 3000;

// ─── Пути к файлам данных ────────────────────────────────────────────────────

const DATA_DIR          = path.join(__dirname, 'data');
const TRANSACTIONS_FILE = path.join(DATA_DIR, 'transactions.json');
const CREDENTIALS_FILE  = path.join(DATA_DIR, 'credentials.json');

// ─── Инициализация хранилища ─────────────────────────────────────────────────

function ensureDataDir() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
}

function readJSON(file, defaultValue) {
  try {
    if (fs.existsSync(file)) return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (e) {
    console.error(`[Storage] Error reading ${file}:`, e.message);
  }
  return defaultValue;
}

function writeJSON(file, data) {
  ensureDataDir();
  fs.writeFileSync(file, JSON.stringify(data, null, 2), 'utf8');
}

function loadTransactions() {
  return readJSON(TRANSACTIONS_FILE, []);
}

function saveTransactions(txs) {
  writeJSON(TRANSACTIONS_FILE, txs);
}

function loadCredentials() {
  return readJSON(CREDENTIALS_FILE, {
    admitad: { client_id: 'test_client', client_secret: 'test_secret' },
    epn:     { client_id: 'test_client', client_secret: 'test_secret' },
  });
}

function saveCredentials(creds) {
  writeJSON(CREDENTIALS_FILE, creds);
}

// ─── Токен-хранилище (in-memory) ─────────────────────────────────────────────

/** Map: token → { network, expires_at } */
const activeTokens  = new Map();
/** Map: refresh_token → { network, client_id } */
const refreshTokens = new Map();
/** Map: client_id → ssid_token (EPN) */
const ssidTokens    = new Map();

const TOKEN_LIFETIME = parseInt(process.env.MOCK_TOKEN_LIFETIME || '86400', 10);

function generateToken() {
  return crypto.randomBytes(32).toString('hex');
}

function issueTokens(network, clientId) {
  const access  = generateToken();
  const refresh = generateToken();
  activeTokens.set(access, { network, clientId, expires_at: Date.now() + TOKEN_LIFETIME * 1000 });
  refreshTokens.set(refresh, { network, clientId });
  return { access, refresh };
}

function validateBearerToken(authHeader) {
  if (!authHeader || !authHeader.startsWith('Bearer ')) return null;
  const token = authHeader.slice(7);
  const info  = activeTokens.get(token);
  if (!info) return null;
  if (Date.now() > info.expires_at) { activeTokens.delete(token); return null; }
  return info;
}

function validateEpnToken(req) {
  const token = req.headers['x-access-token'];
  if (!token) return null;
  const info  = activeTokens.get(token);
  if (!info) return null;
  if (Date.now() > info.expires_at) { activeTokens.delete(token); return null; }
  return info;
}

// ─── Лог запросов (кольцевой буфер 100 записей) ──────────────────────────────

const requestLog = [];
const MAX_LOG    = 100;

function logRequest(method, path, status, extra) {
  requestLog.unshift({ ts: new Date().toISOString(), method, path, status, ...(extra || {}) });
  if (requestLog.length > MAX_LOG) requestLog.pop();
}

// ─── Middleware ───────────────────────────────────────────────────────────────

app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static(path.join(__dirname, 'public')));

// CORS (нужен если WordPress на другом порту)
app.use((req, res, next) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,POST,PUT,DELETE,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type,Authorization,X-ACCESS-TOKEN,X-API-VERSION');
  if (req.method === 'OPTIONS') return res.sendStatus(204);
  next();
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Парсить dd.mm.YYYY → Date (полночь UTC).
 * Также поддерживает YYYY-MM-DD.
 */
function parseDate(str) {
  if (!str) return null;
  // dd.mm.YYYY
  const dmY = str.match(/^(\d{2})\.(\d{2})\.(\d{4})/);
  if (dmY) return new Date(`${dmY[3]}-${dmY[2]}-${dmY[1]}`);
  // YYYY-MM-DD or YYYY-MM-DD HH:MM:SS
  const ymd = str.match(/^(\d{4}-\d{2}-\d{2})/);
  if (ymd) return new Date(ymd[1]);
  return null;
}

/**
 * Возвращает "YYYY-MM-DD" из строки даты/времени
 */
function dateOnly(str) {
  if (!str) return '';
  return str.substring(0, 10);
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADMITAD MOCK
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * POST /admitad/token/
 *
 * Admitad OAuth2: Basic Auth + client_credentials grant.
 * Источник: class-admitad-adapter.php:72-109
 */
app.post('/admitad/token/', (req, res) => {
  const authHeader  = req.headers['authorization'] || '';
  const grantType   = req.body.grant_type || '';

  // Декодируем Basic Auth
  let clientId = '', clientSecret = '';
  if (authHeader.startsWith('Basic ')) {
    try {
      const decoded = Buffer.from(authHeader.slice(6), 'base64').toString('utf8');
      [clientId, clientSecret] = decoded.split(':');
    } catch (_) {}
  }

  const creds = loadCredentials();
  const admitad = creds.admitad || {};

  // Проверяем credentials
  if (grantType !== 'client_credentials') {
    logRequest('POST', '/admitad/token/', 400, { error: 'wrong grant_type' });
    return res.status(400).json({ error: 'unsupported_grant_type', error_description: 'Only client_credentials supported' });
  }

  if (!clientId || clientId !== admitad.client_id || clientSecret !== admitad.client_secret) {
    logRequest('POST', '/admitad/token/', 401, { error: 'invalid_credentials', clientId });
    return res.status(401).json({ error: 'invalid_client', error_description: 'Invalid client credentials' });
  }

  const { access, refresh } = issueTokens('admitad', clientId);
  logRequest('POST', '/admitad/token/', 200, { clientId });

  return res.json({
    access_token:  access,
    token_type:    'bearer',
    expires_in:    TOKEN_LIFETIME,
    refresh_token: refresh,
    scope:         req.body.scope || 'statistics',
    username:      'mock_publisher',
  });
});

/**
 * GET /admitad/statistics/actions/
 *
 * Возвращает транзакции в Admitad формате.
 * Источник: class-admitad-adapter.php:131-188
 *
 * Параметры: limit, offset, date_start, date_end, subid, subid1–subid4,
 *            website, order_by, status_updated_start, status_updated_end
 */
app.get('/admitad/statistics/actions/', (req, res) => {
  const tokenInfo = validateBearerToken(req.headers['authorization'] || '');
  if (!tokenInfo) {
    logRequest('GET', '/admitad/statistics/actions/', 401);
    return res.status(401).json({ error: 'invalid_token', error_description: 'Token expired or invalid' });
  }

  const q      = req.query;
  const limit  = Math.min(parseInt(q.limit  || '500', 10), 500);
  const offset = parseInt(q.offset || '0', 10);

  const dateStart = parseDate(q.date_start);
  const dateEnd   = parseDate(q.date_end);
  const statusUpdStart = parseDate(q.status_updated_start);
  const statusUpdEnd   = parseDate(q.status_updated_end);

  let txs = loadTransactions().filter(tx => tx.network === 'admitad');

  // Фильтрация по subid-полям
  for (const key of ['subid', 'subid1', 'subid2', 'subid3', 'subid4']) {
    if (q[key] !== undefined && q[key] !== '') {
      txs = txs.filter(tx => String(tx[key] || '') === String(q[key]));
    }
  }

  // Фильтрация по дате действия
  if (dateStart) txs = txs.filter(tx => new Date(dateOnly(tx.action_date)) >= dateStart);
  if (dateEnd)   txs = txs.filter(tx => new Date(dateOnly(tx.action_date)) <= dateEnd);

  // Фильтрация по дате обновления статуса (используем action_time)
  if (statusUpdStart) txs = txs.filter(tx => new Date(dateOnly(tx.action_time)) >= statusUpdStart);
  if (statusUpdEnd)   txs = txs.filter(tx => new Date(dateOnly(tx.action_time)) <= statusUpdEnd);

  // Фильтрация по площадке
  if (q.website) txs = txs.filter(tx => String(tx.website_id || '') === String(q.website));

  const total    = txs.length;
  const pageData = txs.slice(offset, offset + limit);

  // Маппинг во Admitad формат ответа
  const results = pageData.map(tx => ({
    action_id:         parseInt(tx.action_id) || 0,
    order_id:          tx.order_id         || '',
    cart:              String(parseFloat(tx.order_sum  || 0).toFixed(2)),
    status:            tx.status           || 'pending',
    payment:           String(parseFloat(tx.payment || 0).toFixed(2)),
    currency:          tx.currency         || 'RUB',
    website_name:      tx.website_name     || String(tx.website_id || ''),
    subid:             tx.subid1           || tx.click_id || '',
    subid1:            tx.subid1           || tx.click_id || '',
    subid2:            tx.subid2           || '',
    subid3:            tx.subid3           || '',
    subid4:            tx.subid4           || '',
    click_date:        tx.click_time       || '',
    action_time:       tx.action_time      || '',
    action_date:       dateOnly(tx.action_date) || dateOnly(tx.action_time) || '',
    advcampaign_id:    parseInt(tx.advcampaign_id) || 0,
    advcampaign_name:  tx.advcampaign_name || '',
    action_type:       tx.action_type      || 'sale',
    closing_date:      tx.closing_date     || '',
  }));

  logRequest('GET', '/admitad/statistics/actions/', 200, {
    filters: { subid1: q.subid1, subid2: q.subid2, date_start: q.date_start, date_end: q.date_end },
    returned: results.length, total,
  });

  return res.json({
    results,
    _meta: { count: total, limit, offset },
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// EPN MOCK
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * GET /epn/ssid
 *
 * Шаг 1 EPN OAuth2: получить SSID-токен.
 * Источник: class-epn-adapter.php:77-112
 *
 * В моке нет rate limiting (1 запрос/сутки) — возвращает всегда.
 */
app.get('/epn/ssid', (req, res) => {
  const clientId = req.query.client_id;
  if (!clientId) {
    logRequest('GET', '/epn/ssid', 400);
    return res.status(400).json({
      errors: [{ error: 400018, error_description: 'Required client id' }],
      result: false, request: [],
    });
  }

  const ssidToken = crypto.randomBytes(16).toString('hex');
  ssidTokens.set(clientId, ssidToken);

  logRequest('GET', '/epn/ssid', 200, { clientId });

  return res.json({
    data:    { type: 'ssid', attributes: { ssid_token: ssidToken } },
    result:  true,
    request: [],
  });
});

/**
 * POST /epn/token
 *
 * Шаг 2 EPN OAuth2: обменять SSID + credentials на access_token.
 * Источник: class-epn-adapter.php:114-168
 *
 * Body JSON: { grant_type, client_id, client_secret, ssid_token, check_ip }
 */
app.post('/epn/token', (req, res) => {
  const { grant_type, client_id, client_secret, ssid_token } = req.body || {};

  if (grant_type !== 'client_credential') {
    logRequest('POST', '/epn/token', 400);
    return res.status(400).json({
      errors: [{ error: 400005, error_description: 'Required grand type' }],
      result: false, request: [],
    });
  }

  const creds = loadCredentials();
  const epn   = creds.epn || {};

  if (!client_id || client_id !== epn.client_id || client_secret !== epn.client_secret) {
    logRequest('POST', '/epn/token', 400, { error: 'wrong credentials', client_id });
    return res.status(400).json({
      errors: [{ error: 400012, error_description: 'Wrong username or password' }],
      result: false, request: [],
    });
  }

  // В моке принимаем любой ssid_token (если есть)
  if (!ssid_token) {
    logRequest('POST', '/epn/token', 400, { error: 'missing ssid_token' });
    return res.status(400).json({
      errors: [{ error: 400017, error_description: 'Invalid ssid token' }],
      result: false, request: [],
    });
  }

  const { access, refresh } = issueTokens('epn', client_id);
  logRequest('POST', '/epn/token', 200, { client_id });

  return res.json({
    data: {
      type: 'token',
      id:   '',
      attributes: {
        access_token:  access,
        token_type:    'jwt',
        refresh_token: refresh,
        expires_in:    TOKEN_LIFETIME,
        isAuth:        true,
      },
    },
    result:  true,
    request: {},
  });
});

/**
 * POST /epn/token/refresh
 *
 * Обновить EPN access_token через refresh_token.
 * Источник: class-epn-adapter.php:181-219
 *
 * Body JSON: { grant_type: 'refresh_token', refresh_token, client_id }
 */
app.post('/epn/token/refresh', (req, res) => {
  const { grant_type, refresh_token, client_id } = req.body || {};

  if (grant_type !== 'refresh_token') {
    logRequest('POST', '/epn/token/refresh', 400);
    return res.status(400).json({
      errors: [{ error: 400005, error_description: 'Required grand type' }],
      result: false, request: [],
    });
  }

  const info = refreshTokens.get(refresh_token);
  if (!info) {
    logRequest('POST', '/epn/token/refresh', 400, { error: 'invalid refresh_token' });
    return res.status(400).json({
      errors: [{ error: 400013, error_description: 'Wrong refresh token' }],
      result: false, request: [],
    });
  }

  // Инвалидируем старый refresh_token
  refreshTokens.delete(refresh_token);

  const { access, refresh: newRefresh } = issueTokens('epn', info.clientId);
  logRequest('POST', '/epn/token/refresh', 200, { client_id });

  return res.json({
    data: {
      type: 'token',
      id:   '',
      attributes: {
        access_token:  access,
        token_type:    'jwt',
        refresh_token: newRefresh,
        expires_in:    TOKEN_LIFETIME,
        isAuth:        true,
      },
    },
    result:  true,
    request: {},
  });
});

/**
 * GET /epn/transactions/user
 *
 * Список транзакций в EPN nested формате.
 * Источник: class-epn-adapter.php:245-388
 *
 * Query: page, perPage, tsFrom, tsTo, fields, clickId, sub, currency
 * Auth:  X-ACCESS-TOKEN header
 *
 * Ответ нормализуется адаптером через normalize_action():
 *   attrs.commission_user → payment
 *   attrs.revenue         → cart
 *   attrs.order_status    → status
 *   attrs.sub1            → subid1 / click_id (field настраивается)
 *   attrs.sub2            → subid2 / user_id
 */
app.get('/epn/transactions/user', (req, res) => {
  const tokenInfo = validateEpnToken(req);
  if (!tokenInfo) {
    logRequest('GET', '/epn/transactions/user', 401);
    return res.status(401).json({
      errors: [{ error: 401002, error_description: 'Unauthorized invalid token' }],
      result: false, request: [],
    });
  }

  const q       = req.query;
  const page    = Math.max(1, parseInt(q.page    || '1',   10));
  const perPage = Math.min(parseInt(q.perPage || '500', 10), 1000);
  const offset  = (page - 1) * perPage;

  const tsFrom = q.tsFrom ? new Date(q.tsFrom) : null;
  const tsTo   = q.tsTo   ? new Date(q.tsTo)   : null;

  let txs = loadTransactions().filter(tx => tx.network === 'epn');

  // Фильтрация по дате (tsFrom/tsTo — YYYY-MM-DD)
  if (tsFrom) txs = txs.filter(tx => new Date(dateOnly(tx.action_date)) >= tsFrom);
  if (tsTo)   txs = txs.filter(tx => new Date(dateOnly(tx.action_date)) <= tsTo);

  // Фильтрация по clickId (click_id или sub1)
  if (q.clickId) {
    txs = txs.filter(tx => tx.click_id === q.clickId || tx.subid1 === q.clickId);
  }

  // Фильтрация по sub (sub1/subid2)
  if (q.sub) {
    txs = txs.filter(tx => tx.subid1 === q.sub || tx.subid2 === q.sub);
  }

  const total   = txs.length;
  const hasNext = offset + perPage < total;
  const pageData = txs.slice(offset, offset + perPage);

  // Формируем EPN-ответ: data[].{type, id, attributes}
  // Поля соответствуют normalize_action() в class-epn-adapter.php
  const data = pageData.map((tx, idx) => ({
    type: 'transaction',
    id:   tx.action_id || String(idx + 1),
    attributes: {
      order_number:      tx.order_id          || '',
      order_time:        tx.action_time        || tx.action_date || '',
      order_status:      tx.status            || 'pending',
      transaction_time:  tx.click_time        || tx.action_time || '',
      revenue:           parseFloat(tx.order_sum || 0),   // cart (сумма заказа)
      commission_user:   parseFloat(tx.payment   || 0),   // payment (комиссия)
      creative_title:    tx.advcampaign_name   || '',
      sub_title:         '',
      user_click_id:     tx.click_id           || tx.subid1 || '',
      offer_type:        tx.action_type        || 'sale',
      offer_id:          String(tx.advcampaign_id || ''),
      currency:          tx.currency           || 'RUB',
      transactionId:     tx.action_id          || '',
      click_id:          tx.click_id           || tx.subid1 || '',
      sub1:              tx.subid1             || tx.click_id || '',
      sub2:              tx.subid2             || '',
      sub3:              tx.subid3             || '',
      sub4:              tx.subid4             || '',
      sub5:              tx.subid5             || '',
      action_id:         tx.action_id          || '',
      country_code:      tx.country_code       || 'RU',
      type_id:           '1',
    },
  }));

  logRequest('GET', '/epn/transactions/user', 200, {
    filters: { tsFrom: q.tsFrom, tsTo: q.tsTo, clickId: q.clickId },
    returned: data.length, total,
  });

  return res.json({
    data,
    meta:    { totalFound: total, hasNext },
    result:  true,
    request: [],
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// WEB UI MANAGEMENT API
// ═══════════════════════════════════════════════════════════════════════════════

/** GET /api/status */
app.get('/api/status', (req, res) => {
  const txs   = loadTransactions();
  const creds = loadCredentials();
  res.json({
    status: 'ok',
    transactions: {
      total:   txs.length,
      admitad: txs.filter(t => t.network === 'admitad').length,
      epn:     txs.filter(t => t.network === 'epn').length,
    },
    credentials: {
      admitad: { client_id: creds.admitad?.client_id || '', configured: !!creds.admitad?.client_id },
      epn:     { client_id: creds.epn?.client_id     || '', configured: !!creds.epn?.client_id },
    },
    active_tokens: activeTokens.size,
    uptime_seconds: Math.round(process.uptime()),
  });
});

/** GET /api/logs */
app.get('/api/logs', (req, res) => {
  res.json(requestLog);
});

/** GET /api/transactions */
app.get('/api/transactions', (req, res) => {
  let txs = loadTransactions();
  if (req.query.network) txs = txs.filter(t => t.network === req.query.network);
  res.json(txs);
});

/** POST /api/transactions — добавить одну или массив транзакций */
app.post('/api/transactions', (req, res) => {
  const body = req.body;
  const items = Array.isArray(body) ? body : [body];

  const txs = loadTransactions();
  const added = [];

  for (const item of items) {
    if (!item.network || !['admitad', 'epn'].includes(item.network)) {
      return res.status(400).json({ error: 'network must be "admitad" or "epn"' });
    }

    const tx = {
      id:              uuidv4(),
      network:         item.network,
      action_id:       item.action_id       || String(Date.now()),
      order_id:        item.order_id        || '',
      order_sum:       parseFloat(item.order_sum  || item.cart || 0),
      payment:         parseFloat(item.payment    || item.commission || 0),
      currency:        item.currency        || 'RUB',
      status:          item.status          || 'pending',
      click_id:        item.click_id        || item.subid1 || '',
      subid1:          item.subid1          || item.click_id || '',
      subid2:          item.subid2          || '',
      subid3:          item.subid3          || '',
      subid4:          item.subid4          || '',
      subid5:          item.subid5          || '',
      action_date:     item.action_date     || new Date().toISOString().slice(0, 10),
      action_time:     item.action_time     || new Date().toISOString().slice(0, 19).replace('T', ' '),
      click_time:      item.click_time      || '',
      advcampaign_id:  parseInt(item.advcampaign_id  || item.offer_id || 0),
      advcampaign_name: item.advcampaign_name || item.campaign_name || '',
      website_id:      item.website_id      || '',
      action_type:     item.action_type     || 'sale',
      country_code:    item.country_code    || 'RU',
      closing_date:    item.closing_date    || '',
      created_at:      new Date().toISOString(),
    };

    txs.push(tx);
    added.push(tx);
  }

  saveTransactions(txs);
  res.status(201).json({ added: added.length, transactions: added });
});

/** PUT /api/transactions/:id */
app.put('/api/transactions/:id', (req, res) => {
  const txs = loadTransactions();
  const idx = txs.findIndex(t => t.id === req.params.id);
  if (idx === -1) return res.status(404).json({ error: 'Not found' });

  const updated = { ...txs[idx], ...req.body, id: txs[idx].id, created_at: txs[idx].created_at };
  txs[idx] = updated;
  saveTransactions(txs);
  res.json(updated);
});

/** DELETE /api/transactions/:id */
app.delete('/api/transactions/:id', (req, res) => {
  const txs = loadTransactions();
  const filtered = txs.filter(t => t.id !== req.params.id);
  if (filtered.length === txs.length) return res.status(404).json({ error: 'Not found' });
  saveTransactions(filtered);
  res.json({ deleted: true });
});

/** POST /api/transactions/clear */
app.post('/api/transactions/clear', (req, res) => {
  const { network } = req.body || {};
  if (network) {
    const txs = loadTransactions().filter(t => t.network !== network);
    saveTransactions(txs);
    res.json({ cleared: true, network });
  } else {
    saveTransactions([]);
    res.json({ cleared: true, network: 'all' });
  }
});

// ═══════════════════════════════════════════════════════════════════════════════
// HOOK FILE IMPORT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Маппинг EPN-native → Admitad-native статусов.
 *
 * Hook-файлы используют EPN-формат статусов.
 * При импорте для Admitad нужно конвертировать в родные Admitad-статусы,
 * чтобы плагин корректно применял свой status_map.
 *
 * EPN native:     pending, approved, rejected, waiting, completed
 * Admitad native: pending, approved, declined, approved_but_stalled
 */
const EPN_TO_ADMITAD = {
  pending:   'pending',
  approved:  'approved',
  completed: 'approved',
  rejected:  'declined',
  waiting:   'approved_but_stalled',
};

/**
 * Разобрать одну строку hook-файла в объект транзакции.
 * Строка формат: key=val&key=val&...
 */
function parseHookLine(line, network) {
  const params = {};
  for (const part of line.split('&')) {
    const eq = part.indexOf('=');
    if (eq === -1) continue;
    const key = part.slice(0, eq).trim();
    try {
      params[key] = decodeURIComponent(part.slice(eq + 1).replace(/\+/g, ' '));
    } catch (_) {
      params[key] = part.slice(eq + 1);
    }
  }

  if (!params.click_id) return null;

  const rawStatus = (params.order_status || 'pending').toLowerCase();
  const status = (network === 'admitad')
    ? (EPN_TO_ADMITAD[rawStatus] || 'pending')
    : rawStatus; // EPN: оставляем нативный статус

  return {
    id:               uuidv4(),
    network,
    action_id:        params.uniq_id         || String(Date.now()),
    order_id:         params.order_number    || '',
    order_sum:        parseFloat(params.sum_order  || 0),
    payment:          parseFloat(params.comission  || 0), // одна 's' в исходнике
    currency:         params.currency        || 'RUB',
    status,
    click_id:         params.click_id,
    subid1:           params.click_id,                    // ключ матчинга
    subid2:           params.user_id         || '',
    action_date:      (params.action_date    || '').slice(0, 10),
    action_time:      params.action_date     || '',
    click_time:       params.click_time      || '',
    advcampaign_id:   parseInt(params.offer_id || 0),
    advcampaign_name: params.offer_name      || '',
    website_id:       params.website_id      || '',
    action_type:      params.action_type     || 'sale',
    created_at:       new Date().toISOString(),
  };
}

/**
 * POST /api/preview/hook
 * Разобрать hook-текст без сохранения — для предпросмотра в UI.
 * Body: { text: "...", network: "admitad|epn" }
 */
app.post('/api/preview/hook', (req, res) => {
  const { text, network } = req.body || {};
  if (!text) return res.status(400).json({ error: 'text required' });
  if (!network || !['admitad', 'epn'].includes(network)) {
    return res.status(400).json({ error: 'network must be "admitad" or "epn"' });
  }

  const lines   = text.split('\n').map(l => l.trim()).filter(Boolean);
  const parsed  = [];
  const skipped = [];

  for (const line of lines) {
    const tx = parseHookLine(line, network);
    if (tx) parsed.push(tx);
    else skipped.push(line.slice(0, 60));
  }

  res.json({ found: parsed.length, skipped: skipped.length, transactions: parsed });
});

/**
 * POST /api/import/hook
 * Разобрать hook-текст и сохранить транзакции.
 * Body: { text: "...", network: "admitad|epn" }
 */
app.post('/api/import/hook', (req, res) => {
  const { text, network } = req.body || {};
  if (!text) return res.status(400).json({ error: 'text required' });
  if (!network || !['admitad', 'epn'].includes(network)) {
    return res.status(400).json({ error: 'network must be "admitad" or "epn"' });
  }

  const lines   = text.split('\n').map(l => l.trim()).filter(Boolean);
  const txs     = loadTransactions();
  const added   = [];
  let skipped   = 0;

  for (const line of lines) {
    const tx = parseHookLine(line, network);
    if (tx) { txs.push(tx); added.push(tx); }
    else skipped++;
  }

  saveTransactions(txs);
  logRequest('POST', '/api/import/hook', 200, { network, added: added.length, skipped });

  res.status(201).json({ added: added.length, skipped, transactions: added });
});

/** GET /api/credentials */
app.get('/api/credentials', (req, res) => {
  const creds = loadCredentials();
  // Не возвращаем client_secret в открытом виде
  res.json({
    admitad: { client_id: creds.admitad?.client_id || '', has_secret: !!creds.admitad?.client_secret },
    epn:     { client_id: creds.epn?.client_id     || '', has_secret: !!creds.epn?.client_secret },
  });
});

/** POST /api/credentials */
app.post('/api/credentials', (req, res) => {
  const { network, client_id, client_secret } = req.body || {};
  if (!network || !['admitad', 'epn'].includes(network)) {
    return res.status(400).json({ error: 'network must be "admitad" or "epn"' });
  }
  if (!client_id || !client_secret) {
    return res.status(400).json({ error: 'client_id and client_secret required' });
  }

  const creds = loadCredentials();
  creds[network] = { client_id, client_secret };
  saveCredentials(creds);

  // Инвалидируем токены этой сети
  for (const [token, info] of activeTokens) {
    if (info.network === network) activeTokens.delete(token);
  }

  res.json({ updated: true, network, client_id });
});

/** POST /api/tokens/clear — сброс всех токенов */
app.post('/api/tokens/clear', (req, res) => {
  activeTokens.clear();
  refreshTokens.clear();
  ssidTokens.clear();
  res.json({ cleared: true });
});

// ─── 404 для неизвестных API путей ───────────────────────────────────────────

app.use((req, res) => {
  logRequest(req.method, req.path, 404);
  res.status(404).json({ error: 'Not found', path: req.path });
});

// ─── Запуск ───────────────────────────────────────────────────────────────────

ensureDataDir();

app.listen(PORT, () => {
  console.log(`\n  CPA Mock Server running at http://localhost:${PORT}`);
  console.log(`  Web UI:        http://localhost:${PORT}/`);
  console.log(`  Status:        http://localhost:${PORT}/api/status`);
  console.log(`\n  Admitad token: POST http://localhost:${PORT}/admitad/token/`);
  console.log(`  Admitad actions: GET http://localhost:${PORT}/admitad/statistics/actions/`);
  console.log(`\n  EPN SSID:      GET  http://localhost:${PORT}/epn/ssid`);
  console.log(`  EPN token:     POST http://localhost:${PORT}/epn/token`);
  console.log(`  EPN actions:   GET  http://localhost:${PORT}/epn/transactions/user`);
  console.log('');
});
