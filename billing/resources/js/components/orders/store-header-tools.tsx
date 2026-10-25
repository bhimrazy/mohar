import { Link, usePage } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import store from '@/routes/store';

type StoreProps = {
    cart_count?: number;
    currency?: string;
    cart?: { currency?: string };
};

/**
 * Cart and currency in the client-area header on store pages (issue #37). A signed-in
 * customer shops in the account's currency, so it is shown, not switched.
 */
export function StoreHeaderTools() {
    const { props } = usePage();
    const shop = props as unknown as StoreProps;
    const count = shop.cart_count;
    const currency = shop.currency ?? shop.cart?.currency;
    const { currencyFormats } = props;

    if (count === undefined) {
        return null;
    }

    const format = currencyFormats.find(
        (candidate) => candidate.code === currency,
    );
    const symbol = (format?.prefix || format?.suffix || '').trim();

    return (
        <>
            {currency && (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <span
                            tabIndex={0}
                            className="hidden h-8 items-center rounded-md border px-2.5 text-xs font-medium text-muted-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:inline-flex"
                        >
                            <span className="sr-only">Prices in </span>
                            {currency}
                            {symbol && symbol !== currency && (
                                <span aria-hidden="true">&nbsp;· {symbol}</span>
                            )}
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>
                        Prices are in your account currency.
                    </TooltipContent>
                </Tooltip>
            )}
            <Button asChild variant="outline" size="sm" className="h-8">
                <Link
                    href={store.cart.url()}
                    aria-label={`Cart, ${count} ${count === 1 ? 'item' : 'items'}`}
                >
                    <ShoppingCart aria-hidden="true" />
                    <span className="hidden sm:inline">Cart</span>
                    {count > 0 && (
                        <Badge className="h-5 min-w-5 px-1.5 tabular-nums">
                            {count}
                        </Badge>
                    )}
                </Link>
            </Button>
        </>
    );
}
