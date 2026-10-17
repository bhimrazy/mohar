import InputError from '@/components/input-error';
import { Field } from '@/components/app/field';
import { KeyValueEditor } from '@/components/catalog/key-value-editor';
import { SelectInput } from '@/components/catalog/select-input';
import type { KeyValuePair } from '@/components/catalog/types';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';

/** Shape of App\Contracts\Services\ProvisioningDrivers::all(). */
export type ProvisioningDriver = {
    key: string;
    label: string;
    config_fields: {
        key: string;
        label: string;
        type: string;
        required: boolean;
        secret: boolean;
        options?: string[];
    }[];
};

type DriverSettingsProps = {
    idPrefix: string;
    drivers: ProvisioningDriver[];
    driver: string;
    onDriverChange: (driver: string) => void;
    config: KeyValuePair[];
    onConfigChange: (config: KeyValuePair[]) => void;
    errors: Record<string, string | undefined>;
    disabled?: boolean;
    /** Secret settings already stored (values are never sent to the browser). */
    secretKeys?: string[];
};

/**
 * Driver picker + settings. Lists the drivers installed in Services and renders their
 * declared settings; other keys stay editable as free-form settings. Without installed
 * drivers it falls back to a key input.
 */
export function DriverSettings({
    idPrefix,
    drivers,
    driver,
    onDriverChange,
    config,
    onConfigChange,
    errors,
    disabled,
    secretKeys = [],
}: DriverSettingsProps) {
    const selected = drivers.find((candidate) => candidate.key === driver);
    const fields = selected?.config_fields ?? [];
    const declared = fields.map((field) => field.key);
    const extra = config.filter((pair) => !declared.includes(pair.key));
    const valueOf = (key: string) =>
        config.find((pair) => pair.key === key)?.value ?? '';

    const setValue = (key: string, value: string) => {
        const exists = config.some((pair) => pair.key === key);

        onConfigChange(
            exists
                ? config.map((pair) =>
                      pair.key === key ? { ...pair, value } : pair,
                  )
                : [...config, { key, value }],
        );
    };

    const driverId = `${idPrefix}-driver`;

    return (
        <div className="grid gap-6">
            <Field
                htmlFor={driverId}
                label="Provisioning driver"
                hint={
                    drivers.length === 0
                        ? 'No drivers are installed yet. Use “none” for manual setup, or a driver key such as log.'
                        : selected
                          ? undefined
                          : 'Choose “None” to set services up by hand.'
                }
                error={errors.provisioning_driver}
            >
                {drivers.length > 0 ? (
                    <SelectInput
                        id={driverId}
                        value={driver}
                        onChange={onDriverChange}
                        disabled={disabled}
                        invalid={!!errors.provisioning_driver}
                        options={[
                            { value: 'none', label: 'None (manual setup)' },
                            ...drivers
                                .filter((candidate) => candidate.key !== 'none')
                                .map((candidate) => ({
                                    value: candidate.key,
                                    label: candidate.label,
                                })),
                            ...(driver !== 'none' && !selected
                                ? [
                                      {
                                          value: driver,
                                          label: `${driver} (not installed)`,
                                      },
                                  ]
                                : []),
                        ]}
                    />
                ) : (
                    <Input
                        id={driverId}
                        value={driver}
                        disabled={disabled}
                        onChange={(event) => onDriverChange(event.target.value)}
                        aria-invalid={!!errors.provisioning_driver}
                        aria-describedby={`${driverId}-description`}
                        className="font-mono"
                    />
                )}
            </Field>

            {fields.length > 0 && (
                <div className="grid items-start gap-6 sm:grid-cols-2">
                    {fields.map((field) => {
                        const id = `${idPrefix}-setting-${field.key}`;
                        const error = errors[`driver_settings.${field.key}`];

                        if (
                            field.type === 'checkbox' ||
                            field.type === 'boolean'
                        ) {
                            return (
                                <div
                                    key={field.key}
                                    className="grid gap-1 sm:col-span-2"
                                >
                                    <label
                                        htmlFor={id}
                                        className="flex items-center gap-2 text-sm font-medium"
                                    >
                                        <Checkbox
                                            id={id}
                                            checked={valueOf(field.key) === '1'}
                                            disabled={disabled}
                                            onCheckedChange={(checked) =>
                                                setValue(
                                                    field.key,
                                                    checked === true
                                                        ? '1'
                                                        : '0',
                                                )
                                            }
                                        />
                                        {field.label}
                                    </label>
                                    <InputError message={error} />
                                </div>
                            );
                        }

                        return (
                            <Field
                                key={field.key}
                                htmlFor={id}
                                label={field.label}
                                optional={!field.required}
                                error={error}
                            >
                                {field.options && field.options.length > 0 ? (
                                    <SelectInput
                                        id={id}
                                        value={valueOf(field.key)}
                                        onChange={(value) =>
                                            setValue(field.key, value)
                                        }
                                        options={field.options.map(
                                            (option) => ({
                                                value: option,
                                                label: option,
                                            }),
                                        )}
                                        disabled={disabled}
                                        invalid={!!error}
                                    />
                                ) : (
                                    <Input
                                        id={id}
                                        type={
                                            field.secret
                                                ? 'password'
                                                : field.type === 'number'
                                                  ? 'number'
                                                  : 'text'
                                        }
                                        autoComplete="off"
                                        placeholder={
                                            secretKeys.includes(field.key)
                                                ? '•••••••• (leave empty to keep)'
                                                : undefined
                                        }
                                        value={valueOf(field.key)}
                                        disabled={disabled}
                                        onChange={(event) =>
                                            setValue(
                                                field.key,
                                                event.target.value,
                                            )
                                        }
                                        aria-invalid={!!error}
                                        aria-describedby={`${id}-description`}
                                    />
                                )}
                            </Field>
                        );
                    })}
                </div>
            )}

            <div className="grid gap-2">
                <span className="text-sm font-medium">
                    {fields.length > 0
                        ? 'Additional settings'
                        : 'Driver settings'}
                </span>
                <KeyValueEditor
                    value={extra}
                    onChange={(pairs) =>
                        onConfigChange([
                            ...config.filter((pair) =>
                                declared.includes(pair.key),
                            ),
                            ...pairs,
                        ])
                    }
                    errors={errors}
                    disabled={disabled}
                    secretKeys={secretKeys}
                />
            </div>
        </div>
    );
}
