# Quire default theme

The default theme of [Quire](https://quirecms.com): plain semantic HTML and CSS, no JavaScript, responsive, with a light and a dark look that follows the visitor's system setting. It meets WCAG 2.2 AA, writes right-to-left languages mirrored, and comes in English and German.

New Quire sites use it already. To use it again after switching:

```bash
bin/console quire:theme:use quire-theme-default
```

Settings: `theme.quire-theme-default.accent` (`blue`, `green`, `purple`, `orange` or `red`) and `theme.quire-theme-default.show_dates`.

To build your own look on it, make a child theme with `"theme": {"parent": "quire-theme-default"}` in `extra.quire`, add a stylesheet that overrides its CSS custom properties, and override the templates you want to change. Each template starts with a comment listing its variables, blocks and slots. See the [theme documentation](https://github.com/quirecms/framework/blob/main/docs/themes.md).

License: MIT.
