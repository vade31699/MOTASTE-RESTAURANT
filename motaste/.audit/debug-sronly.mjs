// Scratch debug: what does the browser actually compute for .sr-only in each variant?
import fs from 'fs';
import { execFileSync } from 'child_process';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = process.cwd();

const PROBE = `
for (const el of document.querySelectorAll('[hidden]')) el.removeAttribute('hidden');
const out = [];
for (const span of document.querySelectorAll('.sr-only, span')) {
  if (!/Select log category|Choose log date/.test(span.textContent)) continue;
  const cs = getComputedStyle(span);
  out.push({ text: span.textContent.trim(), position: cs.position, display: cs.display,
    width: cs.width, height: cs.height, clip: cs.clip, clipPath: cs.clipPath,
    overflow: cs.overflow, whiteSpace: cs.whiteSpace, marginTop: cs.marginTop, marginLeft: cs.marginLeft,
    top: cs.top, left: cs.left, inlineStyle: span.getAttribute('style') });
}
document.getElementById('probe-result').textContent = JSON.stringify(out, null, 1);
`;

function run(label, html, css) {
  let out = html
    .replace(/<meta http-equiv="Content-Security-Policy"[^>]*>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<link[^>]*>/gi, '')
    .replace(/\sloading="lazy"/gi, '');
  const styleTag = `<style>${css}</style>`;
  out = /<style/i.test(out) ? out.replace(/<style[^>]*>[\s\S]*?<\/style>/i, styleTag) : out.replace(/<\/head>/i, `${styleTag}</head>`);
  out = out.replace('</body>', `<pre id="probe-result"></pre><script>${PROBE}</script></body>`);
  const file = `.audit/debug-${label}.html`;
  fs.writeFileSync(file, out);
  const dom = execFileSync(CHROME, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files',
    '--virtual-time-budget=8000', '--window-size=1440,3000', '--run-all-compositor-stages-before-draw', '--dump-dom',
    'file:///' + ROOT.replace(/\\/g, '/') + '/' + file], { encoding: 'utf8', maxBuffer: 1 << 28 });
  const m = dom.match(/<pre id="probe-result">([\s\S]*?)<\/pre>/);
  const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
  console.log(`\n===== ${label}\n` + decode(m ? m[1] : '(probe missing)'));
}

const html = fs.readFileSync('resources/portal/staff.html', 'utf8');
const headCss = execFileSync('git', ['show', 'HEAD:motaste/public/style.css'], { encoding: 'utf8', maxBuffer: 1 << 28, cwd: '..' });
run('before', execFileSync('git', ['show', 'HEAD:motaste/resources/portal/staff.html'], { encoding: 'utf8', maxBuffer: 1 << 28, cwd: '..' }), headCss);
run('after', html, fs.readFileSync('public/style.css', 'utf8'));
