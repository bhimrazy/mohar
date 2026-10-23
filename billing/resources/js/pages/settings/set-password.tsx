import { Form, Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/profile';
import { store } from '@/routes/password/set';

export default function SetPassword({
    token,
    valid,
    passwordRules,
}: {
    token: string;
    valid: boolean;
    passwordRules: string;
}) {
    return (
        <>
            <Head title="Set a password" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Set a password for your account"
                    description="You need a password to confirm sensitive actions. It is also used to sign in."
                />

                {!valid ? (
                    <div className="space-y-4">
                        <p role="alert" className="text-sm text-destructive">
                            This link no longer works. Ask for a new one the
                            next time you are asked to confirm your password.
                        </p>
                        <Button asChild variant="outline">
                            <Link href={edit().url}>Back to settings</Link>
                        </Button>
                    </div>
                ) : (
                    <Form
                        {...store.form({ token })}
                        resetOnError={['password', 'password_confirmation']}
                        className="space-y-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <InputError message={errors.token} />

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        autoFocus
                                        autoComplete="new-password"
                                        placeholder="Password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        placeholder="Confirm password"
                                        passwordrules={passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>

                                <Button
                                    disabled={processing}
                                    data-test="set-password-submit"
                                >
                                    {processing && <Spinner />}
                                    Set password
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

SetPassword.layout = {
    breadcrumbs: [{ title: 'Set a password', href: edit() }],
};
