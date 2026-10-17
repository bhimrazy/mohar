/**
 * Visual directions (design themes). Tokens live in resources/css/themes/<id>.css and
 * are activated with <html data-theme="<id>">. `default` is the original kit look.
 * Light/dark appearance is independent (see use-appearance).
 */
export const THEMES = [
    {
        id: 'default',
        label: 'Current',
        description: 'The original starter-kit neutral look.',
    },
    {
        id: 'calm',
        label: 'Calm Fintech',
        description: 'Airy, soft indigo, Inter. Stripe-grade calm for finance.',
    },
    {
        id: 'operator',
        label: 'Operator Pro',
        description:
            'Dense, dark-first, IBM Plex. Built for keyboard operators.',
    },
    {
        id: 'warm',
        label: 'Hosting Warm',
        description:
            'Friendly, rounded, terracotta. Approachable for SMB hosts.',
    },
    {
        id: 'slate',
        label: 'Enterprise Slate',
        description: 'Monochrome, sharp, Geist. Quiet enterprise precision.',
    },
] as const;

export type ThemeId = (typeof THEMES)[number]['id'];

export function isThemeId(value: unknown): value is ThemeId {
    return THEMES.some((theme) => theme.id === value);
}
