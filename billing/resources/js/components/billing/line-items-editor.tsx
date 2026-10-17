import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type EditableLine = {
    description: string;
    quantity: string;
    unit_amount: string;
    taxable: boolean;
    discount_percent?: string;
};

export const blankLine = (withDiscount = false): EditableLine => ({
    description: '',
    quantity: '1',
    unit_amount: '',
    taxable: true,
    ...(withDiscount ? { discount_percent: '0' } : {}),
});

type LineItemsEditorProps = {
    lines: EditableLine[];
    onChange: (lines: EditableLine[]) => void;
    errors: Record<string, string | undefined>;
    currency?: string;
    withDiscount?: boolean;
};

/**
 * Editable lines for drafts and quotes. Amounts are decimals ("12.50"); the server converts and computes
 * totals and tax on save.
 */
export function LineItemsEditor({
    lines,
    onChange,
    errors,
    currency,
    withDiscount = false,
}: LineItemsEditorProps) {
    const update = (index: number, changes: Partial<EditableLine>) =>
        onChange(
            lines.map((line, position) =>
                position === index ? { ...line, ...changes } : line,
            ),
        );

    return (
        <div className="grid gap-4">
            <InputError message={errors.items} />
            {lines.map((line, index) => {
                const error = (field: string) =>
                    errors[`items.${index}.${field}`];

                return (
                    <div
                        key={index}
                        className="grid gap-3 rounded-lg border p-3 sm:grid-cols-12 sm:items-start"
                    >
                        <div className="grid gap-1.5 sm:col-span-5">
                            <Label
                                htmlFor={`line-${index}-description`}
                                className="text-xs text-muted-foreground"
                            >
                                Description
                            </Label>
                            <Input
                                id={`line-${index}-description`}
                                value={line.description}
                                onChange={(event) =>
                                    update(index, {
                                        description: event.target.value,
                                    })
                                }
                                aria-invalid={!!error('description')}
                            />
                            <InputError message={error('description')} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label
                                htmlFor={`line-${index}-quantity`}
                                className="text-xs text-muted-foreground"
                            >
                                Qty
                            </Label>
                            <Input
                                id={`line-${index}-quantity`}
                                inputMode="numeric"
                                value={line.quantity}
                                onChange={(event) =>
                                    update(index, {
                                        quantity: event.target.value,
                                    })
                                }
                                aria-invalid={!!error('quantity')}
                            />
                            <InputError message={error('quantity')} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label
                                htmlFor={`line-${index}-unit`}
                                className="text-xs text-muted-foreground"
                            >
                                Unit price{currency ? ` (${currency})` : ''}
                            </Label>
                            <Input
                                id={`line-${index}-unit`}
                                inputMode="decimal"
                                placeholder="0.00"
                                value={line.unit_amount}
                                onChange={(event) =>
                                    update(index, {
                                        unit_amount: event.target.value,
                                    })
                                }
                                aria-invalid={!!error('unit_amount')}
                            />
                            <InputError message={error('unit_amount')} />
                        </div>
                        {withDiscount && (
                            <div className="grid gap-1.5 sm:col-span-1">
                                <Label
                                    htmlFor={`line-${index}-discount`}
                                    className="text-xs text-muted-foreground"
                                >
                                    Disc. %
                                </Label>
                                <Input
                                    id={`line-${index}-discount`}
                                    inputMode="decimal"
                                    value={line.discount_percent ?? '0'}
                                    onChange={(event) =>
                                        update(index, {
                                            discount_percent:
                                                event.target.value,
                                        })
                                    }
                                    aria-invalid={!!error('discount_percent')}
                                />
                                <InputError
                                    message={error('discount_percent')}
                                />
                            </div>
                        )}
                        <div
                            className={`flex items-center justify-between gap-2 sm:pt-6 ${withDiscount ? 'sm:col-span-2' : 'sm:col-span-3'}`}
                        >
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`line-${index}-taxable`}
                                    checked={line.taxable}
                                    onCheckedChange={(checked) =>
                                        update(index, {
                                            taxable: checked === true,
                                        })
                                    }
                                />
                                <Label
                                    htmlFor={`line-${index}-taxable`}
                                    className="font-normal"
                                >
                                    Taxable
                                </Label>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={`Remove line ${index + 1}`}
                                disabled={lines.length === 1}
                                onClick={() =>
                                    onChange(
                                        lines.filter(
                                            (_, position) => position !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>
                );
            })}
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="w-fit"
                onClick={() => onChange([...lines, blankLine(withDiscount)])}
            >
                <Plus />
                Add line
            </Button>
        </div>
    );
}
