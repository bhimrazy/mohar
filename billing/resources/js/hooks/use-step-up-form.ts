import type { FormComponentRef } from '@inertiajs/core';
import { useCallback, useRef } from 'react';
import { requestStepUp, STEP_UP_CALLER_RETRIES_HEADER } from '@/lib/step-up';

/**
 * Props for an Inertia `<Form>` behind `password.confirm` or `password.recent` that must keep its own callbacks after
 * the confirm dialog (an `onSuccess` that opens the next setup step). On a step-up refusal it asks for the password and
 * submits the same form again; the header keeps the gate from also replaying the visit. Use `onOtherError` instead of
 * `resetOnError`, which would clear the input the retry needs.
 */
export function useStepUpForm(
    onOtherError?: (form: FormComponentRef | null) => void,
) {
    const ref = useRef<FormComponentRef>(null);

    const onError = useCallback(
        async (errors: Record<string, string>) => {
            if (!errors.password_confirmation) {
                onOtherError?.(ref.current);

                return;
            }

            if (
                await requestStepUp(
                    window,
                    errors.step_up_reason === 'password_not_set',
                )
            ) {
                ref.current?.submit();
            }
        },
        [onOtherError],
    );

    return {
        ref,
        headers: { [STEP_UP_CALLER_RETRIES_HEADER]: '1' },
        onError,
    };
}
