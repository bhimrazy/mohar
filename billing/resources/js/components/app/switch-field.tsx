import { useState } from 'react';
import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

type SwitchFieldProps = {
    id: string;
    /** Form field name; submits "1" / "0" so unchecked values are sent too. */
    name?: string;
    label: ReactNode;
    description?: ReactNode;
    defaultChecked?: boolean;
    checked?: boolean;
    onCheckedChange?: (checked: boolean) => void;
    disabled?: boolean;
    className?: string;
};

/** Toggle in a bordered row with title + one-line description (design system §2 Settings). */
export function SwitchField({
    id,
    name,
    label,
    description,
    defaultChecked = false,
    checked: controlled,
    onCheckedChange,
    disabled,
    className,
}: SwitchFieldProps) {
    const [internal, setInternal] = useState(defaultChecked);
    const checked = controlled ?? internal;

    return (
        <div
            data-slot="switch-field"
            className={cn(
                'flex items-start justify-between gap-4 rounded-lg border p-3',
                className,
            )}
        >
            <div className="space-y-0.5">
                <Label htmlFor={id}>{label}</Label>
                {description && (
                    <p
                        id={`${id}-description`}
                        className="text-sm text-muted-foreground"
                    >
                        {description}
                    </p>
                )}
            </div>
            <Switch
                id={id}
                checked={checked}
                disabled={disabled}
                aria-describedby={description ? `${id}-description` : undefined}
                onCheckedChange={(next) => {
                    setInternal(next);
                    onCheckedChange?.(next);
                }}
            />
            {name && (
                <input type="hidden" name={name} value={checked ? '1' : '0'} />
            )}
        </div>
    );
}
