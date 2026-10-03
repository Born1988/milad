"""One-off generator for the game's PNG sprites (run once, commit the output).

Draws everything at 4x supersampling with Pillow, then downsamples with
LANCZOS for clean anti-aliasing, so the shapes look like real rendered
sprites instead of flat polygons drawn live every frame. Meteors are
baked in neutral grayscale on purpose: the game tints them per-stage at
runtime with BLEND_RGBA_MULT, so one asset serves every color phase.

Run with:  python3 gen_assets.py
"""

import math
import os

from PIL import Image, ImageDraw, ImageFilter

OUT = os.path.join(os.path.dirname(__file__), "assets")
os.makedirs(OUT, exist_ok=True)
SS = 4  # supersampling factor


def canvas(w, h):
    return Image.new("RGBA", (w * SS, h * SS), (0, 0, 0, 0))


def save(img, name, final_size):
    img = img.resize((final_size[0] * SS // SS, final_size[1] * SS // SS), Image.LANCZOS) \
        if img.size == (final_size[0] * SS, final_size[1] * SS) else img
    img = img.resize(final_size, Image.LANCZOS)
    img.save(os.path.join(OUT, name))
    print("wrote", name, img.size)


def vgradient(size, stops):
    """stops: list of (t in [0,1], (r,g,b,a))."""
    w, h = size
    grad = Image.new("RGBA", (1, h))
    px = grad.load()
    for y in range(h):
        t = y / max(1, h - 1)
        lo = stops[0]
        hi = stops[-1]
        for i in range(len(stops) - 1):
            if stops[i][0] <= t <= stops[i + 1][0]:
                lo, hi = stops[i], stops[i + 1]
                break
        span = max(1e-6, hi[0] - lo[0])
        local_t = (t - lo[0]) / span
        c = tuple(int(lo[1][k] + (hi[1][k] - lo[1][k]) * local_t) for k in range(4))
        px[0, y] = c
    return grad.resize((w, h))


def masked_fill(mask_l, gradient_rgba):
    out = Image.new("RGBA", mask_l.size, (0, 0, 0, 0))
    out.paste(gradient_rgba, (0, 0), mask_l)
    return out


def poly_mask(size, points):
    m = Image.new("L", size, 0)
    ImageDraw.Draw(m).polygon(points, fill=255)
    return m


# ---------------------------------------------------------------- plane ----
def make_plane():
    W, H = 72, 96
    img = canvas(W, H)
    w, h = W * SS, H * SS
    cx, cy = w / 2, h / 2

    shadow = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).ellipse(
        [cx - w * 0.22, cy + h * 0.30, cx + w * 0.22, cy + h * 0.40], fill=(0, 0, 0, 90)
    )
    shadow = shadow.filter(ImageFilter.GaussianBlur(w * 0.02))
    img = Image.alpha_composite(img, shadow)

    tail_pts = [
        (cx, cy - h * 0.40),
        (cx - w * 0.17, cy + h * 0.16),
        (cx - w * 0.06, cy + h * 0.16),
    ]
    tail_grad = vgradient((w, h), [(0, (235, 238, 244, 255)), (1, (190, 196, 210, 255))])
    img = Image.alpha_composite(img, masked_fill(poly_mask((w, h), tail_pts), tail_grad))

    wing_grad = vgradient((w, h), [(0, (225, 231, 240, 255)), (1, (175, 183, 200, 255))])
    wing_l = [
        (cx, cy - h * 0.02), (cx - w * 0.46, cy + h * 0.17),
        (cx - w * 0.30, cy + h * 0.17), (cx, cy + h * 0.08),
    ]
    wing_r = [(2 * cx - p[0], p[1]) for p in wing_l]
    img = Image.alpha_composite(img, masked_fill(poly_mask((w, h), wing_l), wing_grad))
    img = Image.alpha_composite(img, masked_fill(poly_mask((w, h), wing_r), wing_grad))

    body_pts = [
        (cx, cy - h * 0.46),
        (cx - w * 0.13, cy + h * 0.05),
        (cx - w * 0.095, cy + h * 0.30),
        (cx + w * 0.095, cy + h * 0.30),
        (cx + w * 0.13, cy + h * 0.05),
    ]
    body_grad = vgradient((w, h), [
        (0.0, (255, 255, 255, 255)),
        (0.45, (238, 241, 246, 255)),
        (1.0, (205, 211, 222, 255)),
    ])
    body_mask = poly_mask((w, h), body_pts)
    img = Image.alpha_composite(img, masked_fill(body_mask, body_grad))

    d = ImageDraw.Draw(img)
    d.line([body_pts[0], body_pts[1], body_pts[2]], fill=(150, 157, 172, 255), width=max(2, w // 90))
    d.line([body_pts[0], body_pts[4], body_pts[3]], fill=(150, 157, 172, 255), width=max(2, w // 90))

    stripe = [
        (cx, cy - h * 0.02), (cx - w * 0.065, cy + h * 0.22), (cx + w * 0.065, cy + h * 0.22),
    ]
    stripe_grad = vgradient((w, h), [(0, (255, 184, 97, 255)), (1, (230, 120, 40, 255))])
    img = Image.alpha_composite(img, masked_fill(poly_mask((w, h), stripe), stripe_grad))

    cockpit_box = [cx - w * 0.075, cy - h * 0.30, cx + w * 0.075, cy - h * 0.14]
    cockpit = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    cd = ImageDraw.Draw(cockpit)
    cd.ellipse(cockpit_box, fill=(90, 190, 255, 255))
    img = Image.alpha_composite(img, cockpit)
    highlight = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    hb = [cx - w * 0.05, cy - h * 0.285, cx + w * 0.01, cy - h * 0.2]
    ImageDraw.Draw(highlight).ellipse(hb, fill=(255, 255, 255, 190))
    img = Image.alpha_composite(img, highlight)

    img = img.resize((W, H), Image.LANCZOS)
    img.save(os.path.join(OUT, "plane.png"))
    print("wrote plane.png", img.size)


# --------------------------------------------------------------- meteor ----
def make_meteor():
    size = 160
    w = h = size * SS
    img = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    cx, cy = w / 2, h / 2
    r = w * 0.40

    import random
    rnd = random.Random(7)
    n = 11
    points = []
    for i in range(n):
        a = (math.tau / n) * i
        jitter = 0.72 + rnd.uniform(0, 0.28)
        points.append((cx + math.cos(a) * r * jitter, cy + math.sin(a) * r * jitter))

    base_grad = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    bd = ImageDraw.Draw(base_grad)
    for rr in range(int(r), 0, -2):
        t = rr / r
        shade = int(235 - 90 * (1 - t))
        bd.ellipse([cx - rr, cy - rr, cx + rr, cy + rr], fill=(shade, shade, shade, 255))
    mask = poly_mask((w, h), points)
    img = Image.alpha_composite(img, masked_fill(mask, base_grad))

    d = ImageDraw.Draw(img)
    d.polygon(points, outline=(90, 90, 90, 255), width=max(2, w // 60))

    craters = [(-0.28, -0.18, 0.17), (0.3, 0.12, 0.12), (0.02, 0.3, 0.09), (-0.1, -0.32, 0.08)]
    for dx, dy, rad in craters:
        crater_r = rad * w
        cxx, cyy = cx + dx * w, cy + dy * h
        cm = Image.new("L", (w, h), 0)
        ImageDraw.Draw(cm).ellipse([cxx - crater_r, cyy - crater_r, cxx + crater_r, cyy + crater_r], fill=200)
        cm = cm.filter(ImageFilter.GaussianBlur(crater_r * 0.18))
        shadow_layer = Image.new("RGBA", (w, h), (80, 80, 80, 255))
        img = Image.composite(shadow_layer, img, cm)
        rim = Image.new("L", (w, h), 0)
        ImageDraw.Draw(rim).ellipse(
            [cxx - crater_r * 0.5, cyy - crater_r * 0.55, cxx + crater_r * 0.25, cyy + crater_r * 0.1],
            fill=150,
        )
        rim = rim.filter(ImageFilter.GaussianBlur(crater_r * 0.12))
        light_layer = Image.new("RGBA", (w, h), (255, 255, 255, 255))
        img = Image.composite(light_layer, img, rim)

    img.putalpha(Image.composite(Image.new("L", (w, h), 255), Image.new("L", (w, h), 0), mask))

    img = img.resize((size, size), Image.LANCZOS)
    img.save(os.path.join(OUT, "meteor.png"))
    print("wrote meteor.png", img.size)


# ----------------------------------------------------------------- items ---
def glossy_badge(size, ring_color, fill_func):
    w = h = size * SS
    img = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    cx, cy = w / 2, h / 2
    r = w * 0.46

    shadow = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(shadow).ellipse([cx - r, cy - r + h * 0.03, cx + r, cy + r + h * 0.03], fill=(0, 0, 0, 70))
    img = Image.alpha_composite(img, shadow.filter(ImageFilter.GaussianBlur(w * 0.02)))

    base = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(base).ellipse([cx - r, cy - r, cx + r, cy + r], fill=(255, 255, 255, 255))
    img = Image.alpha_composite(img, base)

    d = ImageDraw.Draw(img)
    d.ellipse([cx - r, cy - r, cx + r, cy + r], outline=ring_color, width=max(3, int(w * 0.035)))

    fill_func(img, cx, cy, r)

    gloss = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(gloss).ellipse(
        [cx - r * 0.55, cy - r * 0.75, cx + r * 0.15, cy - r * 0.1], fill=(255, 255, 255, 90)
    )
    img = Image.alpha_composite(img, gloss.filter(ImageFilter.GaussianBlur(w * 0.01)))
    return img.resize((size, size), Image.LANCZOS)


def make_items():
    def coin_fill(img, cx, cy, r):
        d = ImageDraw.Draw(img)
        d.ellipse([cx - r * 0.62, cy - r * 0.62, cx + r * 0.62, cy + r * 0.62], fill=(255, 212, 80, 255))
        pts = []
        for i in range(10):
            a = -math.pi / 2 + i * math.pi / 5
            rad = r * (0.34 if i % 2 else 0.58)
            pts.append((cx + math.cos(a) * rad, cy + math.sin(a) * rad))
        d.polygon(pts, fill=(255, 245, 210, 255))

    def shield_fill(img, cx, cy, r):
        d = ImageDraw.Draw(img)
        pts = [
            (cx, cy - r * 0.6), (cx + r * 0.55, cy - r * 0.25), (cx + r * 0.55, cy + r * 0.2),
            (cx, cy + r * 0.65), (cx - r * 0.55, cy + r * 0.2), (cx - r * 0.55, cy - r * 0.25),
        ]
        d.polygon(pts, fill=(110, 220, 255, 255), outline=(255, 255, 255, 255))

    def rapid_fill(img, cx, cy, r):
        d = ImageDraw.Draw(img)
        pts = [
            (cx - r * 0.08, cy - r * 0.6), (cx - r * 0.5, cy + r * 0.08), (cx - r * 0.05, cy + r * 0.08),
            (cx - r * 0.25, cy + r * 0.62), (cx + r * 0.5, cy - r * 0.05), (cx + r * 0.05, cy - r * 0.05),
        ]
        d.polygon(pts, fill=(255, 221, 70, 255), outline=(200, 150, 0, 255))

    def multishot_fill(img, cx, cy, r):
        d = ImageDraw.Draw(img)
        for dx in (-0.35, 0, 0.35):
            tip = (cx + dx * r * 2, cy - r * 0.5)
            d.polygon([tip, (tip[0] - r * 0.14, tip[1] + r * 0.4), (tip[0] + r * 0.14, tip[1] + r * 0.4)],
                      fill=(178, 130, 255, 255))

    def life_fill(img, cx, cy, r):
        d = ImageDraw.Draw(img)
        rr = r * 0.34
        d.ellipse([cx - rr * 2.1, cy - rr * 1.3, cx - rr * 0.1, cy + rr * 0.9], fill=(255, 99, 132, 255))
        d.ellipse([cx + rr * 0.1, cy - rr * 1.3, cx + rr * 2.1, cy + rr * 0.9], fill=(255, 99, 132, 255))
        d.polygon([(cx - rr * 1.7, cy - rr * 0.15), (cx + rr * 1.7, cy - rr * 0.15), (cx, cy + rr * 1.8)],
                  fill=(255, 99, 132, 255))

    make_items_table = [
        ("item_coin.png", (220, 170, 40), coin_fill),
        ("item_shield.png", (60, 180, 220), shield_fill),
        ("item_rapid.png", (210, 160, 0), rapid_fill),
        ("item_multishot.png", (140, 90, 220), multishot_fill),
        ("item_life.png", (220, 70, 100), life_fill),
    ]
    for name, ring, fn in make_items_table:
        img = glossy_badge(48, ring, fn)
        img.save(os.path.join(OUT, name))
        print("wrote", name, img.size)


# ------------------------------------------------------------------ heart --
def make_heart():
    size = 48
    w = h = size * SS
    img = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    cx, cy = w / 2, h * 0.46
    rr = w * 0.26
    d = ImageDraw.Draw(img)
    d.ellipse([cx - rr * 2.05, cy - rr * 1.25, cx - rr * 0.05, cy + rr * 0.85], fill=(255, 92, 130, 255))
    d.ellipse([cx + rr * 0.05, cy - rr * 1.25, cx + rr * 2.05, cy + rr * 0.85], fill=(255, 92, 130, 255))
    d.polygon(
        [(cx - rr * 1.7, cy - rr * 0.1), (cx + rr * 1.7, cy - rr * 0.1), (cx, cy + rr * 1.75)],
        fill=(255, 92, 130, 255),
    )
    gloss = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(gloss).ellipse(
        [cx - rr * 1.5, cy - rr * 1.0, cx - rr * 0.5, cy - rr * 0.3], fill=(255, 255, 255, 110)
    )
    img = Image.alpha_composite(img, gloss.filter(ImageFilter.GaussianBlur(w * 0.012)))
    img = img.resize((size, size), Image.LANCZOS)
    img.save(os.path.join(OUT, "heart.png"))
    print("wrote heart.png", img.size)


if __name__ == "__main__":
    make_plane()
    make_meteor()
    make_items()
    make_heart()
