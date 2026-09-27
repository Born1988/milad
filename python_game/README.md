# Plane Dodge (Python / pygame)

An endless arcade game: steer a plane left and right to dodge falling
meteors. Built with pygame, featuring a gradient sky, parallax stars and
clouds, a glowing exhaust trail, tilt animation on the plane, meteor
wobble/spin, screen shake and flash on impact, and a persisted high score.

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

High scores are saved to `highscore.json` next to the script.
