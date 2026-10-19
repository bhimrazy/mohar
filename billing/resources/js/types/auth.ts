export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** False for a login created through a sign-in provider: step-up offers "Set a password" (ADR 0018 §4). */
    has_password: boolean | null;
    /** Whether visitors may create a client account themselves (security.client_signup_enabled). */
    signup_open: boolean;
};

/** A sign-in method a module offers on the login and sign-up pages (ADR 0018 §1). */
export type SignInMethodOption = {
    key: string;
    label: string;
    start_url: string;
    staff: boolean;
    logo: { url: string; dark_url: string | null; alt: string } | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
