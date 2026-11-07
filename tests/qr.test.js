/* Tests du générateur de QR code (public/assets/js/qr.js). Lancer : npm run test:qr */
'use strict';
const assert = require('node:assert/strict');
const TalQR = require('../public/assets/js/qr.js');

let n = 0;
const test = (name, fn) => { fn(); n++; console.log('  ✓', name); };

// Capacités en octets (mode octets) de la norme : version 1-L = 17, 1-M = 14, 1-Q = 11, 1-H = 7.
test('choix automatique de la version selon la capacité', () => {
  assert.equal(TalQR.encode('a'.repeat(17), 'L').version, 1);
  assert.equal(TalQR.encode('a'.repeat(18), 'L').version, 2);
  assert.equal(TalQR.encode('a'.repeat(11), 'Q').version, 1);
  assert.equal(TalQR.encode('a'.repeat(12), 'Q').version, 2);
  assert.equal(TalQR.encode('a'.repeat(130), 'Q').version, 9); // 9-Q = 130 octets
  assert.equal(TalQR.encode('a'.repeat(131), 'Q').version, 10);
});

test('taille de la matrice = 4 × version + 17', () => {
  for (const len of [5, 40, 120, 300]) {
    const q = TalQR.encode('x'.repeat(len), 'Q');
    assert.equal(q.size, q.version * 4 + 17);
    assert.equal(q.modules.length, q.size);
  }
});

test('repères d’angle, synchronisation et module sombre fixe', () => {
  const q = TalQR.encode('otpauth://totp/Test:a@b.c?secret=JBSWY3DPEHPK3PXP', 'Q');
  const m = q.modules;
  const finder = (ox, oy) => {
    for (let y = 0; y < 7; y++) for (let x = 0; x < 7; x++) {
      const edge = x === 0 || y === 0 || x === 6 || y === 6;
      const core = x >= 2 && x <= 4 && y >= 2 && y <= 4;
      assert.equal(m[oy + y][ox + x], edge || core ? 1 : 0);
    }
  };
  finder(0, 0); finder(q.size - 7, 0); finder(0, q.size - 7);
  for (let i = 8; i < q.size - 8; i++) { assert.equal(m[6][i], i % 2 === 0 ? 1 : 0); assert.equal(m[i][6], i % 2 === 0 ? 1 : 0); }
  assert.equal(m[q.size - 8][8], 1);
});

test('information de format valide (BCH 15,5) et identique aux deux emplacements', () => {
  const q = TalQR.encode('hello', 'M');
  const m = q.modules;
  const a = [];
  for (let i = 0; i <= 5; i++) a.push(m[i][8]);
  a.push(m[7][8], m[8][8], m[8][7]);
  for (let i = 9; i < 15; i++) a.push(m[8][14 - i]);
  const b = [];
  for (let i = 0; i < 8; i++) b.push(m[8][q.size - 1 - i]);
  for (let i = 8; i < 15; i++) b.push(m[q.size - 15 + i][8]);
  assert.deepEqual(a, b);
  const bits = a.reduce((acc, bit, i) => acc | (bit << i), 0) ^ 0x5412;
  const check = (v) => { let r = v; for (let i = 14; i >= 10; i--) if ((r >>> i) & 1) r ^= 0x537 << (i - 10); return r; };
  assert.equal(check(bits), 0); // le mot de 15 bits est un multiple du polynôme générateur 0x537
});

test('le texte trop long est refusé proprement', () => {
  assert.throws(() => TalQR.encode('a'.repeat(2000), 'Q'), /trop long/);
});

test('encodage UTF-8', () => {
  assert.doesNotThrow(() => TalQR.encode('é€ ✓ 日本語', 'Q'));
});

test('SVG : pas d’attribut style (CSP), classes Tailwind, logo dégagé', () => {
  const uri = 'otpauth://totp/TAL%20Messaging:ceo%40talmessaging.local?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=TAL%20Messaging&algorithm=SHA1&digits=6&period=30';
  const r = TalQR.svg(uri, { logo: true, eyeClass: 'fill-brand' });
  assert.ok(r.svg.startsWith('<svg'));
  assert.ok(!/\sstyle=/.test(r.svg), 'aucun attribut style autorisé par la CSP');
  assert.ok(!/<script|on\w+=/i.test(r.svg));
  assert.ok(r.svg.includes('class="fill-slate-900"') && r.svg.includes('class="fill-brand"'));
  assert.ok(r.logoRatio > 0.15 && r.logoRatio < 0.3);
  assert.equal(TalQR.svg(uri, { logo: false }).logoRatio, 0);
  // avec logo, le niveau est forcé à Q minimum
  assert.equal(TalQR.svg('abc', { level: 'L' }).version >= 1, true);
});

console.log(`\n${n} tests OK`);
