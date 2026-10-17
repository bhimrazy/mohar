import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type FieldProps = {
    /** Must match the control's `id`. */
    htmlFor: string;
    label: ReactNode;
    /** Helper text shown under the control (hidden while an error shows). */
    hint?: ReactNode;
    error?: string;
    /** Adds a subtle "Optional" marker; required is the default assumption. */
    optional?: boolean;
    children: ReactNode;
    className?: string;
};

/**
 * Label + control + hint/error. Pass `aria-invalid={!!error}` and
 * `aria-describedby={`${id}-description`}` to the control for a11y.
 */
export function Field({
    htmlFor,
    label,
    hint,
    error,
    optional = false,
    children,
    className,
}: FieldProps) {
    return (
        <div data-slot="field" className={cn('grid gap-2', className)}>
            <Label htmlFor={htmlFor}>
                {label}
                {optional && (
                    <span className="ml-1 font-normal text-muted-foreground">
                        (optional)
                    </span>
                )}
            </Label>
            {children}
            {error ? (
                <InputError id={`${htmlFor}-description`} message={error} />
            ) : (
                hint && (
                    <p
                        id={`${htmlFor}-description`}
                        className="text-sm text-muted-foreground"
                    >
                        {hint}
                    </p>
                )
            )}
        </div>
    );
}
