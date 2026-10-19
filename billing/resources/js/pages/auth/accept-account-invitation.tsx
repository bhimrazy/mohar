import { Form, Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import ClientInvitationController from '@/actions/App/Modules/Platform/Http/Controllers/Public/ClientInvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login, password } from '@/routes';

type Props = {
    token: string;
    email: string | null;
    account: string | null;
    state: 'valid' | 'expired' | 'used' | 'invalid';
};

const MESSAGES: Record<Exclude<Props['state'], 'valid'>, string> = {
    expired:
        'This invitation has expired. Ask the account owner or our team to send you a new one.',
    used: 'This invitation has already been used. Log in with your email and password.',
    invalid:
        'This invitation link is not valid. Check the link in your email, or ask for a new invitation.',
};

export default function AcceptAccountInvitation({
    token,
    email,
    account,
    state,
}: Props) {
    return (
        <>
            <Head title="Accept your invitation" />
            {state !== 'valid' ? (
                <div className="space-y-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        {MESSAGES[state]}
                    </p>
                    <div className="flex flex-wrap justify-center gap-2">
                        <Button variant="outline" asChild>
                            <Link href={login()}>Log in</Link>
                        </Button>
                        {state === 'used' && (
                            <Button variant="ghost" asChild>
                                <Link href={password.request()}>
                                    Forgot your password?
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            ) : (
                <Form
                    {...ClientInvitationController.accept.form(token)}
                    resetOnError={['password', 'password_confirmation']}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-center text-sm text-muted-foreground">
                                Choose a password for{' '}
                                <strong className="text-foreground">
                                    {email}
                                </strong>{' '}
                                to use the{' '}
                                <strong className="text-foreground">
                                    {account}
                                </strong>{' '}
                                account.
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
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
                                    required
                                    autoComplete="new-password"
                                />
                            </div>
                            <InputError message={errors.token} />
                            <Button
                                type="submit"
                                className="w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Set password and log in
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}

AcceptAccountInvitation.layout = {
    title: 'Accept your invitation',
    description: 'Choose a password to start using your account.',
};
