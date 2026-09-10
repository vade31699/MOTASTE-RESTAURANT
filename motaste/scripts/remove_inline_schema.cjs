const fs = require('fs').promises;
const path = require('path');

(async () => {
  const base = path.join(__dirname, '..', 'public', 'api');
  const files = await fs.readdir(base);
  let modified = 0;

  const patterns = [
    /DB::statement\(\"CREATE TABLE IF NOT EXISTS[\s\S]*?\"\);\r?\n?/g,
    /DB::statement\(\"ALTER TABLE[\s\S]*?\"\);\r?\n?/g,
    /DB::statement\(\"CREATE UNIQUE INDEX IF NOT EXISTS[\s\S]*?\"\);\r?\n?/g,
  ];

  const helperReplacements = [
    {
      re: /function ensureOrderLogsTable\(\): void\s*\{[\s\S]*?\n\}/g,
      replacement: 'function ensureOrderLogsTable(): void\n{\n    // Schema is managed by Laravel migrations.\n    return;\n}',
    },
    {
      re: /function ensureReviewTables\(\): void\s*\{[\s\S]*?\n\}/g,
      replacement: 'function ensureReviewTables(): void\n{\n    // Schema is managed by Laravel migrations.\n    return;\n}',
    },
    {
      re: /function ensureStaffInviteTokensTable\(\): void\s*\{[\s\S]*?\n\}/g,
      replacement: 'function ensureStaffInviteTokensTable(): void\n{\n    // Schema is managed by Laravel migrations.\n    return;\n}',
    },
    {
      re: /function ensureAdminCredentialChangeTokensTable\(\): void\s*\{[\s\S]*?\n\}/g,
      replacement: 'function ensureAdminCredentialChangeTokensTable(): void\n{\n    // Schema is managed by Laravel migrations.\n    return;\n}',
    },
  ];

  for (const name of files) {
    if (!name.endsWith('.php')) continue;
    const filePath = path.join(base, name);
    let text = await fs.readFile(filePath, 'utf8');
    const original = text;

    patterns.forEach((pat) => {
      text = text.replace(pat, '');
    });

    helperReplacements.forEach(({ re, replacement }) => {
      text = text.replace(re, replacement);
    });

    text = text.replace(/\r?\n{3,}/g, '\n\n');

    if (text !== original) {
      await fs.writeFile(filePath, text, 'utf8');
      modified += 1;
      console.log(`Updated ${name}`);
    }
  }

  console.log(`Modified ${modified} files`);
})();
