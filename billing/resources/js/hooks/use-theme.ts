import { useSyncExternalStore } from 'react';
import { isThemeId } from '@/lib/themes';
import type { ThemeId } from '@/lib/themes';

const STORAGE_KEY = 'theme';
const listeners = new Set<() => void>();
let currentTheme: ThemeId = 'default';

function readStored(): ThemeId {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        return isThemeId(stored) ? stored : 'default';
    } catch {
        return 'default';
    }
}

function apply(theme: ThemeId): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (theme === 'default') {
        delete document.documentElement.dataset.theme;
    } else {
        document.documentElement.dataset.theme = theme;
    }
}

/** Applies the stored design theme on boot (the Blade head script does it pre-paint too). */
export function initializeDesignTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    currentTheme = readStored();
    apply(currentTheme);
}

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

export function useDesignTheme() {
    const theme = useSyncExternalStore(
        subscribe,
        () => currentTheme,
        () => 'default' as ThemeId,
    );

    const updateTheme = (next: ThemeId): void => {
        currentTheme = next;

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Private mode: the theme still applies for this page view.
        }

        apply(next);
        listeners.forEach((listener) => listener());
    };

    return { theme, updateTheme } as const;
}
