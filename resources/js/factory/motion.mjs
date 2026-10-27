/**
 * FactoryFlow presentation-only motion utilities.
 * All factory operations and production counts remain server authoritative.
 */
export function positiveWrap(value, minimum, period) {
  if (!Number.isFinite(value) || !Number.isFinite(minimum) || !(period > 0)) {
    throw new RangeError('A finite coordinate and positive wrap length are required.');
  }
  // JS remainder retains the sign of the dividend. Normalise both directions.
  return minimum + ((((value - minimum) % period) + period) % period);
}

export function smoothAlpha(dt, responsiveness = 14) {
  return 1 - Math.exp(-Math.max(0, dt) * responsiveness);
}

export function advanceDisplayElapsed(current, authoritative, snapshotAge, speed, dt, duration) {
  const bound = (v) => Math.max(0, Math.min(duration, v));
  if (!(duration > 0)) return 0;
  const rate = Math.max(0, speed);
  // Hold equipment exactly where it was drawn when stopped or blocked.
  if (rate === 0) return bound(current);
  // Extrapolate only a short interval; don't outrun Laravel when a poll is late.
  const target = bound(authoritative + Math.min(Math.max(0, snapshotAge), .28) * rate);
  const projected = bound(current + Math.max(0, dt) * rate);
  return bound(projected + (target - projected) * smoothAlpha(dt, 6));
}
