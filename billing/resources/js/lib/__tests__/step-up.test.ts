import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    ensureStepUp,
    inertiaStepUpRefusal,
    replacePending,
    requestStepUp,
    StepUpCancelled,
    STEP_UP_CONFIRM_EVENT,
    stepUpRefusal,
    withStepUp,
} from '@/lib/step-up';
import type { StepUpConfirmDetail } from '@/lib/step-up';

/** A stand-in for PasswordConfirmationGate that answers every request with `confirmed`. */
function gate(confirmed: boolean) {
    const target = new EventTarget();
    const asked: StepUpConfirmDetail[] = [];
    target.addEventListener(STEP_UP_CONFIRM_EVENT, (event) => {
        event.preventDefault();
        const detail = (event as CustomEvent<StepUpConfirmDetail>).detail;
        asked.push(detail);
        detail.resolve(confirmed);
    });

    return { target, asked };
}

/** The shape useHttp() rejects with (HttpResponseError). */
const refused = (
    body: unknown = { message: 'Password confirmation required.' },
) =>
    Object.assign(new Error('Request failed with status 423'), {
        response: { status: 423, data: JSON.stringify(body), headers: {} },
    });

afterEach(() => vi.unstubAllGlobals());

describe('requestStepUp', () => {
    it('resolves with the gate answer and passes the no-password reason', async () => {
        const { target, asked } = gate(true);

        await expect(requestStepUp(target, true)).resolves.toBe(true);
        expect(asked[0].noPassword).toBe(true);
    });

    it('resolves false on a layout with no gate', async () => {
        await expect(requestStepUp(new EventTarget())).resolves.toBe(false);
    });
});

describe('stepUpRefusal', () => {
    it('recognises a 423 and a login without a password, and nothing else', () => {
        expect(stepUpRefusal(refused())).toEqual({ noPassword: false });
        expect(stepUpRefusal(refused({ code: 'password_not_set' }))).toEqual({
            noPassword: true,
        });
        expect(
            stepUpRefusal(
                Object.assign(new Error('x'), {
                    response: { status: 500, data: '' },
                }),
            ),
        ).toBeNull();
        expect(stepUpRefusal(new Error('offline'))).toBeNull();
    });
});

describe('withStepUp', () => {
    it('asks once after a refusal, then runs the request again', async () => {
        const { target, asked } = gate(true);
        const request = vi
            .fn<() => Promise<string>>()
            .mockRejectedValueOnce(refused())
            .mockResolvedValueOnce('<svg/>');

        await expect(withStepUp(request, target)).resolves.toBe('<svg/>');
        expect(request).toHaveBeenCalledTimes(2);
        expect(asked).toHaveLength(1);
    });

    it('throws StepUpCancelled when the dialog is cancelled, so a flow can stop asking', async () => {
        const { target } = gate(false);
        const request = vi
            .fn<() => Promise<string>>()
            .mockRejectedValue(refused());

        await expect(withStepUp(request, target)).rejects.toBeInstanceOf(
            StepUpCancelled,
        );
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('does not ask for other errors', async () => {
        const { target, asked } = gate(true);
        const error = new Error('offline');

        await expect(
            withStepUp(() => Promise.reject(error), target),
        ).rejects.toBe(error);
        expect(asked).toHaveLength(0);
    });
});

describe('ensureStepUp', () => {
    it('goes ahead without asking when the probe passes', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(new Response(null, { status: 204 }));
        vi.stubGlobal('fetch', fetch);
        const { target, asked } = gate(false);

        await expect(
            ensureStepUp('/user/passkeys/options', target),
        ).resolves.toBe(true);
        expect(asked).toHaveLength(0);
        expect(fetch.mock.calls[0][1].headers['X-Step-Up-Check']).toBe('1');
    });

    it('asks when the probe is refused and follows the answer', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                new Response(JSON.stringify({ code: 'password_not_set' }), {
                    status: 423,
                }),
            ),
        );
        const { target, asked } = gate(false);

        await expect(
            ensureStepUp('/user/passkeys/options', target),
        ).resolves.toBe(false);
        expect(asked[0].noPassword).toBe(true);
    });
});

describe('replacePending', () => {
    it('tells a waiting caller it was not confirmed when its request is replaced', async () => {
        let settled: boolean | undefined;
        const waiting = {
            url: '',
            resolve: (confirmed: boolean) => {
                settled = confirmed;
            },
        };
        const next: { url: string; resolve?: (confirmed: boolean) => void } = {
            url: '/file.csv',
        };

        expect(replacePending(waiting, next)).toBe(next);
        expect(settled).toBe(false);
    });

    it('leaves nothing to answer when nothing was waiting, or the same request is set again', () => {
        const resolve = vi.fn();
        const same = { url: '', resolve };

        expect(replacePending(null, same)).toBe(same);
        expect(replacePending(same, same)).toBe(same);
        expect(resolve).not.toHaveBeenCalled();
    });
});

describe('inertiaStepUpRefusal', () => {
    it('recognises the 423 with the step-up header, parsed or raw, and the no-password reason', () => {
        const headers = { 'x-step-up-required': '1' };

        expect(
            inertiaStepUpRefusal({
                status: 423,
                headers,
                data: { message: 'x' },
            }),
        ).toEqual({ noPassword: false });
        expect(
            inertiaStepUpRefusal({
                status: 423,
                headers,
                data: JSON.stringify({ code: 'password_not_set' }),
            }),
        ).toEqual({ noPassword: true });
    });

    it('ignores a 423 without the header and any other status', () => {
        expect(
            inertiaStepUpRefusal({ status: 423, headers: {}, data: {} }),
        ).toBeNull();
        expect(
            inertiaStepUpRefusal({
                status: 403,
                headers: { 'x-step-up-required': '1' },
                data: {},
            }),
        ).toBeNull();
    });
});
