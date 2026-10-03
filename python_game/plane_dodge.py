"""Plane Dodge - an endless plane-dodging arcade game built with pygame.

Steer the plane left and right (arrow keys / A-D, or drag with the mouse)
to weave between falling meteors while your plane auto-fires glowing
tracer shots to blast them out of the sky. Survive as long as you can;
the game speeds up, meteors grow denser, and the sky shifts through
color phases the longer you last.

Run with:  python3 plane_dodge.py
"""

import json
import math
import os
import random
import sys

import pygame

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import sound as sound_module

WIDTH, HEIGHT = 480, 800
FPS = 60
HIGHSCORE_FILE = os.path.join(os.path.dirname(__file__), "highscore.json")
_BASE_DIR = getattr(sys, "_MEIPASS", os.path.dirname(os.path.abspath(__file__)))
ASSETS_DIR = os.path.join(_BASE_DIR, "assets")

ITEM_IMAGE_FILES = {
    "coin": "item_coin.png",
    "shield": "item_shield.png",
    "rapid": "item_rapid.png",
    "multishot": "item_multishot.png",
    "life": "item_life.png",
}


def load_images():
    """Loads the pre-rendered PNG sprites (see gen_assets.py). Returns {} if missing."""
    images = {}
    try:
        images["plane"] = pygame.image.load(os.path.join(ASSETS_DIR, "plane.png")).convert_alpha()
        images["meteor"] = pygame.image.load(os.path.join(ASSETS_DIR, "meteor.png")).convert_alpha()
        images["heart"] = pygame.image.load(os.path.join(ASSETS_DIR, "heart.png")).convert_alpha()
        for kind, fname in ITEM_IMAGE_FILES.items():
            images[f"item_{kind}"] = pygame.image.load(os.path.join(ASSETS_DIR, fname)).convert_alpha()
    except (pygame.error, FileNotFoundError):
        return {}
    return images

WHITE = (255, 255, 255)
GOLD = (255, 212, 121)
ORANGE = (255, 157, 61)
CYAN = (120, 220, 255)
DANGER = (239, 68, 68)
DANGER_DARK = (127, 29, 29)
PURPLE = (168, 120, 255)
GREEN = (110, 230, 150)
PINK = (255, 130, 190)

MAX_LIVES = 3
# Triangular thresholds: stage N is cleared once the score passes this.
def stage_threshold(stage):
    return int(380 * stage * (stage + 1) / 2)

# Slowly-cycling sky palettes: (top, mid, bottom). The game blends between
# consecutive palettes over time so the backdrop never looks static.
SKY_PALETTES = [
    ((10, 20, 46), (24, 58, 110), (54, 120, 190)),   # dusk blue
    ((18, 10, 40), (72, 30, 96), (196, 90, 110)),    # violet sunset
    ((6, 8, 28), (18, 24, 70), (40, 70, 130)),        # deep night
    ((8, 24, 34), (14, 70, 88), (58, 160, 150)),      # aurora teal
]
PALETTE_DURATION = 22.0


def lerp(a, b, t):
    return a + (b - a) * t


def lerp_color(c1, c2, t):
    return tuple(int(lerp(c1[i], c2[i], t)) for i in range(3))


def sky_colors(elapsed):
    n = len(SKY_PALETTES)
    idx = int(elapsed // PALETTE_DURATION) % n
    nxt = (idx + 1) % n
    t = (elapsed % PALETTE_DURATION) / PALETTE_DURATION
    t = t * t * (3 - 2 * t)  # smoothstep
    a, b = SKY_PALETTES[idx], SKY_PALETTES[nxt]
    return tuple(lerp_color(a[i], b[i], t) for i in range(3))


def load_highscore():
    try:
        with open(HIGHSCORE_FILE, "r", encoding="utf-8") as f:
            return json.load(f).get("best", 0)
    except (FileNotFoundError, json.JSONDecodeError):
        return 0


def save_highscore(value):
    try:
        with open(HIGHSCORE_FILE, "w", encoding="utf-8") as f:
            json.dump({"best": value}, f)
    except OSError:
        pass


class Star:
    def __init__(self):
        self.x = random.uniform(0, WIDTH)
        self.y = random.uniform(0, HEIGHT)
        self.size = random.uniform(1, 2.6)
        self.speed = random.uniform(20, 60)
        self.twinkle = random.uniform(0, math.tau)

    def update(self, dt, speed_mult):
        self.y += self.speed * speed_mult * dt
        self.twinkle += dt * 3
        if self.y > HEIGHT:
            self.y = -4
            self.x = random.uniform(0, WIDTH)

    def draw(self, surf):
        alpha = 140 + int(90 * math.sin(self.twinkle))
        s = pygame.Surface((6, 6), pygame.SRCALPHA)
        pygame.draw.circle(s, (255, 255, 255, max(0, alpha)), (3, 3), self.size)
        surf.blit(s, (self.x - 3, self.y - 3))


class Cloud:
    def __init__(self, y=None):
        self.x = random.uniform(-40, WIDTH + 40)
        self.y = y if y is not None else random.uniform(0, HEIGHT)
        self.scale = random.uniform(0.6, 1.5)
        self.speed = random.uniform(30, 70)
        self.alpha = random.randint(30, 70)

    def update(self, dt, speed_mult):
        self.y += self.speed * speed_mult * dt
        if self.y - 60 * self.scale > HEIGHT:
            self.y = -60 * self.scale
            self.x = random.uniform(-40, WIDTH + 40)

    def draw(self, surf):
        w, h = 130 * self.scale, 46 * self.scale
        s = pygame.Surface((w, h), pygame.SRCALPHA)
        color = (255, 255, 255, self.alpha)
        pygame.draw.ellipse(s, color, (0, h * 0.3, w * 0.6, h * 0.6))
        pygame.draw.ellipse(s, color, (w * 0.25, 0, w * 0.55, h * 0.75))
        pygame.draw.ellipse(s, color, (w * 0.45, h * 0.25, w * 0.55, h * 0.65))
        surf.blit(s, (self.x - w / 2, self.y - h / 2))


class Particle:
    __slots__ = ("x", "y", "vx", "vy", "life", "max_life", "color", "size", "gravity")

    def __init__(self, x, y, vx, vy, life, color, size, gravity=0.0):
        self.x, self.y, self.vx, self.vy = x, y, vx, vy
        self.life = life
        self.max_life = life
        self.color = color
        self.size = size
        self.gravity = gravity

    def update(self, dt):
        self.vy += self.gravity * dt
        self.x += self.vx * dt
        self.y += self.vy * dt
        self.life -= dt
        return self.life > 0

    def draw(self, surf):
        t = max(0.0, self.life / self.max_life)
        alpha = int(255 * t)
        size = max(1, self.size * t)
        s = pygame.Surface((size * 2, size * 2), pygame.SRCALPHA)
        pygame.draw.circle(s, (*self.color, alpha), (size, size), size)
        surf.blit(s, (self.x - size, self.y - size))


class ShockRing:
    """An expanding, fading ring used for explosion punch."""

    __slots__ = ("x", "y", "radius", "max_radius", "life", "max_life", "color", "width")

    def __init__(self, x, y, max_radius, life, color, width=4):
        self.x, self.y = x, y
        self.radius = max_radius * 0.15
        self.max_radius = max_radius
        self.life = life
        self.max_life = life
        self.color = color
        self.width = width

    def update(self, dt):
        t = 1 - max(0.0, self.life / self.max_life)
        self.radius = lerp(self.max_radius * 0.15, self.max_radius, t)
        self.life -= dt
        return self.life > 0

    def draw(self, surf):
        t = max(0.0, self.life / self.max_life)
        alpha = int(220 * t)
        w = max(1, int(self.width * t + 1))
        size = int(self.radius * 2 + w * 2)
        s = pygame.Surface((size, size), pygame.SRCALPHA)
        pygame.draw.circle(s, (*self.color, alpha), (size // 2, size // 2), self.radius, width=w)
        surf.blit(s, (self.x - size / 2, self.y - size / 2))


class ScorePopup:
    __slots__ = ("x", "y", "vy", "life", "max_life", "text", "color")

    def __init__(self, x, y, text, color):
        self.x, self.y = x, y
        self.vy = -70
        self.life = 0.7
        self.max_life = 0.7
        self.text = text
        self.color = color

    def update(self, dt):
        self.y += self.vy * dt
        self.vy *= 0.94
        self.life -= dt
        return self.life > 0


class Bullet:
    __slots__ = ("x", "y", "vy", "radius")

    def __init__(self, x, y):
        self.x = x
        self.y = y
        self.vy = -640
        self.radius = 5

    def update(self, dt):
        self.y += self.vy * dt
        return self.y > -30

    def draw(self, surf):
        glow = pygame.Surface((self.radius * 6, self.radius * 8), pygame.SRCALPHA)
        gw, gh = glow.get_size()
        pygame.draw.ellipse(glow, (*CYAN, 70), (0, 0, gw, gh))
        surf.blit(glow, (self.x - gw / 2, self.y - gh / 2))
        pygame.draw.line(surf, CYAN, (self.x, self.y - 12), (self.x, self.y + 10), 3)
        pygame.draw.circle(surf, WHITE, (int(self.x), int(self.y - 12)), 3)


ITEM_KINDS = {
    "coin":      {"color": GOLD,   "weight": 46},
    "shield":    {"color": CYAN,   "weight": 16},
    "rapid":     {"color": (255, 232, 90), "weight": 16},
    "multishot": {"color": PURPLE, "weight": 14},
    "life":      {"color": (255, 99, 132), "weight": 8},
}


class Item:
    """A falling collectible power-up / coin."""

    IMAGES = {}  # set once by Game after loading sprites; {kind: Surface}

    def __init__(self, x, y, kind):
        self.x = x
        self.y = y
        self.kind = kind
        self.color = ITEM_KINDS[kind]["color"]
        self.vy = 150
        self.phase = random.uniform(0, math.tau)
        self.base_x = x
        self.spin = 0.0
        self.radius = 20

    def update(self, dt):
        self.y += self.vy * dt
        self.phase += dt * 2.4
        self.x = self.base_x + math.sin(self.phase) * 14
        self.spin += dt * 90

    def draw(self, surf):
        pulse = 0.5 + 0.5 * math.sin(self.phase * 2)
        glow_r = self.radius * (1.6 + 0.25 * pulse)
        glow = pygame.Surface((glow_r * 2, glow_r * 2), pygame.SRCALPHA)
        pygame.draw.circle(glow, (*self.color, 70), (glow_r, glow_r), glow_r)
        surf.blit(glow, (self.x - glow_r, self.y - glow_r))

        sprite = Item.IMAGES.get(self.kind)
        if sprite is not None:
            size = int(self.radius * 2.1)
            scaled = pygame.transform.smoothscale(sprite, (size, size))
            rotated = pygame.transform.rotozoom(scaled, math.sin(self.spin * 0.02) * 12, 1.0)
            rect = rotated.get_rect(center=(self.x, self.y))
            surf.blit(rotated, rect)
            return

        badge = pygame.Surface((self.radius * 2.2, self.radius * 2.2), pygame.SRCALPHA)
        c = self.radius * 1.1
        pygame.draw.circle(badge, (255, 255, 255, 235), (c, c), self.radius)
        pygame.draw.circle(badge, self.color, (c, c), self.radius, width=4)

        if self.kind == "coin":
            pygame.draw.circle(badge, self.color, (c, c), self.radius * 0.55)
            star_pts = []
            for i in range(10):
                a = -math.pi / 2 + i * math.pi / 5
                rad = self.radius * (0.32 if i % 2 else 0.55)
                star_pts.append((c + math.cos(a) * rad, c + math.sin(a) * rad))
            pygame.draw.polygon(badge, (255, 255, 255), star_pts)
        elif self.kind == "shield":
            pts = [
                (c, c - self.radius * 0.6), (c + self.radius * 0.55, c - self.radius * 0.25),
                (c + self.radius * 0.55, c + self.radius * 0.2), (c, c + self.radius * 0.65),
                (c - self.radius * 0.55, c + self.radius * 0.2), (c - self.radius * 0.55, c - self.radius * 0.25),
            ]
            pygame.draw.polygon(badge, self.color, pts)
            pygame.draw.polygon(badge, (255, 255, 255), pts, width=2)
        elif self.kind == "rapid":
            pts = [
                (c - self.radius * 0.1, c - self.radius * 0.6), (c - self.radius * 0.5, c + self.radius * 0.1),
                (c - self.radius * 0.05, c + self.radius * 0.1), (c - self.radius * 0.25, c + self.radius * 0.65),
                (c + self.radius * 0.5, c - self.radius * 0.05), (c + self.radius * 0.05, c - self.radius * 0.05),
            ]
            pygame.draw.polygon(badge, (200, 150, 0), pts)
        elif self.kind == "multishot":
            for dx in (-0.35, 0, 0.35):
                tip = (c + dx * self.radius * 2, c - self.radius * 0.55)
                pygame.draw.polygon(badge, self.color, [
                    tip, (tip[0] - 6, tip[1] + 16), (tip[0] + 6, tip[1] + 16)
                ])
        elif self.kind == "life":
            r = self.radius * 0.38
            pygame.draw.circle(badge, self.color, (c - r * 0.8, c - r * 0.35), r)
            pygame.draw.circle(badge, self.color, (c + r * 0.8, c - r * 0.35), r)
            pygame.draw.polygon(badge, self.color, [
                (c - r * 1.6, c - r * 0.15), (c + r * 1.6, c - r * 0.15), (c, c + r * 1.7)
            ])

        rotated = pygame.transform.rotozoom(badge, math.sin(self.spin * 0.02) * 12, 1.0)
        rect = rotated.get_rect(center=(self.x, self.y))
        surf.blit(rotated, rect)


class Meteor:
    BASE_IMAGE = None  # set once by Game after loading sprites
    _tint_cache = {}

    def __init__(self, x, y, size, tint=DANGER, tint_dark=DANGER_DARK):
        self.x = x
        self.y = y
        self.size = size
        self.angle = random.uniform(0, 360)
        self.spin = random.uniform(-140, 140)
        self.wobble_phase = random.uniform(0, math.tau)
        self.wobble_speed = random.uniform(1.5, 3.0)
        self.wobble_amp = random.uniform(6, 16)
        self.base_x = x
        self.grazed = False
        self.hp = 1 if size < 62 else 2
        self.tint = tint
        self.tint_dark = tint_dark

    def update(self, dt, fall_speed):
        self.y += fall_speed * dt
        self.angle += self.spin * dt
        self.wobble_phase += self.wobble_speed * dt
        self.x = self.base_x + math.sin(self.wobble_phase) * self.wobble_amp

    def radius(self):
        return self.size * 0.42

    def _tinted_sprite(self):
        key = self.tint
        cached = Meteor._tint_cache.get(key)
        if cached is None:
            tinted = Meteor.BASE_IMAGE.copy()
            tinted.fill((*self.tint, 255), special_flags=pygame.BLEND_RGBA_MULT)
            Meteor._tint_cache[key] = tinted
            cached = tinted
        return cached

    def draw(self, surf):
        r = self.size / 2
        glow = pygame.Surface((self.size * 2.4, self.size * 2.4), pygame.SRCALPHA)
        gc = (*self.tint, 55)
        pygame.draw.circle(glow, gc, (self.size * 1.2, self.size * 1.2), r * 1.5)
        surf.blit(glow, (self.x - self.size * 1.2, self.y - self.size * 1.2))

        if Meteor.BASE_IMAGE is not None:
            scaled = pygame.transform.smoothscale(self._tinted_sprite(), (self.size, self.size))
            rotated = pygame.transform.rotate(scaled, self.angle)
            if self.hp > 1:
                core = pygame.Surface(rotated.get_size(), pygame.SRCALPHA)
                pygame.draw.circle(core, (255, 200, 90, 220), (core.get_width() // 2, core.get_height() // 2),
                                    r * 0.26)
                rotated.blit(core, (0, 0))
            rect = rotated.get_rect(center=(self.x, self.y))
            surf.blit(rotated, rect)
            return

        body = pygame.Surface((self.size, self.size), pygame.SRCALPHA)
        cx = cy = self.size / 2
        points = []
        n = 9
        for i in range(n):
            a = (math.tau / n) * i
            jitter = 0.78 + 0.22 * math.sin(a * 3 + self.angle * 0.03)
            px = cx + math.cos(a) * r * jitter
            py = cy + math.sin(a) * r * jitter
            points.append((px, py))
        pygame.draw.polygon(body, self.tint, points)
        pygame.draw.polygon(body, self.tint_dark, points, width=max(2, int(self.size * 0.05)))
        crater = lerp_color(self.tint_dark, (0, 0, 0), 0.15)
        pygame.draw.circle(body, crater, (cx - r * 0.25, cy - r * 0.2), r * 0.22)
        pygame.draw.circle(body, crater, (cx + r * 0.3, cy + r * 0.15), r * 0.14)
        if self.hp > 1:
            pygame.draw.circle(body, (255, 200, 90), (cx, cy), r * 0.28)

        rotated = pygame.transform.rotate(body, self.angle)
        rect = rotated.get_rect(center=(self.x, self.y))
        surf.blit(rotated, rect)


class Plane:
    IMAGE = None  # set once by Game after loading sprites

    def __init__(self):
        self.x = WIDTH / 2
        self.target_x = WIDTH / 2
        self.y = HEIGHT * 0.78
        self.tilt = 0.0
        self.width = 58
        self.height = 78
        self.bob_phase = 0.0

    def update(self, dt):
        prev_x = self.x
        self.x += (self.target_x - self.x) * min(1, dt * 9)
        vel = (self.x - prev_x) / max(dt, 1e-6)
        target_tilt = max(-32, min(32, -vel * 0.09))
        self.tilt += (target_tilt - self.tilt) * min(1, dt * 8)
        self.bob_phase += dt * 4

    def nose(self):
        y_off = math.sin(self.bob_phase) * 3
        return self.x, self.y - self.height / 2 + y_off

    def draw(self, surf):
        y_off = math.sin(self.bob_phase) * 3
        cx, cy = self.x, self.y + y_off
        w, h = self.width, self.height

        shadow = pygame.Surface((w * 1.1, h * 0.35), pygame.SRCALPHA)
        pygame.draw.ellipse(shadow, (0, 0, 0, 70), shadow.get_rect())
        surf.blit(shadow, (cx - w * 0.55, self.y + h * 0.55 + 10))

        if Plane.IMAGE is not None:
            scaled = pygame.transform.smoothscale(Plane.IMAGE, (int(w * 1.1), int(h * 1.1)))
            rotated = pygame.transform.rotozoom(scaled, self.tilt, 1.0)
            rect = rotated.get_rect(center=(cx, cy))
            surf.blit(rotated, rect)
            return

        body = pygame.Surface((w * 1.6, h * 1.3), pygame.SRCALPHA)
        bx, by = w * 0.8, h * 0.65

        tail_pts = [
            (bx, by - h / 2 + h * 0.62),
            (bx - w * 0.5, by + h / 2 - 4),
            (bx - w * 0.18, by + h / 2 - 4),
        ]
        pygame.draw.polygon(body, (220, 226, 235), tail_pts)

        wing_pts = [
            (bx, by - h * 0.02),
            (bx - w * 0.78, by + h * 0.34),
            (bx - w * 0.5, by + h * 0.34),
            (bx, by + h * 0.14),
        ]
        pygame.draw.polygon(body, (210, 218, 230), wing_pts)
        wing_pts_r = [(bx * 2 - p[0], p[1]) for p in wing_pts]
        pygame.draw.polygon(body, (210, 218, 230), wing_pts_r)

        fuselage = [
            (bx, by - h / 2),
            (bx - w * 0.22, by + h * 0.1),
            (bx - w * 0.16, by + h / 2 - 2),
            (bx + w * 0.16, by + h / 2 - 2),
            (bx + w * 0.22, by + h * 0.1),
        ]
        pygame.draw.polygon(body, WHITE, fuselage)
        pygame.draw.polygon(body, (200, 206, 218), fuselage, width=2)

        pygame.draw.polygon(body, ORANGE, [
            (bx, by - h * 0.05),
            (bx - w * 0.12, by + h * 0.28),
            (bx + w * 0.12, by + h * 0.28),
        ])

        cockpit_rect = pygame.Rect(0, 0, w * 0.22, h * 0.22)
        cockpit_rect.center = (bx, by - h * 0.2)
        pygame.draw.ellipse(body, (120, 200, 255), cockpit_rect)
        pygame.draw.ellipse(body, (255, 255, 255, 160), cockpit_rect.inflate(-6, -10))

        rotated = pygame.transform.rotozoom(body, self.tilt, 1.0)
        rect = rotated.get_rect(center=(cx, cy))
        surf.blit(rotated, rect)

    def draw_shield(self, surf, pulse):
        y_off = math.sin(self.bob_phase) * 3
        cx, cy = self.x, self.y + y_off
        r = self.width * 0.85 + pulse * 4
        s = pygame.Surface((r * 2.4, r * 2.4), pygame.SRCALPHA)
        cc = r * 1.2
        pygame.draw.circle(s, (*CYAN, 40), (cc, cc), r)
        pygame.draw.circle(s, (*CYAN, 180), (cc, cc), r, width=3)
        pygame.draw.circle(s, (255, 255, 255, 120), (cc, cc), r * 0.7, width=1)
        surf.blit(s, (cx - cc, cy - cc))


class Game:
    def __init__(self):
        pygame.init()
        pygame.display.set_caption("پرواز هواپیما | Plane Dodge")
        self.screen = pygame.display.set_mode((WIDTH, HEIGHT))
        self.clock = pygame.time.Clock()
        self.sound = sound_module.SoundBank()

        self.images = load_images()
        Plane.IMAGE = self.images.get("plane")
        Meteor.BASE_IMAGE = self.images.get("meteor")
        Item.IMAGES = {k: self.images[f"item_{k}"] for k in ITEM_KINDS if f"item_{k}" in self.images}
        self.heart_image = self.images.get("heart")

        self.font_big = self._font(64, bold=True)
        self.font_mid = self._font(30, bold=True)
        self.font_small = self._font(20)
        self.font_tiny = self._font(16)

        self.stars = [Star() for _ in range(50)]
        self.clouds_far = [Cloud() for _ in range(4)]
        self.clouds_near = [Cloud() for _ in range(3)]
        self.particles = []
        self.rings = []
        self.popups = []
        self.meteors = []
        self.bullets = []
        self.items = []
        self.plane = Plane()

        self.vignette = self._build_vignette()

        self.best = load_highscore()
        self.reset()
        self.state = "menu"  # menu, playing, gameover
        self.shake = 0.0
        self.flash = 0.0

    def _font(self, size, bold=False):
        candidates = ["Vazirmatn", "Arial", "DejaVu Sans", None]
        for name in candidates:
            try:
                f = pygame.font.SysFont(name, size, bold=bold)
                if f:
                    return f
            except Exception:
                continue
        return pygame.font.Font(None, size)

    def _build_vignette(self):
        surf = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
        cx, cy = WIDTH / 2, HEIGHT / 2
        max_dist = math.hypot(cx, cy)
        step = 6
        for y in range(0, HEIGHT, step):
            for x in range(0, WIDTH, step):
                d = math.hypot(x - cx, y - cy) / max_dist
                a = max(0, int((d - 0.55) * 190))
                if a > 0:
                    pygame.draw.rect(surf, (0, 0, 0, min(a, 140)), (x, y, step, step))
        return surf

    def reset(self):
        self.meteors.clear()
        self.particles.clear()
        self.rings.clear()
        self.popups.clear()
        self.bullets.clear()
        self.items.clear()
        self.plane.x = self.plane.target_x = WIDTH / 2
        self.elapsed = 0.0
        self.score = 0
        self.fall_speed = 210
        self.spawn_timer = 0.0
        self.spawn_interval = 1.05
        self.combo_flash = 0.0
        self.near_misses = 0
        self.fire_timer = 0.0
        self.base_fire_interval = 0.32
        self.combo = 0
        self.combo_timer = 0.0
        self.level = 0

        self.lives = MAX_LIVES
        self.stage = 1
        self.stage_target = stage_threshold(1)
        self.stage_flash = 0.0
        self.item_timer = 0.0
        self.item_interval = 5.5
        self.shield_timer = 0.0
        self.rapid_timer = 0.0
        self.multishot_timer = 0.0
        self.invincible_timer = 0.0

    def meteor_tint(self):
        # Sky/meteor palette shifts every couple of stages for visual variety.
        phase = (self.stage - 1) // 2 % 3
        if phase == 0:
            return DANGER, DANGER_DARK
        if phase == 1:
            return PURPLE, (60, 30, 110)
        return GREEN, (20, 90, 60)

    def spawn_meteor(self):
        size = random.uniform(46, 78 + self.stage * 2)
        x = random.uniform(size, WIDTH - size)
        tint, tint_dark = self.meteor_tint()
        self.meteors.append(Meteor(x, -size, size, tint=tint, tint_dark=tint_dark))

    def spawn_item(self):
        kinds = list(ITEM_KINDS.keys())
        weights = [ITEM_KINDS[k]["weight"] for k in kinds]
        kind = random.choices(kinds, weights=weights, k=1)[0]
        if kind == "life" and self.lives >= MAX_LIVES:
            kind = "coin"
        x = random.uniform(40, WIDTH - 40)
        self.items.append(Item(x, -30, kind))

    def emit_trail(self, dt):
        nx, ny = self.plane.nose()
        for _ in range(2):
            spread = random.uniform(-8, 8)
            speed = random.uniform(60, 140)
            angle = math.pi / 2 + random.uniform(-0.3, 0.3)
            vx = math.cos(angle) * speed * 0.3 + spread
            vy = math.sin(angle) * speed + 40
            color = random.choice([GOLD, ORANGE, (255, 240, 200)])
            self.particles.append(
                Particle(nx + random.uniform(-6, 6), ny + 10, vx, vy,
                         life=random.uniform(0.25, 0.5), color=color,
                         size=random.uniform(3, 6))
            )

    def fire_bullet(self):
        nx, ny = self.plane.nose()
        if self.multishot_timer > 0:
            for dx in (-14, 0, 14):
                self.bullets.append(Bullet(nx + dx, ny - 6))
        else:
            self.bullets.append(Bullet(nx, ny - 6))
        for _ in range(6):
            angle = random.uniform(-0.5, 0.5) + math.pi * 1.5
            speed = random.uniform(60, 160)
            self.particles.append(
                Particle(nx, ny, math.cos(angle) * speed, math.sin(angle) * speed,
                          life=random.uniform(0.08, 0.16), color=CYAN, size=random.uniform(2, 4))
            )
        self.sound.play("shoot", volume=0.5)

    def apply_item(self, item):
        self.sound.play("pickup", volume=0.5)
        if item.kind == "coin":
            gain = 30
            self.score += gain
            self.popups.append(ScorePopup(item.x, item.y, f"+{gain}", GOLD))
        elif item.kind == "shield":
            self.shield_timer = 5.0
            self.sound.play("shield_on", volume=0.5)
            self.popups.append(ScorePopup(item.x, item.y, "SHIELD", CYAN))
        elif item.kind == "rapid":
            self.rapid_timer = 6.0
            self.popups.append(ScorePopup(item.x, item.y, "RAPID FIRE", (255, 232, 90)))
        elif item.kind == "multishot":
            self.multishot_timer = 6.0
            self.popups.append(ScorePopup(item.x, item.y, "MULTI-SHOT", PURPLE))
        elif item.kind == "life":
            self.lives = min(MAX_LIVES, self.lives + 1)
            self.popups.append(ScorePopup(item.x, item.y, "+1 LIFE", (255, 99, 132)))
        for _ in range(18):
            angle = random.uniform(0, math.tau)
            speed = random.uniform(40, 160)
            self.particles.append(
                Particle(item.x, item.y, math.cos(angle) * speed, math.sin(angle) * speed,
                          life=random.uniform(0.25, 0.5), color=item.color, size=random.uniform(2, 5))
            )

    def take_damage(self):
        self.lives -= 1
        self.invincible_timer = 1.6
        self.sound.play("life_lost", volume=0.6)
        px, py = self.plane.x, self.plane.y
        for _ in range(24):
            angle = random.uniform(0, math.tau)
            speed = random.uniform(60, 220)
            self.particles.append(
                Particle(px, py, math.cos(angle) * speed, math.sin(angle) * speed,
                          life=random.uniform(0.3, 0.6), color=random.choice([DANGER, ORANGE]),
                          size=random.uniform(2, 6))
            )
        self.rings.append(ShockRing(px, py, 90, 0.4, DANGER, width=5))
        self.shake = max(self.shake, 12)

    def destroy_meteor(self, m, from_bullet=True):
        self.meteors.remove(m)
        color = ORANGE if from_bullet else DANGER
        for _ in range(26):
            angle = random.uniform(0, math.tau)
            speed = random.uniform(60, 260)
            self.particles.append(
                Particle(m.x, m.y, math.cos(angle) * speed, math.sin(angle) * speed,
                          life=random.uniform(0.3, 0.65), color=random.choice([DANGER, ORANGE, GOLD]),
                          size=random.uniform(3, 7), gravity=40)
            )
        self.rings.append(ShockRing(m.x, m.y, m.size * 1.3, 0.4, ORANGE, width=5))
        if from_bullet:
            gain = 15 + self.combo * 5
            self.score += gain
            self.combo += 1
            self.combo_timer = 1.1
            self.popups.append(ScorePopup(m.x, m.y, f"+{gain}", GOLD))
            self.sound.play("explosion", volume=0.35)
        self.shake = max(self.shake, 6)

    def explode_plane(self, x, y):
        for _ in range(46):
            angle = random.uniform(0, math.tau)
            speed = random.uniform(80, 420)
            color = random.choice([DANGER, ORANGE, GOLD, WHITE])
            self.particles.append(
                Particle(x, y, math.cos(angle) * speed, math.sin(angle) * speed,
                          life=random.uniform(0.4, 0.9), color=color,
                          size=random.uniform(3, 8))
            )
        self.rings.append(ShockRing(x, y, 140, 0.6, DANGER, width=8))
        self.shake = 20
        self.flash = 1.0
        self.sound.play("crash", volume=0.8)

    def handle_input(self):
        keys = pygame.key.get_pressed()
        move = 0
        if keys[pygame.K_LEFT] or keys[pygame.K_a]:
            move -= 1
        if keys[pygame.K_RIGHT] or keys[pygame.K_d]:
            move += 1
        if move != 0:
            self.plane.target_x += move * 340 * self.dt
            self.plane.target_x = max(40, min(WIDTH - 40, self.plane.target_x))

        if pygame.mouse.get_pressed()[0]:
            mx, _ = pygame.mouse.get_pos()
            self.plane.target_x = max(40, min(WIDTH - 40, mx))

    def update_playing(self):
        self.elapsed += self.dt
        self.fall_speed = 210 + self.elapsed * 5.5 + self.stage * 10
        self.spawn_interval = max(0.32, 1.05 - self.elapsed * 0.008 - self.stage * 0.02)

        for timer in ("shield_timer", "rapid_timer", "multishot_timer", "invincible_timer"):
            val = getattr(self, timer)
            if val > 0:
                setattr(self, timer, max(0.0, val - self.dt))

        self.spawn_timer += self.dt
        if self.spawn_timer >= self.spawn_interval:
            self.spawn_timer = 0
            self.spawn_meteor()

        self.item_timer += self.dt
        if self.item_timer >= self.item_interval:
            self.item_timer = 0
            self.spawn_item()

        fire_interval = self.base_fire_interval - self.stage * 0.015
        if self.rapid_timer > 0:
            fire_interval *= 0.4
        fire_interval = max(0.09, fire_interval)
        self.fire_timer += self.dt
        if self.fire_timer >= fire_interval:
            self.fire_timer = 0
            self.fire_bullet()

        if self.combo_timer > 0:
            self.combo_timer -= self.dt
            if self.combo_timer <= 0:
                self.combo = 0

        for m in self.meteors:
            m.update(self.dt, self.fall_speed)
        self.meteors = [m for m in self.meteors if m.y - m.size < HEIGHT + 60]

        for it in self.items:
            it.update(self.dt)
        self.items = [it for it in self.items if it.y - it.radius < HEIGHT + 40]

        for b in self.bullets:
            b.update(self.dt)
        self.bullets = [b for b in self.bullets if b.y > -30]

        for b in list(self.bullets):
            for m in list(self.meteors):
                if math.hypot(b.x - m.x, b.y - m.y) < m.radius() + b.radius:
                    m.hp -= 1
                    if b in self.bullets:
                        self.bullets.remove(b)
                    if m.hp <= 0:
                        self.destroy_meteor(m, from_bullet=True)
                    else:
                        self.rings.append(ShockRing(b.x, b.y, 24, 0.2, CYAN, width=3))
                    break

        self.emit_trail(self.dt)
        self.score = max(self.score, int(self.elapsed * 10))

        px, py = self.plane.x, self.plane.y
        plane_r = self.plane.width * 0.28

        for it in list(self.items):
            if math.hypot(px - it.x, py - it.y) < plane_r + it.radius * 0.8:
                self.items.remove(it)
                self.apply_item(it)

        if self.invincible_timer <= 0:
            for m in list(self.meteors):
                dist = math.hypot(px - m.x, py - m.y)
                if dist < plane_r + m.radius():
                    if self.shield_timer > 0:
                        self.shield_timer = 0
                        self.destroy_meteor(m, from_bullet=False)
                        self.invincible_timer = 0.6
                        self.sound.play("shield_hit", volume=0.6)
                    else:
                        self.meteors.remove(m)
                        self.take_damage()
                        if self.lives <= 0:
                            self.explode_plane(px, py)
                            self.state = "gameover"
                            if self.score > self.best:
                                self.best = self.score
                                save_highscore(self.best)
                    break
                elif dist < plane_r + m.radius() + 22 and not m.grazed:
                    m.grazed = True
                    self.near_misses += 1
                    self.combo_flash = 0.25
                    self.sound.play("near_miss", volume=0.3)

        if self.state == "playing" and self.score >= self.stage_target:
            self.stage += 1
            self.stage_target = stage_threshold(self.stage)
            self.stage_flash = 2.2
            self.lives = min(MAX_LIVES, self.lives + 1)
            self.shield_timer = max(self.shield_timer, 2.0)
            self.popups.append(ScorePopup(WIDTH / 2, HEIGHT * 0.32, f"STAGE {self.stage}", CYAN))
            self.sound.play("stage_clear", volume=0.7)
            for _ in range(50):
                angle = random.uniform(0, math.tau)
                speed = random.uniform(60, 320)
                self.particles.append(
                    Particle(WIDTH / 2, HEIGHT * 0.35, math.cos(angle) * speed, math.sin(angle) * speed,
                              life=random.uniform(0.5, 1.0),
                              color=random.choice([GOLD, CYAN, PURPLE, GREEN, PINK]),
                              size=random.uniform(3, 6), gravity=60)
                )

    def update_particles(self):
        self.particles = [p for p in self.particles if p.update(self.dt)]
        self.rings = [r for r in self.rings if r.update(self.dt)]
        self.popups = [p for p in self.popups if p.update(self.dt)]
        if self.stage_flash > 0:
            self.stage_flash = max(0.0, self.stage_flash - self.dt)

    def update_background(self):
        mult = 1.0 if self.state == "playing" else 0.4
        for s in self.stars:
            s.update(self.dt, mult)
        for c in self.clouds_far:
            c.update(self.dt, mult * 0.6)
        for c in self.clouds_near:
            c.update(self.dt, mult * 1.3)

    def draw_sky(self, surf):
        top, mid, bottom = sky_colors(self.elapsed if self.state != "menu" else pygame.time.get_ticks() / 1000)
        for y in range(0, HEIGHT, 2):
            t = y / HEIGHT
            if t < 0.5:
                color = lerp_color(top, mid, t / 0.5)
            else:
                color = lerp_color(mid, bottom, (t - 0.5) / 0.5)
            pygame.draw.line(surf, color, (0, y), (WIDTH, y), 2)

        sun_x, sun_y = WIDTH * 0.78, HEIGHT * 0.16
        for r, a in ((120, 18), (80, 26), (46, 40)):
            s = pygame.Surface((r * 2, r * 2), pygame.SRCALPHA)
            pygame.draw.circle(s, (255, 230, 180, a), (r, r), r)
            surf.blit(s, (sun_x - r, sun_y - r))

    def _draw_heart(self, surf, x, y, size, color, filled=True):
        if self.heart_image is not None:
            scaled = pygame.transform.smoothscale(self.heart_image, (int(size * 2.1), int(size * 2.1)))
            if not filled:
                scaled = scaled.copy()
                scaled.fill((255, 255, 255, 60), special_flags=pygame.BLEND_RGBA_MULT)
            surf.blit(scaled, scaled.get_rect(center=(x, y)))
            return

        r = size * 0.32
        if filled:
            pygame.draw.circle(surf, color, (x - r * 0.9, y - r * 0.3), r)
            pygame.draw.circle(surf, color, (x + r * 0.9, y - r * 0.3), r)
            pygame.draw.polygon(surf, color, [
                (x - r * 1.8, y - r * 0.1), (x + r * 1.8, y - r * 0.1), (x, y + r * 1.9)
            ])
        else:
            pygame.draw.circle(surf, color, (x - r * 0.9, y - r * 0.3), r, width=2)
            pygame.draw.circle(surf, color, (x + r * 0.9, y - r * 0.3), r, width=2)
            pygame.draw.polygon(surf, color, [
                (x - r * 1.8, y - r * 0.1), (x + r * 1.8, y - r * 0.1), (x, y + r * 1.9)
            ], width=2)

    def draw_hud(self, surf):
        score_text = self.font_big.render(str(self.score), True, WHITE)
        rect = score_text.get_rect(midtop=(WIDTH / 2, 28))
        shadow = self.font_big.render(str(self.score), True, (0, 0, 0))
        surf.blit(shadow, (rect.x + 2, rect.y + 3))
        surf.blit(score_text, rect)

        best_label = self.font_tiny.render("BEST", True, (255, 255, 255))
        best_val = self.font_small.render(str(self.best), True, GOLD)
        surf.blit(best_label, (WIDTH - 90, 24))
        surf.blit(best_val, (WIDTH - 90, 42))

        stage_label = self.font_tiny.render(f"STAGE {self.stage}", True, CYAN)
        surf.blit(stage_label, (WIDTH - 90, 68))

        for i in range(MAX_LIVES):
            hx = 30 + i * 30
            hy = 30
            self._draw_heart(surf, hx, hy, 16, (255, 99, 132), filled=i < self.lives)

        badge_y = 58
        badges = []
        if self.shield_timer > 0:
            badges.append((CYAN, "S"))
        if self.rapid_timer > 0:
            badges.append(((255, 232, 90), "R"))
        if self.multishot_timer > 0:
            badges.append((PURPLE, "M"))
        for i, (color, letter) in enumerate(badges):
            bx = 22 + i * 26
            pygame.draw.circle(surf, color, (bx, badge_y), 10)
            letter_txt = self.font_tiny.render(letter, True, (20, 20, 20))
            surf.blit(letter_txt, letter_txt.get_rect(center=(bx, badge_y)))

        if self.combo >= 2:
            combo_text = self.font_small.render(f"x{self.combo} COMBO", True, ORANGE)
            surf.blit(combo_text, combo_text.get_rect(midtop=(WIDTH / 2, 92)))

        if self.combo_flash > 0:
            self.combo_flash -= self.dt
            alpha = int(255 * min(1, self.combo_flash / 0.25))
            near_text = self.font_small.render("NICE!", True, GOLD)
            near_text.set_alpha(alpha)
            surf.blit(near_text, near_text.get_rect(center=(WIDTH / 2, 118)))

        for p in self.popups:
            t = max(0.0, p.life / p.max_life)
            f = self.font_small if len(p.text) <= 4 else self.font_mid
            txt = f.render(p.text, True, p.color)
            txt.set_alpha(int(255 * t))
            surf.blit(txt, txt.get_rect(center=(p.x, p.y)))

    def draw_menu(self, surf):
        overlay = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
        overlay.fill((4, 10, 24, 150))
        surf.blit(overlay, (0, 0))

        title = self.font_big.render("PLANE DODGE", True, WHITE)
        surf.blit(title, title.get_rect(center=(WIDTH / 2, HEIGHT * 0.3)))

        subtitle_lines = [
            "با کلیدهای ← → یا A/D یا موس هدایت کن",
            "آیتم‌های سپر، شلیک‌سریع و چندتیر رو جمع کن",
            "هر مرحله سخت‌تر می‌شه؛ ۳ جون داری، مراقب باش!",
        ]
        for i, line in enumerate(subtitle_lines):
            t = self.font_small.render(line, True, (220, 226, 240))
            surf.blit(t, t.get_rect(center=(WIDTH / 2, HEIGHT * 0.42 + i * 27)))

        pulse = 0.5 + 0.5 * math.sin(pygame.time.get_ticks() / 300)
        btn_color = lerp_color(ORANGE, GOLD, pulse)
        btn_rect = pygame.Rect(0, 0, 260, 64)
        btn_rect.center = (WIDTH / 2, HEIGHT * 0.64)
        pygame.draw.rect(surf, btn_color, btn_rect, border_radius=32)
        btn_text = self.font_mid.render("START", True, (40, 20, 0))
        surf.blit(btn_text, btn_text.get_rect(center=btn_rect.center))

        best_text = self.font_small.render(f"Best: {self.best}", True, GOLD)
        surf.blit(best_text, best_text.get_rect(center=(WIDTH / 2, HEIGHT * 0.74)))
        self.start_btn_rect = btn_rect

    def draw_gameover(self, surf):
        overlay = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
        overlay.fill((4, 6, 16, 190))
        surf.blit(overlay, (0, 0))

        title = self.font_big.render("CRASHED", True, DANGER)
        surf.blit(title, title.get_rect(center=(WIDTH / 2, HEIGHT * 0.28)))

        score_text = self.font_mid.render(f"Score: {self.score}", True, WHITE)
        surf.blit(score_text, score_text.get_rect(center=(WIDTH / 2, HEIGHT * 0.4)))

        is_new_best = self.score >= self.best and self.score > 0
        best_color = GOLD if is_new_best else (200, 206, 220)
        label = "NEW BEST!" if is_new_best else f"Best: {self.best}"
        best_text = self.font_small.render(label, True, best_color)
        surf.blit(best_text, best_text.get_rect(center=(WIDTH / 2, HEIGHT * 0.46)))

        pulse = 0.5 + 0.5 * math.sin(pygame.time.get_ticks() / 300)
        btn_color = lerp_color(ORANGE, GOLD, pulse)
        btn_rect = pygame.Rect(0, 0, 260, 64)
        btn_rect.center = (WIDTH / 2, HEIGHT * 0.62)
        pygame.draw.rect(surf, btn_color, btn_rect, border_radius=32)
        btn_text = self.font_mid.render("RETRY", True, (40, 20, 0))
        surf.blit(btn_text, btn_text.get_rect(center=btn_rect.center))
        self.retry_btn_rect = btn_rect

    def draw(self):
        render_target = pygame.Surface((WIDTH, HEIGHT))
        self.draw_sky(render_target)
        for c in self.clouds_far:
            c.draw(render_target)
        for s in self.stars:
            s.draw(render_target)
        for c in self.clouds_near:
            c.draw(render_target)

        for r in self.rings:
            r.draw(render_target)
        for p in self.particles:
            p.draw(render_target)

        if self.state in ("playing", "gameover"):
            for it in self.items:
                it.draw(render_target)
            for b in self.bullets:
                b.draw(render_target)
            for m in self.meteors:
                m.draw(render_target)
            blink_hidden = (
                self.invincible_timer > 0
                and self.state == "playing"
                and int(self.invincible_timer * 12) % 2 == 0
            )
            if (self.state == "playing" or self.shake > 0) and not blink_hidden:
                if self.shield_timer > 0:
                    pulse = math.sin(pygame.time.get_ticks() / 120)
                    self.plane.draw_shield(render_target, pulse)
                self.plane.draw(render_target)

        render_target.blit(self.vignette, (0, 0))

        if self.stage_flash > 0 and self.state == "playing":
            alpha = int(70 * min(1.0, self.stage_flash / 0.6))
            tint = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
            tint.fill((*CYAN, alpha))
            render_target.blit(tint, (0, 0))

        if self.state == "playing":
            self.draw_hud(render_target)
        elif self.state == "menu":
            self.draw_hud(render_target)
            self.draw_menu(render_target)
        elif self.state == "gameover":
            self.draw_hud(render_target)
            self.draw_gameover(render_target)

        if self.flash > 0:
            flash_surf = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
            flash_surf.fill((255, 255, 255, int(160 * self.flash)))
            render_target.blit(flash_surf, (0, 0))
            self.flash = max(0, self.flash - self.dt * 4)

        offset = [0, 0]
        if self.shake > 0:
            offset = [random.uniform(-1, 1) * self.shake, random.uniform(-1, 1) * self.shake]
            self.shake = max(0, self.shake - self.dt * 60)

        self.screen.fill((0, 0, 0))
        self.screen.blit(render_target, offset)
        pygame.display.flip()

    def run(self):
        running = True
        while running:
            self.dt = min(self.clock.tick(FPS) / 1000, 0.05)

            for event in pygame.event.get():
                if event.type == pygame.QUIT:
                    running = False
                elif event.type == pygame.KEYDOWN:
                    if event.key == pygame.K_ESCAPE:
                        running = False
                    elif event.key in (pygame.K_SPACE, pygame.K_RETURN):
                        if self.state in ("menu", "gameover"):
                            self.reset()
                            self.state = "playing"
                elif event.type == pygame.MOUSEBUTTONDOWN:
                    if self.state == "menu" and self.start_btn_rect.collidepoint(event.pos):
                        self.sound.play("click")
                        self.reset()
                        self.state = "playing"
                    elif self.state == "gameover" and self.retry_btn_rect.collidepoint(event.pos):
                        self.sound.play("click")
                        self.reset()
                        self.state = "playing"

            if self.state == "playing":
                self.handle_input()
                self.plane.update(self.dt)
                self.update_playing()

            self.update_particles()
            self.update_background()
            self.draw()

        pygame.quit()
        sys.exit()


if __name__ == "__main__":
    Game().run()
