# Bundled fonts

The annotation UI uses Outfit for its live-text wordmark and headings, and Source Sans 3 for body text and controls. Source text stays monospace. Both fonts are embedded locally in the page: no external requests, asset publishing, or frontend build is required.

## Outfit

`outfit-variable.woff2` contains the full 100–900 weight range, converted without subsetting from [Google Fonts' Outfit variable TTF](https://github.com/google/fonts/blob/main/ofl/outfit/Outfit%5Bwght%5D.ttf).

The README's `tolltroll-wordmark.svg` and dark variant use actual Outfit glyph outlines at weight 650, shaped with HarfBuzz kerning and −0.02em tracking. This preserves the lettering on GitHub without loading fonts. The icon is unchanged; the annotation UI still uses live text.

Outfit is copyright 2021 The Outfit Project Authors and distributed under the [SIL Open Font License 1.1](OFL.txt), separately from the package's MIT license.

## Source Sans 3

`source-sans-3-variable.woff2` is Adobe's unmodified upright variable WOFF2 (weights 200–900), from [source-sans revision 87b37a2](https://github.com/adobe-fonts/source-sans/blob/87b37a2daaed80fcb8e8ccb0085c4d72ddade12e/WOFF2/VF/SourceSans3VF-Upright.otf.woff2). All upstream glyphs are preserved. Italic styles are not used in the annotation UI.

Source Sans 3 is copyright 2010–2024 Adobe, with Reserved Font Name “Source”, and distributed under its included [SIL Open Font License 1.1](source-sans-3-LICENSE.md), separately from the package's MIT license.
