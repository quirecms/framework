// Rebuilds every web-font file in this folder from pinned upstream sources. See FONTS.md for sources, versions and checksums.
// Usage: npm i harfbuzzjs@0.4.15 fontverter@2.0.0 && node build-fonts.js <srcDir> <outDir>
//   <srcDir> holds the upstream variable TTFs as the curl lines in FONTS.md download them (one sub-folder per family).
// Each output = a character subset + pinned/narrowed axes + only the OpenType features the theme uses, as WOFF2.
// (subset-font keeps every OpenType feature, which made Inter 50% heavier; this is the same harfbuzz call with a feature list.)
const fs = require('fs'); const path = require('path'); const crypto = require('crypto'); const fontverter = require('fontverter');
const [src, out] = process.argv.slice(2);
if (!src || !out) { console.error('usage: node build-fonts.js <srcDir> <outDir>'); process.exit(1); }

const range = (a, b) => { let s = ''; for (let c = a; c <= b; c++) s += String.fromCodePoint(c); return s; };
// LATIN: what the theme's latin @font-face unicode-range declares (ASCII + Latin-1 + the punctuation the theme prints).
const LATIN = range(0x20, 0x7e) + range(0xa0, 0xff) + 'ıŒœ‘’“”–—…•·→←↑↓↩€™−‹›„‚″′∕';
// LATIN_EXT: Latin Extended-A/B, IPA/spacing modifiers, Latin Extended Additional, -C, -D.
const LATIN_EXT = range(0x100, 0x2ff) + range(0x1e00, 0x1eff) + range(0x2c60, 0x2c7f) + range(0xa720, 0xa7ff);
// CYRILLIC: basic Cyrillic (Macedonian, Serbian, Bulgarian, Russian, Ukrainian, Belarusian), Ґґ, Ұұ, combining acute, №.
const CYRILLIC = range(0x400, 0x45f) + 'ҐґҰұ́№';

// Shaping features browsers apply by default, plus what theme.css asks for (tnum, lnum) and locl for Macedonian/Serbian forms.
// Dropped because the theme never turns them on: stylistic sets, character variants, case, sups/subs, onum.
const FEATURES = ['ccmp', 'locl', 'mark', 'mkmk', 'kern', 'liga', 'clig', 'calt', 'rlig', 'rclt', 'rvrn', 'curs', 'dist',
  'ltra', 'ltrm', 'rtla', 'rtlm', 'tnum', 'lnum', 'pnum', 'frac', 'numr', 'dnom'];
const HINTING = process.env.NO_HINTING !== '1'; // keep the sources' hinting (costs < 0.3 KB per file)

const NR = 'newsreader/Newsreader[opsz,wght].ttf', NRI = 'newsreader/Newsreader-Italic[opsz,wght].ttf';
const SS = 'sourceserif4/SourceSerif4[opsz,wght].ttf', SSI = 'sourceserif4/SourceSerif4-Italic[opsz,wght].ttf';
const IN = 'inter/Inter[opsz,wght].ttf';

const JOBS = [
  // name                               source chars      axes
  ['Newsreader-text-latin',             NR,  LATIN,     { wght: [400, 700], opsz: 16 }],
  ['Newsreader-display-latin',          NR,  LATIN,     { wght: [600, 800], opsz: 60 }],
  ['Newsreader-text-italic-latin',      NRI, LATIN,     { wght: 400, opsz: 20 }],      // static 400, as in v7.3 (bold italic is synthesized)
  ['Newsreader-latin-ext',              NR,  LATIN_EXT, { wght: [400, 800] }],   // opsz stays variable: text and display share it
  ['Newsreader-italic-latin-ext',       NRI, LATIN_EXT, { wght: [400, 700], opsz: 20 }],
  ['Inter-ui-latin',                    IN,  LATIN,     { wght: [400, 700], opsz: 14 }],
  ['Inter-latin-ext',                   IN,  LATIN_EXT, { wght: [400, 700], opsz: 14 }],
  // Cyrillic (v7.4): Newsreader has no Cyrillic, so the serif families fall through to Source Serif 4 (locl MKD/SRB/BGR).
  ['SourceSerif4-text-cyrillic',        SS,  CYRILLIC,  { wght: [400, 700], opsz: 16 }],
  ['SourceSerif4-display-cyrillic',     SS,  CYRILLIC,  { wght: [600, 800], opsz: 60 }],
  ['SourceSerif4-text-italic-cyrillic', SSI, CYRILLIC,  { wght: 400, opsz: 20 }],      // matches the Latin italic
  ['Inter-ui-cyrillic',                 IN,  CYRILLIC,  { wght: [400, 700], opsz: 14 }],
];

const tag = s => s.split('').reduce((a, ch) => (a << 8) + ch.charCodeAt(0), 0) >>> 0;
let hb, heap;
async function subset(font, chars, axes) {
  font = await fontverter.convert(font, 'truetype');
  const input = hb.hb_subset_input_create_or_fail();
  const ptr = hb.malloc(font.byteLength); heap().set(new Uint8Array(font), ptr);
  const blob = hb.hb_blob_create(ptr, font.byteLength, 2, 0, 0); const face = hb.hb_face_create(blob, 0); hb.hb_blob_destroy(blob);
  const feats = hb.hb_subset_input_set(input, 6); hb.hb_set_clear(feats); for (const f of FEATURES) hb.hb_set_add(feats, tag(f));
  if (!HINTING) hb.hb_subset_input_set_flags(input, hb.hb_subset_input_get_flags(input) | 0x1); // HB_SUBSET_FLAGS_NO_HINTING
  const uni = hb.hb_subset_input_unicode_set(input); for (const c of chars) hb.hb_set_add(uni, c.codePointAt(0));
  for (const [axis, v] of Object.entries(axes)) {
    const ok = Array.isArray(v) ? hb.hb_subset_input_set_axis_range(input, face, tag(axis), v[0], v[1], NaN)
                                : hb.hb_subset_input_pin_axis_location(input, face, tag(axis), v);
    if (!ok) throw new Error(`axis ${axis} failed`);
  }
  const sub = hb.hb_subset_or_fail(face, input); hb.hb_subset_input_destroy(input);
  if (!sub) throw new Error('hb_subset_or_fail returned 0');
  const res = hb.hb_face_reference_blob(sub); const off = hb.hb_blob_get_data(res, 0); const len = hb.hb_blob_get_length(res);
  const ttf = Buffer.from(heap().subarray(off, off + len));
  hb.hb_blob_destroy(res); hb.hb_face_destroy(sub); hb.hb_face_destroy(face); hb.free(ptr);
  return fontverter.convert(ttf, 'woff2', 'truetype');
}

(async () => {
  const { instance } = await WebAssembly.instantiate(fs.readFileSync(require.resolve('harfbuzzjs/hb-subset.wasm')));
  hb = instance.exports; heap = () => new Uint8Array(hb.memory.buffer);
  fs.mkdirSync(out, { recursive: true });
  for (const [name, file, chars, axes] of JOBS) {
    const buf = await subset(fs.readFileSync(path.join(src, file)), chars, axes);
    fs.writeFileSync(path.join(out, name + '.woff2'), buf);
    console.log(`${name}.woff2\t${(buf.length / 1024).toFixed(1)} KB\tsha256 ${crypto.createHash('sha256').update(buf).digest('hex')}`);
  }
})();
