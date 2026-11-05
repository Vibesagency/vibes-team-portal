const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '..', 'includes');
for (const name of fs.readdirSync(dir).filter((file) => file.endsWith('.php'))) {
  const file = path.join(dir, name);
  const source = fs.readFileSync(file, 'utf8');
  let state = 'code';
  let clean = '';
  for (let i = 0; i < source.length; i += 1) {
    const c = source[i]; const n = source[i + 1];
    if (state === 'code') {
      if (c === '/' && n === '*') { state = 'block'; i += 1; continue; }
      if (c === '/' && n === '/') { state = 'line'; i += 1; continue; }
      if (c === "'" || c === '"') { state = c; clean += ' '; continue; }
      clean += c;
    } else if (state === 'line') { if (c === '\n') { state = 'code'; clean += '\n'; } }
    else if (state === 'block') { if (c === '*' && n === '/') { state = 'code'; i += 1; } }
    else { if (c === '\\') { i += 1; clean += '  '; continue; } if (c === state) state = 'code'; clean += c === '\n' ? '\n' : ' '; }
  }
  for (const [open, close] of [['(', ')'], ['[', ']'], ['{', '}']]) {
    let depth = 0;
    for (const c of clean) { if (c === open) depth += 1; if (c === close) depth -= 1; }
    if (depth !== 0) throw new Error(`${name}: unbalanced ${open}${close}: ${depth}`);
  }
  console.log(`PASS: ${name} delimiter balance`);
}
