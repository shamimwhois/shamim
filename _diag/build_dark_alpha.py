"""Build the dark hero portrait with the white studio field removed.

Strategy decided by knockout_mask.py's measurements: border-connected
flood fill over near-white pixels (min(R,G,B) >= 240) marks the background;
everything else — including the ~105k bright interior pixels (face
highlights, glasses, white art strokes, shard glints) the old SVG alpha
ramp was ghosting — stays opaque at full alpha 255. The mask is eroded 1px
and feathered for an AA-grade edge, then baked into the asset so no
runtime filter is needed (and the face can never be touched by one)."""

from collections import deque

import numpy as np
from PIL import Image, ImageFilter

SRC = "public/images/hero-portrait-dark.jpg"
TOLERANCE = 240

rgb = np.asarray(Image.open(SRC).convert("RGB")).astype(np.int16)
mn = rgb.min(axis=2)
h, w = mn.shape
white = mn >= TOLERANCE

# Border-connected flood fill over `white`.
flooded = np.zeros_like(white, dtype=bool)
dq = deque()
for x in range(w):
    for y in (0, h - 1):
        if white[y, x] and not flooded[y, x]:
            flooded[y, x] = True
            dq.append((y, x))
for y in range(h):
    for x in (0, w - 1):
        if white[y, x] and not flooded[y, x]:
            flooded[y, x] = True
            dq.append((y, x))
while dq:
    y, x = dq.popleft()
    for ny, nx in ((y - 1, x), (y + 1, x), (y, x - 1), (y, x + 1)):
        if 0 <= ny < h and 0 <= nx < w and white[ny, nx] and not flooded[ny, nx]:
            flooded[ny, nx] = True
            dq.append((ny, nx))

# Alpha mask: background 0, subject 255. Hard mask first...
hard = np.where(flooded, 0, 255).astype(np.uint8)
hard[~white & ~flooded] = hard[~white & ~flooded]  # non-white subject = 255
mask = Image.fromarray(hard, "L")
# ...then erode to kill the white AA fringe...
mask = mask.filter(ImageFilter.MinFilter(3))
# ...and feather for a clean edge on any background.
mask = mask.filter(ImageFilter.GaussianBlur(0.8))

rgba = Image.open(SRC).convert("RGB").convert("RGBA")
rgba.putalpha(mask)

png_path = "public/images/hero-portrait-dark.alpha.png"
rgba.save(png_path, "PNG", optimize=True)
webp_path = "public/images/hero-portrait-dark.alpha.webp"
rgba.save(webp_path, "WEBP", quality=95, method=6)

import os

print(f"field removed: {flooded.sum()} px ({100 * flooded.sum() / mn.size:.1f}%), "
      f"interior bright kept: {(white & ~flooded).sum()} px")
print(f"PNG {os.path.getsize(png_path):,} B   WebP {os.path.getsize(webp_path):,} B")

# Sanity: face-region pixel (upper-left quadrant, on the head) stays opaque.
cx, cy = int(w * 0.23), int(h * 0.30)
print("face px:", rgba.getpixel((cx, cy)))
