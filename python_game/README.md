# Plane Dodge (Python / pygame)

A staged arcade game: steer a plane left and right to dodge falling
meteors while it auto-fires glowing tracer shots to blast them apart,
collect power-ups, and clear one stage after another.

## Stages & items

- **Stages**: the game is split into stages with rising score targets.
  Clearing one triggers a confetti burst, a cyan screen flash, a bonus
  life, and a short free shield — then meteors fall faster and denser,
  and their color palette shifts (red → violet → toxic green) every two
  stages.
- **Lives**: you start with 3 hearts (shown top-left). A meteor hit
  costs a heart and grants brief blinking invincibility instead of an
  instant game over; you're out when hearts run out.
- **Items** fall from the sky and can be collected by flying into them:
  - 🪙 **Coin** — instant bonus score
  - 🛡 **Shield** — blocks the next hit for free (destroys the meteor too)
  - ⚡ **Rapid fire** — much faster auto-fire for a few seconds
  - 🔱 **Multi-shot** — fires a 3-way spread instead of one bullet
  - ❤️ **Extra life** — regains a heart (capped at 3)

## Visuals

A slowly color-cycling gradient sky (dusk → violet sunset → deep night
→ aurora teal), a soft sun glow, parallax stars and clouds, a glowing
exhaust trail, a tilting plane sprite, spinning/wobbling meteors,
shockwave rings and particle bursts on every hit, screen shake and
flash on impact, floating score/status popups, a combo counter,
stage-clear banners, animated power-up badges, a heart-based life HUD,
a shield aura around the plane, and a vignette.

## Audio

Every sound effect (shoot, explosion, crash, near-miss whoosh, pickup
chime, shield on/hit, life lost, stage-clear fanfare, UI click) is
synthesized on the fly with numpy — no audio files, nothing to download.

## Run it

```bash
pip install -r requirements.txt
python3 plane_dodge.py
```

## Controls

- Arrow keys or `A` / `D`: move left/right
- Hold left mouse button: drag the plane to the cursor's x position
- `Space` / `Enter`: start or retry
- `Esc`: quit

The plane fires automatically — just focus on dodging, collecting
items, and clearing stages. Destroying a meteor scores more than
letting it pass, and chaining kills quickly builds a combo multiplier.

High scores are saved to `highscore.json` next to the script.
