import { router, usePage } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import MailTestController from '@/actions/App/Modules/Platform/Http/Controllers/Admin/MailTestController';
import { Field } from '@/components/app/field';
import { SectionCard } from '@/components/app/section-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

export type MailTestProps = {
    default_to: string;
    saved_here: boolean;
    max_attempts: number;
};

type MailTestFlash = {
    ok: boolean;
    to: string;
    message: string;
    error: string | null;
};

/** "Send test email" under the mail server settings; it always uses the saved settings. */
export function MailTestCard({
    test,
    unsaved,
}: {
    test: MailTestProps;
    /** The settings form has changes that are not saved yet. */
    unsaved: boolean;
}) {
    const page = usePage<{ errors: Record<string, string> }>();
    const result = (page.flash as { mailTest?: MailTestFlash }).mailTest;
    const error = page.props.errors.to;
    const [to, setTo] = useState(test.default_to);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            MailTestController.url(),
            { to },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <form onSubmit={submit} className="min-w-0">
            <SectionCard
                title="Send a test email"
                description={`Sends one message now with the saved settings${test.saved_here ? '' : ' (from the server environment until this page is saved)'} and shows the mail server's answer. At most ${test.max_attempts} tests every 10 minutes.`}
                footer={
                    <div className="flex flex-wrap items-center justify-end gap-3">
                        {unsaved && (
                            <span className="text-sm text-muted-foreground">
                                Save your changes first: the test uses the saved
                                settings.
                            </span>
                        )}
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing || unsaved}
                        >
                            {processing && <Spinner />}
                            Send test email
                        </Button>
                    </div>
                }
            >
                <div className="grid gap-6">
                    <Field
                        htmlFor="mail-test-to"
                        label="Send to"
                        error={error}
                        hint="Any address you can check. Defaults to your own."
                    >
                        <Input
                            id="mail-test-to"
                            type="email"
                            value={to}
                            onChange={(event) => setTo(event.target.value)}
                            aria-invalid={Boolean(error)}
                            aria-describedby="mail-test-to-description"
                            className="max-w-md"
                        />
                    </Field>

                    {result && (
                        <Alert variant={result.ok ? 'default' : 'destructive'}>
                            {result.ok ? <CheckCircle2 /> : <XCircle />}
                            <AlertTitle>
                                {result.ok
                                    ? 'Test email sent'
                                    : 'Test email failed'}
                            </AlertTitle>
                            <AlertDescription>
                                <p>{result.message}</p>
                                {result.error && (
                                    <code className="mt-2 block max-w-full overflow-x-auto rounded-md border bg-muted/50 p-2 font-mono text-xs break-words whitespace-pre-wrap text-foreground">
                                        {result.error}
                                    </code>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                </div>
            </SectionCard>
        </form>
    );
}
