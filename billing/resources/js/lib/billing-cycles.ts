const LABELS: Record<string, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    semiannually: 'Every 6 months',
    annually: 'Annually',
    biennially: 'Every 2 years',
    triennially: 'Every 3 years',
    onetime: 'One-time',
    free: 'Free',
};

const SUFFIXES: Record<string, string> = {
    monthly: '/mo',
    quarterly: '/qtr',
    semiannually: '/6 mo',
    annually: '/yr',
    biennially: '/2 yrs',
    triennially: '/3 yrs',
};

const MONTHS: Record<string, number> = {
    monthly: 1,
    quarterly: 3,
    semiannually: 6,
    annually: 12,
    biennially: 24,
    triennially: 36,
};

/** "annually" → "Annually"; domain terms like "2_years" → "Every 2 years". */
export function cycleLabel(cycle: string | null | undefined): string {
    if (!cycle) {
        return '—';
    }

    const years = /^(\d+)_years$/.exec(cycle);

    if (years) {
        return `Every ${years[1]} years`;
    }

    return LABELS[cycle] ?? cycle;
}

/** "annually" → "/yr" (empty for one-time/free). */
export function cycleSuffix(cycle: string | null | undefined): string {
    return (cycle && SUFFIXES[cycle]) || '';
}

export function cycleMonths(cycle: string): number | null {
    return MONTHS[cycle] ?? null;
}

/** Cheapest monthly-equivalent row, used for "from $x/mo" teasers. */
export function lowestMonthly<T extends { cycle: string; recurring: number }>(
    rows: T[],
): { row: T; perMonth: number } | null {
    let best: { row: T; perMonth: number } | null = null;

    for (const row of rows) {
        const months = cycleMonths(row.cycle);

        if (!months) {
            continue;
        }

        const perMonth = Math.round(row.recurring / months);

        if (!best || perMonth < best.perMonth) {
            best = { row, perMonth };
        }
    }

    return best;
}
