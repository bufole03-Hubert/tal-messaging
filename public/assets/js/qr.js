/* TAL Messaging — générateur de QR code local (aucune dépendance, aucun CDN, compatible CSP « script-src 'self' »).
 *
 * - Encodage en mode octets (UTF-8), corrections d'erreur L / M / Q / H, versions 1 à 20 (choisie automatiquement).
 * - Rendu SVG au design personnalisé : modules en « pilules » arrondies, repères d'angle arrondis,
 *   zone centrale dégagée pour un logo. Aucun attribut `style` : les couleurs passent par des classes Tailwind
 *   (fill-slate-900, fill-brand…), donc rien n'est bloqué par la CSP `style-src 'self'`.
 * - Le texte encodé ne quitte jamais le navigateur (pas de service tiers de génération de QR code).
 *
 * API : TalQR.encode(texte, 'Q') → { size, version, level, modules, isFunction }
 *       TalQR.svg(texte, options) → { svg, size, version, logoRatio }
 */
(() => {
  'use strict';

  const MAX_VERSION = 20;
  const LEVELS = { L: 0, M: 1, Q: 2, H: 3 };
  const FORMAT_BITS = { L: 1, M: 0, Q: 3, H: 2 }; // bits de niveau dans l'information de format

  // Index 0 = remplissage (valeur illégale). Tables de la norme ISO/IEC 18004, indexées [niveau][version].
  const ECC_PER_BLOCK = [
    [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28],
    [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26],
    [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30],
    [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28],
  ];
  const NUM_BLOCKS = [
    [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8],
    [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16],
    [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20],
    [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25],
  ];

  /* ------------------------------------------------- Corps de Galois GF(256) */
  const EXP = new Uint8Array(512);
  const LOG = new Uint8Array(256);
  (() => {
    let x = 1;
    for (let i = 0; i < 255; i++) {
      EXP[i] = x;
      LOG[x] = i;
      x <<= 1;
      if (x & 0x100) x ^= 0x11d;
    }
    for (let i = 255; i < 512; i++) EXP[i] = EXP[i - 255];
  })();
  const gmul = (a, b) => (a && b ? EXP[LOG[a] + LOG[b]] : 0);

  const generatorCache = new Map();
  function generator(degree) {
    if (generatorCache.has(degree)) return generatorCache.get(degree);
    let poly = [1];
    for (let i = 0; i < degree; i++) {
      const next = new Array(poly.length + 1).fill(0);
      for (let j = 0; j < poly.length; j++) {
        next[j] ^= poly[j];
        next[j + 1] ^= gmul(poly[j], EXP[i]);
      }
      poly = next;
    }
    generatorCache.set(degree, poly);
    return poly;
  }

  /** Reste de la division polynomiale (octets de correction Reed-Solomon). */
  function reedSolomon(data, degree) {
    const gen = generator(degree);
    const rem = new Array(degree).fill(0);
    for (const b of data) {
      const factor = b ^ rem.shift();
      rem.push(0);
      for (let i = 0; i < degree; i++) rem[i] ^= gmul(gen[i + 1], factor);
    }
    return rem;
  }

  /* ------------------------------------------------------- Capacités */
  function rawModules(ver) {
    let n = (16 * ver + 128) * ver + 64;
    if (ver >= 2) {
      const align = Math.floor(ver / 7) + 2;
      n -= (25 * align - 10) * align - 55;
      if (ver >= 7) n -= 36;
    }
    return n;
  }
  const dataCodewords = (ver, li) => Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[li][ver] * NUM_BLOCKS[li][ver];

  function utf8(text) {
    return Array.from(new TextEncoder().encode(String(text)));
  }

  /** Ajoute les octets de correction à chaque bloc puis entrelace le tout. */
  function interleave(data, ver, li) {
    const nb = NUM_BLOCKS[li][ver];
    const eccLen = ECC_PER_BLOCK[li][ver];
    const raw = Math.floor(rawModules(ver) / 8);
    const numShort = nb - (raw % nb);
    const shortLen = Math.floor(raw / nb);
    const blocks = [];
    let k = 0;
    for (let i = 0; i < nb; i++) {
      const len = shortLen - eccLen + (i < numShort ? 0 : 1);
      const d = data.slice(k, k + len);
      k += len;
      const ecc = reedSolomon(d, eccLen);
      if (i < numShort) d.push(0); // emplacement fictif, ignoré à l'entrelacement
      blocks.push(d.concat(ecc));
    }
    const out = [];
    for (let i = 0; i < blocks[0].length; i++) {
      blocks.forEach((blk, j) => {
        if (i !== shortLen - eccLen || j >= numShort) out.push(blk[i]);
      });
    }
    return out;
  }

  /* -------------------------------------------------------- Matrice */
  function alignmentPositions(ver) {
    if (ver === 1) return [];
    const size = ver * 4 + 17;
    const n = Math.floor(ver / 7) + 2;
    const step = ver === 32 ? 26 : Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
    const pos = [6];
    for (let p = size - 7; pos.length < n; p -= step) pos.splice(1, 0, p);
    return pos;
  }

  function penalty(m, size) {
    let score = 0;
    const lines = [];
    for (let i = 0; i < size; i++) {
      const row = [];
      const col = [];
      for (let j = 0; j < size; j++) { row.push(m[i][j]); col.push(m[j][i]); }
      lines.push(row, col);
    }
    const pat = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
    const rev = pat.slice().reverse();
    for (const line of lines) {
      let run = 1;
      for (let i = 1; i <= size; i++) {
        if (i < size && line[i] === line[i - 1]) run++;
        else { if (run >= 5) score += 3 + (run - 5); run = 1; }
      }
      for (let i = 0; i + 11 <= size; i++) {
        let a = true;
        let b = true;
        for (let k = 0; k < 11; k++) {
          if (line[i + k] !== pat[k]) a = false;
          if (line[i + k] !== rev[k]) b = false;
        }
        if (a || b) score += 40;
      }
    }
    let dark = 0;
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) {
        dark += m[y][x];
        if (x < size - 1 && y < size - 1 && m[y][x] === m[y][x + 1] && m[y][x] === m[y + 1][x] && m[y][x] === m[y + 1][x + 1]) score += 3;
      }
    }
    const total = size * size;
    score += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
    return score;
  }

  const MASKS = [
    (x, y) => (x + y) % 2 === 0,
    (x, y) => y % 2 === 0,
    (x, y) => x % 3 === 0,
    (x, y) => (x + y) % 3 === 0,
    (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
    (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
    (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
    (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
  ];

  /**
   * Encode un texte en QR code.
   * @param {string} text
   * @param {'L'|'M'|'Q'|'H'} level
   */
  function encode(text, level = 'Q') {
    const li = LEVELS[level];
    if (li === undefined) throw new Error('Niveau de correction inconnu.');
    const bytes = utf8(text);

    let ver = 1;
    for (; ver <= MAX_VERSION; ver++) {
      if (4 + (ver <= 9 ? 8 : 16) + bytes.length * 8 <= dataCodewords(ver, li) * 8) break;
    }
    if (ver > MAX_VERSION) throw new Error('Texte trop long pour un QR code.');

    // Flux de bits : mode octets, longueur, données, terminateur, bourrage.
    const bits = [];
    const put = (val, len) => { for (let i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); };
    put(0b0100, 4);
    put(bytes.length, ver <= 9 ? 8 : 16);
    bytes.forEach((b) => put(b, 8));
    const capBits = dataCodewords(ver, li) * 8;
    put(0, Math.min(4, capBits - bits.length));
    while (bits.length % 8) bits.push(0);
    for (let pad = 0xec; bits.length < capBits; pad ^= 0xec ^ 0x11) put(pad, 8);
    const data = [];
    for (let i = 0; i < bits.length; i += 8) {
      let v = 0;
      for (let k = 0; k < 8; k++) v = (v << 1) | bits[i + k];
      data.push(v);
    }
    const codewords = interleave(data, ver, li);

    // Matrice et modules de fonction.
    const size = ver * 4 + 17;
    const m = Array.from({ length: size }, () => new Uint8Array(size));
    const fn = Array.from({ length: size }, () => new Uint8Array(size));
    const setFn = (x, y, dark) => { m[y][x] = dark ? 1 : 0; fn[y][x] = 1; };

    for (let i = 0; i < size; i++) { setFn(6, i, i % 2 === 0); setFn(i, 6, i % 2 === 0); }
    const finder = (cx, cy) => {
      for (let dy = -4; dy <= 4; dy++) {
        for (let dx = -4; dx <= 4; dx++) {
          const x = cx + dx;
          const y = cy + dy;
          const d = Math.max(Math.abs(dx), Math.abs(dy));
          if (x >= 0 && x < size && y >= 0 && y < size) setFn(x, y, d !== 2 && d !== 4);
        }
      }
    };
    finder(3, 3); finder(size - 4, 3); finder(3, size - 4);

    const ap = alignmentPositions(ver);
    ap.forEach((cx, i) => ap.forEach((cy, j) => {
      if ((i === 0 && j === 0) || (i === 0 && j === ap.length - 1) || (i === ap.length - 1 && j === 0)) return;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) setFn(cx + dx, cy + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }));

    const formatBits = (mask) => {
      const d = (FORMAT_BITS[level] << 3) | mask;
      let rem = d;
      for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
      return ((d << 10) | rem) ^ 0x5412;
    };
    const drawFormat = (mask) => {
      const bits15 = formatBits(mask);
      const bit = (i) => ((bits15 >>> i) & 1) !== 0;
      for (let i = 0; i <= 5; i++) setFn(8, i, bit(i));
      setFn(8, 7, bit(6)); setFn(8, 8, bit(7)); setFn(7, 8, bit(8));
      for (let i = 9; i < 15; i++) setFn(14 - i, 8, bit(i));
      for (let i = 0; i < 8; i++) setFn(size - 1 - i, 8, bit(i));
      for (let i = 8; i < 15; i++) setFn(8, size - 15 + i, bit(i));
      setFn(8, size - 8, true); // module sombre fixe
    };
    drawFormat(0); // réserve la zone

    if (ver >= 7) {
      let rem = ver;
      for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
      const v = (ver << 12) | rem;
      for (let i = 0; i < 18; i++) {
        const dark = ((v >>> i) & 1) !== 0;
        const a = size - 11 + (i % 3);
        const b = Math.floor(i / 3);
        setFn(a, b, dark); setFn(b, a, dark);
      }
    }

    // Placement des données en zigzag.
    let idx = 0;
    for (let right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (let vert = 0; vert < size; vert++) {
        for (let j = 0; j < 2; j++) {
          const x = right - j;
          const upward = ((right + 1) & 2) === 0;
          const y = upward ? size - 1 - vert : vert;
          if (!fn[y][x] && idx < codewords.length * 8) {
            m[y][x] = (codewords[idx >>> 3] >>> (7 - (idx & 7))) & 1;
            idx++;
          }
        }
      }
    }

    // Choix du masque au plus faible pénalité.
    const applyMask = (mask) => {
      for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!fn[y][x] && MASKS[mask](x, y)) m[y][x] ^= 1;
    };
    let best = 0;
    let bestScore = Infinity;
    for (let mask = 0; mask < 8; mask++) {
      applyMask(mask);
      drawFormat(mask);
      const s = penalty(m, size);
      if (s < bestScore) { bestScore = s; best = mask; }
      applyMask(mask); // annule
    }
    applyMask(best);
    drawFormat(best);

    return { size, version: ver, level, modules: m, isFunction: fn };
  }

  /* ----------------------------------------------------- Rendu SVG stylé */
  const n2 = (v) => (Math.round(v * 100) / 100).toString();

  /** Rectangle arrondi (sous-chemin SVG fermé). */
  function roundRect(x, y, w, h, r) {
    r = Math.min(r, w / 2, h / 2);
    return `M${n2(x + r)} ${n2(y)}h${n2(w - 2 * r)}a${n2(r)} ${n2(r)} 0 0 1 ${n2(r)} ${n2(r)}v${n2(h - 2 * r)}`
      + `a${n2(r)} ${n2(r)} 0 0 1 -${n2(r)} ${n2(r)}h-${n2(w - 2 * r)}a${n2(r)} ${n2(r)} 0 0 1 -${n2(r)} -${n2(r)}`
      + `v-${n2(h - 2 * r)}a${n2(r)} ${n2(r)} 0 0 1 ${n2(r)} -${n2(r)}z`;
  }

  /**
   * Génère le SVG d'un texte.
   * @param {string} text
   * @param {{level?:string, quiet?:number, logo?:boolean, logoRatio?:number, dotSize?:number, eyeRadius?:number, dotClass?:string, eyeClass?:string, label?:string}} [opts]
   *  - logo : dégage le centre pour un logo (superposé en HTML) ; impose au moins le niveau Q.
   *  - logoRatio : largeur de la zone dégagée, en fraction de la largeur du QR (0.22 par défaut).
   *  - dotSize : épaisseur des pilules (0–1, 0.88 par défaut) ; eyeRadius : arrondi des repères (0 = carrés, 1 par défaut ; au-delà de ~1.4 certains lecteurs peinent à repérer les angles).
   *  - dotClass / eyeClass : classes Tailwind de remplissage (le QR doit rester sombre sur fond clair).
   */
  function svg(text, opts = {}) {
    const logo = opts.logo !== false;
    const level = logo && (opts.level === 'L' || opts.level === 'M' || !opts.level) ? 'Q' : opts.level || 'Q';
    const qr = encode(text, level);
    const { size, modules } = qr;
    const quiet = opts.quiet ?? 2;
    const total = size + quiet * 2;
    const logoRatio = logo ? opts.logoRatio ?? 0.22 : 0;

    // Zone dégagée centrale (carrée, en modules, de même parité que la taille pour rester centrée).
    let clear = Math.round(size * logoRatio);
    if ((size - clear) % 2) clear += 1;
    const c0 = (size - clear) / 2;
    const inLogo = (x, y) => logo && x >= c0 && x < c0 + clear && y >= c0 && y < c0 + clear;
    const inEye = (x, y) => (x < 8 && y < 8) || (x >= size - 8 && y < 8) || (x < 8 && y >= size - 8);

    // Données : séries horizontales de modules sombres fusionnées en « pilules ».
    let dots = '';
    const h = opts.dotSize ?? 0.88;
    const off = (1 - h) / 2;
    for (let y = 0; y < size; y++) {
      let x = 0;
      while (x < size) {
        if (modules[y][x] && !inEye(x, y) && !inLogo(x, y)) {
          let end = x;
          while (end + 1 < size && modules[y][end + 1] && !inEye(end + 1, y) && !inLogo(end + 1, y)) end++;
          dots += roundRect(quiet + x + off, quiet + y + off, end - x + 1 - 2 * off, h, h / 2);
          x = end + 1;
        } else {
          x++;
        }
      }
    }

    // Repères d'angle : anneau 7×7 arrondi (évidé en 5×5) + cœur 3×3 arrondi.
    let eyes = '';
    [[0, 0], [size - 7, 0], [0, size - 7]].forEach(([ex, ey]) => {
      const X = quiet + ex;
      const Y = quiet + ey;
      const r = opts.eyeRadius ?? 1;
      eyes += roundRect(X, Y, 7, 7, r) + roundRect(X + 1, Y + 1, 5, 5, r * 0.64) + roundRect(X + 2, Y + 2, 3, 3, r * 0.45);
    });

    const dotClass = opts.dotClass || 'fill-slate-900';
    const eyeClass = opts.eyeClass || 'fill-slate-900';
    const label = String(opts.label || 'QR code').replace(/[<>&"]/g, '');
    const out = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total} ${total}" class="block h-full w-full" role="img" aria-label="${label}" shape-rendering="geometricPrecision">`
      + `<path class="${dotClass}" d="${dots}"/>`
      + `<path class="${eyeClass}" fill-rule="evenodd" d="${eyes}"/>`
      + '</svg>';
    return { svg: out, size, version: qr.version, logoRatio: logo ? (clear / total) : 0 };
  }

  const api = { encode, svg, MAX_VERSION };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else (typeof window !== 'undefined' ? window : globalThis).TalQR = api;
})();
