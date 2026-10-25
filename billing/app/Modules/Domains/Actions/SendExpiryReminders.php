<?php

namespace App\Modules\Domains\Actions;

use App\Events\Domains\DomainExpiring;
use App\Events\Domains\DomainExpiryDigest;
use App\Modules\Core\Support\SettingList;
use App\Modules\Core\Support\Settings;
use App\Modules\Domains\Enums\DomainStatus;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\DomainReminder;
use App\Modules\Domains\Services\DomainEvents;
use App\Support\CalendarDate;

/**
 * ICANN-style expiry reminders (spec §9). For each offset (days before expiry; negative = after) the
 * matching domains get one domain_reminders row per expiry cycle (unique key → idempotent re-runs),
 * DomainExpiring per domain and one DomainExpiryDigest per client per run (Notifications sends the email
 * from the digest). A missed automation day is caught up for up to CATCH_UP_DAYS, never past the next
 * offset; domains whose renewal for the cycle is already paid are skipped.
 */
class SendExpiryReminders
{
    public const TEMPLATE = 'domains.expiry_reminder';

    public const CATCH_UP_DAYS = 7;

    public function __construct(private Settings $settings) {}

    /**
     * @return int number of reminders recorded
     */
    public function handle(CalendarDate $today): int
    {
        $offsets = array_slice(array_map('intval', SettingList::of($this->settings->get('domains.reminder_offsets', [30, 7, -3]))), 0, 5);
        rsort($offsets);
        $remindAutoRenewOff = (bool) $this->settings->get('domains.remind_when_auto_renew_off', true);
        $remindFree = (bool) $this->settings->get('domains.remind_free_domains', false);
        $digests = [];
        $sent = 0;

        foreach ($offsets as $index => $offset) {
            $latest = $today->addDays($offset);
            $floor = $offset - self::CATCH_UP_DAYS;

            if (isset($offsets[$index + 1])) {
                $floor = max($floor, $offsets[$index + 1] + 1);
            }

            $earliest = $today->addDays($floor);

            $domains = Domain::query()
                ->with('client:id,email')
                ->withStatus([DomainStatus::Active, DomainStatus::Grace, DomainStatus::Redemption])
                ->whereDate('expiry_date', '<=', $latest->toString())
                ->whereDate('expiry_date', '>=', $earliest->toString())
                ->whereDoesntHave('invoices', fn ($query) => $query->where('status', 'paid')->whereColumn('domain_invoices.period_start', 'domains.next_due_date'))
                ->when(! $remindAutoRenewOff, fn ($query) => $query->where('auto_renew', true))
                ->when(! $remindFree, fn ($query) => $query->where('recurring_amount', '>', 0))
                ->orderBy('id')
                ->get();

            foreach ($domains as $domain) {
                $reminder = DomainReminder::query()->firstOrCreate(
                    ['domain_id' => $domain->id, 'expiry_date' => $domain->expiry_date, 'offset_days' => $offset],
                    ['client_id' => $domain->client_id, 'template_key' => self::TEMPLATE, 'recipients' => [mb_strtolower($domain->client->email)], 'dispatched_at' => now()],
                );

                if (! $reminder->wasRecentlyCreated) {
                    continue;
                }

                $sent++;
                $daysLeft = $today->daysUntil(CalendarDate::parse($domain->expiry_date->toDateString()));
                DomainEvents::dispatch(new DomainExpiring($domain->id, $domain->client_id, $daysLeft));
                $digests[$domain->client_id][] = [
                    'domain_id' => $domain->id,
                    'domain' => $domain->name_unicode,
                    'expiry_date' => (string) $domain->expiry_date?->toDateString(),
                    'days_left' => $daysLeft,
                ];
            }
        }

        foreach ($digests as $clientId => $domains) {
            DomainEvents::dispatch(new DomainExpiryDigest($clientId, $domains, $today->toString()));
        }

        return $sent;
    }
}
