import { cn } from '@/lib/utils';

type MarkdownProps = {
    /**
     * HTML rendered on the server by MarkdownRenderer (raw HTML stripped,
     * unsafe links removed). Never pass unsanitised user input here.
     */
    html: string;
    className?: string;
};

/** Typography for server-rendered, sanitised Markdown. */
export function Markdown({ html, className }: MarkdownProps) {
    return (
        <div
            data-slot="markdown"
            className={cn(
                'text-sm leading-relaxed break-words',
                '[&_a]:font-medium [&_a]:underline [&_a]:underline-offset-4',
                '[&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground',
                '[&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:py-0.5 [&_code]:font-mono [&_code]:text-[0.85em]',
                '[&_h1]:mt-4 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:mt-4 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-3 [&_h3]:font-semibold',
                '[&_hr]:my-4',
                '[&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:my-0.5',
                '[&_p]:my-2 [&_p:first-child]:mt-0 [&_p:last-child]:mb-0',
                '[&_pre]:my-3 [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-muted [&_pre]:p-3 [&_pre_code]:bg-transparent [&_pre_code]:p-0',
                '[&_table]:my-3 [&_table]:w-full [&_table]:text-left [&_td]:border-t [&_td]:px-2 [&_td]:py-1.5 [&_th]:px-2 [&_th]:py-1.5 [&_th]:font-medium',
                className,
            )}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
