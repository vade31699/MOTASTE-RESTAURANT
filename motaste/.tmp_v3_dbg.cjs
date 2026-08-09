// Debug: why is desktop sticky nav not staying at top?
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

async function main() {
  const port = 9455;
  const profile = path.join(os.tmpdir(), 'v3dbg_' + Date.now());
  const chrome = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--window-size=1280,900', 'about:blank'
  ], { stdio: 'ignore' });

  let ws;
  for (let i = 0; i < 40; i++) {
    try {
      const list = await getJson(`http://127.0.0.1:${port}/json`);
      const page = list.find((t) => t.type === 'page');
      if (page && page.webSocketDebuggerUrl) { ws = page.webSocketDebuggerUrl; break; }
    } catch (e) { }
    await sleep(250);
  }
  if (!ws) { console.error('NO_CDP'); chrome.kill(); process.exit(1); }

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

  await send('Page.enable');
  await send('Page.navigate', { url: URL });
  await sleep(4000);

  const evalJs = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true });
    return r.result ? r.result.value : null;
  };

  console.log('INIT:', JSON.stringify(await evalJs(`(() => {
    const bar = document.querySelector('.top-bar');
    const cs = getComputedStyle(bar);
    const htmlCs = getComputedStyle(document.documentElement);
    const bodyCs = getComputedStyle(document.body);
    return {
      navPos: cs.position, navTop: cs.top,
      htmlOverflow: htmlCs.overflow, htmlOverflowX: htmlCs.overflowX, htmlOverflowY: htmlCs.overflowY,
      bodyOverflow: bodyCs.overflow, bodyOverflowX: bodyCs.overflowX, bodyOverflowY: bodyCs.overflowY,
      parentTag: bar.parentElement.tagName,
      parentCs: getComputedStyle(bar.parentElement).overflow,
      scrollHeight: document.documentElement.scrollHeight,
      innerH: window.innerHeight,
      htmlScrollBehavior: htmlCs.scrollBehavior,
    };
  })()`)));

  // Scroll with instant behavior to avoid smooth-scroll animation
  await evalJs(`document.documentElement.style.scrollBehavior = 'auto'; window.scrollTo(0, 900);`);
  await sleep(1200);
  console.log('SCROLL 900:', JSON.stringify(await evalJs(`(() => {
    const bar = document.querySelector('.top-bar');
    const r = bar.getBoundingClientRect();
    return { scrollY: Math.round(window.scrollY), navTop: Math.round(r.top), navBottom: Math.round(r.bottom) };
  })()`)));

  await evalJs(`window.scrollTo(0, 3000)`);
  await sleep(1200);
  console.log('SCROLL 3000:', JSON.stringify(await evalJs(`(() => {
    const bar = document.querySelector('.top-bar');
    const r = bar.getBoundingClientRect();
    return { scrollY: Math.round(window.scrollY), navTop: Math.round(r.top) };
  })()`)));

  sock.close();
  chrome.kill();
  try { fs.rmSync(profile, { recursive: true, force: true }); } catch (e) { }
}

main().catch((e) => { console.error('ERR', e.message); process.exit(1); });
