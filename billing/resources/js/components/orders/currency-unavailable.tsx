import { router, usePage } from '@inertiajs/react';
import { Coins } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import store from '@/routes/store';

type Props = {
    title: string;
    /** Other currencies that have what the visitor looked for. */
    soldIn: string[];
};

/**
 * Says why nothing can be bought in the visitor's currency and, for guests, offers the currencies that can.
 * Signed-in clients shop in their account's currency, so they only get the explanation.
 */
export function CurrencyUnavailable({ title, soldIn }: Props) {
    const { can_switch_currency: canSwitch } = usePage<{
        can_switch_currency?: boolean;
    }>().props;

    return (
        <Alert>
            <Coins aria-hidden="true" />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>
                {soldIn.length === 0 ? (
                    <p>It is not available to order right now.</p>
                ) : canSwitch ? (
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <span>Available in:</span>
                        {soldIn.map((code) => (
                            <Button
                                key={code}
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.put(
                                        store.currency.url(),
                                        { currency: code },
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Switch to {code}
                            </Button>
                        ))}
                    </div>
                ) : (
                    <p>
                        It is sold in {soldIn.join(', ')}. Your account is
                        billed in a different currency; contact support to order
                        it.
                    </p>
                )}
            </AlertDescription>
        </Alert>
    );
}
