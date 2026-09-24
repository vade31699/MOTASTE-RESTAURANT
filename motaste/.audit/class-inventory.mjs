// Scratch audit: for each HTML/Blade template, report markup classes that are
// (a) defined in that page's own <style>, (b) defined in a shared stylesheet
// reachable by the page, or (c) defined nowhere. Read-only.
import fs from 'fs';
import path from 'path';

const PAGES = [
  { file: 'public/home.html', sheets: ['public/style.css'] },
  { file: 'public/privacy.html', sheets: [] },
  { file: 'public/terms.html', sheets: [] },
  { file: 'resources/portal/staff.html', sheets: ['public/style.css'] },
  { file: 'resources/views/app.blade.php', sheets: ['resources/css/app.css'] },
  { file: 'resources/views/dashboard.blade.php', sheets: ['resources/css/app.css'] },
  { file: 'resources/views/auth/forgot-password.blade.php', sheets: [] },
  { file: 'resources/views/auth/reset-password.blade.php', sheets: [] },
  { file: 'resources/views/auth/reset-password-success.blade.php', sheets: [] },
  { file: 'resources/views/auth/login.blade.php', sheets: [] },
];

function stripCssComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Class tokens that appear as `.foo` in selector position. */
function definedClasses(css) {
  const out = new Map(); // class -> Set(selector-ish context)
  css = stripCssComments(css);
  // Selector text = whatever sits before a `{`, minus any at-rule prelude and
  // minus the declarations that preceded it (we rescan from the last `}`/`;`).
  const chunks = css.split(/[{}]/);
  for (let i = 0; i < chunks.length; i++) {
    const chunk = chunks[i];
    // selector chunks are the ones at even positions when the split starts at a selector
    for (const m of chunk.matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)) {
      if (!out.has(m[1])) out.set(m[1], new Set());
      out.get(m[1]).add(chunk.trim().slice(0, 80).replace(/\s+/g, ' '));
    }
  }
  return out;
}

function styleBlocks(html) {
  return [...html.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/gi)].map((m) => m[1]).join('\n');
}

function markupClasses(html) {
  const body = html.replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '').replace(/<script[^>]*>[\s\S]*?<\/script>/gi, '');
  const counts = new Map();
  for (const m of body.matchAll(/\sclass="([^"]*)"/g)) {
    for (const tok of m[1].split(/\s+/).filter(Boolean)) {
      counts.set(tok, (counts.get(tok) ?? 0) + 1);
    }
  }
  return counts;
}

const shared = new Map();
for (const f of ['public/style.css', 'resources/css/app.css']) {
  if (fs.existsSync(f)) shared.set(f, definedClasses(fs.readFileSync(f, 'utf8')));
}

for (const { file, sheets } of PAGES) {
  if (!fs.existsSync(file)) {
    console.log(`\n### ${file}  (MISSING)`);
    continue;
  }
  const html = fs.readFileSync(file, 'utf8');
  const own = definedClasses(styleBlocks(html));
  const used = markupClasses(html);
  const reachable = new Set();
  for (const [name, defs] of shared) {
    if (sheets.includes(name)) for (const c of defs.keys()) reachable.add(c);
  }
  const undefined_ = [];
  const ownDefined = [];
  const sharedDefined = [];
  for (const [cls, count] of [...used].sort()) {
    if (own.has(cls)) ownDefined.push(`${cls}(${count})`);
    else if (reachable.has(cls)) sharedDefined.push(`${cls}(${count})`);
    else undefined_.push(`${cls}(${count})`);
  }
  const utilityLike = [...used.keys()].filter((c) => /^(mb|mt|ml|mr|mx|my|p|px|py|w|h|m)-?\d*$/.test(c) || /^[a-z]+-\d+$/.test(c));
  console.log(`\n### ${file}`);
  console.log(`  markup classes: ${used.size} | own <style>: ${own.size} | shared sheets: ${[...reachable].length}`);
  console.log(`  utility/one-off-looking: ${utilityLike.join(', ') || '(none)'}`);
  console.log(`  defined ONLY in own <style>: ${ownDefined.join(', ') || '(none)'}`);
  console.log(`  defined ONLY in shared: ${sharedDefined.join(', ') || '(none)'}`);
  console.log(`  DEFINED NOWHERE: ${undefined_.join(', ') || '(none)'}`);
}
