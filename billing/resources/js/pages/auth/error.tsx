import { Link, setLayoutProps } from '@inertiajs/react';
import { Head } from '@/components/app/head';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';

const MESSAGES: Record<number, { title: string; description: string }> = {
    403: {
        title: "You don't have access to this page",
        description:
            "Your account can't open this page. If you think it should, ask the person who manages your account or contact support.",
    },
    404: {
        title: 'Page not found',
        description:
            'The page may have moved, or the link may be out of date. Check the address, or go back and try again.',
    },
    419: {
        title: 'This page has expired',
        description:
            'It was open for too long. Go back, reload the page and try again; nothing was changed.',
    },
    429: {
        title: 'Too many attempts',
        description: 'Wait a minute, then try again.',
    },
    500: {
        title: 'Something went wrong on our side',
        description:
            'The problem has been recorded. Try again in a moment; if it keeps happening, contact support.',
    },
};

export default function ErrorPage({ status }: { status: number }) {
    const message = MESSAGES[status] ?? MESSAGES[500];

    setLayoutProps({ title: message.title, description: message.description });

    return (
        <>
            <Head title={message.title} />

            <p className="text-center text-sm text-muted-foreground tabular-nums">
                Error {status}
            </p>
            <div className="flex flex-col gap-2 sm:flex-row sm:justify-center">
                <Button variant="outline" onClick={() => window.history.back()}>
                    Go back
                </Button>
                <Button asChild>
                    <Link href={home()}>Go to the home page</Link>
                </Button>
            </div>
        </>
    );
}
