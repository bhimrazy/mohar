/** Props shared by the Support module pages (see TicketPresenter.php). */

export type TicketAttachment = {
    id: number;
    name: string;
    size_bytes: number;
    mime: string;
    is_image: boolean;
    pruned: boolean;
    url: string;
};

export type TicketMessage = {
    id: number;
    type: 'reply' | 'note';
    author_type: 'client' | 'guest' | 'staff' | 'system';
    author_name: string;
    body_html: string;
    created_at: string | null;
    edited_at: string | null;
    is_autoresponse: boolean;
    rating: number | null;
    can_rate: boolean;
    attachments: TicketAttachment[];
    /** Admin only. */
    author_email?: string | null;
    body?: string;
    source?: string;
    is_unverified_sender?: boolean;
};

export type TicketListItem = {
    id?: number;
    number: string;
    subject: string;
    status: string;
    priority: string;
    department: string | null;
    last_reply_at: string | null;
    created_at: string | null;
    requester_name?: string;
    requester_email?: string;
    client_id?: number | null;
    client?: string | null;
    assignee?: string | null;
    is_pinned?: boolean;
    is_unverified_requester?: boolean;
    tags?: string[];
    source?: string;
};

export type TicketDetail = TicketListItem & {
    department: string;
    requester_name: string;
    service_id: number | null;
    closed_at: string | null;
    can_close: boolean;
    department_id?: number;
    assigned_to?: number | null;
    cc?: string[];
    prevent_client_close?: boolean;
    version?: number;
    first_staff_reply_at?: string | null;
    closed_by?: string | null;
    is_guest?: boolean;
    guest_url?: string;
};

export type UploadLimits = {
    extensions: string[];
    max_mb: number;
    max_files: number;
};

export type DepartmentOption = {
    value: string;
    label: string;
    description?: string | null;
};

/** From the Services module's ClientServiceDirectory (empty when not installed). */
export type ServiceOption = {
    id: number;
    label: string;
    status: string;
};
