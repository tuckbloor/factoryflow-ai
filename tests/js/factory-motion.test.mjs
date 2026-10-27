import test from 'node:test';
import assert from 'node:assert/strict';
import { positiveWrap, smoothAlpha, advanceDisplayElapsed } from '../../resources/js/factory/motion.mjs';

test('reverse conveyor stripes stay inside the top deck for thousands of cycles', () => {
  const min = -7.75, length = 15.5;
  for (let n = 0; n < 9000; n++) {
    const raw = -7.75 + (n % 22) * .73 - n * .83;
    const x = positiveWrap(raw, min, length);
    assert.ok(x >= min && x < min + length, `escaped belt at step ${n}: ${x}`);
  }
});

test('forward conveyor stripe wrapping stays inside the same limits', () => {
  for (let n = 0; n < 10000; n++) {
    const x = positiveWrap(-7.75 + n * .43, -7.75, 15.5);
    assert.ok(x >= -7.75 && x < 7.75);
  }
});

test('smoothing is framerate independent and bounded', () => {
  assert.equal(smoothAlpha(0), 0);
  assert.ok(smoothAlpha(.016) > 0 && smoothAlpha(.016) < 1);
  const a = 1 - (1 - smoothAlpha(.016)) ** 2;
  const b = smoothAlpha(.032);
  assert.ok(Math.abs(a - b) < 1e-12);
});

test('display position interpolates between polls without moving while stopped', () => {
  let current = .3;
  for (let n = 0; n < 60; n++) {
    const next = advanceDisplayElapsed(current, .3 + n / 60, 0, 1, 1/60, 3);
    assert.ok(next >= current, 'playhead moved backward');
    current = next;
  }
  const atPause = advanceDisplayElapsed(current, current, 0, 0, 1/60, 3);
  assert.equal(atPause, current);
  assert.equal(advanceDisplayElapsed(current, current - .2, .4, 0, 1/60, 3), current);
  assert.equal(advanceDisplayElapsed(2.9, 3.0, .2, 1, .1, 3), 3);
});

test('display interpolation cannot run beyond a stage boundary', () => {
  assert.ok(advanceDisplayElapsed(.3, 0, 999, 1, 1, 2) <= 2);
  assert.equal(positiveWrap(-31.25, -7.75, 15.5), -0.25);
});
