// Scratch audit: classify classes that have no `.class {}` rule of their own.
// For each, look for other ways they are targeted: attribute selectors,
// descendant-of-id selectors, JS querySelector/getElementById, classList use.
import fs from 'fs';

const CANDIDATES = {
  'public/home.html': ['footer-legal-links', 'menu-place-order-primary', 'product-detail-qty-input', 'fa-solid', 'hidden', 'active', 'is-active', 'is-waiting'],
  'resources/portal/staff.html': [
    'account-settings', 'account-settings-actions-card', 'account-settings-card', 'confirm-modal',
    'device-verify-step', 'forgot-password-step', 'insights-best-sellers', 'insights-compare-result',
    'insights-hourly-chart', 'inventory-access-note', 'login-history-list', 'sr-only', 'totp-manual-key-label',
    'secondary', 'toggle-password', 'is-active', 'active',
  ],
  'resources/views/app.blade.php': ['antialiased', 'font-sans'],
};

const css = fs.readFileSync('public/style.css', 'utf8');
const appCss = fs.existsSync('resources/css/app.css') ? fs.readFileSync('resources/css/app.css', 'utf8') : '';
const js = fs.readFileSync('public/script.js', 'utf8');

const hunt = (needle, haystack) => haystack.split('\n')
  .map((l, i) => [i + 1, l])
  .filter(([, l]) => l.includes(needle))
  .slice(0, 6)
  .map(([n, l]) => `${n}: ${l.trim().slice(0, 150)}`);

for (const [file, names] of Object.entries(CANDIDATES)) {
  console.log(`\n=========== ${file}`);
  for (const name of names) {
    const inCss = hunt(name, css);
    const inAppCss = hunt(name, appCss);
    const inJs = hunt(name, js);
    const used = fs.existsSync(file) ? hunt(name, fs.readFileSync(file, 'utf8')) : [];
    console.log(`\n-- ${name}`);
    if (used.length) console.log(`   markup: ${used.join(' | ')}`);
    if (inCss.length) console.log(`   style.css: ${inCss.join(' | ')}`);
    if (inAppCss.length) console.log(`   app.css: ${inAppCss.join(' | ')}`);
    if (inJs.length) console.log(`   script.js: ${inJs.join(' | ')}`);
    if (!inCss.length && !inAppCss.length && !inJs.length) console.log('   >>> NOT REFERENCED ANYWHERE ELSE');
  }
}

console.log('\n=========== email templates: inline styles / style blocks');
for (const f of ['resources/views/emails/admin-reset-attempt.blade.php', 'resources/views/emails/password-reset-code.blade.php']) {
  const h = fs.readFileSync(f, 'utf8');
  console.log(`${f}: style= ${(h.match(/style="/g) || []).length}, <style> ${(h.match(/<style/g) || []).length}, classes ${(h.match(/class="/g) || []).length}`);
}
