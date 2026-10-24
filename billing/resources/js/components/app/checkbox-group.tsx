import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type CheckboxGroupProps = {
    id: string;
    label: ReactNode;
    hint?: ReactNode;
    error?: string;
    options: { value: string; label: string }[];
    value: string[];
    onValueChange: (value: string[]) => void;
    disabled?: boolean;
    className?: string;
};

/** Several choices from a short list, one checkbox each. The value keeps the options' order. */
export function CheckboxGroup({
    id,
    label,
    hint,
    error,
    options,
    value,
    onValueChange,
    disabled,
    className,
}: CheckboxGroupProps) {
    const toggle = (option: string, checked: boolean) =>
        onValueChange(
            options
                .map((item) => item.value)
                .filter((item) =>
                    item === option ? checked : value.includes(item),
                ),
        );

    return (
        <fieldset
            id={id}
            data-slot="checkbox-group"
            aria-describedby={error || hint ? `${id}-description` : undefined}
            aria-invalid={Boolean(error) || undefined}
            className={cn('grid gap-3', className)}
        >
            <legend className="mb-2 text-sm leading-none font-medium">
                {label}
            </legend>
            <div className="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                {options.map((option) => {
                    const optionId = `${id}-${option.value.replace(/[^a-z0-9_-]/gi, '-')}`;

                    return (
                        <div
                            key={option.value}
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id={optionId}
                                checked={value.includes(option.value)}
                                disabled={disabled}
                                aria-invalid={Boolean(error) || undefined}
                                onCheckedChange={(checked) =>
                                    toggle(option.value, checked === true)
                                }
                            />
                            <Label htmlFor={optionId} className="font-normal">
                                {option.label}
                            </Label>
                        </div>
                    );
                })}
            </div>
            {error ? (
                <InputError id={`${id}-description`} message={error} />
            ) : (
                hint && (
                    <p
                        id={`${id}-description`}
                        className="text-sm text-muted-foreground"
                    >
                        {hint}
                    </p>
                )
            )}
        </fieldset>
    );
}
