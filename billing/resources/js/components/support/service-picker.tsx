import { Field } from '@/components/app/field';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ServiceOption } from '@/types/support';

type ServicePickerProps = {
    services: ServiceOption[];
    defaultValue?: number | null;
    error?: string;
    label?: string;
    hint?: string;
    className?: string;
};

/**
 * Related-service select fed by the Services module's ClientServiceDirectory.
 * Renders nothing when the client has no services (or no Services module is installed).
 */
export function ServicePicker({
    services,
    defaultValue,
    error,
    label = 'Related service',
    hint,
    className,
}: ServicePickerProps) {
    if (services.length === 0) {
        return null;
    }

    return (
        <Field
            htmlFor="service_id"
            label={label}
            optional
            hint={hint}
            error={error}
            className={className}
        >
            <Select
                name="service_id"
                defaultValue={defaultValue ? String(defaultValue) : undefined}
            >
                <SelectTrigger
                    id="service_id"
                    className="w-full"
                    aria-invalid={!!error}
                >
                    <SelectValue placeholder="No specific service" />
                </SelectTrigger>
                <SelectContent>
                    {services.map((service) => (
                        <SelectItem key={service.id} value={String(service.id)}>
                            {service.label}
                            <span className="ml-2 text-muted-foreground">
                                {service.status}
                            </span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </Field>
    );
}
