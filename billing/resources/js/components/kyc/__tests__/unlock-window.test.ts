import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    RELOAD_AFTER_END_MS,
    unlockRemaining,
    watchUnlock,
} from '../unlock-window';

const NOW = Date.parse('2026-10-22T10:00:00+05:45');

describe('unlockRemaining', () => {
    it('is null without an unlock', () => {
        expect(unlockRemaining(null, NOW)).toBeNull();
    });

    it('counts down to the end and stays at zero after it', () => {
        expect(unlockRemaining('2026-10-22T10:15:00+05:45', NOW)).toBe(
            15 * 60_000,
        );
        expect(unlockRemaining('2026-10-22T09:59:59+05:45', NOW)).toBe(0);
    });

    it('treats an unreadable time as ended', () => {
        expect(unlockRemaining('not a date', NOW)).toBe(0);
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

    it('hides at the end of the unlock, then reloads the page', () => {
        watchUnlock('2026-10-22T10:15:00+05:45', onEnd, reload);

        vi.advanceTimersByTime(15 * 60_000 - 1);
        expect(onEnd).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(onEnd).toHaveBeenCalledOnce();
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(RELOAD_AFTER_END_MS);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('hides and reloads a page restored after its unlock ended', () => {
        watchUnlock('2026-10-22T09:50:00+05:45', onEnd, reload);

        vi.advanceTimersByTime(0);
        expect(onEnd).toHaveBeenCalledOnce();
        vi.advanceTimersByTime(RELOAD_AFTER_END_MS);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('does nothing without an unlock or once cancelled', () => {
        watchUnlock(null, onEnd, reload);
        const cancel = watchUnlock('2026-10-22T10:15:00+05:45', onEnd, reload);
        cancel();

        vi.advanceTimersByTime(60 * 60_000);
        expect(onEnd).not.toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();
    });
});
