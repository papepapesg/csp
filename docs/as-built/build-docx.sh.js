#!/usr/bin/env node
/*
 * Build .docx versions of the as-built docs with every mermaid block rendered to
 * an embedded PNG image (so the diagrams show clearly in Word / on a phone).
 *
 * Requires: pandoc, and @mermaid-js/mermaid-cli (via `npx`). A puppeteer config
 * with --no-sandbox is written to /tmp for headless chromium.
 *
 * Usage:
 *   node docs/as-built/build-docx.sh.js                 # build every *.md
 *   node docs/as-built/build-docx.sh.js catalog.md ...  # build specific docs
 *
 * Output: docs/as-built/docx/<name>.docx
 */
const fs = require('fs'), cp = require('child_process'), path = require('path');

const srcDir = path.resolve(__dirname);
const outDir = path.join(srcDir, 'docx');
const imgDir = fs.mkdtempSync('/tmp/docximg-');
fs.mkdirSync(outDir, { recursive: true });

const pptr = '/tmp/pptr-docx.json';
fs.writeFileSync(pptr, JSON.stringify({ args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] }));

let files = process.argv.slice(2);
if (files.length === 0) {
  files = fs.readdirSync(srcDir).filter(f => f.endsWith('.md'));
}

for (const file of files) {
  const lines = fs.readFileSync(path.join(srcDir, file), 'utf8').split('\n');
  const out = [];
  let inBlock = false, buf = [], k = 0;
  for (const l of lines) {
    if (!inBlock && l.trim() === '```mermaid') { inBlock = true; buf = []; continue; }
    if (inBlock && l.trim() === '```') {
      inBlock = false; k++;
      const base = file.replace(/\W+/g, '_') + '_' + k;
      const mmd = path.join(imgDir, base + '.mmd');
      const png = path.join(imgDir, base + '.png');
      fs.writeFileSync(mmd, buf.join('\n'));
      try {
        cp.execSync(`npx -y -p @mermaid-js/mermaid-cli mmdc -p "${pptr}" -i "${mmd}" -o "${png}" -b white -s 2`, { stdio: 'pipe' });
        out.push('', `![diagram](${png})`, '');
      } catch (e) {
        console.error('mermaid render failed in', file, 'block', k);
        out.push('', '```', ...buf, '```', '');
      }
      continue;
    }
    if (inBlock) { buf.push(l); continue; }
    out.push(l);
  }
  const pre = path.join(imgDir, file.replace(/\W+/g, '_') + '.pre.md');
  fs.writeFileSync(pre, out.join('\n'));
  const docx = path.join(outDir, file.replace(/\.md$/, '.docx'));
  cp.execSync(`pandoc "${pre}" -f gfm -o "${docx}" --resource-path="${imgDir}"`, { stdio: 'pipe' });
  console.log('built', path.relative(process.cwd(), docx));
}
