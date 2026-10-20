import { Link } from '@inertiajs/react';
import ServerImportController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServerImportController';
import ServiceController from '@/actions/App/Modules/Services/Http/Controllers/Admin/ServiceController';
import { DateTime } from '@/components/app/date';
import { useQueryParams } from '@/components/app/use-query-params';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

export type ImportReportData = {
    imported: number;
    not_imported: number;
    stop_reason: string | null;
    lines: {
        username: string;
        imported: boolean;
        message: string;
        service_id: number | null;
        at: string | null;
    }[];
    page: { current: number; last: number };
};

const NOT_REACHED = 'Not reached.';

const plural = (count: number, noun: string) =>
    `${count} ${noun}${count === 1 ? '' : 's'}`;

/**
 * The last import's result: a summary, the stop banner when it stopped (with one action to go on), and every line a
 * page at a time, the accounts not imported first.
 */
export function ImportReport({
    serverId,
    report,
    remaining,
}: {
    serverId: number;
    report: ImportReportData;
    /** Accounts still ticked: what "Review the remaining" would import. */
    remaining: number;
}) {
    const { merge } = useQueryParams();
    const total = report.imported + report.not_imported;
    const pageLink = (page: number) => ({
        url: '?' + new URLSearchParams(merge({ report_page: page })).toString(),
    });

    return (
        <div className="grid gap-3">
            {report.stop_reason && (
                <Alert variant="destructive">
                    <AlertTitle className="line-clamp-none">
                        The import stopped after {report.imported} of{' '}
                        {plural(total, 'account')}.
                    </AlertTitle>
                    <AlertDescription className="flex flex-col items-start gap-2">
                        <span>
                            {report.stop_reason}{' '}
                            {plural(report.not_imported, 'account')} were not
                            imported; your choices for them are kept.
                        </span>
                        {remaining > 0 && (
                            <Button size="sm" asChild>
                                <Link
                                    href={ServerImportController.review(
                                        serverId,
                                    )}
                                >
                                    Review the remaining {remaining}
                                </Link>
                            </Button>
                        )}
                    </AlertDescription>
                </Alert>
            )}

            <Alert>
                <AlertTitle className="line-clamp-none">
                    Last import: {report.imported} of {plural(total, 'account')}{' '}
                    imported
                    {report.not_imported > 0 &&
                        `, ${report.not_imported} not imported`}
                </AlertTitle>
                <AlertDescription>
                    <details open={report.not_imported > 0 && total <= 50}>
                        <summary className="cursor-pointer text-sm">
                            Show details
                        </summary>
                        <ul className="mt-2 grid gap-1">
                            {report.lines.map((line) => (
                                <li key={line.username} className="break-words">
                                    <span className="font-mono">
                                        {line.username}
                                    </span>
                                    {' — '}
                                    {line.imported ? (
                                        <>
                                            Imported{' '}
                                            <DateTime
                                                value={line.at}
                                                relative
                                            />
                                            {line.service_id && (
                                                <>
                                                    {' · '}
                                                    <Link
                                                        href={ServiceController.show(
                                                            line.service_id,
                                                        )}
                                                        className="text-primary hover:underline"
                                                    >
                                                        View service
                                                    </Link>
                                                </>
                                            )}
                                        </>
                                    ) : line.message === NOT_REACHED ? (
                                        <span className="text-muted-foreground">
                                            Not reached
                                        </span>
                                    ) : (
                                        <span className="text-destructive">
                                            Not imported: {line.message}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {report.page.last > 1 && (
                            <p className="mt-2 flex gap-4 text-sm">
                                Page {report.page.current} of {report.page.last}
                                {report.page.current > 1 && (
                                    <Link
                                        href={
                                            pageLink(report.page.current - 1)
                                                .url
                                        }
                                        preserveScroll
                                        className="text-primary hover:underline"
                                    >
                                        Previous
                                    </Link>
                                )}
                                {report.page.current < report.page.last && (
                                    <Link
                                        href={
                                            pageLink(report.page.current + 1)
                                                .url
                                        }
                                        preserveScroll
                                        className="text-primary hover:underline"
                                    >
                                        Next
                                    </Link>
                                )}
                            </p>
                        )}
                    </details>
                </AlertDescription>
            </Alert>
        </div>
    );
}
