import { useHttp } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { StepUpCancelled, withStepUp } from '@/lib/step-up';
import { qrCode, recoveryCodes, secretKey } from '@/routes/two-factor';

export type UseTwoFactorAuthReturn = {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    recoveryCodesList: string[];
    hasSetupData: boolean;
    errors: string[];
    clearErrors: () => void;
    clearSetupData: () => void;
    clearTwoFactorAuthData: () => void;
    fetchQrCode: () => Promise<void>;
    fetchSetupKey: () => Promise<void>;
    fetchSetupData: () => Promise<void>;
    fetchRecoveryCodes: () => Promise<void>;
};

export const OTP_MAX_LENGTH = 6;

const CANCELLED_MESSAGE = 'Confirm your password to continue.';

/** The message for a failed fetch; a cancelled password dialog says so instead of "failed". */
const failure = (error: unknown, message: string): string =>
    error instanceof StepUpCancelled ? CANCELLED_MESSAGE : message;

export const useTwoFactorAuth = (): UseTwoFactorAuthReturn => {
    const { submit } = useHttp();

    const [qrCodeSvg, setQrCodeSvg] = useState<string | null>(null);
    const [manualSetupKey, setManualSetupKey] = useState<string | null>(null);
    const [recoveryCodesList, setRecoveryCodesList] = useState<string[]>([]);
    const [errors, setErrors] = useState<string[]>([]);

    const hasSetupData = qrCodeSvg !== null && manualSetupKey !== null;

    const clearErrors = useCallback((): void => {
        setErrors([]);
    }, []);

    const clearSetupData = useCallback((): void => {
        setManualSetupKey(null);
        setQrCodeSvg(null);
        setErrors([]);
    }, []);

    const clearTwoFactorAuthData = useCallback((): void => {
        setManualSetupKey(null);
        setQrCodeSvg(null);
        setErrors([]);
        setRecoveryCodesList([]);
    }, []);

    const fetchQrCode = useCallback(async (): Promise<void> => {
        try {
            const { svg } = (await withStepUp(() => submit(qrCode()))) as {
                svg: string;
                url: string;
            };

            setQrCodeSvg(svg);
        } catch (error) {
            setErrors((prev) => [
                ...prev,
                failure(error, 'Failed to fetch QR code'),
            ]);
            setQrCodeSvg(null);

            // fetchSetupData stops here, so a cancelled dialog is not opened again for the setup key.
            if (error instanceof StepUpCancelled) {
                throw error;
            }
        }
    }, [submit]);

    const fetchSetupKey = useCallback(async (): Promise<void> => {
        try {
            const { secretKey: key } = (await withStepUp(() =>
                submit(secretKey()),
            )) as {
                secretKey: string;
            };

            setManualSetupKey(key);
        } catch (error) {
            setErrors((prev) => [
                ...prev,
                failure(error, 'Failed to fetch a setup key'),
            ]);
            setManualSetupKey(null);
        }
    }, [submit]);

    const fetchRecoveryCodes = useCallback(async (): Promise<void> => {
        try {
            setErrors([]);
            const codes = (await withStepUp(() =>
                submit(recoveryCodes()),
            )) as string[];
            setRecoveryCodesList(codes);
        } catch (error) {
            setErrors((prev) => [
                ...prev,
                failure(error, 'Failed to fetch recovery codes'),
            ]);
            setRecoveryCodesList([]);
        }
    }, [submit]);

    const fetchSetupData = useCallback(async (): Promise<void> => {
        try {
            setErrors([]);
            // One after the other: a step-up refusal on the first asks once, and the second then passes.
            await fetchQrCode();
            await fetchSetupKey();
        } catch {
            setQrCodeSvg(null);
            setManualSetupKey(null);
        }
    }, [fetchQrCode, fetchSetupKey]);

    return {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        hasSetupData,
        errors,
        clearErrors,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchQrCode,
        fetchSetupKey,
        fetchSetupData,
        fetchRecoveryCodes,
    };
};
