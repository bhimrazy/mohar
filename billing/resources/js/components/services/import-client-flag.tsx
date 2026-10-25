import { DateTime } from '@/components/app/date';

/** Why an email match to this client is not proposed (AccountImportMatcher::clientFlags()). */
export type ImportClientFlagData = {
    kind: 'email_changed' | 'unverified';
    at: string | null;
};

/**
 * The flag as one sentence, its date in the app's format, followed by `children` (what to do about it).
 */
export function ImportClientFlag({
    flag,
    children,
    className = 'text-xs text-muted-foreground',
}: {
    flag: ImportClientFlagData;
    children?: React.ReactNode;
    className?: string;
}) {
    return (
        <span className={className}>
            {flag.kind === 'email_changed' ? (
                <>
                    This client changed email
                    {flag.at && (
                        <>
                            {' on '}
                            <DateTime value={flag.at} />
                        </>
                    )}
                </>
            ) : (
                'Email set by the client and not verified'
            )}
            {children}
        </span>
    );
}
