// Scratch audit: every occurrence of each candidate class anywhere in the repo
// (excluding .git / node_modules / vendor / storage), to prove removal is safe.
import fs from 'fs';
import path from 'path';

const CANDIDATES = [
  'account-settings-card', 'account-settings-actions-card', 'login-history-list',
  'insights-best-sellers', 'insights-compare-result', 'insights-hourly-chart',
  'inventory-access-note', 'totp-manual-key-label', 'device-verify-step',
  'forgot-password-step', 'confirm-modal', 'account-settings',
  'footer-legal-links', 'menu-place-order-primary', 'product-detail-qty-input',
];

const SKIP = new Set(['node_modules', 'vendor', 'storage', '.git', 'build']);
const walk = (dir, out = []) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (SKIP.has(e.name) || e.name.startsWith('.')) continue;
      walk(p, out);
    } else if (/\.(html|php|vue|js|jsx|ts|tsx|css|json|md)$/.test(e.name)) out.push(p);
  }
  return out;
};

const files = ['.', 'public', 'resources', 'tests', 'routes', 'app', 'docs'].filter((d) => fs.existsSync(d)).flatMap((d) => walk(d));
const unique = [...new Set(files)];

for (const cls of CANDIDATES) {
  const hits = [];
  for (const f of unique) {
    const src = fs.readFileSync(f, 'utf8');
    if (!src.includes(cls)) continue;
    src.split('\n').forEach((line, i) => {
      if (line.includes(cls)) hits.push(`${f.replace(/\\/g, '/')}:${i + 1}: ${line.trim().slice(0, 120)}`);
    });
  }
  const other = hits.filter((h) => !/class="[^"]*\b/.test(h.split(': ').slice(1).join(': ')));
  console.log(`\n== ${cls}  (${hits.length} occurrence lines, ${other.length} NOT a class attribute)`);
  for (const h of hits) console.log('   ' + h);
}
