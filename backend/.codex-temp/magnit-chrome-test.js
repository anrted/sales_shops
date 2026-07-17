const fs = require('fs');
const path = require('path');
const os = require('os');
const { spawn } = require('child_process');

const envPath = 'C:/OSPanel/home/xeber.loc/discounts.loc/backend/.env';
const chromePath = 'C:/Program Files/Google/Chrome/Application/chrome.exe';

function loadEnv(file) {
  const env = {};
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    if (!line || line.startsWith('#')) continue;
    const idx = line.indexOf('=');
    if (idx === -1) continue;
    let key = line.slice(0, idx);
    let value = line.slice(idx + 1);
    value = value.replace(/^"|"$/g, '');
    env[key] = value;
  }
  return env;
}

function parseCookies(header) {
  return header.split(/;\s*/).map(part => {
    const idx = part.indexOf('=');
    if (idx === -1) return null;
    return { name: part.slice(0, idx), value: part.slice(idx + 1) };
  }).filter(Boolean);
}

async function delay(ms) { return new Promise(r => setTimeout(r, ms)); }

async function getJson(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error('HTTP ' + res.status + ' for ' + url);
  return res.json();
}

async function waitForWsUrl(port, attempts = 50) {
  for (let i = 0; i < attempts; i++) {
    try {
      const info = await getJson(`http://127.0.0.1:${port}/json/version`);
      if (info.webSocketDebuggerUrl) return info.webSocketDebuggerUrl;
    } catch {}
    await delay(200);
  }
  throw new Error('DevTools websocket URL not available');
}

async function main() {
  const env = loadEnv(envPath);
  const port = 9227;
  const userDataDir = path.join(os.tmpdir(), 'magnit-chrome-' + Date.now());
  fs.mkdirSync(userDataDir, { recursive: true });

  const chrome = spawn(chromePath, [
    '--headless=new',
    '--disable-gpu',
    '--remote-debugging-port=' + port,
    '--user-data-dir=' + userDataDir,
    '--no-first-run',
    '--no-default-browser-check',
    'about:blank'
  ], { stdio: ['ignore', 'pipe', 'pipe'] });

  let stderr = '';
  chrome.stderr.on('data', chunk => { stderr += chunk.toString(); });

  try {
    const wsUrl = await waitForWsUrl(port);
    const ws = new WebSocket(wsUrl);
    let msgId = 0;
    const pending = new Map();
    let loadResolve = null;

    ws.onmessage = (event) => {
      const msg = JSON.parse(event.data);
      if (msg.id && pending.has(msg.id)) {
        const { resolve, reject } = pending.get(msg.id);
        pending.delete(msg.id);
        if (msg.error) reject(new Error(msg.error.message));
        else resolve(msg.result);
        return;
      }
      if (msg.method === 'Page.loadEventFired' && loadResolve) {
        loadResolve();
        loadResolve = null;
      }
    };

    await new Promise((resolve, reject) => {
      ws.onopen = resolve;
      ws.onerror = reject;
    });

    const send = (method, params = {}) => new Promise((resolve, reject) => {
      const id = ++msgId;
      pending.set(id, { resolve, reject });
      ws.send(JSON.stringify({ id, method, params }));
    });

    await send('Network.enable');
    await send('Page.enable');

    const cookies = parseCookies(env.MAGNIT_RAW_COOKIE_HEADER || '').map(cookie => ({
      ...cookie,
      domain: 'magnit.ru',
      path: '/',
      secure: true,
      httpOnly: false,
      sameSite: 'None',
      url: 'https://magnit.ru/'
    }));

    if (cookies.length) {
      await send('Network.setCookies', { cookies });
    }

    const loadPromise = new Promise(resolve => { loadResolve = resolve; });
    await send('Page.navigate', { url: 'https://magnit.ru/catalog?shopCode=230972' });
    await Promise.race([loadPromise, delay(15000)]);

    const payload = {
      categories: [47161],
      includeAdultGoods: true,
      pagination: { limit: 1, offset: 0 },
      sort: { order: 'desc', type: 'discount' },
      storeCode: '230972',
      storeType: '1',
      catalogType: '1'
    };

    const expression = `fetch('https://magnit.ru/webgate/v2/goods/search', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'accept': 'application/json',
        'accept-language': ${JSON.stringify(env.MAGNIT_ACCEPT_LANGUAGE || 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7')},
        'content-type': 'application/json',
        'origin': 'https://magnit.ru',
        'x-device-id': ${JSON.stringify(env.MAGNIT_DEVICE_ID || '')},
        ${env.MAGNIT_BAGGAGE ? `'baggage': ${JSON.stringify(env.MAGNIT_BAGGAGE)},` : ''}
      },
      body: ${JSON.stringify(JSON.stringify(payload))}
    }).then(async r => ({status: r.status, text: await r.text()})).catch(e => ({error: String(e)}))`;

    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    console.log(JSON.stringify(result.result.value));
    ws.close();
  } finally {
    chrome.kill('SIGTERM');
    await delay(1000);
    try { fs.rmSync(userDataDir, { recursive: true, force: true }); } catch {}
    if (stderr.trim()) console.error(stderr.trim());
  }
}

main().catch(err => { console.error(err.stack || err.message); process.exit(1); });
