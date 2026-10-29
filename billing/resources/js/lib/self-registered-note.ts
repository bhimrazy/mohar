const NOTE =
    'A {name} is registered by you at the registry{free}. Once it is approved, set the nameservers shown on your hosting service.';

/** What a visitor needs to know about an extension the client registers at the registry. */
export function selfRegisteredNote(tld: string | null | undefined): string {
    const name = tld
        ? `${tld} domain name`
        : 'domain name under this extension';
    const free = tld?.endsWith('.np') ? ', free for eligible applicants' : '';

    return NOTE.replace('{name}', name).replace('{free}', free);
}
