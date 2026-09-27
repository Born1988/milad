"""Plane Dodge - an endless plane-dodging arcade game built with pygame.

Steer the plane left and right (arrow keys / A-D, or drag with the mouse)
to weave between falling meteors. Survive as long as you can; the game
speeds up and meteors grow denser the longer you last.

Run with:  python3 plane_dodge.py
"""

import json
import math
import os
import random
import sys

import pygame

WIDTH, HEIGHT = 480, 800
FPS = 60
HIGHSCORE_FILE = os.path.join(os.path.dirname(__file__), "highscore.json")

SKY_TOP = (10, 20, 46)
SKY_MID = (24, 58, 110)
SKY_BOTTOM = (54, 120, 190)
WHITE = (255, 255, 255)
GOLD = (255, 212, 121)
ORANGE = (255, 157, 61)
DANGER = (239, 68, 68)
DANGER_DARK = (127, 29, 29)


def lerp(a, b, t):
    return a + (b - a) * t


def lerp_color(c1, c2, t):
    return tuple(int(lerp(c1[i], c2[i], t)) for i in range(3))


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
    __slots__ = ("x", "y", "vx", "vy", "life", "max_life", "color", "size")

    def __init__(self, x, y, vx, vy, life, color, size):
        self.x, self.y, self.vx, self.vy = x, y, vx, vy
        self.life = life
        self.max_life = life
        self.color = color
        self.size = size

    def update(self, dt):
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


class Meteor:
    def __init__(self, x, y, size):
        self.x = x
        self.y = y
        self.size = size
        self.angle = random.uniform(0, 360)
        self.spin = random.uniform(-140, 140)
        self.wobble_phase = random.uniform(0, math.tau)
        self.wobble_speed = random.uniform(1.5, 3.0)
        self.wobble_amp = random.uniform(6, 16)
        self.base_x = x

    def update(self, dt, fall_speed):
        self.y += fall_speed * dt
        self.angle += self.spin * dt
        self.wobble_phase += self.wobble_speed * dt
        self.x = self.base_x + math.sin(self.wobble_phase) * self.wobble_amp

    def radius(self):
        return self.size * 0.42

    def draw(self, surf):
        r = self.size / 2
        glow = pygame.Surface((self.size * 2.4, self.size * 2.4), pygame.SRCALPHA)
        gc = (255, 120, 90, 55)
        pygame.draw.circle(glow, gc, (self.size * 1.2, self.size * 1.2), r * 1.5)
        surf.blit(glow, (self.x - self.size * 1.2, self.y - self.size * 1.2))

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
        pygame.draw.polygon(body, DANGER, points)
        pygame.draw.polygon(body, DANGER_DARK, points, width=max(2, int(self.size * 0.05)))
        # crater highlights
        pygame.draw.circle(body, (185, 45, 45), (cx - r * 0.25, cy - r * 0.2), r * 0.22)
        pygame.draw.circle(body, (185, 45, 45), (cx + r * 0.3, cy + r * 0.15), r * 0.14)

        rotated = pygame.transform.rotate(body, self.angle)
        rect = rotated.get_rect(center=(self.x, self.y))
        surf.blit(rotated, rect)


class Plane:
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

        body = pygame.Surface((w * 1.6, h * 1.3), pygame.SRCALPHA)
        bx, by = w * 0.8, h * 0.65

        shadow = pygame.Surface((w * 1.1, h * 0.35), pygame.SRCALPHA)
        pygame.draw.ellipse(shadow, (0, 0, 0, 70), shadow.get_rect())
        surf.blit(shadow, (cx - w * 0.55, self.y + h * 0.55 + 10))

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


class Game:
    def __init__(self):
        pygame.init()
        pygame.display.set_caption("پرواز هواپیما | Plane Dodge")
        self.screen = pygame.display.set_mode((WIDTH, HEIGHT))
        self.clock = pygame.time.Clock()

        self.font_big = self._font(64, bold=True)
        self.font_mid = self._font(30, bold=True)
        self.font_small = self._font(20)
        self.font_tiny = self._font(16)

        self.stars = [Star() for _ in range(50)]
        self.clouds_far = [Cloud() for _ in range(4)]
        self.clouds_near = [Cloud() for _ in range(3)]
        self.particles = []
        self.meteors = []
        self.plane = Plane()

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

    def reset(self):
        self.meteors.clear()
        self.particles.clear()
        self.plane.x = self.plane.target_x = WIDTH / 2
        self.elapsed = 0.0
        self.score = 0
        self.fall_speed = 210
        self.spawn_timer = 0.0
        self.spawn_interval = 1.05
        self.combo_flash = 0.0
        self.near_misses = 0

    def spawn_meteor(self):
        size = random.uniform(46, 78)
        x = random.uniform(size, WIDTH - size)
        self.meteors.append(Meteor(x, -size, size))

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

    def explode(self, x, y):
        for _ in range(46):
            angle = random.uniform(0, math.tau)
            speed = random.uniform(80, 420)
            color = random.choice([DANGER, ORANGE, GOLD, WHITE])
            self.particles.append(
                Particle(x, y, math.cos(angle) * speed, math.sin(angle) * speed,
                          life=random.uniform(0.4, 0.9), color=color,
                          size=random.uniform(3, 8))
            )
        self.shake = 18
        self.flash = 1.0

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
        self.fall_speed = 210 + self.elapsed * 5.5
        self.spawn_interval = max(0.42, 1.05 - self.elapsed * 0.008)

        self.spawn_timer += self.dt
        if self.spawn_timer >= self.spawn_interval:
            self.spawn_timer = 0
            self.spawn_meteor()

        for m in self.meteors:
            m.update(self.dt, self.fall_speed)
        self.meteors = [m for m in self.meteors if m.y - m.size < HEIGHT + 60]

        self.emit_trail(self.dt)

        self.score = int(self.elapsed * 10)

        px, py = self.plane.x, self.plane.y
        plane_r = self.plane.width * 0.28
        for m in list(self.meteors):
            dist = math.hypot(px - m.x, py - m.y)
            if dist < plane_r + m.radius():
                self.explode(px, py)
                self.state = "gameover"
                if self.score > self.best:
                    self.best = self.score
                    save_highscore(self.best)
                break
            elif dist < plane_r + m.radius() + 22 and not hasattr(m, "_grazed"):
                m._grazed = True
                self.near_misses += 1
                self.combo_flash = 0.25

    def update_particles(self):
        self.particles = [p for p in self.particles if p.update(self.dt)]

    def update_background(self):
        mult = 1.0 if self.state == "playing" else 0.4
        for s in self.stars:
            s.update(self.dt, mult)
        for c in self.clouds_far:
            c.update(self.dt, mult * 0.6)
        for c in self.clouds_near:
            c.update(self.dt, mult * 1.3)

    def draw_sky(self, surf):
        for y in range(0, HEIGHT, 2):
            t = y / HEIGHT
            if t < 0.5:
                color = lerp_color(SKY_TOP, SKY_MID, t / 0.5)
            else:
                color = lerp_color(SKY_MID, SKY_BOTTOM, (t - 0.5) / 0.5)
            pygame.draw.line(surf, color, (0, y), (WIDTH, y), 2)

    def draw_hud(self, surf):
        score_text = self.font_big.render(str(self.score), True, WHITE)
        rect = score_text.get_rect(midtop=(WIDTH / 2, 28))
        shadow = self.font_big.render(str(self.score), True, (0, 0, 0))
        surf.blit(shadow, (rect.x + 2, rect.y + 3))
        surf.blit(score_text, rect)

        best_label = self.font_tiny.render("BEST", True, (255, 255, 255, 180))
        best_val = self.font_small.render(str(self.best), True, GOLD)
        surf.blit(best_label, (WIDTH - 90, 24))
        surf.blit(best_val, (WIDTH - 90, 42))

        if self.combo_flash > 0:
            self.combo_flash -= self.dt
            alpha = int(255 * min(1, self.combo_flash / 0.25))
            near_text = self.font_small.render("NICE!", True, (*GOLD,))
            near_text.set_alpha(alpha)
            surf.blit(near_text, near_text.get_rect(center=(WIDTH / 2, 110)))

    def draw_menu(self, surf):
        overlay = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
        overlay.fill((4, 10, 24, 150))
        surf.blit(overlay, (0, 0))

        title = self.font_big.render("PLANE DODGE", True, WHITE)
        surf.blit(title, title.get_rect(center=(WIDTH / 2, HEIGHT * 0.32)))

        subtitle_lines = [
            "با کلیدهای ← → یا A/D یا موس هدایت کن",
            "از برخورد با شهاب‌سنگ‌های قرمز فرار کن",
        ]
        for i, line in enumerate(subtitle_lines):
            t = self.font_small.render(line, True, (220, 226, 240))
            surf.blit(t, t.get_rect(center=(WIDTH / 2, HEIGHT * 0.44 + i * 28)))

        pulse = 0.5 + 0.5 * math.sin(pygame.time.get_ticks() / 300)
        btn_color = lerp_color(ORANGE, GOLD, pulse)
        btn_rect = pygame.Rect(0, 0, 260, 64)
        btn_rect.center = (WIDTH / 2, HEIGHT * 0.62)
        pygame.draw.rect(surf, btn_color, btn_rect, border_radius=32)
        btn_text = self.font_mid.render("START", True, (40, 20, 0))
        surf.blit(btn_text, btn_text.get_rect(center=btn_rect.center))

        best_text = self.font_small.render(f"Best: {self.best}", True, GOLD)
        surf.blit(best_text, best_text.get_rect(center=(WIDTH / 2, HEIGHT * 0.72)))
        self.start_btn_rect = btn_rect

    def draw_gameover(self, surf):
        overlay = pygame.Surface((WIDTH, HEIGHT), pygame.SRCALPHA)
        overlay.fill((4, 6, 16, 190))
        surf.blit(overlay, (0, 0))

        title = self.font_big.render("CRASHED", True, DANGER)
        surf.blit(title, title.get_rect(center=(WIDTH / 2, HEIGHT * 0.3)))

        score_text = self.font_mid.render(f"Score: {self.score}", True, WHITE)
        surf.blit(score_text, score_text.get_rect(center=(WIDTH / 2, HEIGHT * 0.42)))

        is_new_best = self.score >= self.best and self.score > 0
        best_color = GOLD if is_new_best else (200, 206, 220)
        label = "NEW BEST!" if is_new_best else f"Best: {self.best}"
        best_text = self.font_small.render(label, True, best_color)
        surf.blit(best_text, best_text.get_rect(center=(WIDTH / 2, HEIGHT * 0.48)))

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

        for p in self.particles:
            p.draw(render_target)

        if self.state in ("playing", "gameover"):
            for m in self.meteors:
                m.draw(render_target)
            if self.state == "playing" or self.shake > 0:
                self.plane.draw(render_target)

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
                        self.reset()
                        self.state = "playing"
                    elif self.state == "gameover" and self.retry_btn_rect.collidepoint(event.pos):
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
