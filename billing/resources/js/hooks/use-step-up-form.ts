import type { FormComponentRef, HttpExceptionResponse } from '@inertiajs/core';
import { useCallback, useRef } from 'react';
import { inertiaStepUpRefusal, requestStepUp } from '@/lib/step-up';

/**
 * Props for an Inertia `<Form>` behind `password.confirm` or `password.recent` that must keep its own callbacks after
 * the confirm dialog (an `onSuccess` that opens the next setup step). The server refuses with a 423 and changes
 * nothing; this asks for the password and submits the same form again, with its input. Returning false keeps the
 * gate from also replaying the visit. Use `onOtherError` instead of `resetOnError`.
 */
export function useStepUpForm(
    onOtherError?: (form: FormComponentRef | null) => void,
) {
    const ref = useRef<FormComponentRef>(null);

    const onHttpException = useCallback(
        (response: HttpExceptionResponse): boolean | void => {
            const refusal = inertiaStepUpRefusal(response);

            if (!refusal) {
                return;
            }

            void requestStepUp(window, refusal.noPassword).then(
                (confirmed) => confirmed && ref.current?.submit(),
            );

            return false;
        },
        [],
    );

    const onError = useCallback(
        () => onOtherError?.(ref.current),
        [onOtherError],
    );

    return { ref, onHttpException, onError };
}
