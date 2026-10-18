import { Head } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { DateTime } from '@/components/app/date';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    invoice: { id: number; number: string };
    reference: string;
    form: { action: string; fields: Record<string, string> } | null;
    qr: { payload: string; expires_at: string } | null;
    status_url: string;
};

/**
 * Hands the customer to a hosted payment page that expects a POSTed form (eSewa, ConnectIPS), or shows a QR
 * to scan (Fonepay). Completion is always confirmed by a status lookup.
 */
export default function Checkout({
    invoice,
    reference,
    form,
    qr,
    status_url,
}: Props) {
    const formRef = useRef<HTMLFormElement>(null);

    useEffect(() => {
        if (form) {
            formRef.current?.submit();
        }
    }, [form]);

    return (
        <>
            <Head title={`Pay invoice ${invoice.number}`} />
            <Page size="narrow">
                <PageHeader
                    title={`Pay invoice ${invoice.number}`}
                    meta={<span>Reference {reference}</span>}
                />
                <SectionCard>
                    {form && (
                        <form
                            ref={formRef}
                            action={form.action}
                            method="post"
                            className="flex flex-col items-start gap-3"
                        >
                            {Object.entries(form.fields).map(
                                ([name, value]) => (
                                    <input
                                        key={name}
                                        type="hidden"
                                        name={name}
                                        value={value}
                                    />
                                ),
                            )}
                            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Spinner />
                                Taking you to the payment page…
                            </p>
                            <Button type="submit" variant="outline">
                                Continue to payment
                            </Button>
                        </form>
                    )}
                    {qr && (
                        <div className="grid gap-3">
                            <p className="text-sm text-muted-foreground">
                                Scan this code with your wallet app. It expires{' '}
                                <DateTime
                                    value={qr.expires_at}
                                    format="datetime"
                                />
                                .
                            </p>
                            <code className="block rounded-md bg-muted p-3 text-xs break-all">
                                {qr.payload}
                            </code>
                            <Button asChild variant="outline" className="w-fit">
                                <a href={status_url}>
                                    I’ve paid — check status
                                </a>
                            </Button>
                        </div>
                    )}
                </SectionCard>
            </Page>
        </>
    );
}
