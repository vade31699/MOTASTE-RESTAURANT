// Scratch audit: map every rule in public/style.css that a class from
// dead-css-check.mjs's "no use anywhere" list touches, so the sweep can be done
// surgically rather than with a regex.
//
// Read-only. Classifies each affected rule as:
//   DROP  - every selector in the list names only classes that are unreferenced
//           (or none at all), so the whole rule can go
//   PRUNE - the rule's selector list mixes live and unreferenced classes, so it
//           can only lose the unreferenced selector(s)
//   KEEP  - the rule mentions an unreferenced class but also a live one inside
//           the same selector (.a.dead), so removing the class would change it
import fs from 'fs';

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

const css = fs.readFileSync('public/style.css', 'utf8');
// A comment's prose can name classes that are unrelated to the rule it sits by
// (e.g. "switched with .admin-edit-mode-btn instead"), so scan a comment-blanked
// copy - same length, same newlines, so offsets and line numbers still hold.
const masked = css.replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, ' '));
const lineAt = (i) => css.slice(0, i).split('\n').length;

/** Walk the sheet and return every rule with its selector text and offsets. */
function rules(src) {
  const out = [];
  const open = []; // indexes of '{' currently open
  for (let i = 0; i < src.length; i++) {
    const ch = src[i];
    if (ch === '{') open.push(i);
    else if (ch === '}') {
      const start = open.pop();
      if (start === undefined) continue;
      // The selector runs from after the previous rule's `}` - or after the
      // `{` of the at-block this rule sits in, for the first rule in a media
      // query - up to this `{`.
      const prevEnd = Math.max(src.lastIndexOf('}', start), src.lastIndexOf('{', start - 1), src.lastIndexOf(';', start));
      const sel = src.slice(prevEnd + 1, start);
      const selStart = prevEnd + 1 + (sel.length - sel.trimStart().length);
      out.push({ sel: sel.trim(), selStart, from: start, to: i + 1, depth: open.length });
    }
  }
  return out;
}

/**
 * Class names a single selector names. Arguments of functional pseudo-classes
 * (`:not(.a)`, `:is(...)`) are dropped: they do not have to match, so a class
 * that only appears there never makes the selector unsatisfiable.
 */
const classesIn = (sel) => [...sel.replace(/\([^()]*\)/g, '()').matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)].map((m) => m[1]);

const all = rules(masked);
const report = [];
for (const r of all) {
  if (r.sel.startsWith('@') || r.sel === '') continue;
  const parts = r.sel.split(',').map((s) => s.trim()).filter(Boolean);
  const perPart = parts.map((p) => ({ part: p, classes: classesIn(p) }));
  // An element has to carry every class a selector names, so ONE unreferenced
  // class anywhere in the compound makes that selector impossible to match.
  const perPartDead = perPart.map((p) => ({ ...p, dead: p.classes.some((c) => DEAD.has(c)) }));
  if (!perPartDead.some((p) => p.dead)) continue;
  const dropParts = perPartDead.filter((p) => p.dead);
  const keepParts = perPartDead.filter((p) => !p.dead);
  const kind = keepParts.length ? 'PRUNE' : 'DROP';
  report.push({ kind, line: lineAt(r.from), sel: r.sel, dropParts: dropParts.map((p) => p.part), keepParts: keepParts.map((p) => p.part), body: css.slice(r.from, r.to) });
}

const count = (k) => report.filter((r) => r.kind === k).length;
console.log(`rules touching an unreferenced class: ${report.length}  (DROP ${count('DROP')}, PRUNE ${count('PRUNE')}, KEEP ${count('KEEP')})\n`);
for (const kind of ['DROP', 'PRUNE', 'KEEP']) {
  console.log(`=================== ${kind}`);
  for (const r of report.filter((x) => x.kind === kind)) {
    console.log(`\nline ${r.line}: ${r.sel.replace(/\s+/g, ' ')}`);
    if (kind === 'PRUNE') console.log(`   drop: ${r.dropParts.join(' | ')}\n   keep: ${r.keepParts.join(' | ')}`);
    console.log('   body: ' + r.body.replace(/\}\s*$/, '}').replace(/\s+/g, ' ').slice(0, 220));
  }
}
