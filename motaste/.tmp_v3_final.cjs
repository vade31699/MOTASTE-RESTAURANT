// Final v3 verification: desktop nav + mobile hamburger + cart FAB intact
const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const URL = process.env.URL || 'http://127.0.0.1:8011/';

function getJson(url) {
  return new Promise((resolve, reject) => {
    http.get(url, (res) => {
      let d = '';
      res.on('data', (c) => (d += c));
      res.on('end', () => resolve(JSON.parse(d)));
    }).on('error', reject);
  });
}
function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

async function runViewport(port, profile, width, height) {
  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, `--window-size=${width},${height}`, 'about:blank'
  ], { stdio: 'ignore' });

  let ws;
  for (let i = 0; i < 40; i++) {
    try {
      const list = await getJson(`http://127.0.0.1:${port}/json`);
      const page = list.find((t) => t.type === 'page');
      if (page && page.webSocketDebuggerUrl) { ws = page.webSocketDebuggerUrl; break; }
    } catch (e) { /* retry */ }
    await sleep(250);
  }
  if (!ws) { console.error(`NO_CDP ${width}`); chrome.kill(); return null; }

  const sock = new WebSocket(ws);
  let id = 0;
  const pending = new Map();
  sock.onmessage = (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  };
  await new Promise((r) => (sock.onopen = r));
  const send = (method, params = {}) => new Promise((res) => {
    const mid = ++id;
    pending.set(mid, (m) => res(m.result || {}));
    sock.send(JSON.stringify({ id: mid, method, params }));
  });

  if (width <= 900) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: true });
  }
  await send('Page.enable');
  await send('Page.navigate', { url: URL });
  await sleep(3500);

  const evalJs = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true });
    return r.result ? r.result.value : null;
  };

  const out = {};
  if (width > 900) {
    out.nav = await evalJs(`(() => {
      const cs = getComputedStyle(document.querySelector('.top-bar'));
      return { position: cs.position, top: cs.top, bg: cs.backgroundColor };
    })()`);
    await evalJs('window.scrollTo(0, 900)');
    await sleep(500);
    out.navStuck = await evalJs(`Math.round(document.querySelector('.top-bar').getBoundingClientRect().top)`);
    out.overflowX = await evalJs(`document.documentElement.scrollWidth > document.documentElement.clientWidth`);
  } else {
    out.toggle = await evalJs(`(() => {
      const t = document.querySelector('.mobile-menu-toggle');
      const r = t.getBoundingClientRect();
      return { display: getComputedStyle(t).display, w: Math.round(r.width), h: Math.round(r.height), bg: getComputedStyle(t).backgroundColor };
    })()`);
    // Tap hamburger -> overlay opens
    await evalJs(`document.querySelector('.mobile-menu-toggle').click()`);
    await sleep(500);
    out.overlayOpen = await evalJs(`document.querySelector('.top-nav').classList.contains('open') && getComputedStyle(document.querySelector('.top-nav')).display !== 'none'`);
    out.overlayRect = await evalJs(`(() => { const r = document.querySelector('.top-nav').getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height) }; })()`);
    // Close it
    await evalJs(`document.querySelector('.mobile-menu-toggle').click()`);
    await sleep(300);
    out.overlayClosed = await evalJs(`!document.querySelector('.top-nav').classList.contains('open')`);
  }

  // Cart FAB anchored to viewport bottom-right on both
  out.cartFab = await evalJs(`(() => {
    const b = document.querySelector('#menuCartButton.menu-cart-button').getBoundingClientRect();
    const vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
    return { right: Math.round(vw - b.right), bottom: Math.round(vh - b.bottom), visible: b.width > 0 };
  })()`);

  console.log(`WIDTH ${width}:`, JSON.stringify(out));
  sock.close();
  chrome.kill();
  fs.rmSync(profile, { recursive: true, force: true });
}

async function main() {
  await runViewport(9441, path.join(os.tmpdir(), 'v3desk_' + Date.now()), 1280, 900);
  await runViewport(9442, path.join(os.tmpdir(), 'v3mob_' + Date.now()), 390, 844);
}

main().catch((e) => { console.error('ERR', e.message); process.exit(1); });
