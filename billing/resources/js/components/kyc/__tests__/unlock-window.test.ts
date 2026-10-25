import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    RELOAD_AFTER_END_MS,
    unlockDeadline,
    watchUnlock,
} from '../unlock-window';

const NOW = Date.parse('2026-10-22T10:00:00+05:45');

describe('unlockDeadline', () => {
    it('is null without an unlock', () => {
        expect(unlockDeadline(null, null, NOW)).toBeNull();
    });

    it('counts the seconds the server sent from now, whatever the time of day says', () => {
        // The end time is far from NOW: only the seconds matter (a browser clock ahead of or behind the server).
        expect(unlockDeadline('2026-10-22T03:00:00+00:00', 900, NOW)).toBe(
            NOW + 900_000,
        );
        expect(unlockDeadline('2026-10-22T23:00:00+00:00', 900, NOW)).toBe(
            NOW + 900_000,
        );
    });

    it('keeps the first deadline for the same unlock, so a page restored from history does not count afresh', () => {
        const first = unlockDeadline('2026-10-22T04:15:00+00:00', 600, NOW);

        expect(
            unlockDeadline('2026-10-22T04:15:00+00:00', 600, NOW + 300_000),
        ).toBe(first);
        expect(
            unlockDeadline(
                '2026-10-22T04:15:00+00:00',
                120,
                NOW + 300_000,
                true,
            ),
        ).toBe(NOW + 300_000 + 120_000);
    });

    it('treats unreadable seconds as ended', () => {
        expect(unlockDeadline('2026-10-22T04:16:00+00:00', null, NOW)).toBe(0);
    });
});

describe('watchUnlock', () => {
    const onEnd = vi.fn();
    const reload = vi.fn();

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(NOW);
    });

    afterEach(() => {
        vi.useRealTimers();
        onEnd.mockReset();
        reload.mockReset();
    });

    it('hides when the seconds run out, then reloads the page, never before', () => {
        watchUnlock(NOW + 15 * 60_000, onEnd, reload);

        vi.advanceTimersByTime(15 * 60_000 - 1);
        expect(onEnd).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(onEnd).toHaveBeenCalledOnce();
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(RELOAD_AFTER_END_MS);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('hides and reloads a page whose deadline has passed', () => {
        watchUnlock(NOW - 600_000, onEnd, reload);

        vi.advanceTimersByTime(0);
        expect(onEnd).toHaveBeenCalledOnce();
        vi.advanceTimersByTime(RELOAD_AFTER_END_MS);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('does nothing without an unlock or once cancelled', () => {
        watchUnlock(null, onEnd, reload);
        const cancel = watchUnlock(NOW + 900_000, onEnd, reload);
        cancel();

        vi.advanceTimersByTime(60 * 60_000);
        expect(onEnd).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });
});
