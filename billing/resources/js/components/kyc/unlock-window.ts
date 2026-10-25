import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

/** Wait after the end before asking the server, so a slow response or a rounded second still gets the ended page. */
export const RELOAD_AFTER_END_MS = 1000;

const STORAGE_PREFIX = 'kyc.unlock-deadline.';
const memory = new Map<string, number>();

/**
 * When the unlock ends on this browser's clock, from the seconds the server says are left (issue #257). The browser
 * never compares its clock with a time the server wrote, so a clock that runs ahead or behind changes nothing.
 *
 * The first sight of an unlock (named by its end time) fixes the deadline; a page restored from history later finds
 * it again instead of counting the old seconds afresh. `fresh` replaces it with a new answer from the server.
 * Null without an unlock; a deadline of 0 (ended) when the seconds are unreadable.
 */
export function unlockDeadline(
    unlockedUntil: string | null,
    unlockedFor: number | null,
    now: number = Date.now(),
    fresh = false,
): number | null {
    if (unlockedUntil === null) {
        return null;
    }

    const key = STORAGE_PREFIX + unlockedUntil;

    if (!fresh) {
        const kept = remembered(key);

        if (kept !== null) {
            return kept;
        }
    }

    const deadline =
        typeof unlockedFor === 'number' && Number.isFinite(unlockedFor)
            ? now + Math.max(0, unlockedFor) * 1000
            : 0;
    memory.set(key, deadline);

    try {
        sessionStorage.setItem(key, String(deadline));
    } catch {
        // Private mode or blocked storage: the deadline lives in memory only.
    }

    return deadline;
}

function remembered(key: string): number | null {
    const inMemory = memory.get(key);

    if (inMemory !== undefined) {
        return inMemory;
    }

    try {
        const stored = Number(sessionStorage.getItem(key));

        return stored > 0 ? stored : null;
    } catch {
        return null;
    }
}

/**
 * Calls onEnd when the deadline passes (at once if it already has), then reload a little later. Returns a cancel
 * function.
 */
export function watchUnlock(
    deadline: number | null,
    onEnd: () => void,
    reload: () => void,
    now: number = Date.now(),
): () => void {
    if (deadline === null) {
        return () => {};
    }

    let reloadTimer: ReturnType<typeof setTimeout> | undefined;
    const endTimer = setTimeout(
        () => {
            onEnd();
            reloadTimer = setTimeout(reload, RELOAD_AFTER_END_MS);
        },
        Math.max(0, deadline - now),
    );

    return () => {
        clearTimeout(endTimer);
        clearTimeout(reloadTimer);
    };
}

/**
 * Whether the documents may show. They hide when the server's seconds run out, also on a page restored from history,
 * and the page then reloads so the server's answer clears the browser history (issue #240). If the server still says
 * the unlock is open, the new seconds start a new wait: the page asks again, it is never stuck on a stale answer.
 */
export function useUnlockWindow(
    unlockedUntil: string | null,
    unlockedFor: number | null,
): boolean {
    const [attempt, setAttempt] = useState(0);
    const [ended, setEnded] = useState<number | null>(null);
    const deadline = useMemo(
        () =>
            unlockDeadline(unlockedUntil, unlockedFor, Date.now(), attempt > 0),
        // The reload counter makes a repeated answer for the same unlock count as new.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [unlockedUntil, unlockedFor, attempt],
    );

    useEffect(() => {
        const reload = () =>
            router.reload({ onSuccess: () => setAttempt((n) => n + 1) });
        const cancel = watchUnlock(deadline, () => setEnded(deadline), reload);

        // A timer does not advance while a laptop sleeps or a tab is throttled: look at the clock again on return.
        const recheck = () => {
            if (deadline !== null && deadline <= Date.now()) {
                setEnded(deadline);
            }
        };
        document.addEventListener('visibilitychange', recheck);
        window.addEventListener('pageshow', recheck);

        return () => {
            cancel();
            document.removeEventListener('visibilitychange', recheck);
            window.removeEventListener('pageshow', recheck);
        };
    }, [deadline]);

    return deadline !== null && ended !== deadline && deadline > Date.now();
}
