// Scratch verification: render a page in headless Chrome with a given stylesheet
// and diff computed styles, geometry and text between two variants.
//
// Usage: node .audit/parity.mjs <page>
//   page = staff | home | forgot | reset | success
import fs from 'fs';
import { execFileSync } from 'child_process';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const ROOT = process.cwd();

const PROPS = [
  'display', 'position', 'top', 'right', 'bottom', 'left', 'float', 'clear', 'z-index',
  'width', 'height', 'min-width', 'min-height', 'max-width', 'max-height', 'box-sizing',
  'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
  'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
  'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
  'border-top-color', 'border-radius', 'background-color', 'color', 'opacity', 'visibility',
  'font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing',
  'text-align', 'text-transform', 'text-decoration-line', 'white-space', 'word-spacing', 'text-indent',
  'overflow-x', 'overflow-y', 'clip', 'clip-path', 'transform', 'box-shadow',
  'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis', 'justify-content',
  'align-items', 'align-self', 'gap', 'row-gap', 'column-gap', 'order', 'grid-template-columns',
  'cursor', 'outline-style', 'outline-width', 'outline-color', 'list-style-type', 'direction',
  'transition-property', 'transition-duration', 'vertical-align',
];

const PROBE = `
const props = ${JSON.stringify(PROPS)};
// Unhide everything so every section/modals state gets measured.
for (const el of document.querySelectorAll('[hidden]')) el.removeAttribute('hidden');
// Measure only once every image has settled: a half-loaded image changes its
// intrinsic height and would otherwise make the two runs disagree at random.
const settled = Promise.all([...document.images].map((img) => img.complete
    ? Promise.resolve()
    : new Promise((resolve) => { img.addEventListener('load', resolve); img.addEventListener('error', resolve); })));
Promise.race([settled, new Promise((r) => setTimeout(r, 4000))]).then(() => measure());
function measure() {
const out = [];
let i = 0;
for (const el of document.querySelectorAll('body *')) {
  const cs = getComputedStyle(el);
  const r = el.getBoundingClientRect();
  const vals = props.map((p) => cs.getPropertyValue(p)).join(';');
  out.push([
    i++,
    el.tagName,
    el.id || '',
    (el.getAttribute('class') || ''),
    vals,
    [r.x, r.y, r.width, r.height].map((n) => Math.round(n * 100) / 100).join(','),
    el.textContent ? el.textContent.replace(/\\s+/g, ' ').trim().slice(0, 120) : '',
  ]);
}
document.getElementById('probe-result').textContent = JSON.stringify(out);
}
`;

function prepare(html, cssText) {
  let out = html
    .replace(/<meta http-equiv="Content-Security-Policy"[^>]*>/gi, '')
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    // Blade comments must go too: these files are compared as raw source (no
    // Blade pass), and a stray non-whitespace character token in <head> makes
    // the HTML parser close head early — which pushed the injected <style> into
    // <body> and misaligned every node index in the diff.
    .replace(/\{\{--[\s\S]*?--\}\}/g, '')
    .replace(/<link[^>]*>/gi, '')
    // Lazy images below the fold load nondeterministically in headless runs and
    // their intrinsic height then shifts layout; compare them eagerly instead.
    .replace(/\sloading="lazy"/gi, '');
  // Inline the stylesheet under test. An empty cssText leaves whatever <style>
  // the document already carries (the auth pages' pre-consolidation blocks).
  if (cssText) {
    const styleTag = `<style>${cssText}</style>`;
    if (/<style/i.test(out)) {
      out = out.replace(/<style[^>]*>[\s\S]*?<\/style>/i, styleTag);
    } else {
      out = out.replace(/<\/head>/i, `${styleTag}</head>`);
    }
  }
  return out.replace('</body>', `<pre id="probe-result"></pre><script>${PROBE}</script></body>`);
}

function render(name, html, cssText) {
  const file = `.audit/render-${name}.html`;
  fs.writeFileSync(file, prepare(html, cssText));
  const dom = execFileSync(CHROME, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--allow-file-access-from-files',
    '--virtual-time-budget=8000', '--window-size=1440,3000',
    '--run-all-compositor-stages-before-draw', '--dump-dom',
    'file:///' + ROOT.replace(/\\/g, '/') + '/' + file,
  ], { encoding: 'utf8', maxBuffer: 1 << 28 });
  const m = dom.match(/<pre id="probe-result">([\s\S]*?)<\/pre>/);
  if (!m) throw new Error(`${name}: probe did not run (dom ${dom.length})`);
  const decode = (s) => s.replace(/&quot;/g, '"').replace(/&#39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
  return JSON.parse(decode(m[1]));
}

// Git root is the parent of the Laravel project; paths carry the motaste/ prefix.
function git(args) {
  return execFileSync('git', args, { encoding: 'utf8', maxBuffer: 1 << 28, cwd: '..' });
}

function head(file) {
  return git(['show', `HEAD:motaste/${file}`]);
}

function parseCssPayload(payload) {
  // Values only, in PROPS order (value strings may themselves contain ':', so
  // zip against the property list instead of parsing "name:value" pairs).
  const parts = payload.split(';');
  const m = new Map();
  PROPS.forEach((p, i) => m.set(p, parts[i]));
  return m;
}

function diff(label, before, after) {
  console.log(`\n=================== ${label}`);
  console.log(`nodes: before ${before.length}, after ${after.length}`);
  if (before.length !== after.length) {
    console.log('!!! node count differs - structure changed');
  }
  const n = Math.min(before.length, after.length);
  let styleDiffs = 0, geomDiffs = 0, textDiffs = 0;
  const report = [];
  for (let i = 0; i < n; i++) {
    const b = before[i], a = after[i];
    const [bi, btag, bid, bcls, bvals, bgeom, btext] = b;
    const [, atag, aid, acls, avals, ageom, atext] = a;
    if (btag !== atag || bid !== aid) {
      report.push(`NODE ${i} structure changed: <${btag} id=${bid}> vs <${atag} id=${aid}>`);
      continue;
    }
    const bm = parseCssPayload(bvals), am = parseCssPayload(avals);
    const changed = [];
    for (const [p, bv] of bm) if (am.get(p) !== bv) changed.push(`${p}: ${bv} -> ${am.get(p)}`);
    const clsChanged = bcls !== acls ? ` [class "${bcls}" -> "${acls}"]` : '';
    if (changed.length) {
      styleDiffs++;
      report.push(`STYLE ${i} <${btag}${aid ? ' id=' + aid : ''}>${clsChanged}\n      ${changed.join('\n      ')}`);
    }
    if (bgeom !== ageom) {
      geomDiffs++;
      report.push(`GEOM  ${i} <${btag}${aid ? ' id=' + aid : ''}>${clsChanged} ${bgeom} -> ${ageom}`);
    }
    if (btext !== atext) {
      textDiffs++;
      report.push(`TEXT  ${i} <${btag}${aid ? ' id=' + aid : ''}> "${btext}" -> "${atext}"`);
    }
  }
  console.log(`style diffs: ${styleDiffs}, geometry diffs: ${geomDiffs}, text diffs: ${textDiffs}`);
  for (const line of report.slice(0, 100000)) console.log('  ' + line);
  if (report.length > 40) console.log(`  ... ${report.length - 40} more`);
  return { styleDiffs, geomDiffs, textDiffs };
}

const page = process.argv[2];
const control = process.argv[3] === 'control';
const workCss = fs.readFileSync('public/style.css', 'utf8');
const authCss = fs.existsSync('public/css/auth.css') ? fs.readFileSync('public/css/auth.css', 'utf8') : '';

if (page === 'staff' || page === 'home') {
  const file = page === 'staff' ? 'resources/portal/staff.html' : 'public/home.html';
  const beforeHtml = control ? fs.readFileSync(file, 'utf8') : head(file);
  const beforeCss = control ? workCss : git(['show', 'HEAD:motaste/public/style.css']);
  const label = control ? `${page}.html  (CONTROL: worktree vs worktree)` : `${page}.html  (HEAD vs worktree)`;
  const before = render(`${page}-before`, beforeHtml, beforeCss);
  const after = render(`${page}-after`, fs.readFileSync(file, 'utf8'), workCss);
  diff(label, before, after);
} else {
  const file = `resources/views/auth/${page === 'success' ? 'reset-password-success' : page === 'reset' ? 'reset-password' : 'forgot-password'}.blade.php`;
  const before = render(`${page}-before`, head(file), '');   // HEAD markup still carries its own <style>
  const after = render(`${page}-after`, fs.readFileSync(file, 'utf8'), authCss); // new markup links auth.css
  diff(`${file}  (HEAD vs worktree)`, before, after);
}
