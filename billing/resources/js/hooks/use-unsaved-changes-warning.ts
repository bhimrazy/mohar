import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const MESSAGE = 'You have unsaved changes. Leave this page and discard them?';

/**
 * Warn before leaving a page with unsaved form changes: the browser's own prompt on reload or close, and a
 * confirmation before Inertia navigates away (GET visits only, so submitting the form is never blocked).
 */
export function useUnsavedChangesWarning(dirty: boolean): void {
    useEffect(() => {
        if (!dirty) {
            return;
        }

        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', beforeUnload);

        const off = router.on('before', (event) => {
            const visit = event.detail.visit;
            // Partial reloads, polling and prefetches stay on the page.
            const leaving =
                visit.method === 'get' &&
                !visit.prefetch &&
                visit.only.length === 0 &&
                visit.except.length === 0;

            if (leaving && !window.confirm(MESSAGE)) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            off();
        };
    }, [dirty]);
}

/** Render inside an Inertia <Form> render prop: <UnsavedChangesGuard dirty={isDirty} />. */
export function UnsavedChangesGuard({ dirty }: { dirty: boolean }) {
    useUnsavedChangesWarning(dirty);

    return null;
}
