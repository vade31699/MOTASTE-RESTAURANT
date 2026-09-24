// Scratch audit: classes DEFINED in public/style.css (in selector position) that
// no template, view or script ever mentions. The mirror image of the no-op
// markup classes the audit just removed.
//
// Read-only. A hit here is a candidate, not a verdict: a class can be built at
// runtime ('foo-' + bar) or toggled through a variable, so every name is
// confirmed by eye before anything is deleted.
import fs from 'fs';
import path from 'path';

const SHEET = 'public/style.css';
const SKIP_DIRS = new Set(['node_modules', 'vendor', 'storage', '.git', 'build', 'public']);
const MARKUP = /\.[a-z]+$/;

/** Class tokens that appear as `.foo` in selector position (not declarations). */
function definedClasses(css) {
  const out = new Map();
  // Everything between a `}` (or the start) and the next `{` is a selector list.
  const blocks = css.replace(/\/\*[\s\S]*?\*\//g, '').split('{');
  for (let i = 0; i < blocks.length - 1; i++) {
    // The selector is what follows the previous block's closing brace.
    const sel = blocks[i].slice(blocks[i].lastIndexOf('}') + 1);
    if (sel.trim().startsWith('@')) continue; // at-rule prelude, not a selector
    for (const m of sel.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)) {
      out.set(m[1], (out.get(m[1]) ?? 0) + 1);
    }
  }
  return out;
}

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (SKIP_DIRS.has(e.name) || e.name.startsWith('.')) continue;
      walk(p, out);
    } else if (MARKUP.test(e.name)) out.push(p);
  }
  return out;
}

// Everywhere a class name could legitimately be written down: templates, views,
// the Vue components, and the portal script.
const sources = [
  ...walk('resources'),
  'public/home.html',
  'public/script.js',
  'public/staff-first-paint.js',
  'public/privacy.html',
  'public/terms.html',
].filter((f) => fs.existsSync(f));

const haystack = sources.map((f) => fs.readFileSync(f, 'utf8')).join('\n');

const defined = definedClasses(fs.readFileSync(SHEET, 'utf8'));
const escaped = (s) => s.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&');

const dead = [];
const used = [];
for (const [cls, rules] of [...defined].sort()) {
  const re = new RegExp(`(?<![\\w-])${cls.replace(/[-[\]{}()*+?.,\\^$|#\s]/g, '\\$&')}(?![\\w-])`);
  (re.test(haystack) ? used : dead).push(`${cls}(${rules})`);
}

console.log(`${SHEET}: ${defined.size} classes defined`);
console.log(`  referenced somewhere in ${sources.length} source files: ${used.length}`);
console.log(`  NOT referenced anywhere (${dead.length}):`);
for (const d of dead) console.log('    ' + d);

// Second pass: a class can be built at runtime ('status-' + order.status), which
// the word-boundary search above cannot see. Flag a candidate as "possibly
// dynamic" when any of its trailing name segments still occurs in the sources on
// its own — the tell-tale of a concatenated class name.
console.log('\n--- possibly built at runtime (kept out of any removal list):');
const dynamic = new Set();
for (const d of dead) {
  const cls = d.replace(/\(\d+\)$/, '');
  const segs = cls.split('-');
  // A class name can be interpolated: `status-${order.status}` covers every
  // `.status-*` rule at once. Look for the candidate's own prefixes followed by
  // an interpolation opener, which is invisible to the literal search above.
  const prefixes = [];
  for (let i = segs.length - 1; i >= 1; i--) prefixes.push(segs.slice(0, i).join('-'));
  const opener = String.fromCharCode(36) + '{'; // "${" without interpolating here
  const closer = '}';
  // Either the name is the prefix of an interpolated class ("status-${...}") or
  // its tail is ("${...}-completed").
  const tails = [];
  for (let i = 1; i < segs.length; i++) tails.push(segs.slice(i).join('-'));
  const hit = prefixes.find((p) => haystack.includes(p + '-' + opener))
    ?? tails.find((t) => new RegExp(escaped(opener) + '[^}]*' + escaped(closer) + '-' + t + '(?![\\w-])').test(haystack));
  if (!hit) continue;
  dynamic.add(cls);
  console.log(`    ${cls}  (interpolated class name built from "${hit}")`);
}

const likelyDead = dead.map((d) => d.replace(/\(\d+\)$/, '')).filter((c) => !dynamic.has(c));
console.log(`\n--- no literal use and no interpolation found (${likelyDead.length}):`);
for (const c of likelyDead) console.log('    ' + c);
