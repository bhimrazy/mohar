import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

export default function AuthLayout({
    title = '',
    description = '',
    backHref,
    backLabel,
    children,
}: {
    title?: string;
    description?: string;
    backHref?: string;
    backLabel?: string;
    children: React.ReactNode;
}) {
    return (
        <AuthLayoutTemplate
            title={title}
            description={description}
            backHref={backHref}
            backLabel={backLabel}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
