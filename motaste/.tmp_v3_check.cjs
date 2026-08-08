// CDP verification for v3 changes: orange sticky nav, 2-col footer, crisp hero
const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const URL = process.env.URL || 'http://127.0.0.1:8011/';
const PROFILE = path.join(os.tmpdir(), 'v3profile_' + Date.now());

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

async function main() {
  const port = 9333 + Math.floor(Math.random() * 1000);
  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    `--remote-debugging-port=${port}`, `--user-data-dir=${PROFILE}`, '--window-size=390,900', 'about:blank'
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
  if (!ws) { console.error('NO_CDP'); chrome.kill(); process.exit(1); }

  const WebSocket = global.WebSocket;
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

  // Mobile emulation
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await send('Page.enable');
  await send('Page.navigate', { url: URL });
  await sleep(4000);

  const evalJs = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true });
    return r.result ? r.result.value : null;
  };

  const results = {};

  // 1) Nav: sticky at top + orange glass bg
  results.nav = await evalJs(`(() => {
    const bar = document.querySelector('.top-bar');
    const cs = getComputedStyle(bar);
    const before = getComputedStyle(bar, '::before');
    return {
      position: cs.position,
      top: cs.top,
      zIndex: cs.zIndex,
      bg: cs.backgroundColor,
      blur: before.backdropFilter || before.webkitBackdropFilter,
      beforeBg: before.backgroundImage.slice(0, 60),
      wordmarkColor: getComputedStyle(document.querySelector('.brand-wordmark')).color,
      linkColor: getComputedStyle(document.querySelector('.top-nav a')).color,
    };
  })()`);

  // 2) Scroll down 800px -> nav still at top?
  await evalJs(`window.scrollTo(0, 800)`);
  await sleep(600);
  results.navAfterScroll = await evalJs(`(() => {
    const r = document.querySelector('.top-bar').getBoundingClientRect();
    return { top: Math.round(r.top), bottom: Math.round(r.bottom) };
  })()`);

  // 3) Footer 2 columns on 390px
  results.footer = await evalJs(`(() => {
    const top = document.querySelector('.footer-top');
    const cs = getComputedStyle(top);
    const brand = document.querySelector('.footer-brand').getBoundingClientRect();
    return {
      cols: cs.gridTemplateColumns,
      brandSpan: cs.gridColumn,
      brandWidth: Math.round(brand.width),
      width: Math.round(top.getBoundingClientRect().width),
    };
  })()`);

  // 4) Hero: overlay hidden, no shadow, no blur, image crisp
  results.hero = await evalJs(`(() => {
    const overlay = document.querySelector('.hero-overlay');
    const content = document.querySelector('.hero-content');
    const cs = getComputedStyle(content);
    const bg = getComputedStyle(document.querySelector('.hero-bg'));
    return {
      overlayDisplay: getComputedStyle(overlay).display,
      cardShadow: cs.boxShadow,
      cardBlur: cs.backdropFilter || cs.webkitBackdropFilter,
      cardBg: cs.backgroundColor,
      imgObjectFit: bg.objectFit,
      imgFilter: bg.filter,
      imgPos: bg.objectPosition,
    };
  })()`);

  console.log(JSON.stringify(results, null, 2));

  sock.close();
  chrome.kill();
  fs.rmSync(PROFILE, { recursive: true, force: true });
}

main().catch((e) => { console.error('ERR', e.message); process.exit(1); });
