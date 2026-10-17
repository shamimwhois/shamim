"""Build the dark hero portrait with ALL white field removed — including the
big white disc painted around the head, which the v1 border-only flood fill
could not reach (the disc's toned edge ring blocked the flood).

v2 strategy: connected-component analysis instead of a border flood.
  1. near-white mask: neutral, bright pixels (min(RGB) >= 238, max-min <= 24),
     so warm skin/colored art can never qualify.
  2. label connected white components (scipy); ANY component covering more
     than AREA_MIN pixels is background — that catches the outer studio field
     and the enclosed white disc alike, while the small bright subject
     details (glasses glare, white art strokes, shard glints — all far
     below the area cut) stay opaque at full alpha 255.
  3. alpha = 255 everywhere except background; the alpha boundary is feathered
     with a 1px gaussian for an AA-grade edge.
Baked into the asset so no runtime filter can ever ghost the face."""

import numpy as np
from PIL import Image
from scipy import ndimage

SRC = "public/images/hero-portrait-dark.jpg"
OUT = "public/images/hero-portrait-dark.alpha.webp"
AREA_MIN = 3000  # px; the smallest real background is the enclosed disc (~200k px)

rgb = np.asarray(Image.open(SRC).convert("RGB")).astype(np.int16)
mn = rgb.min(axis=2)
mx = rgb.max(axis=2)
white = (mn >= 238) & ((mx - mn) <= 24)

labels, n = ndimage.label(white)
if n:
    areas = np.bincount(labels.ravel())
    big = np.flatnonzero(areas >= AREA_MIN)
    bg = np.isin(labels, big[big > 0]) if big.size else np.zeros_like(white)
else:
    bg = np.zeros_like(white)

bg_f = ndimage.gaussian_filter(bg.astype(np.float32), sigma=1.0)
alpha = np.clip((1.0 - bg_f) * 255.0, 0, 255).astype(np.uint8)

out = np.dstack([rgb.astype(np.uint8), alpha])
img = Image.fromarray(out, "RGBA")
img.save(OUT, "WEBP", quality=92, method=6)

a = img.getchannel("A")
hist = a.histogram()
print(f"bg components removed: {int(bg.sum())} px ({bg.mean() * 100:.1f}%)")
print(f"alpha255 {sum(hist[250:])}  alpha0 {sum(hist[:6])}  mid {sum(hist[6:250])}")
for name, (x, y) in {
    "disc white (207,409)": (207, 409),
    "outer field (240,691)": (240, 691),
    "face skin (330,356)": (330, 356),
    "hair dark (400,120)": (400, 120),
}.items():
    print(f"{name}: {img.getpixel((x, y))}")
