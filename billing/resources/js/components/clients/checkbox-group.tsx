import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';

type CheckboxGroupProps = {
    idPrefix: string;
    options: { value: string; label: string }[];
    value: string[];
    onChange: (value: string[]) => void;
    disabled?: boolean;
};

export function CheckboxGroup({
    idPrefix,
    options,
    value,
    onChange,
    disabled,
}: CheckboxGroupProps) {
    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
            {options.map((option) => {
                const id = `${idPrefix}-${option.value}`;

                return (
                    <div key={option.value} className="flex items-center gap-2">
                        <Checkbox
                            id={id}
                            checked={value.includes(option.value)}
                            disabled={disabled}
                            onCheckedChange={(checked) =>
                                onChange(
                                    checked
                                        ? [...value, option.value]
                                        : value.filter(
                                              (item) => item !== option.value,
                                          ),
                                )
                            }
                        />
                        <Label htmlFor={id} className="font-normal">
                            {option.label}
                        </Label>
                    </div>
                );
            })}
        </div>
    );
}
