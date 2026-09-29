# Plane Dodge (Python / pygame)

An endless arcade game: steer a plane left and right to dodge falling
meteors while it auto-fires glowing tracer shots to blast them apart.

Visuals: a slowly color-cycling gradient sky (dusk → violet sunset →
deep night → aurora teal), a soft sun glow, parallax stars and clouds,
a glowing exhaust trail, a tilting plane sprite, spinning/wobbling
meteors, shockwave rings and particle bursts on every hit, screen shake
and flash on impact, floating score popups, a combo counter, level-up
banners, a vignette, and a persisted high score.

Audio: every sound effect (shoot, explosion, crash, near-miss whoosh,
score ding, level-up chime, UI click) is synthesized on the fly with
numpy — no audio files, nothing to download.

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

The plane fires automatically — just focus on dodging and let the
tracers do the work. Destroying a meteor scores more than letting it
pass, and chaining kills quickly builds a combo multiplier.

High scores are saved to `highscore.json` next to the script.
