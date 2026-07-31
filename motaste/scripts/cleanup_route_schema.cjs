const fs = require('fs').promises;
const path = require('path');

(async () => {
  const filePath = path.join(__dirname, '..', 'routes', 'web.php');
  let text = await fs.readFile(filePath, 'utf8');
  const original = text;

  text = text.replace(/\s*DB::statement\("ALTER TABLE staff ADD COLUMN IF NOT EXISTS full_name VARCHAR\(191\)"\);\r?\n?/g, '');
  text = text.replace(/\s*DB::statement\("ALTER TABLE staff ADD COLUMN IF NOT EXISTS role VARCHAR\(100\)"\);\r?\n?/g, '');
  text = text.replace(/\s*DB::statement\("ALTER TABLE staff ADD COLUMN IF NOT EXISTS email VARCHAR\(191\)"\);\r?\n?/g, '');
  text = text.replace(/\s*DB::statement\("ALTER TABLE staff ADD COLUMN IF NOT EXISTS password_hash VARCHAR\(191\)"\);\r?\n?/g, '');
  text = text.replace(/\r?\n{3,}/g, '\n\n');

  if (text !== original) {
    await fs.writeFile(filePath, text, 'utf8');
    console.log('Updated routes/web.php');
  } else {
    console.log('No changes in routes/web.php');
  }
})();
