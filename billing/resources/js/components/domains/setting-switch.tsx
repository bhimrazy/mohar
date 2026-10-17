import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Switch } from '@/components/ui/switch';
import type { RouteAction } from '@/types/app';

type SettingSwitchProps = {
    id: string;
    title: string;
    description: ReactNode;
    checked: boolean;
    action: RouteAction;
    disabled?: boolean;
};

/** Bordered on/off row that saves immediately (PUT { enabled }). */
export function SettingSwitch({
    id,
    title,
    description,
    checked,
    action,
    disabled = false,
}: SettingSwitchProps) {
    const [processing, setProcessing] = useState(false);

    const toggle = (enabled: boolean) =>
        router.visit(action.url, {
            method: action.method,
            data: { enabled },
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });

    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
            <div className="min-w-0 space-y-0.5">
                <label htmlFor={id} className="text-sm font-medium">
                    {title}
                </label>
                <p
                    id={`${id}-description`}
                    className="text-sm text-muted-foreground"
                >
                    {description}
                </p>
            </div>
            <Switch
                id={id}
                checked={checked}
                disabled={disabled || processing}
                onCheckedChange={toggle}
                aria-describedby={`${id}-description`}
            />
        </div>
    );
}
