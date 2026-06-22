#!/usr/bin/env node
/*
 * Build a GitHub-app-readable mirror of the as-built docs: each doc is copied to
 * docs/as-built/rendered/<name>.md with every ```mermaid block replaced by a
 * committed PNG image (docs/as-built/rendered/img/<name>_N.png). The GitHub web
 * site AND the GitHub mobile app both render markdown image links, so the
 * diagrams show as pictures everywhere (unlike raw mermaid, which the app skips).
 *
 * Requires @mermaid-js/mermaid-cli (via npx). Usage:
 *   node docs/as-built/build-rendered.cjs            # all *.md
 *   node docs/as-built/build-rendered.cjs catalog.md # specific docs
 */
const fs = require('fs'), cp = require('child_process'), path = require('path');

const srcDir = path.resolve(__dirname);
const outDir = path.join(srcDir, 'rendered');
const imgDir = path.join(outDir, 'img');
fs.mkdirSync(imgDir, { recursive: true });

const pptr = '/tmp/pptr-rendered.json';
fs.writeFileSync(pptr, JSON.stringify({ args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] }));

let files = process.argv.slice(2);
if (files.length === 0) {
  files = fs.readdirSync(srcDir).filter(f => f.endsWith('.md'));
}

for (const file of files) {
  const name = file.replace(/\.md$/, '');
  const lines = fs.readFileSync(path.join(srcDir, file), 'utf8').split('\n');
  const out = [];
  let inBlock = false, buf = [], k = 0;
  // a short banner so a reader knows this is the rendered mirror
  out.push(`> 📱 **Rendered view** — diagrams below are images so they show in the GitHub app. ` +
           `Editable source (with mermaid): [\`../${file}\`](../${file}).`, '');
  for (const l of lines) {
    if (!inBlock && l.trim() === '```mermaid') { inBlock = true; buf = []; continue; }
    if (inBlock && l.trim() === '```') {
      inBlock = false; k++;
      const base = `${name.replace(/\W+/g, '_')}_${k}`;
      const mmd = path.join(imgDir, base + '.mmd');
      const png = path.join(imgDir, base + '.png');
      fs.writeFileSync(mmd, buf.join('\n'));
      try {
        cp.execSync(`npx -y -p @mermaid-js/mermaid-cli mmdc -p "${pptr}" -i "${mmd}" -o "${png}" -b white -s 2`, { stdio: 'pipe' });
        fs.unlinkSync(mmd);
        out.push('', `![diagram](img/${base}.png)`, '');
      } catch (e) {
        console.error('mermaid render failed in', file, 'block', k);
        out.push('', '```mermaid', ...buf, '```', '');
      }
      continue;
    }
    if (inBlock) { buf.push(l); continue; }
    out.push(l);
  }
  fs.writeFileSync(path.join(outDir, file), out.join('\n'));
  console.log('built', path.relative(process.cwd(), path.join(outDir, file)));
}
