"""Deep analysis of the dark hero portrait: can a border flood-fill separate
the white studio field (background) from the subject without ever touching
the face? Prints the numbers that decide the knockout strategy."""

from collections import deque

import numpy as np
from PIL import Image

SRC = "public/images/hero-portrait-dark.jpg"

img = Image.open(SRC).convert("RGB")
a = np.asarray(img).astype(np.int16)
mn = a.min(axis=2)  # the "whiteness" carrier the old SVG filter used
h, w = mn.shape

print(f"size: {w}x{h}")

# --- Old-filter damage model: alpha := saturate(1 - mn) -------------------
# Pixels the old knockout made semi-transparent = bright, low-min pixels.
ghosted = ((255 - mn) / 255.0)
for lo, hi in [(0.05, 0.2), (0.2, 0.5), (0.5, 1.0)]:
    band = ((ghosted >= lo) & (ghosted < hi)).sum()
    print(f"old-filter alpha band {lo}-{hi}: {band} px "
          f"({100 * band / mn.size:.2f}%)  <- visible subjects, not field")

# --- Flood fill from the border over near-white pixels --------------------
for tolerance in (240, 235, 230):
    white = mn >= tolerance
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

    field = flooded.sum()
    # Interior near-white: bright subject pixels the flood never reached.
    interior_bright = (white & ~flooded).sum()
    # Pixels the old ~0.62-0.9 alpha ramp ghosted that flood-fill keeps.
    mn_norm = mn / 255.0
    protected = (((mn_norm >= 0.62) & (mn_norm < 0.9)) & ~flooded).sum()
    print(f"T>={tolerance}: field={field} ({100 * field / mn.size:.1f}%)  "
          f"interior_bright_kept={interior_bright}  "
          f"face/shard px protected vs old filter={protected}")

# Separation band: brighter bright-side of subject vs field start.
mn_max_outside_old_field = None
