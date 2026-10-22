/** Badge tones for verification and document statuses, shared by the client and staff pages. */
export const KYC_TONE = {
    not_started: 'neutral',
    in_progress: 'info',
    pending_review: 'warning',
    declined: 'danger',
    verified: 'success',
    pending: 'warning',
    approved: 'success',
} as const;
