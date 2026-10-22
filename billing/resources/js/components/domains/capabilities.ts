/** Readable names for registrar driver capabilities (RegistrarDriver::capabilities()). */
const CAPABILITY_LABELS: Record<string, string> = {
    availability: 'Availability',
    suggestions: 'Suggestions',
    register: 'Register',
    transfer: 'Transfer',
    renew: 'Renew',
    restore: 'Restore',
    info: 'Domain info',
    nameservers: 'Nameservers',
    lock: 'Transfer lock',
    epp: 'EPP code',
    contacts: 'Contacts',
    id_protection: 'ID protection',
    sync: 'Sync',
    transfer_sync: 'Transfer sync',
    reconcile: 'Reconcile lost submissions',
    test_connection: 'Connection test',
};

export function capabilityLabel(capability: string): string {
    const label =
        CAPABILITY_LABELS[capability] ?? capability.replace(/[_-]+/g, ' ');

    return label.charAt(0).toUpperCase() + label.slice(1);
}
