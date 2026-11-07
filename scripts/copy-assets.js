/* Copie les polices et la police d'icônes installées par npm vers public/assets (servies en local, aucun CDN).
 * Lancé automatiquement par `npm install` (postinstall) et par `npm run build`. Tolérant : n'échoue jamais. */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const nm = (...p) => path.join(root, 'node_modules', ...p);
const out = (...p) => path.join(root, 'public', 'assets', ...p);

function copy(src, dest) {
  fs.mkdirSync(path.dirname(dest), { recursive: true });
  fs.copyFileSync(src, dest);
}

function copyDir(srcDir, destDir, filter = () => true) {
  if (!fs.existsSync(srcDir)) return 0;
  let n = 0;
  for (const f of fs.readdirSync(srcDir)) {
    const s = path.join(srcDir, f);
    if (fs.statSync(s).isFile() && filter(f)) { copy(s, path.join(destDir, f)); n++; }
  }
  return n;
}

let ok = true;

// 1. Icônes Tabler (police) : CSS + fichiers de police
const tabler = nm('@tabler', 'icons-webfont', 'dist');
if (fs.existsSync(path.join(tabler, 'tabler-icons.min.css'))) {
  copy(path.join(tabler, 'tabler-icons.min.css'), out('vendor', 'tabler', 'tabler-icons.min.css'));
  copyDir(path.join(tabler, 'fonts'), out('vendor', 'tabler', 'fonts'), (f) => /\.(woff2?|ttf)$/.test(f));
  console.log('[assets] Icônes Tabler copiées.');
} else {
  ok = false;
  console.warn('[assets] @tabler/icons-webfont introuvable : lancez `npm install`.');
}

// 2. Polices Inter + Plus Jakarta Sans (variables, sous-ensembles latin et latin-ext)
for (const pkg of ['inter', 'plus-jakarta-sans']) {
  const n = copyDir(nm('@fontsource-variable', pkg, 'files'), out('fonts'), (f) => /-(latin|latin-ext)-wght-normal\.woff2$/.test(f));
  if (n === 0) { ok = false; console.warn(`[assets] @fontsource-variable/${pkg} introuvable : lancez \`npm install\`.`); }
  else console.log(`[assets] Police ${pkg} copiée (${n} fichiers).`);
}

if (!ok) console.warn('[assets] Certains fichiers manquent : les icônes/polices utiliseront le rendu de secours.');
