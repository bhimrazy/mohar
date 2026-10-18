export type FieldGroups = Record<string, string[]>;

export type EmailSummary = {
    id: number;
    uuid: string;
    template_key: string | null;
    template_name: string;
    to: string[];
    recipient: string;
    subject: string;
    status: string;
    attempts: number;
    client: { id: number; name: string } | null;
    created_at: string | null;
    sent_at: string | null;
};

export type WebhookEndpointSummary = {
    id: number;
    url: string;
    description: string | null;
    events: string[];
    is_active: boolean;
    consecutive_failures: number;
    disabled_reason: string | null;
    last_success_at: string | null;
    rotating_until: string | null;
    created_at: string | null;
};
