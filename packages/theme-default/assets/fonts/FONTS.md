# Theme fonts: sources, licenses and how to rebuild them

All three families are under the **SIL Open Font License 1.1**. Ship each license text with the theme: `OFL-newsreader.txt`, `OFL-sourceserif4.txt`, `OFL-inter.txt`. The OFL allows subsetting and bundling, and the theme's MIT license doesn't cover the fonts. None of the families has a Reserved Font Name, so the subset files may keep their names.

## Sources (pinned)
The variable TTFs come from the google/fonts repository at commit `7af0d90b4d532ffa4b041937b560c6df4e7b1e95` (18 Nov 2026).

| Family | Version | Upstream (per google/fonts METADATA.pb) | File | sha256 |
|---|---|---|---|---|
| Newsreader | 1.003 | github.com/productiontype/Newsreader @ 1ece6a8b | `ofl/newsreader/Newsreader[opsz,wght].ttf` | `8a08d13f8a6c0d51be379a60af84f945f65369a67e509ee3c3bdcc421254d7c1` |
| | | | `ofl/newsreader/Newsreader-Italic[opsz,wght].ttf` | `796668611f80b64d5adf182fde3b6f29ed83b4e7cbec7b96937e84ac01364792` |
| Source Serif 4 | 4.004 | github.com/adobe-fonts/source-serif @ b3980ade | `ofl/sourceserif4/SourceSerif4[opsz,wght].ttf` | `97b2d4da6e3cb494b5a1e66ae176914d852ccabef49e0c02c0df25f3e39aca0b` |
| | | | `ofl/sourceserif4/SourceSerif4-Italic[opsz,wght].ttf` | `15fbc7e4679489a501998c3669272637a6646388ef7e4bd77eebb5bf967a1f42` |
| Inter | 4.001 | github.com/rsms/inter @ 66647c0b (Inter-4.1-GoogleFonts.zip) | `ofl/inter/Inter[opsz,wght].ttf` | `29160a80ff49ddcab2c97711247e08b1fab27a484a329ce8b813d820dc559031` |

```sh
C=7af0d90b4d532ffa4b041937b560c6df4e7b1e95; mkdir -p src/newsreader src/sourceserif4 src/inter
for p in 'newsreader/Newsreader[opsz,wght].ttf' 'newsreader/Newsreader-Italic[opsz,wght].ttf' newsreader/OFL.txt \
         'sourceserif4/SourceSerif4[opsz,wght].ttf' 'sourceserif4/SourceSerif4-Italic[opsz,wght].ttf' sourceserif4/OFL.txt \
         'inter/Inter[opsz,wght].ttf' inter/OFL.txt; do
  curl -fL -o "src/$p" "https://github.com/google/fonts/raw/$C/ofl/$(echo "$p" | sed 's/\[/%5B/;s/\]/%5D/')"; done
sha256sum src/*/*.ttf    # must match the table
```

## Rebuild
```sh
npm i harfbuzzjs@0.4.15 fontverter@2.1.0
node build-fonts.js src .
```
`build-fonts.js` is the only definition of what's in each file: the character set, the axes and the OpenType features. It prints each output's sha256. The build is deterministic for the same harfbuzzjs version.

## The files
| File | Family name in CSS | Source | Characters | Axes | KB |
|---|---|---|---|---|---|
| Newsreader-text-latin | Newsreader Text | Newsreader | Latin | wght 400–700, opsz 16 | 38.5 |
| Newsreader-display-latin | Newsreader Display | Newsreader | Latin | wght 600–800, opsz 60 | 39.0 |
| Newsreader-text-italic-latin | Newsreader Text, italic | Newsreader Italic | Latin | wght 400, opsz 20 (bold italic is synthesized) | 22.3 |
| Newsreader-latin-ext | Newsreader Text and Display | Newsreader | Latin Extended | wght 400–800, opsz variable | 54.8 |
| Newsreader-italic-latin-ext | Newsreader Text, italic | Newsreader Italic | Latin Extended | wght 400–700, opsz 20 | 24.4 |
| Inter-ui-latin | Inter | Inter | Latin | wght 400–700, opsz 14 | 28.1 |
| Inter-latin-ext | Inter | Inter | Latin Extended | wght 400–700, opsz 14 | 46.3 |
| SourceSerif4-text-cyrillic | Newsreader Text | Source Serif 4 | Cyrillic | wght 400–700, opsz 16 | 24.8 |
| SourceSerif4-display-cyrillic | Newsreader Display | Source Serif 4 | Cyrillic | wght 600–800, opsz 60 | 23.6 |
| SourceSerif4-text-italic-cyrillic | Newsreader Text, italic | Source Serif 4 Italic | Cyrillic | wght 400, opsz 20 | 12.9 |
| Inter-ui-cyrillic | Inter | Inter | Cyrillic | wght 400–700, opsz 14 | 12.3 |

**Features kept:** default shaping features plus `tnum`, `lnum`, `pnum`, `frac`/`numr`/`dnom` and `locl`. Stylistic sets and character variants are dropped because the theme doesn't use them; keeping them made Inter 50% heavier.

## How the theme uses them
- **Latin pages** download the three Latin files (106 KB), plus the italic on articles (128 KB).
- **Cyrillic:** Newsreader has no Cyrillic. The Source Serif 4 files are declared under the **same family names** with a Cyrillic `unicode-range`, so the browser picks the right file per character and templates never change.
  - `size-adjust` (95% for text and italic, 104% for display) brings Source Serif's taller x-height close to Newsreader's.
  - A Macedonian page downloads the Latin files too, for digits, spaces and Latin names: about 167 KB in total.
  - **Preload by locale:** for a Cyrillic site, preload `SourceSerif4-display-cyrillic`, `SourceSerif4-text-cyrillic` and `Inter-ui-cyrillic` instead of the Latin trio.
- **Macedonian letterforms** (the italic б г д п т, and ѓ ќ) come from Source Serif 4's `locl` for MKD and SRB. They only switch on with `<html lang="mk">` (or `sr`), so the theme must print the site's locale in `lang`.
- **Arabic and other scripts:** the system fallback, as before (page 11).
