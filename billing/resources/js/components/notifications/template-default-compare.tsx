import { History, Sparkles } from 'lucide-react';
import EmailTemplateController from '@/actions/App/Modules/Notifications/Http/Controllers/Admin/EmailTemplateController';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

export type TemplateText = {
    subject: string;
    preheader: string | null;
    heading: string | null;
    body: string;
    action_label: string | null;
};

const PARTS: { key: keyof TemplateText; label: string }[] = [
    { key: 'subject', label: 'Subject' },
    { key: 'preheader', label: 'Preheader' },
    { key: 'heading', label: 'Heading' },
    { key: 'body', label: 'Body' },
    { key: 'action_label', label: 'Button label' },
];

function TextColumn({ title, text }: { title?: string; text: TemplateText }) {
    return (
        <div className="min-w-0 space-y-3">
            {title && <h3 className="text-sm font-semibold">{title}</h3>}
            {PARTS.filter(({ key }) => (text[key] ?? '') !== '').map(
                ({ key, label }) => (
                    <div key={key} className="space-y-1">
                        <p className="text-xs text-muted-foreground">{label}</p>
                        <pre className="max-h-72 overflow-auto rounded-md border bg-muted/40 p-2 font-mono text-xs whitespace-pre-wrap">
                            {text[key]}
                        </pre>
                    </div>
                ),
            )}
        </div>
    );
}

/**
 * An edited system template whose shipped default changed: compare the two side by side and switch to the new
 * default (the current text is kept). Also shows the text a previous switch replaced.
 */
export function TemplateDefaultCompare({
    templateId,
    current,
    newDefault,
    previous,
    canManage,
}: {
    templateId: number;
    current: TemplateText;
    newDefault: { status: 'changed' | 'untracked'; text: TemplateText } | null;
    previous: (TemplateText & { replaced_at: string }) | null;
    canManage: boolean;
}) {
    if (!newDefault && !previous) {
        return null;
    }

    return (
        <div className="grid gap-3">
            {newDefault && (
                <Alert>
                    <Sparkles />
                    <AlertTitle>
                        {newDefault.status === 'changed'
                            ? 'New default available'
                            : 'Compare with the current default'}
                    </AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center gap-3">
                        {newDefault.status === 'changed'
                            ? 'This release ships new wording for this email. Your edited text is unchanged until you choose.'
                            : 'You edited this email before defaults were tracked, and its default wording has changed since. Your text is unchanged until you choose.'}
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                >
                                    Compare
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-4xl">
                                <DialogHeader>
                                    <DialogTitle>
                                        Your text and the new default
                                    </DialogTitle>
                                    <DialogDescription>
                                        Using the new default keeps a copy of
                                        your text, shown on this page.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-6 md:grid-cols-2">
                                    <TextColumn
                                        title="Your text"
                                        text={current}
                                    />
                                    <TextColumn
                                        title="New default"
                                        text={newDefault.text}
                                    />
                                </div>
                                {canManage && (
                                    <DialogFooter>
                                        <ConfirmDialog
                                            trigger={
                                                <Button type="button">
                                                    Use new default
                                                </Button>
                                            }
                                            title="Use the new default text?"
                                            description="Your current text is replaced by the new default and kept as your previous text."
                                            confirmLabel="Use new default"
                                            destructive={false}
                                            action={EmailTemplateController.restore(
                                                templateId,
                                            )}
                                        />
                                    </DialogFooter>
                                )}
                            </DialogContent>
                        </Dialog>
                    </AlertDescription>
                </Alert>
            )}
            {previous && (
                <Alert>
                    <History />
                    <AlertTitle>Your previous text is kept</AlertTitle>
                    <AlertDescription className="flex flex-wrap items-center gap-3">
                        Replaced on{' '}
                        {new Date(previous.replaced_at).toLocaleDateString()}.
                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                >
                                    View previous text
                                </Button>
                            </DialogTrigger>
                            <DialogContent className="sm:max-w-2xl">
                                <DialogHeader>
                                    <DialogTitle>Previous text</DialogTitle>
                                    <DialogDescription>
                                        Copy anything you want back into the
                                        editor.
                                    </DialogDescription>
                                </DialogHeader>
                                <TextColumn text={previous} />
                            </DialogContent>
                        </Dialog>
                    </AlertDescription>
                </Alert>
            )}
        </div>
    );
}
