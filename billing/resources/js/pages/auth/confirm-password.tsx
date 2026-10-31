import { Form, setLayoutProps } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { SetPasswordButton } from '@/components/app/set-password-button';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword({
    download = false,
    hasPassword = true,
}: {
    download?: boolean;
    hasPassword?: boolean;
}) {
    if (!hasPassword) {
        setLayoutProps({
            title: 'Set a password to continue',
            description:
                "This action needs a password, and your account doesn't have one yet. We can email you a link to set one; then come back.",
        });

        return (
            <>
                <Head title="Set a password" />

                <SetPasswordButton className="w-full" />
            </>
        );
    }

    if (download) {
        setLayoutProps({
            description:
                "Enter your password to download this file; you won't be asked again for 15 minutes. The download starts as soon as you confirm.",
        });
    }

    return (
        <>
            <Head title="Confirm password" />

            {/* No passkey option: only a password confirmation counts as step-up (ADR 0018 §4). */}
            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Password"
                                autoComplete="current-password"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center">
                            <Button
                                className="w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                Confirm password
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm your password',
    description:
        "This page changes sensitive settings. Enter your password to continue; you won't be asked again for 15 minutes.",
};
