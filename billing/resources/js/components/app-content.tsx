import * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';

export function AppContent({
    children,
    ...props
}: React.ComponentProps<'main'>) {
    return <SidebarInset {...props}>{children}</SidebarInset>;
}
