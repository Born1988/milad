"""Procedurally synthesized sound effects (no audio files, no network).

Every effect is generated on the fly as a short numpy waveform and handed
to pygame as a Sound object. This keeps the game self-contained and lets
the effects be tuned by pure math (frequency sweeps, noise bursts, simple
envelopes) instead of shipping binary assets.
"""

import numpy as np
import pygame

SAMPLE_RATE = 44100


def _stereo(mono):
    mono = np.clip(mono, -1.0, 1.0)
    data = np.ascontiguousarray((mono * 32767).astype(np.int16))
    return np.repeat(data.reshape(-1, 1), 2, axis=1)


def _envelope(n, attack=0.02, release=0.6):
    env = np.ones(n)
    a = max(1, int(n * attack))
    r = max(1, int(n * release))
    env[:a] = np.linspace(0, 1, a)
    env[-r:] *= np.linspace(1, 0, r)
    return env


def _sweep_tone(f_start, f_end, duration, wave="triangle", volume=0.5, attack=0.01, release=0.7):
    n = int(SAMPLE_RATE * duration)
    t = np.linspace(0, duration, n, endpoint=False)
    freq = np.linspace(f_start, f_end, n)
    phase = 2 * np.pi * np.cumsum(freq) / SAMPLE_RATE
    if wave == "sine":
        raw = np.sin(phase)
    elif wave == "square":
        raw = np.sign(np.sin(phase))
    else:  # triangle
        raw = 2 * np.abs(2 * (phase / (2 * np.pi) - np.floor(phase / (2 * np.pi) + 0.5))) - 1
    raw *= _envelope(n, attack, release) * volume
    return _stereo(raw)


def _noise_burst(duration, volume=0.6, lowpass_window=6, attack=0.0, release=0.85):
    n = int(SAMPLE_RATE * duration)
    raw = np.random.uniform(-1, 1, n)
    if lowpass_window > 1:
        kernel = np.ones(lowpass_window) / lowpass_window
        raw = np.convolve(raw, kernel, mode="same")
    raw *= _envelope(n, attack, release) * volume
    return _stereo(raw)


def _chord(freqs, duration, volume=0.35, wave="sine", attack=0.005, release=0.7):
    n = int(SAMPLE_RATE * duration)
    t = np.linspace(0, duration, n, endpoint=False)
    mix = np.zeros(n)
    for f in freqs:
        if wave == "square":
            mix += np.sign(np.sin(2 * np.pi * f * t))
        else:
            mix += np.sin(2 * np.pi * f * t)
    mix /= len(freqs)
    mix *= _envelope(n, attack, release) * volume
    return _stereo(mix)


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
        self.sounds["shoot"] = pygame.sndarray.make_sound(
            _sweep_tone(1500, 500, 0.11, wave="triangle", volume=0.35, release=0.8)
        )
        self.sounds["explosion"] = pygame.sndarray.make_sound(
            _noise_burst(0.5, volume=0.55, lowpass_window=10, release=0.9)
        )
        self.sounds["crash"] = pygame.sndarray.make_sound(
            self._mix(
                _noise_burst(0.7, volume=0.6, lowpass_window=14, release=0.9),
                _sweep_tone(220, 40, 0.6, wave="square", volume=0.25, release=0.9),
            )
        )
        self.sounds["hit_small"] = pygame.sndarray.make_sound(
            _noise_burst(0.22, volume=0.4, lowpass_window=4, release=0.8)
        )
        self.sounds["score"] = pygame.sndarray.make_sound(
            _chord([880, 1320], 0.12, volume=0.3, wave="sine", release=0.75)
        )
        self.sounds["near_miss"] = pygame.sndarray.make_sound(
            _sweep_tone(700, 1400, 0.16, wave="sine", volume=0.22, release=0.8)
        )
        self.sounds["level_up"] = pygame.sndarray.make_sound(
            self._sequence([
                (523, 0.09), (659, 0.09), (784, 0.09), (1046, 0.16),
            ])
        )
        self.sounds["click"] = pygame.sndarray.make_sound(
            _sweep_tone(500, 900, 0.06, wave="square", volume=0.25, release=0.7)
        )

    @staticmethod
    def _mix(a, b):
        n = min(len(a), len(b))
        out = a[:n].astype(np.int32) + b[:n].astype(np.int32)
        return np.clip(out, -32768, 32767).astype(np.int16)

    @staticmethod
    def _sequence(notes):
        parts = [_chord([f], d, volume=0.32, wave="sine", release=0.6) for f, d in notes]
        return np.concatenate(parts, axis=0)

    def play(self, name, volume=1.0):
        if not self.enabled:
            return
        snd = self.sounds.get(name)
        if snd:
            snd.set_volume(volume)
            snd.play()
