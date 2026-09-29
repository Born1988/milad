"""Procedurally synthesized sound effects (no audio files, no network).

Every effect is generated on the fly as a short waveform (pure Python,
no numpy) and handed to pygame as a Sound object. This keeps the game
self-contained, keeps the dependency list to just pygame, and lets the
effects be tuned by pure math (frequency sweeps, noise bursts, simple
envelopes) instead of shipping binary assets.
"""

import array
import math
import random

import pygame

SAMPLE_RATE = 44100


def _envelope(i, n, attack=0.02, release=0.6):
    a = max(1, int(n * attack))
    r = max(1, int(n * release))
    if i < a:
        return i / a
    if i > n - r:
        return max(0.0, (n - i) / r)
    return 1.0


def _to_sound(samples):
    buf = array.array("h", samples)
    interleaved = array.array("h", (0,) * (len(buf) * 2))
    interleaved[0::2] = buf
    interleaved[1::2] = buf
    return pygame.mixer.Sound(buffer=interleaved.tobytes())


def _clip16(x):
    return max(-32767, min(32767, int(x)))


def _sweep_tone(f_start, f_end, duration, wave="triangle", volume=0.5, attack=0.01, release=0.7):
    n = int(SAMPLE_RATE * duration)
    samples = array.array("h", (0,) * n)
    phase = 0.0
    for i in range(n):
        t = i / n
        freq = f_start + (f_end - f_start) * t
        phase += 2 * math.pi * freq / SAMPLE_RATE
        if wave == "sine":
            raw = math.sin(phase)
        elif wave == "square":
            raw = 1.0 if math.sin(phase) >= 0 else -1.0
        else:  # triangle
            frac = (phase / (2 * math.pi)) % 1.0
            raw = 4 * abs(frac - 0.5) - 1
        env = _envelope(i, n, attack, release)
        samples[i] = _clip16(raw * env * volume * 32767)
    return samples


def _noise_burst(duration, volume=0.6, lowpass_window=6, attack=0.0, release=0.85):
    n = int(SAMPLE_RATE * duration)
    raw = [random.uniform(-1, 1) for _ in range(n)]
    if lowpass_window > 1:
        smoothed = []
        w = lowpass_window
        running = sum(raw[:w]) if n >= w else sum(raw)
        half = w // 2
        for i in range(n):
            lo = max(0, i - half)
            hi = min(n, i + half + 1)
            smoothed.append(sum(raw[lo:hi]) / (hi - lo))
        raw = smoothed
    samples = array.array("h", (0,) * n)
    for i in range(n):
        env = _envelope(i, n, attack, release)
        samples[i] = _clip16(raw[i] * env * volume * 32767)
    return samples


def _chord(freqs, duration, volume=0.35, wave="sine", attack=0.005, release=0.7):
    n = int(SAMPLE_RATE * duration)
    samples = array.array("h", (0,) * n)
    for i in range(n):
        t = i / SAMPLE_RATE
        mix = 0.0
        for f in freqs:
            if wave == "square":
                mix += 1.0 if math.sin(2 * math.pi * f * t) >= 0 else -1.0
            else:
                mix += math.sin(2 * math.pi * f * t)
        mix /= len(freqs)
        env = _envelope(i, n, attack, release)
        samples[i] = _clip16(mix * env * volume * 32767)
    return samples


def _mix(a, b):
    n = min(len(a), len(b))
    out = array.array("h", (0,) * n)
    for i in range(n):
        out[i] = _clip16(a[i] + b[i])
    return out


def _sequence(notes):
    out = array.array("h")
    for f, d in notes:
        out.extend(_chord([f], d, volume=0.32, wave="sine", release=0.6))
    return out


class SoundBank:
    """Lazily synthesizes and caches all sound effects used by the game."""

    def __init__(self):
        self.enabled = False
        self.sounds = {}
        try:
            pygame.mixer.init(frequency=SAMPLE_RATE, size=-16, channels=2)
            self._build()
            self.enabled = True
        except Exception:
            self.enabled = False

    def _build(self):
        self.sounds["shoot"] = _to_sound(
            _sweep_tone(1500, 500, 0.11, wave="triangle", volume=0.35, release=0.8)
        )
        self.sounds["explosion"] = _to_sound(
            _noise_burst(0.5, volume=0.55, lowpass_window=10, release=0.9)
        )
        self.sounds["crash"] = _to_sound(
            _mix(
                _noise_burst(0.7, volume=0.6, lowpass_window=14, release=0.9),
                _sweep_tone(220, 40, 0.6, wave="square", volume=0.25, release=0.9),
            )
        )
        self.sounds["hit_small"] = _to_sound(
            _noise_burst(0.22, volume=0.4, lowpass_window=4, release=0.8)
        )
        self.sounds["score"] = _to_sound(
            _chord([880, 1320], 0.12, volume=0.3, wave="sine", release=0.75)
        )
        self.sounds["near_miss"] = _to_sound(
            _sweep_tone(700, 1400, 0.16, wave="sine", volume=0.22, release=0.8)
        )
        self.sounds["level_up"] = _to_sound(
            _sequence([(523, 0.09), (659, 0.09), (784, 0.09), (1046, 0.16)])
        )
        self.sounds["click"] = _to_sound(
            _sweep_tone(500, 900, 0.06, wave="square", volume=0.25, release=0.7)
        )
        self.sounds["pickup"] = _to_sound(_sequence([(660, 0.06), (990, 0.08)]))
        self.sounds["shield_on"] = _to_sound(
            _sweep_tone(300, 1200, 0.28, wave="sine", volume=0.3, release=0.8)
        )
        self.sounds["shield_hit"] = _to_sound(
            _mix(
                _sweep_tone(900, 1400, 0.12, wave="sine", volume=0.3, release=0.8),
                _noise_burst(0.12, volume=0.2, lowpass_window=4, release=0.8),
            )
        )
        self.sounds["life_lost"] = _to_sound(
            _mix(
                _noise_burst(0.35, volume=0.45, lowpass_window=10, release=0.9),
                _sweep_tone(420, 90, 0.35, wave="triangle", volume=0.3, release=0.9),
            )
        )
        self.sounds["stage_clear"] = _to_sound(
            _sequence([(523, 0.1), (659, 0.1), (784, 0.1), (988, 0.1), (1318, 0.24)])
        )

    def play(self, name, volume=1.0):
        if not self.enabled:
            return
        snd = self.sounds.get(name)
        if snd:
            snd.set_volume(volume)
            snd.play()
