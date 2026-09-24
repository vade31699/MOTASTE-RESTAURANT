// Scratch debug: dump the raw payload rows the parity probe produces for the
// two .sr-only spans, in both variants, to find why styles were not diffed.
import fs from 'fs';
import { execFileSync } from 'child_process';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = process.cwd();

const PROPS = ['display', 'position', 'width', 'height', 'clip', 'clip-path', 'overflow-x', 'margin-top'];

const PROBE = `
const props = ${JSON.stringify(PROPS)};
for (const el of document.querySelectorAll('[hidden]')) el.removeAttribute('hidden');
const out = [];
let i = 0;
for (const el of document.querySelectorAll('body *')) {
  const cs = getComputedStyle(el);
  const vals = props.map((p) => cs.getPropertyValue(p)).join(';');
  out.push([i++, el.tagName, (el.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 30), vals]);
}
document.getElementById('probe-result').textContent = JSON.stringify(out);
`;

function run(label, html, css) {
  let out = html
    .replace(/<meta http-equiv="Content-Security-Policy"[^>]*>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<link[^>]*>/gi, '');
  const styleTag = `<style>${css}</style>`;
  out = /<style/i.test(out) ? out.replace(/<style[^>]*>[\s\S]*?<\/style>/i, styleTag) : out.replace(/<\/head>/i, `${styleTag}</head>`);
  out = out.replace('</body>', `<pre id="probe-result"></pre><script>${PROBE}</script></body>`);
  const file = `.audit/debug-harness-${label}.html`;
  fs.writeFileSync(file, out);
  const dom = execFileSync(CHROME, ['--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files',
    '--virtual-time-budget=8000', '--window-size=1440,3000', '--run-all-compositor-stages-before-draw', '--dump-dom',
    'file:///' + ROOT.replace(/\\/g, '/') + '/' + file], { encoding: 'utf8', maxBuffer: 1 << 28 });
  const m = dom.match(/<pre id="probe-result">([\s\S]*?)<\/pre>/);
  const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
  const rows = JSON.parse(decode(m[1]));
  const hits = rows.filter((r) => /Select log category|Choose log date/.test(r[2]));
  console.log(`\n===== ${label}`);
  for (const h of hits) console.log(JSON.stringify(h));
  console.log('  (rows whose payload would parse to an empty map:',
    rows.filter((r) => r[3].split(';').filter((p) => p.indexOf(':') > 0).length === 0).length, 'of', rows.length + ')');
}

run('before', execFileSync('git', ['show', 'HEAD:motaste/resources/portal/staff.html'], { encoding: 'utf8', maxBuffer: 1 << 28, cwd: '..' }),
  execFileSync('git', ['show', 'HEAD:motaste/public/style.css'], { encoding: 'utf8', maxBuffer: 1 << 28, cwd: '..' }));
run('after', fs.readFileSync('resources/portal/staff.html', 'utf8'), fs.readFileSync('public/style.css', 'utf8'));
