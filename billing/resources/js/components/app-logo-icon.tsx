import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

/** The brand mark (square app icon) from Settings → Appearance → Brand. */
export default function AppLogoIcon({ className }: { className?: string }) {
    const { brand } = usePage().props;

    return (
        <img
            src={brand?.mark ?? '/brand/mark.svg'}
            alt=""
            aria-hidden="true"
            className={cn('shrink-0 rounded-md object-contain', className)}
        />
    );
}
