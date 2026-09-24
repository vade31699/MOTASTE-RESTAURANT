// Scratch audit: render staff.html (static DOM + real style.css, page scripts
// stripped) and report whether the `.sr-only` spans actually render.
import fs from 'fs';
import { execFileSync } from 'child_process';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const html = fs.readFileSync('resources/portal/staff.html', 'utf8');
const css = fs.readFileSync('public/style.css', 'utf8');

const probe = `
const out = [];
for (const span of document.querySelectorAll('.sr-only')) {
  const cs = getComputedStyle(span);
  const r = span.getBoundingClientRect();
  out.push({
    text: span.textContent.trim(),
    display: cs.display, visibility: cs.visibility, position: cs.position,
    clip: cs.clip, clipPath: cs.clipPath, width: cs.width, height: cs.height,
    fontSize: cs.fontSize, color: cs.color,
    rect: { w: Math.round(r.width), h: Math.round(r.height), x: Math.round(r.x), y: Math.round(r.y) },
  });
}
document.getElementById('probe-result').textContent = JSON.stringify(out);
`;

const patched = html
  .replace(/<meta http-equiv="Content-Security-Policy"[^>]*>/gi, '')
  .replace(/<script[\s\S]*?<\/script>/gi, '')
  .replace(/<link[^>]+style\.css[^>]*>/i, `<style>${css}</style>`)
  .replace(/<link[^>]+>/gi, '')
  .replace('<section id="logs" class="orders-section" hidden>', '<section id="logs" class="orders-section">')
  .replace('</body>', `<pre id="probe-result"></pre><script>${probe}</script></body>`);

const file = '.audit/sr-only-check.html';
fs.writeFileSync(file, patched);

const dom = execFileSync(CHROME, [
  '--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files',
  '--virtual-time-budget=5000', '--run-all-compositor-stages-before-draw', '--dump-dom',
  'file:///' + process.cwd().replace(/\\/g, '/') + '/' + file,
], { encoding: 'utf8', maxBuffer: 1 << 28 });

const m = dom.match(/<pre id="probe-result">([\s\S]*?)<\/pre>/);
if (!m) {
  console.log('probe did not run; dump length', dom.length);
} else {
  const unescape = (s) => s.replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
  console.log(JSON.stringify(JSON.parse(unescape(m[1])), null, 1));
}
