import { Field } from '@/components/app/field';
import { SettingFieldInput } from '@/components/app/setting-field';
import type { SettingFieldData } from '@/components/app/setting-field';
import { KeyValueEditor } from '@/components/catalog/key-value-editor';
import { SelectInput } from '@/components/catalog/select-input';
import type { KeyValuePair } from '@/components/catalog/types';
import { Input } from '@/components/ui/input';

/** Shape of App\Contracts\Services\ProvisioningDrivers::all(). */
export type ProvisioningDriver = {
    key: string;
    label: string;
    config_fields: SettingFieldData[];
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
                    {fields.map((field) => (
                        <SettingFieldInput
                            key={field.key}
                            className={
                                field.type === 'text' ||
                                field.type === 'secret_text'
                                    ? 'sm:col-span-2'
                                    : undefined
                            }
                            id={`${idPrefix}-setting-${field.key}`}
                            field={field}
                            value={valueOf(field.key)}
                            onChange={(value) => setValue(field.key, value)}
                            disabled={disabled}
                            secretStored={secretKeys.includes(field.key)}
                            error={errors[`driver_settings.${field.key}`]}
                        />
                    ))}
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
