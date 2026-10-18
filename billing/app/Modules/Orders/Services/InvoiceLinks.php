<?php

namespace App\Modules\Orders\Services;

use Illuminate\Support\Facades\Route;

/**
 * Links to invoices owned by the Billing module (null until its routes exist).
 */
class InvoiceLinks
{
    public function admin(?int $invoiceId): ?string
    {
        return $this->link('admin.billing.invoices.show', $invoiceId);
    }

    public function client(?int $invoiceId): ?string
    {
        return $this->link('client.invoices.show', $invoiceId);
    }

    private function link(string $route, ?int $invoiceId): ?string
    {
        return $invoiceId !== null && Route::has($route) ? route($route, $invoiceId) : null;
    }
}
