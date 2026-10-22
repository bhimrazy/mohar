/**
 * The English name of an ISO 3166-1 alpha-2 country code ("NP" becomes "Nepal"); the input comes back unchanged when
 * the browser has no name for it, and null stays null.
 */
export function countryName(code: string | null | undefined): string | null {
    if (!code) {
        return null;
    }

    try {
        return (
            new Intl.DisplayNames(['en'], { type: 'region' }).of(
                code.toUpperCase(),
            ) ?? code
        );
    } catch {
        return code;
    }
}
