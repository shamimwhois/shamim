"""One-off: bake an emerald duotone into hero-portrait-dark.jpg.

Per-pixel gradient-map: source luminance -> lerp(deep emerald, pale mint),
with a gamma curve lifting midtones so the lit face keeps full presence.
Targets chosen so the median skin (~20deg hue, 96% sat in the source)
lands as natural silver-teal skin, and the white studio field stays at the
knockout filter's transparent end (light endpoint luma ~0.975, not 1.0 white).
"""

from PIL import Image
import numpy as np

SRC = "public/images/hero-portrait-dark.jpg"
DST = "public/images/hero-portrait-dark.jpg"

SHADOW = np.array([6, 44, 40], dtype=np.float64)      # deep emerald / ink of the palette
LIGHT = np.array([236, 252, 247], dtype=np.float64)   # pale mint, just under knockout-white
GAMMA = 0.82                                          # lifts midtones: face keeps its luminous key


def main() -> None:
    img = Image.open(SRC).convert("RGB")
    src = np.asarray(img, dtype=np.float64)

    luma = src @ np.array([0.2126, 0.7152, 0.0722], dtype=np.float64) / 255.0
    t = np.clip(luma, 0.0, 1.0) ** GAMMA

    out = SHADOW[None, None, :] + (LIGHT - SHADOW)[None, None, :] * t[:, :, None]
    Image.fromarray(np.clip(out, 0, 255).astype(np.uint8), "RGB").save(
        DST, "JPEG", quality=92, optimize=True
    )
    print(f"graded -> {DST} ({img.size[0]}x{img.size[1]})")


if __name__ == "__main__":
    main()
