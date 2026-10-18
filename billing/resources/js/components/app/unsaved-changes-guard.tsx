import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';

/**
 * Warn before leaving a page with unsaved form changes. In-app navigation asks with our dialog; reloading or
 * closing the tab uses the browser's own prompt (the only option there). Submitting the form, partial reloads,
 * polling and prefetches are never blocked. Render it inside an Inertia <Form> render prop:
 *
 *   <UnsavedChangesGuard dirty={isDirty && !processing} />
 */
export function UnsavedChangesGuard({ dirty }: { dirty: boolean }) {
    const [pendingUrl, setPendingUrl] = useState<string | null>(null);
    const leaving = useRef(false);

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
            const navigates =
                visit.method === 'get' &&
                !visit.prefetch &&
                visit.only.length === 0 &&
                visit.except.length === 0;

            if (navigates && !leaving.current) {
                event.preventDefault();
                setPendingUrl(visit.url.href);
            }
        });

        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            off();
        };
    }, [dirty]);

    return (
        <ConfirmDialog
            open={pendingUrl !== null}
            onOpenChange={(open) => !open && setPendingUrl(null)}
            title="Leave without saving?"
            description="Your changes on this page haven't been saved and will be lost."
            confirmLabel="Discard changes"
            cancelLabel="Keep editing"
            destructive
            onConfirm={() => {
                const url = pendingUrl;
                setPendingUrl(null);

                if (url) {
                    leaving.current = true;
                    router.visit(url);
                }
            }}
        />
    );
}
