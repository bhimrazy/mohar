import { createContext, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { DashboardWidget } from './types';

/**
 * One ISO code = that currency only, unconverted (the default view is the default currency). "all" = every
 * currency converted to the default currency at today's rates: an explicit estimate, because transactions do not
 * store the rate in force when they were paid.
 */
export const ALL_CURRENCIES = 'all';

const STORAGE_KEY = 'dashboard.reporting-currency.v2';

const ReportingCurrencyContext = createContext<string>(ALL_CURRENCIES);

function readStored(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        return null;
    }
}

/** Currency codes offered by the switch: every code any widget has a variant for. */
export function variantCodes(widgets: DashboardWidget[]): string[] {
    const codes: string[] = [];

    for (const widget of widgets) {
        for (const code of Object.keys(variantsOf(widget))) {
            if (!codes.includes(code)) {
                codes.push(code);
            }
        }
    }

    return codes;
}

function variantsOf(widget: DashboardWidget): Record<string, unknown> {
    if (widget.type === 'chart' && !widget.variants && widget.currencies) {
        return widget.currencies;
    }

    return 'variants' in widget && widget.variants ? widget.variants : {};
}

/** Merges the selected currency's variant over the widget's base (consolidated) payload. */
export function withVariant<T extends DashboardWidget>(
    widget: T,
    code: string,
): T {
    if (code === ALL_CURRENCIES) {
        return widget;
    }

    if (widget.type === 'chart' && !widget.variants && widget.currencies) {
        const series = widget.currencies[code];

        return (series ? { ...widget, currency: code, series } : widget) as T;
    }

    const variant = (
        'variants' in widget && widget.variants
            ? (widget.variants as Record<string, Partial<T>>)[code]
            : undefined
    ) as Partial<T> | undefined;

    return variant ? { ...widget, ...variant } : widget;
}

export function useReportingCurrency(): string {
    return useContext(ReportingCurrencyContext);
}

export function useVariant<T extends DashboardWidget>(widget: T): T {
    return withVariant(widget, useReportingCurrency());
}

/**
 * Holds the selection (remembered per browser) and renders the header switch through `children`. Widgets list the
 * default currency first, so `codes[0]` is the default view.
 */
export function ReportingCurrencyProvider({
    codes,
    children,
}: {
    codes: string[];
    children: (switcher: ReactNode, selected: string) => ReactNode;
}) {
    const fallback = codes[0] ?? ALL_CURRENCIES;
    const [selected, setSelected] = useState<string | null>(null);

    useEffect(() => {
        setSelected(readStored());
    }, []);

    const active =
        selected === ALL_CURRENCIES || (selected && codes.includes(selected))
            ? selected
            : fallback;

    const choose = (code: string) => {
        if (!code) {
            return;
        }

        setSelected(code);

        try {
            window.localStorage.setItem(STORAGE_KEY, code);
        } catch {
            // Storage can be unavailable (private mode); the choice then lasts for this visit only.
        }
    };

    const switcher =
        codes.length > 1 ? (
            <ToggleGroup
                type="single"
                variant="outline"
                size="sm"
                value={active}
                onValueChange={choose}
                aria-label="Reporting currency"
            >
                {codes.map((code) => (
                    <ToggleGroupItem
                        key={code}
                        value={code}
                        className="px-3 text-xs"
                    >
                        {code}
                    </ToggleGroupItem>
                ))}
                <ToggleGroupItem
                    value={ALL_CURRENCIES}
                    className="px-3 text-xs"
                    aria-label="All currencies, estimated at today's rates"
                    title="All currencies converted at today's exchange rates (an estimate for past periods)"
                >
                    All (est.)
                </ToggleGroupItem>
            </ToggleGroup>
        ) : null;

    return (
        <ReportingCurrencyContext.Provider value={active}>
            {children(switcher, active)}
        </ReportingCurrencyContext.Provider>
    );
}
