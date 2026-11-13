"""Build Dapter's vector identity with the Artisan Toolbox palette and wordmark."""

import argparse
from pathlib import Path

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--font", default="/System/Library/Fonts/Avenir Next.ttc")
parser.add_argument("--font-number", type=int, default=0)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
font = TTFont(args.font, fontNumber=args.font_number)
glyphs = font.getGlyphSet()
cmap = font.getBestCmap()
scale = 44 / font["head"].unitsPerEm
pen = SVGPathPen(glyphs, ntos=lambda value: f"{value:.2f}")
x = 80
for letter in "Dapter":
    name = cmap[ord(letter)]
    glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, x, 47)))
    x += font["hmtx"][name][0] * scale

artwork = '''  <rect x="2" y="2" width="60" height="60" rx="14" fill="#20252B"/>
  <rect x="2.75" y="2.75" width="58.5" height="58.5" rx="13.25" fill="none" stroke="#FFFFFF" stroke-opacity=".16" stroke-width="1.5"/>
  <path d="M11 20c0-3 4-5 9-5s9 2 9 5v24c0 3-4 5-9 5s-9-2-9-5Z" fill="#FFF7EF"/>
  <path d="M11 20c0 3 4 5 9 5s9-2 9-5M11 30c0 3 4 5 9 5M11 40c0 3 4 5 9 5" fill="none" stroke="#20252B" stroke-width="2"/>
  <rect x="37" y="15" width="17" height="34" rx="3" fill="#FF9A86"/>
  <path d="M41 22h9M43 32h7M43 41h7" fill="none" stroke="#20252B" stroke-width="2.5" stroke-linecap="round"/>
  <path d="M24 26h8v-4l11 10-11 10v-4h-8Z" fill="#FF5947" stroke="#20252B" stroke-width="2" stroke-linejoin="round"/>
'''
output = root / "logo"
output.mkdir(exist_ok=True)
for theme, color in (("icon", None), ("light", "#20252B"), ("dark", "#FFF7EF")):
    width = 64 if color is None else round(x + 4)
    wordmark = "" if color is None else f'  <path fill="{color}" d="{pen.getCommands()}"/>\n'
    title = "Dapter icon" if color is None else "Dapter"
    (output / f"dapter-{theme}.svg").write_text(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} 64" role="img" aria-labelledby="title">\n'
        f'  <title id="title">{title}</title>\n{artwork}{wordmark}</svg>\n'
    )
