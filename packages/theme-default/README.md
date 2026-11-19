# Quire default theme

The default theme of [Quire](https://quirecms.com): plain semantic HTML and CSS, responsive, with a light and a dark look that follows the visitor's system setting. The home page is an edition (the newest post leads, then the week, then earlier posts by month), "All posts" is the archive, and posts show where they live, what's on them and how they changed. It meets WCAG 2.2 AA, writes right-to-left languages mirrored, covers Latin and Cyrillic, and comes in English and German.

New Quire sites use it already. To use it again after switching:

```bash
bin/console quire:theme:use quire-theme-default
```

Settings: `theme.quire-theme-default.accent` (`blue`, `green`, `purple`, `orange` or `red`), `theme.quire-theme-default.show_dates` and `theme.quire-theme-default.show_credit`.

Fonts: Newsreader, Inter and Source Serif 4, served by the site, under the SIL Open Font License 1.1 (`assets/fonts/`, with `FONTS.md` on how they're built).

To build your own look on it, make a child theme with `"theme": {"parent": "quire-theme-default"}` in `extra.quire`, add a stylesheet that overrides its CSS custom properties, and override the templates you want to change. Each template starts with a comment listing its variables, blocks and slots. See the [theme documentation](https://github.com/quirecms/framework/blob/main/docs/themes.md).

License: MIT; the fonts: SIL Open Font License 1.1.
