import { Form, Link } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import StaffInvitationController from '@/actions/App/Modules/Platform/Http/Controllers/Public/StaffInvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';

type Props = {
    token: string;
    email: string | null;
    name: string | null;
    role: string | null;
    state: 'valid' | 'expired' | 'used' | 'invalid';
};

const MESSAGES: Record<Exclude<Props['state'], 'valid'>, string> = {
    expired:
        'This invitation has expired. Ask an administrator to send a new one.',
    used: 'This invitation has already been used. Sign in with your email and password.',
    invalid: 'This invitation link is not valid. Check the link in your email.',
};

export default function AcceptInvitation({
    token,
    email,
    name,
    role,
    state,
}: Props) {
    return (
        <>
            <Head title="Join the team" />
            {state !== 'valid' ? (
                <div className="space-y-6 text-center">
                    <p className="text-sm text-muted-foreground">
                        {MESSAGES[state]}
                    </p>
                    <Button variant="outline" asChild>
                        <Link href={login()}>Go to sign in</Link>
                    </Button>
                </div>
            ) : (
                <Form
                    {...StaffInvitationController.accept.form(token)}
                    resetOnError={['password', 'password_confirmation']}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <p className="text-center text-sm text-muted-foreground">
                                You're joining as{' '}
                                <strong className="text-foreground">
                                    {role}
                                </strong>{' '}
                                with{' '}
                                <strong className="text-foreground">
                                    {email}
                                </strong>
                                .
                            </p>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Your name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={name ?? ''}
                                    required
                                    autoComplete="name"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                />
                                <p className="text-xs text-muted-foreground">
                                    At least 12 characters with a letter and a
                                    number.
                                </p>
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
                                Create my account
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Join the admin team',
    description: 'Set your name and password to activate your staff account.',
};
