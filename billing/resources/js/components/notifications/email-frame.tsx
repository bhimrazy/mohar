import { cn } from '@/lib/utils';

type EmailFrameProps = {
    html: string | null;
    text?: string | null;
    title: string;
    className?: string;
};

/**
 * Renders a stored or previewed email in a fully sandboxed iframe (no scripts, no same-origin
 * access), so email HTML can never touch the admin page. Falls back to the text part.
 */
export function EmailFrame({ html, text, title, className }: EmailFrameProps) {
    if (!html) {
        return (
            <pre
                className={cn(
                    'max-h-[640px] overflow-auto rounded-lg border bg-muted/30 p-4 font-mono text-xs whitespace-pre-wrap',
                    className,
                )}
            >
                {text || 'No body stored.'}
            </pre>
        );
    }

    return (
        <iframe
            title={title}
            sandbox=""
            srcDoc={html}
            className={cn(
                'h-[640px] w-full rounded-lg border bg-white',
                className,
            )}
        />
    );
}
