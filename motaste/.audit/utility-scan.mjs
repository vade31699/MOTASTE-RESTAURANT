// Scratch audit: find machine-generated / single-purpose "utility" class names
// anywhere in templates or stylesheets (the `.mst-iNN` family and lookalikes).
import fs from 'fs';
import path from 'path';

const UTILITY = /^(?:[a-z]{1,4})?(?:mb|mt|ml|mr|mx|my|p|px|py|pt|pb|pl|pr|w|h|m|gap|text|bg|flex|grid|col|row|order|top|left|right|bottom|inset|z|opacity|rounded|shadow|border|font|leading|tracking|space|items|justify|self|overflow|inline|block)-?[a-z0-9-]*$/;

/** Class-name shapes that look generated rather than semantic. */
function suspicious(name) {
  if (/^[a-z]{1,3}(-[a-z])?\d+$/.test(name)) return 'numeric-suffix';
  if (/^[a-z]+-i\d+$/.test(name)) return 'mst-iNN shape';
  if (/^(?:mb|mt|ml|mr|mx|my|p[trblxy]?|w|h|gap|text|bg|flex|grid|col|row|top|left|right|bottom|z|order|leading|tracking|rounded|shadow|border|font|items|justify|self|overflow|inset|space)-[a-z0-9]+$/.test(name)) return 'utility-shape';
  if (/^[a-z]{1,2}\d{1,2}$/.test(name)) return 'short+numeric';
  return null;
}

const roots = ['public', 'resources'];
const walk = (dir, out = []) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (['node_modules', 'vendor', 'build', 'storage', '.git'].includes(e.name)) continue;
      walk(p, out);
    } else if (/\.(html|blade\.php|vue|js|jsx|ts|tsx)$/.test(e.name)) out.push(p);
  }
  return out;
};

const files = roots.flatMap((r) => (fs.existsSync(r) ? walk(r) : []));

const cssDefs = new Map(); // class -> where defined
for (const f of files.filter((f) => f.endsWith('.css')) ) {
  const css = fs.readFileSync(f, 'utf8');
  for (const m of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)) {
    if (!cssDefs.has(m[1])) cssDefs.set(m[1], new Set());
    cssDefs.get(m[1]).add(f);
  }
}
// style.css + app.css are not under walk() (they are, actually) — list any .css we found
console.log('css files scanned:', files.filter((f) => f.endsWith('.css')));
console.log('classes defined in css:', cssDefs.size);

const hits = new Map();
for (const f of files.filter((f) => !f.endsWith('.css'))) {
  const src = fs.readFileSync(f, 'utf8');
  const tokens = new Set();
  for (const m of src.matchAll(/\bclass(?:Name)?\s*[:=]\s*(?:\{?["'`])([^"'`]*)/g)) {
    for (const t of m[1].split(/[\s${}?'"]+/)) if (t) tokens.add(t);
  }
  for (const t of tokens) {
    const why = suspicious(t);
    if (!why) continue;
    if (!hits.has(t)) hits.set(t, { why, files: new Set(), defined: cssDefs.has(t) });
    hits.get(t).files.add(f);
  }
}

console.log('\n--- suspicious class tokens in templates/app code ---');
for (const [t, info] of [...hits].sort()) {
  console.log(`${info.defined ? 'def ' : 'UNDEF'} [${info.why}] ${t}  -> ${[...info.files].join(', ')}`);
}
