import { usePage } from '@inertiajs/react';
import { AlertCircle } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/** Registrar / state errors returned as `registrar` or `domain` error keys. */
export function ActionErrors() {
    const { errors } = usePage().props as {
        errors?: Record<string, string | undefined>;
    };
    const message = errors?.registrar ?? errors?.domain;

    if (!message) {
        return null;
    }

    return (
        <Alert variant="destructive">
            <AlertCircle />
            <AlertTitle>That didn't work</AlertTitle>
            <AlertDescription>{message}</AlertDescription>
        </Alert>
    );
}
