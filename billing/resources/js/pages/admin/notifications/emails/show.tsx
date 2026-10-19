import { Head, Link } from '@inertiajs/react';
import { RefreshCw, ShieldCheck } from 'lucide-react';
import EmailLogController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/EmailLogController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { DateTime } from '@/components/app/date';
import { DescriptionList } from '@/components/app/description-list';
import { Page } from '@/components/app/page';
import { PageHeader } from '@/components/app/page-header';
import { SectionCard } from '@/components/app/section-card';
import { EmailFrame } from '@/components/notifications/email-frame';
import { DeliveryStatus } from '@/components/notifications/email-status';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { EmailSummary } from '@/types/notifications';

type Message = EmailSummary & {
    cc: string[];
    bcc: string[];
    from: string | null;
    locale: string;
    body_html: string | null;
    body_text: string | null;
    has_redactions: boolean;
    body_purged: boolean;
    suppressed_reason: string | null;
    failure_reason: string | null;
    provider_message_id: string | null;
    related: { type: string; id: number } | null;
    queued_at: string | null;
};

export default function EmailLogShow({
    message,
    canResend,
    resendBlocked,
    newLink,
}: {
    message: Message;
    canResend: boolean;
    /** Why Resend is off (mail with a private link), or null. */
    resendBlocked: string | null;
    /** Where staff can give the recipient a new link, if anywhere. */
    newLink: { label: string; href: string } | null;
}) {
    return (
        <>
            <Head title={message.subject} />
            <Page>
                <PageHeader
                    back={{
                        href: EmailLogController.index(),
                        label: 'Email log',
                    }}
                    title={message.subject}
                    badges={<DeliveryStatus status={message.status} />}
                    actions={
                        resendBlocked ? (
                            <div className="flex items-center gap-2">
                                {newLink && (
                                    <Button variant="outline" asChild>
                                        <Link href={newLink.href}>
                                            {newLink.label}
                                        </Link>
                                    </Button>
                                )}
                                <Button
                                    variant="outline"
                                    disabled
                                    title={resendBlocked}
                                >
                                    <RefreshCw />
                                    Resend
                                </Button>
                            </div>
                        ) : (
                            canResend && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline">
                                            <RefreshCw />
                                            Resend
                                        </Button>
                                    }
                                    title="Resend this email?"
                                    description={`The stored copy is sent again to ${message.to.join(', ')} as a new attempt.`}
                                    confirmLabel="Resend"
                                    action={EmailLogController.resend(
                                        message.id,
                                    )}
                                />
                            )
                        )
                    }
                />
                <div className="grid items-start gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        {message.has_redactions && (
                            <Alert>
                                <ShieldCheck />
                                <AlertDescription>
                                    {resendBlocked ??
                                        'This email contained a private link.'}{' '}
                                    The link is masked as •••• in this copy, so
                                    it can't be resent.
                                </AlertDescription>
                            </Alert>
                        )}
                        <SectionCard title="Body" flush>
                            {message.body_purged ? (
                                <p className="p-4 text-sm text-muted-foreground">
                                    The body was removed by the retention
                                    policy.
                                </p>
                            ) : (
                                <Tabs defaultValue="html" className="p-4">
                                    <TabsList>
                                        <TabsTrigger value="html">
                                            HTML
                                        </TabsTrigger>
                                        <TabsTrigger value="text">
                                            Text
                                        </TabsTrigger>
                                    </TabsList>
                                    <TabsContent value="html" className="pt-3">
                                        <EmailFrame
                                            title="Stored email"
                                            html={message.body_html}
                                            text={message.body_text}
                                        />
                                    </TabsContent>
                                    <TabsContent value="text" className="pt-3">
                                        <EmailFrame
                                            title="Stored text"
                                            html={null}
                                            text={message.body_text}
                                        />
                                    </TabsContent>
                                </Tabs>
                            )}
                        </SectionCard>
                    </div>
                    <SectionCard title="Details">
                        <DescriptionList
                            columns={1}
                            items={[
                                { label: 'To', value: message.to.join(', ') },
                                { label: 'CC', value: message.cc.join(', ') },
                                { label: 'BCC', value: message.bcc.join(', ') },
                                { label: 'From', value: message.from },
                                {
                                    label: 'Client',
                                    value: message.client?.name,
                                },
                                {
                                    label: 'Template',
                                    value: (
                                        <span className="font-mono text-xs">
                                            {message.template_name}
                                        </span>
                                    ),
                                },
                                {
                                    label: 'Language',
                                    value: message.locale.toUpperCase(),
                                },
                                { label: 'Attempts', value: message.attempts },
                                {
                                    label: 'Queued',
                                    value: (
                                        <DateTime value={message.queued_at} />
                                    ),
                                },
                                {
                                    label: 'Sent',
                                    value: message.sent_at && (
                                        <DateTime value={message.sent_at} />
                                    ),
                                },
                                ...(message.status === 'sent'
                                    ? []
                                    : [
                                          {
                                              label: 'Why not sent',
                                              value:
                                                  message.failure_reason ??
                                                  message.suppressed_reason?.replace(
                                                      '_',
                                                      ' ',
                                                  ),
                                              full: true,
                                          },
                                      ]),
                                {
                                    label: 'Provider message id',
                                    value: message.provider_message_id && (
                                        <span className="font-mono text-xs break-all">
                                            {message.provider_message_id}
                                        </span>
                                    ),
                                },
                            ]}
                        />
                    </SectionCard>
                </div>
            </Page>
        </>
    );
}
