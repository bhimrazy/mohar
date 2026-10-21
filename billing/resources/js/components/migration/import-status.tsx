import { router, usePoll } from '@inertiajs/react';
import { Check, ServerCrash } from 'lucide-react';
import { useEffect } from 'react';
import { DateTime } from '@/components/app/date';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ClientImportController from '@/actions/App/Modules/Migration/Http/Controllers/Admin/ClientImportController';
import { cn } from '@/lib/utils';

export type ImportRun = {
    id: number;
    name: string;
    status:
        | 'uploaded'
        | 'staging'
        | 'mapping'
        | 'checking'
        | 'ready'
        | 'applying'
        | 'completed'
        | 'failed'
        | 'expired';
    rows: number;
    staged: number;
    checked: number;
    applied: number;
    running: boolean;
    stalled: boolean;
    error: string | null;
    purged: boolean;
    queue: string;
    requested_by: string | null;
    uploaded_at: string | null;
    expires_at: string;
};

const STEPS = ['Upload', 'Map', 'Check', 'Review', 'Done'];

export const count = (value: number) => value.toLocaleString('en-US');

/** Upload → Map → Check → Review → Done, with the current step marked. */
export function ImportSteps({ current }: { current: number }) {
    return (
        <ol className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            {STEPS.map((step, index) => (
                <li
                    key={step}
                    aria-current={index === current ? 'step' : undefined}
                    className={cn(
                        'flex items-center gap-2',
                        index > current && 'text-muted-foreground',
                    )}
                >
                    <span
                        className={cn(
                            'flex size-6 items-center justify-center rounded-full border text-xs tabular-nums',
                            index < current &&
                                'border-primary bg-primary text-primary-foreground',
                            index === current &&
                                'border-primary font-medium text-primary',
                        )}
                    >
                        {index < current ? (
                            <Check className="size-3.5" />
                        ) : (
                            index + 1
                        )}
                    </span>
                    {step}
                </li>
            ))}
        </ol>
    );
}

/**
 * What the run is doing: progress while a step runs (the page polls), the stalled alert after 10 minutes without a
 * worker, and the failure with the action that continues it.
 */
export function ImportProgress({ run }: { run: ImportRun }) {
    const { start, stop } = usePoll(3000, {}, { autoStart: false });
    const live = run.running && !run.stalled;

    useEffect(() => {
        if (live) {
            start();
        } else {
            stop();
        }
    }, [live, start, stop]);

    const resume = (
        <Button
            size="sm"
            variant="outline"
            className="mt-2 w-fit"
            onClick={() =>
                router.post(
                    ClientImportController.resume(run.id).url,
                    {},
                    { preserveScroll: true },
                )
            }
        >
            Continue where it stopped
        </Button>
    );

    if (run.status === 'failed' || run.stalled) {
        return (
            <Alert variant="destructive">
                <ServerCrash />
                <AlertTitle>
                    {run.stalled
                        ? 'This step did not finish'
                        : 'This step stopped'}
                </AlertTitle>
                <AlertDescription>
                    <span>
                        {run.purged
                            ? 'Row details were removed; continue is no longer possible.'
                            : run.stalled
                              ? `No worker has reported for 10 minutes. Check that a queue worker runs the "${run.queue}" queue, then continue.`
                              : run.error}
                    </span>
                    {!run.purged && resume}
                </AlertDescription>
            </Alert>
        );
    }

    if (!live) {
        return null;
    }

    const [title, done, verb] =
        run.status === 'checking'
            ? ['Checking the rows', run.checked, 'checked']
            : run.status === 'applying'
              ? ['Importing the clients', run.applied, 'imported']
              : ['Reading the file', run.staged, 'read'];

    return (
        <Alert>
            <Spinner />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>
                {count(done)} of {count(run.rows)} rows {verb}. This page
                updates as it goes.
            </AlertDescription>
        </Alert>
    );
}

/** "Uploaded 20 Oct 2026 by Asha · kept until 27 Oct 2026". */
export function ImportMeta({ run }: { run: ImportRun }) {
    return (
        <span className="text-sm text-muted-foreground">
            {count(run.rows)} rows · uploaded{' '}
            <DateTime value={run.uploaded_at} format="datetime" />
            {run.requested_by && ` by ${run.requested_by}`} · kept until{' '}
            <DateTime value={run.expires_at} />
        </span>
    );
}
