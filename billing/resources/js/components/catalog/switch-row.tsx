import type { ReactNode } from 'react';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';

type SwitchRowProps = {
    id: string;
    title: ReactNode;
    description?: ReactNode;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
    disabled?: boolean;
    className?: string;
};

/** Bordered toggle row: title + one-line description + switch (design system §2 Settings). */
export function SwitchRow({
    id,
    title,
    description,
    checked,
    onCheckedChange,
    disabled,
    className,
}: SwitchRowProps) {
    return (
        <div
            className={cn(
                'flex items-center justify-between gap-4 rounded-lg border p-3',
                className,
            )}
        >
            <div className="space-y-0.5">
                <label htmlFor={id} className="text-sm font-medium">
                    {title}
                </label>
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
                onCheckedChange={onCheckedChange}
                aria-describedby={description ? `${id}-description` : undefined}
            />
        </div>
    );
}
