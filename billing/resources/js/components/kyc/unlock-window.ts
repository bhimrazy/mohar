import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Wait after the end before asking the server, so a clock a little ahead of it still gets the ended page. */
export const RELOAD_AFTER_END_MS = 2000;

/**
 * Milliseconds left in a document unlock: null without one, 0 once it has ended (or the time is unreadable).
 */
export function unlockRemaining(
    unlockedUntil: string | null,
    now: number = Date.now(),
): number | null {
    if (unlockedUntil === null) {
        return null;
    }

    const until = Date.parse(unlockedUntil);

    return Number.isNaN(until) ? 0 : Math.max(0, until - now);
}

/**
 * Calls onEnd when the unlock ends (at once if it already has), then reload a little later. Returns a cancel function.
 */
export function watchUnlock(
    unlockedUntil: string | null,
    onEnd: () => void,
    reload: () => void,
): () => void {
    const remaining = unlockRemaining(unlockedUntil);

    if (remaining === null) {
        return () => {};
    }

    let reloadTimer: ReturnType<typeof setTimeout> | undefined;
    const endTimer = setTimeout(() => {
        onEnd();
        reloadTimer = setTimeout(reload, RELOAD_AFTER_END_MS);
    }, remaining);

    return () => {
        clearTimeout(endTimer);
        clearTimeout(reloadTimer);
    };
}

/**
 * Whether the documents may show. They hide the moment the unlock ends, also on a page restored from history, and the
 * page then reloads so the server's answer clears the browser history (issue #240).
 */
export function useUnlockWindow(unlockedUntil: string | null): boolean {
    const [ended, setEnded] = useState<string | null>(null);

    useEffect(
        () =>
            watchUnlock(
                unlockedUntil,
                () => setEnded(unlockedUntil),
                () => router.reload(),
            ),
        [unlockedUntil],
    );

    return ended !== unlockedUntil && (unlockRemaining(unlockedUntil) ?? 0) > 0;
}
