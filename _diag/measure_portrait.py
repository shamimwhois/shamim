"""Measure the baked duotone against its source + simulate the knockout filter."""

from PIL import Image
import colorsys

import numpy as np


def hsl_stats(rgb: np.ndarray) -> tuple[float, float, float]:
    r, g, b = rgb[:, 0] / 255.0, rgb[:, 1] / 255.0, rgb[:, 2] / 255.0
    mx, mn = np.maximum(np.maximum(r, g), b), np.minimum(np.minimum(r, g), b)
    l = (mx + mn) / 2.0
    d = mx - mn
    s = np.where(d == 0, 0.0, d / (1.0 - np.abs(2.0 * l - 1.0) + 1e-12))
    h = np.zeros_like(l)
    m = d > 1e-12
    idx = m & (mx == r)
    h[idx] = ((g[idx] - b[idx]) / d[idx]) % 6.0
    idx = m & (mx == g) & ~(mx == r)
    h[idx] = (b[idx] - r[idx]) / d[idx] + 2.0
    idx = m & (mx == b) & ~(mx == r) & ~(mx == g)
    h[idx] = (r[idx] - g[idx]) / d[idx] + 4.0
    return float(np.median(h) * 60.0), float(np.median(s)), float(np.median(l))


def main() -> None:
    src = np.asarray(Image.open("_diag/hero-portrait-dark.source.jpg").convert("RGB"), dtype=np.float64)
    out = np.asarray(Image.open("public/images/hero-portrait-dark.jpg").convert("RGB"), dtype=np.float64)

    # Self-calibrating skin ROI: warm pixels (r>g>b, r>110) in the right half.
    r, g, b = src[:, :, 0], src[:, :, 1], src[:, :, 2]
    mask = (r > g) & (g > b) & (r > 110)
    mask[:, : src.shape[1] // 2] = False
    ys, xs = np.nonzero(mask)
    y0, y1 = int(np.percentile(ys, 10)), int(np.percentile(ys, 90))
    x0, x1 = int(np.percentile(xs, 10)), int(np.percentile(xs, 90))
    roi = np.s_[y0:y1, x0:x1]
    print(f"skin ROI y[{y0}:{y1}] x[{x0}:{x1}] ({mask.sum()} px)")

    for name, arr in (("source", src), ("baked ", out)):
        rr = arr[roi].reshape(-1, 3)
        luma = rr @ np.array([0.2126, 0.7152, 0.0722]) / 255.0
        h, s, l = hsl_stats(rr)
        print(f"{name}: face median rgb=({rr[:,0].mean():.0f},{rr[:,1].mean():.0f},{rr[:,2].mean():.0f}) "
              f"luma={luma.mean():.3f} H={h:.0f}deg S={s*100:.0f}% L={l*100:.0f}%")

    luma_all = out @ np.array([0.2126, 0.7152, 0.0722]) / 255.0
    p = np.percentile(luma_all, [5, 50, 90, 99])
    print(f"baked luma p5/p50/p90/p99 = {p[0]:.2f}/{p[1]:.2f}/{p[2]:.2f}/{p[3]:.2f}")

    # Knockout filter simulation: alpha = 1 - min(1, luma*1.02).
    bright = luma_all[luma_all > 0.8]
    alpha_field = 1.0 - np.minimum(1.0, bright * 1.02)
    print(f"studio field: luma>0.8 px={bright.size}, median knockout alpha={np.median(alpha_field):.3f}")


if __name__ == "__main__":
    main()
