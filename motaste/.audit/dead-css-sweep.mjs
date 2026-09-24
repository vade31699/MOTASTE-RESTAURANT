// Scratch sweep: drop every public/style.css rule that can never match, because
// it names a class nothing references (see dead-css-check.mjs).
//
// Writes the result to .audit/style.sweep.css and prints the plan. Nothing is
// applied to public/style.css from here: the file is diffed and moved into place
// by hand, then the parity renders confirm nothing moved.
//
// Selector semantics: an element must carry every class a compound selector
// names, so a single unreferenced class anywhere in the compound (outside a
// functional pseudo-class argument, which does not have to match) makes that
// selector unsatisfiable.
import fs from 'fs';

const SHEET = 'public/style.css';
const OUT = '.audit/style.sweep.css';

const DEAD = new Set(`account-form-note
admin-edit-mode-btn
admin-edit-mode-switch
chart-scroll-btn
chart-scroll-btn-group
checkout-clock
hide-header
inventory-manager-list
inventory-readonly-summary
logs-filter-btn
menu-cart-component-qty
menu-cart-item-qty
menu-cart-summary-text
menu-categories-visible
menu-category-header
menu-item-action
menu-item-action-primary
menu-item-actions
menu-item-qty
menu-open-btn
menu-overlay-hide-actions
menu-section-heading
order-amount
order-meta
order-status
overview-low-stock-box
overview-low-stock-list
overview-metric-card
primary
review-history-title
review-management
role-tabs
sales-analytics-bar
sales-detail-panel
sales-example
sales-example-note
sales-example-table
sales-list-panel
sales-number-list
slide-nav
social-login-btn
social-login-facebook
social-login-google
social-login-tiktok
special-food-cart-action
stats-card
top-actions
top-bar-actions
top-bar-left
top-link
top-login-btn
totp-modal-steps`.split('\n'));

const raw = fs.readFileSync(SHEET, 'utf8');
// Work on a copy where comments are blanked out (newlines kept) so selector
// offsets stay valid and a comment's own commas cannot split a selector list.
const css = raw.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));

const classesIn = (sel) => [...sel.replace(/\([^()]*\)/g, '()').matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)].map((m) => m[1]);
const lineAt = (i) => css.slice(0, i).split('\n').length;

function scan(src) {
  const out = [];
  const open = [];
  for (let i = 0; i < src.length; i++) {
    if (src[i] === '{') open.push(i);
    else if (src[i] === '}') {
      const from = open.pop();
      if (from === undefined) continue;
      // A rule starts after whichever comes last: the previous rule's `}`, the
      // `{` of the at-block it sits in (the first rule in a media query has no
      // preceding `}` at its own level), or a stray `;`. Using only `}` here
      // made the first rule in every @-block look like it began with the
      // at-rule prelude, and it was then skipped as an at-rule.
      const prevEnd = Math.max(src.lastIndexOf('}', from), src.lastIndexOf('{', from - 1), src.lastIndexOf(';', from));
      const selStart = prevEnd + 1;
      const sel = src.slice(selStart, from).trim();
      const selOffset = src.indexOf(sel, selStart);
      out.push({ sel, selOffset, selEnd: selOffset + sel.length, from, to: i + 1, depth: open.length });
    }
  }
  return out;
}

/**
 * The whole comment block immediately above a rule, if one is attached. Read
 * from the raw text: the working copy has comments blanked out.
 */
function leadingComment(start) {
  let i = start - 1;
  while (i >= 0 && /\s/.test(raw[i])) i--;
  if (i < 1 || raw[i] !== '/' || raw[i - 1] !== '*') return null;
  const open = raw.lastIndexOf('/*', i - 1);
  if (open === -1) return null;
  // Only claim a comment that ends where this rule begins (nothing in between):
  // a comment followed by a rule that survives documents that other rule too.
  return { start: open, text: raw.slice(open, i + 1) };
}

const edits = []; // { from, to, text, kind, sel, line }
for (const r of scan(css)) {
  if (r.sel === '' || r.sel.startsWith('@')) continue;
  const parts = r.sel.split(',').map((s) => s.trim()).filter(Boolean);
  const perPart = parts.map((p) => ({ part: p, dead: classesIn(p).some((c) => DEAD.has(c)) }));
  if (!perPart.some((p) => p.dead)) continue;
  const keep = perPart.filter((p) => !p.dead).map((p) => p.part);
  if (keep.length) {
    edits.push({ from: r.selOffset, to: r.selEnd, text: keep.join(', '), kind: 'PRUNE', sel: r.sel, line: lineAt(r.from), dropped: perPart.filter((p) => p.dead).map((p) => p.part) });
  } else {
    // Comments are never removed automatically: several of them are section
    // headings that also cover rules which survive (e.g. "Logs / review
    // management" above the .logs-filter-bar rules). The genuinely orphaned
    // ones are listed for a human to delete.
    edits.push({ from: r.selOffset, to: r.to, text: '', kind: 'DROP', sel: r.sel, line: lineAt(r.from), comment: leadingComment(r.selOffset)?.text.split('\n')[0].slice(0, 90) ?? null });
  }
}

const ordered = edits.sort((a, b) => a.from - b.from);
// A dropped rule takes its own indentation (or a nested rule would leave the
// closing brace of its @-block behind as an indented `}`) and the blank lines
// that separated it from the next rule. Newlines before the rule stay, so the
// neighbours keep exactly the one blank line between them. Both walks stop at
// the next edit so two adjacent rules can never overlap.
ordered.forEach((e, i) => {
  if (e.kind !== 'DROP') return;
  const floor = i > 0 ? ordered[i - 1].to : 0;
  while (e.from > floor && (raw[e.from - 1] === ' ' || raw[e.from - 1] === '\t')) e.from--;
  const ceiling = ordered[i + 1] ? ordered[i + 1].from : raw.length;
  // Only whole blank lines: the next rule's own indentation must survive.
  for (;;) {
    const blank = /^[ \t]*\r?\n/.exec(raw.slice(e.to, ceiling));
    if (!blank) break;
    e.to += blank[0].length;
  }
});

let next = '';
let cursor = 0;
for (const e of ordered) {
  next += raw.slice(cursor, e.from) + e.text;
  cursor = e.to;
}
next += raw.slice(cursor);

// An at-rule whose body is now empty (or only whitespace) should not survive.
const nextMasked = next.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
const removeAt = (src) => {
  for (const r of scan(src)) {
    if (!r.sel.startsWith('@')) continue;
    const body = src.slice(r.from + 1, r.to - 1);
    if (body.trim() === '') return { from: r.selOffset, to: r.to, sel: r.sel, line: lineAt(r.from) };
  }
  return null;
};
const removedAt = [];
for (let hit = removeAt(nextMasked); hit; hit = removeAt(nextMasked)) {
  removedAt.push(hit);
  next = next.slice(0, hit.from) + next.slice(hit.to);
  break; // report one pass; re-run by hand if more appear
}

const bytes = (s) => s.length;
console.log(`${SHEET}: ${bytes(raw)} bytes -> ${bytes(next)} bytes`);
console.log(`edits: DROP ${edits.filter((e) => e.kind === 'DROP').length}, PRUNE ${edits.filter((e) => e.kind === 'PRUNE').length}`);
console.log(`dropped selector parts: ${edits.filter((e) => e.kind === 'PRUNE').flatMap((e) => e.dropped).length}`);
console.log(`rules a comment sits directly above: ${edits.filter((e) => e.comment).length} (comments are left alone)`);
console.log(`empty at-rules removed: ${removedAt.length}`);
console.log('\n--- plan');
for (const e of edits) {
  const size = e.to - e.from;
  console.log(`  ${e.kind.padEnd(5)} line ${String(e.line).padStart(5)}  ${String(size).padStart(5)}B  ${e.sel.replace(/\s+/g, ' ').slice(0, 96)}`);
  if (e.comment) console.log(`        + comment: ${e.comment}`);
  if (e.dropped) console.log(`        - ${e.dropped.join(' | ')}`);
}
for (const a of removedAt) console.log(`  AT    line ${a.line}  emptied: ${a.sel.replace(/\s+/g, ' ').slice(0, 80)}`);

// Comments that only documented rules this sweep removes. Deleting text a codemod
// merely guessed at is dangerous, so these are named by their opening line and
// confirmed by eye: each one sits above surviving rules that already carry a
// heading of their own, so leaving it would mislabel them.
const ORPHANED_COMMENTS = [
  '/* The two-step admin flows open with a short explanation of what happens next. */',
  '/* ---------- Admin credential panel ----------',
  '/* Social login buttons — light card friendly */',
];
for (const firstLine of ORPHANED_COMMENTS) {
  const at = next.indexOf(firstLine);
  if (at === -1) {
    console.log(`orphaned comment not found: ${firstLine.slice(0, 50)}`);
    continue;
  }
  const end = next.indexOf('*/', at) + '*/'.length;
  const from = next.lastIndexOf('\n', at) + 1;
  let to = end;
  // Take the line break after the comment and the blank line that used to
  // separate it from the rule it described.
  for (let n = 0; n < 2; n++) {
    if (next.startsWith('\r\n', to)) to += 2;
    else if (next[to] === '\n') to += 1;
  }
  next = next.slice(0, from) + next.slice(to);
}

// ---------------------------------------------------------------- self-check
// Re-scan the result and prove three things: every surviving selector existed
// before (nothing was mangled), its body is byte-identical, and no selector that
// was removed was live.
const norm = (s) => s.replace(/\s+/g, ' ').trim();
/** Indentation of the line a rule starts on - a dropped neighbour must not eat it. */
const indentOf = (src) => {
  const map = new Map();
  for (const r of scan(src)) {
    if (r.sel === '' || r.sel.startsWith('@')) continue;
    const lineStart = src.lastIndexOf('\n', r.selOffset) + 1;
    for (const part of r.sel.split(',')) map.set(norm(part), src.slice(lineStart, r.selOffset));
  }
  return map;
};
const bodies = (src) => {
  const map = new Map();
  for (const r of scan(src)) {
    if (r.sel === '' || r.sel.startsWith('@')) continue;
    for (const part of r.sel.split(',')) map.set(norm(part), src.slice(r.from + 1, r.to - 1));
  }
  return map;
};
const before = bodies(css);
const after = bodies(nextMasked);
const indentBefore = indentOf(css);
const indentAfter = indentOf(nextMasked);
const problems = [];
for (const [sel, indent] of indentAfter) {
  if (indentBefore.get(sel) !== undefined && indentBefore.get(sel) !== indent) {
    problems.push(`indentation changed: ${sel} ("${indentBefore.get(sel)}" -> "${indent}")`);
  }
}
for (const [sel, body] of after) {
  if (!before.has(sel)) problems.push(`new selector appears: ${sel}`);
  else if (before.get(sel) !== body) problems.push(`body changed: ${sel}`);
}
for (const [sel] of before) {
  if (after.has(sel)) continue;
  if (!classesIn(sel).some((c) => DEAD.has(c))) problems.push(`live selector removed: ${sel}`);
}
for (const sel of after.keys()) {
  const dead = classesIn(sel).filter((c) => DEAD.has(c));
  if (dead.length) problems.push(`still references dead class: ${sel} (${dead.join(', ')})`);
}
console.log(`\nself-check: ${before.size} selectors before, ${after.size} after`);
if (problems.length) {
  console.log(`  FAILED (${problems.length})`);
  for (const p of problems.slice(0, 20)) console.log('    ' + p);
  process.exitCode = 1;
} else {
  console.log('  OK - every removed selector named an unreferenced class, every survivor is untouched');
}

fs.writeFileSync(OUT, next);
console.log(`\nwrote ${OUT}`);
