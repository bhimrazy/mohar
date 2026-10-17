import { Switch } from '@/components/ui/switch';

/** Header toggle that shows or hides the setup-fee inputs of a pricing grid (values are kept). */
export function SetupFeeToggle({
    id,
    checked,
    onCheckedChange,
}: {
    id: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    return (
        <label
            htmlFor={id}
            className="flex items-center gap-2 text-sm text-muted-foreground"
        >
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onCheckedChange}
            />
            Setup fees
        </label>
    );
}
