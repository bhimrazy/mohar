import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { KeyValuePair } from './types';

type KeyValueEditorProps = {
    value: KeyValuePair[];
    onChange: (value: KeyValuePair[]) => void;
    errors?: Record<string, string | undefined>;
    /** Error key prefix, e.g. "driver_config". */
    errorPrefix?: string;
    disabled?: boolean;
    keyPlaceholder?: string;
    valuePlaceholder?: string;
};

export function pairsFromObject(
    config: Record<string, unknown> | unknown[] | null | undefined,
): KeyValuePair[] {
    if (!config || Array.isArray(config)) {
        return [];
    }

    return Object.entries(config).map(([key, value]) => ({
        key,
        value: typeof value === 'string' ? value : JSON.stringify(value),
    }));
}

/** Editable list of setting name/value pairs (stored as a JSON object). */
export function KeyValueEditor({
    value,
    onChange,
    errors = {},
    errorPrefix = 'driver_config',
    disabled,
    keyPlaceholder = 'Setting',
    valuePlaceholder = 'Value',
}: KeyValueEditorProps) {
    const update = (index: number, patch: Partial<KeyValuePair>) =>
        onChange(
            value.map((pair, current) =>
                current === index ? { ...pair, ...patch } : pair,
            ),
        );

    return (
        <div className="grid gap-2">
            {value.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No settings yet.
                </p>
            )}
            {value.map((pair, index) => {
                const error =
                    errors[`${errorPrefix}.${index}.key`] ??
                    errors[`${errorPrefix}.${index}.value`];

                return (
                    <div key={index} className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <Input
                                aria-label={`Setting ${index + 1} name`}
                                placeholder={keyPlaceholder}
                                value={pair.key}
                                disabled={disabled}
                                aria-invalid={!!error || undefined}
                                onChange={(event) =>
                                    update(index, { key: event.target.value })
                                }
                                className="font-mono text-xs sm:max-w-56"
                            />
                            <Input
                                aria-label={`Setting ${index + 1} value`}
                                placeholder={valuePlaceholder}
                                value={pair.value}
                                disabled={disabled}
                                onChange={(event) =>
                                    update(index, { value: event.target.value })
                                }
                                className="font-mono text-xs"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                disabled={disabled}
                                aria-label={`Remove setting ${pair.key || index + 1}`}
                                onClick={() =>
                                    onChange(
                                        value.filter(
                                            (_, current) => current !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError message={error} />
                    </div>
                );
            })}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled}
                    onClick={() => onChange([...value, { key: '', value: '' }])}
                >
                    <Plus />
                    Add setting
                </Button>
            </div>
        </div>
    );
}
