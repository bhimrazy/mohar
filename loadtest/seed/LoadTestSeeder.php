<?php

declare(strict_types=1);

namespace LoadTest\Seed;

use App\Enums\BillingCycle;
use App\Modules\Billing\Actions\IssueInvoice;
use App\Modules\Billing\Models\Gateway;
use App\Modules\Billing\Services\DocumentNumbers;
use App\Modules\Billing\Services\SellerDetails;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Catalog\Database\Seeders\CatalogSeeder;
use App\Modules\Catalog\Models\Product;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Install\SystemRoles;
use App\Modules\Core\Models\Client;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Support\Settings;
use App\Modules\Services\Support\ServiceSettings;
use App\Modules\Support\Database\Seeders\SupportSeeder;
use App\Modules\Support\Models\Department;
use App\Modules\Support\Services\TicketNumberGenerator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Bulk load-test data (loadtest/README.md): clients in USD, INR and NPR with one login each, services over the
 * demo catalogue's products and billing cycles, and the last year of their invoices, payments and tickets.
 *
 * It writes multi-row INSERTs instead of going through the Billing actions (a large volume takes minutes, not
 * hours), so it keeps their invariants itself:
 * - invoice numbers come from the same sequence rows and format as DocumentNumbers, in issue-date order, with
 *   no gaps; billing_sequences.next_number ends one increment past the last number used;
 * - money is integer minor units in the client's currency; subtotal = the line amounts, total - amount_paid =
 *   balance, every paid invoice has one payment transaction for its total;
 * - one service period per renewal invoice (ADR 0008) with the same period_guard key IssueInvoice writes, and a
 *   service_invoices link; next_due_date is the paid-through date and next_invoice_date the first period not yet
 *   invoiced, so the next automation run behaves exactly as it would on a live install;
 * - the state is what yesterday's automation run would have left: reminders logged, overdue invoices marked,
 *   services 8+ days overdue suspended. Today's run then has a normal day's work (plus the configured peak).
 *
 * Deterministic for a given seed and date. Never runs in production (run.php refuses before this class loads).
 */
final class LoadTestSeeder
{
    public const EMAIL_DOMAIN = 'loadtest.example.com';

    /** Share of the catalogue per product slug (CatalogSeeder); a missing product is skipped. */
    private const PRODUCT_WEIGHTS = [
        'starter' => 32, 'pro' => 24, 'business' => 14, 'vps-s' => 11, 'vps-m' => 7, 'ssl-dv' => 6, 'classic-2019' => 6,
    ];

    private const CYCLE_WEIGHTS = [
        'monthly' => 50, 'quarterly' => 8, 'semiannually' => 4, 'annually' => 30, 'biennially' => 5, 'triennially' => 3,
    ];

    /** [currency, share %, countries] */
    private const MARKETS = [
        ['USD', 60, ['US', 'US', 'US', 'GB', 'CA', 'AU', 'DE']],
        ['INR', 25, ['IN']],
        ['NPR', 15, ['NP']],
    ];

    private const FIRST_NAMES = ['Aarav', 'Olivia', 'Liam', 'Priya', 'Noah', 'Emma', 'Sita', 'Arjun', 'Mia', 'Ravi', 'Sophia', 'Ethan', 'Anjali',
        'Lucas', 'Isha', 'Mason', 'Ava', 'Bikash', 'Grace', 'Rohan', 'Chloe', 'Kiran', 'Daniel', 'Maya', 'Sanjay', 'Leah', 'Nisha', 'James', 'Zoe', 'Dipesh'];

    private const LAST_NAMES = ['Sharma', 'Smith', 'Shrestha', 'Patel', 'Johnson', 'Gurung', 'Brown', 'Iyer', 'Williams', 'Thapa', 'Garcia', 'Rao',
        'Miller', 'Karki', 'Davis', 'Singh', 'Wilson', 'Adhikari', 'Taylor', 'Nair', 'Clark', 'Rai', 'Lewis', 'Mehta', 'Walker', 'Joshi'];

    private const COMPANY_WORDS = ['Northwind', 'Bluefin', 'Copperleaf', 'Summit', 'Lotus', 'Everest', 'Harbor', 'Juniper', 'Saffron', 'Granite',
        'Maple', 'Kestrel', 'Annapurna', 'Indigo', 'Cedar', 'Orbit', 'Monsoon', 'Pinnacle'];

    private const COMPANY_SUFFIXES = ['Labs', 'Studio', 'Traders', 'Solutions', 'Media', 'Pvt Ltd', 'LLC', 'Digital', 'Works', 'Co'];

    private const TICKET_SUBJECTS = [
        'Website shows a 500 error after the update', 'Email not arriving in my inbox', 'How do I point my domain here?',
        'Invoice paid twice by mistake', 'Need help with an SSL certificate', 'Upgrade from Starter to Pro', 'Backup restore request',
        'Slow page loads since yesterday', 'Cannot log in to the control panel', 'Refund for an unused month', 'DNS records for Google Workspace',
        'VPS will not boot after a kernel update', 'Change the billing address on my invoices', 'Increase the PHP memory limit',
    ];

    private const CLIENT_LINES = [
        "Hi team,\n\nSince this morning the site at my domain is not loading properly. Could you take a look?\n\nThanks",
        "Hello,\n\nI followed the guide but it still does not work. Screenshots are below the error text.\n\nRegards",
        'Thanks, that fixed it. One more question: can this be scheduled every night?',
        'It happened again about an hour ago. Nothing changed on my side.',
    ];

    private const STAFF_LINES = [
        "Hello,\n\nThanks for reaching out. I have checked the account and found the cause; it is fixed now. Please clear your browser cache and try again.",
        "Hi,\n\nI have raised the limit and restarted the service. Let us know if you still see the problem.",
        "Hello,\n\nThis is done. The change can take up to an hour to show everywhere.",
    ];

    private BulkWriter $writer;

    private int $today;

    /** @var array<string, int> next id per table */
    private array $ids = [];

    /** @var array<string, int|float|string> */
    private array $stats = [];

    private string $now;

    /**
     * @param  Closure(string): void  $log
     */
    public function __construct(
        private Volume $volume,
        private string $password,
        private int $seed,
        private int $peakShare,
        private Closure $log,
    ) {
        $this->writer = new BulkWriter(DB::connection());
        $today = CarbonImmutable::today();
        $this->today = Calendar::toDays($today->year, $today->month, $today->day);
        $this->now = CarbonImmutable::now()->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, int|float|string>
     */
    public function run(): array
    {
        $started = hrtime(true);
        $this->guard();
        $this->baseline();
        $catalog = $this->catalog();
        mt_srand($this->seed);

        DB::transaction(function () use ($catalog): void {
            foreach (['users', 'clients', 'client_user', 'services', 'invoices', 'invoice_items', 'service_invoices', 'transactions', 'support_tickets', 'support_ticket_messages'] as $table) {
                $this->ids[$table] = (int) DB::table($table)->max('id') + 1;
            }

            $staff = $this->staff();
            $clients = $this->clients();
            $services = $this->services($clients, $catalog);
            $this->invoices($clients, $services);
            $this->tickets($clients, $services, $staff);
            $this->writer->flush();
        });

        $this->resetSequences();

        if (DB::getDriverName() === 'pgsql') {
            // Planner statistics, as autovacuum would collect them on a live install.
            DB::statement('ANALYZE');
        }

        mt_srand();
        $this->stats['rows'] = array_sum($this->writer->written());
        $this->stats['seconds'] = round((hrtime(true) - $started) / 1e9, 1);

        return [...$this->stats, 'tables' => $this->writer->written()];
    }

    private function guard(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Load-test data never goes into a production install (APP_ENV=production).');
        }

        if (DB::table('users')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->exists()) {
            throw new RuntimeException('This database already has load-test data; start from a fresh database (migrate:fresh).');
        }

        if (app(BillingSettings::class)->bool('sequential_paid_numbering')) {
            throw new RuntimeException('The seeder numbers invoices at issue; turn off sequential paid numbering (proforma mode) first.');
        }
    }

    /**
     * Reference data and the demo catalogue and helpdesk content (products, prices, departments, knowledge base),
     * a load-test staff policy, and the gateways invoices are paid through.
     */
    private function baseline(): void
    {
        app(ReferenceDataInstaller::class)->install();

        if (! Product::query()->exists()) {
            app(CatalogSeeder::class)->setContainer(app())->__invoke();
        }

        if (! Department::query()->exists()) {
            app(SupportSeeder::class)->setContainer(app())->__invoke();
        }

        Gateway::query()->firstOrCreate(['key' => 'bank_transfer'], [
            'display_name' => 'Bank transfer', 'is_active' => true, 'show_on_order_form' => true, 'sort_order' => 1,
            'config' => ['instructions' => "Pay to account 0123456789 at Example Bank.\nReference: {invoice}"],
        ]);
        Gateway::query()->firstOrCreate(['key' => 'manual'], [
            'display_name' => 'Manual payment', 'is_active' => true, 'show_on_order_form' => false, 'sort_order' => 2, 'config' => [],
        ]);

        // Load-test staff sign in with a password only; production keeps two-factor enrolment required.
        app(Settings::class)->set('security.staff_2fa_required', false);
        ($this->log)('baseline: reference data, catalogue, helpdesk, gateways');
    }

    /**
     * @return array{products: list<array{id: int, name: string, slug: string, weight: int, prices: array<string, array<string, int>>}>, leads: array<string, int>}
     */
    private function catalog(): array
    {
        $morph = (new Product)->getMorphClass();
        $products = [];

        foreach (DB::table('products')->whereIn('slug', array_keys(self::PRODUCT_WEIGHTS))->whereNull('deleted_at')->orderBy('id')->get() as $product) {
            $prices = [];

            foreach (DB::table('prices')->where('priceable_type', $morph)->where('priceable_id', $product->id)->where('enabled', true)->get() as $price) {
                if ((int) $price->amount > 0) {
                    $prices[$price->currency][$price->cycle] = (int) $price->amount;
                }
            }

            if ($prices !== []) {
                $products[] = ['id' => (int) $product->id, 'name' => (string) $product->name, 'slug' => (string) $product->slug,
                    'weight' => self::PRODUCT_WEIGHTS[$product->slug], 'prices' => $prices];
            }
        }

        if ($products === []) {
            throw new RuntimeException('No priced products found; the demo catalogue (CatalogSeeder) did not install.');
        }

        $settings = app(ServiceSettings::class);
        $leads = [];

        foreach (BillingCycle::recurring() as $cycle) {
            $leads[$cycle->value] = max(0, $settings->leadDays($cycle));
        }

        return ['products' => $products, 'leads' => $leads];
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function staff(): array
    {
        $roleId = Role::query()->where('key', SystemRoles::FULL_ADMINISTRATOR)->value('id');
        $hash = Hash::make($this->password);
        $staff = [];

        for ($n = 1; $n <= $this->volume->staff; $n++) {
            $id = $this->ids['users']++;
            $name = self::FIRST_NAMES[$n % count(self::FIRST_NAMES)].' (support)';
            $email = "staff{$n}@".self::EMAIL_DOMAIN;
            $this->writer->add('users', [
                'id' => $id, 'name' => $name, 'email' => $email, 'email_verified_at' => $this->now, 'password' => $hash,
                'is_staff' => true, 'role_id' => $roleId, 'is_active' => true, 'must_change_password' => false,
                'password_changed_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now,
            ]);
            $staff[] = ['id' => $id, 'name' => $name, 'email' => $email];
        }

        $this->writer->flush();
        $this->stats['staff'] = count($staff);

        return $staff;
    }

    /**
     * Clients with one owner login each: client{n}@loadtest.example.com, sharing the load-test password.
     *
     * @return list<array{id: int, user: int, currency: string, name: string, email: string, created: int, snapshot: string}>
     */
    private function clients(): array
    {
        $hash = Hash::make($this->password);
        $clients = [];

        for ($n = 1; $n <= $this->volume->clients; $n++) {
            $roll = mt_rand(1, 100);
            [$currency, , $countries] = $roll <= self::MARKETS[0][1] ? self::MARKETS[0]
                : ($roll <= self::MARKETS[0][1] + self::MARKETS[1][1] ? self::MARKETS[1] : self::MARKETS[2]);
            $first = self::FIRST_NAMES[mt_rand(0, count(self::FIRST_NAMES) - 1)];
            $last = self::LAST_NAMES[mt_rand(0, count(self::LAST_NAMES) - 1)];
            $company = mt_rand(1, 100) <= 35
                ? self::COMPANY_WORDS[mt_rand(0, count(self::COMPANY_WORDS) - 1)].' '.self::COMPANY_SUFFIXES[mt_rand(0, count(self::COMPANY_SUFFIXES) - 1)]
                : null;
            $email = "client{$n}@".self::EMAIL_DOMAIN;
            // Growth: more customers signed up recently than three years ago.
            $created = $this->today - 1 - (int) floor(1095 * (1 - sqrt(mt_rand() / mt_getrandmax())));
            $createdAt = Calendar::dateTime($created, mt_rand(28_800, 72_000));
            $attributes = [
                'first_name' => $first, 'last_name' => $last, 'company_name' => $company, 'email' => $email,
                'phone' => '+1 555 01'.str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT), 'address_1' => (10 + $n % 890).' Market Street',
                'address_2' => null, 'city' => match ($currency) {
                    'INR' => 'Bengaluru', 'NPR' => 'Kathmandu', default => 'Springfield'
                },
                'state' => match ($currency) {
                    'INR' => 'Karnataka', 'NPR' => 'Bagmati', default => 'CA'
                }, 'postcode' => (string) (10_000 + $n % 89_999),
                'country' => $countries[mt_rand(0, count($countries) - 1)], 'tax_id' => null,
            ];
            $clientId = $this->ids['clients']++;
            $userId = $this->ids['users']++;

            $this->writer->add('clients', [
                'id' => $clientId, ...$attributes, 'tax_exempt' => false, 'currency' => $currency, 'language' => 'en',
                'status' => 'active', 'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            $this->writer->add('users', [
                'id' => $userId, 'name' => "{$first} {$last}", 'email' => $email, 'email_verified_at' => $createdAt, 'password' => $hash,
                'is_staff' => false, 'is_active' => true, 'must_change_password' => false, 'password_changed_at' => $createdAt,
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            $this->writer->add('client_user', ['id' => $this->ids['client_user']++, 'client_id' => $clientId, 'user_id' => $userId,
                'is_owner' => true, 'created_at' => $createdAt, 'updated_at' => $createdAt]);

            $clients[] = [
                'id' => $clientId, 'user' => $userId, 'currency' => $currency, 'name' => "{$first} {$last}", 'email' => $email, 'created' => $created,
                'snapshot' => (string) json_encode(IssueInvoice::snapshot((new Client)->forceFill($attributes))),
            ];
        }

        $this->writer->flush();
        $this->stats['clients'] = count($clients);
        ($this->log)('clients: '.count($clients).' with one login each');

        return $clients;
    }

    /**
     * Assign services to clients (every client has one; the rest go to a long tail of larger customers), then walk
     * each service's billing periods to work out the invoices of the last year and the state they leave.
     *
     * @param  list<array{id: int, user: int, currency: string, name: string, email: string, created: int, snapshot: string}>  $clients
     * @param  array{products: list<array{id: int, name: string, slug: string, weight: int, prices: array<string, array<string, int>>}>, leads: array<string, int>}  $catalog
     * @return array{byClient: array<int, list<int>>, plans: array<int, list<string>>, rows: array<int, array{id: int, client: int, name: string, domain: string, amount: int, currency: string}>}
     */
    private function services(array $clients, array $catalog): array
    {
        $owners = range(0, count($clients) - 1);

        for ($extra = count($clients); $extra < $this->volume->services; $extra++) {
            // Squared uniform: a few clients own many services, most own one or two.
            $owners[] = (int) floor(count($clients) * (mt_rand() / (mt_getrandmax() + 1)) ** 2);
        }

        // Only products priced in the client's currency (invoices must be in the client currency).
        $offer = [];

        foreach (array_column(self::MARKETS, 0) as $currency) {
            $offer[$currency] = array_values(array_filter($catalog['products'], fn (array $product): bool => isset($product['prices'][$currency])));

            if ($offer[$currency] === []) {
                throw new RuntimeException("No product is priced in {$currency}; check the currencies and the demo catalogue.");
            }
        }

        $cycleTotal = array_sum(self::CYCLE_WEIGHTS);
        // Hosts that bill on one day of the month: this share of recurring services renews on the period start
        // that today's run invoices, so the measured run is a billing-day peak, not an average day.
        $peakAnchorDays = $this->today + max($catalog['leads']['monthly'] ?? 14, 0);
        $peakDay = Calendar::fromDays($peakAnchorDays)[2];
        $byClient = [];
        $plans = [];
        $rows = [];
        $counts = ['active' => 0, 'suspended' => 0, 'terminated' => 0, 'peak' => 0];

        foreach ($owners as $clientIndex) {
            $client = $clients[$clientIndex];
            $product = $this->weighted($offer[$client['currency']]);
            $prices = $product['prices'][$client['currency']];
            $cycle = isset($prices['onetime']) && count($prices) === 1 ? 'onetime' : $this->cycleFor($prices, $cycleTotal);
            $amount = $prices[$cycle];
            $id = $this->ids['services']++;
            $registration = $client['created'] + (int) floor(($this->today - 1 - $client['created']) * (mt_rand() / (mt_getrandmax() + 1)) ** 1.5);
            $anchor = Calendar::fromDays($registration)[2];

            if ($cycle !== 'onetime' && mt_rand(1, 100) <= $this->peakShare) {
                // Move the registration to the peak anchor day of the same month, or of the month before when that
                // day is still ahead; a client newer than both keeps its own anchor day.
                [$year, $month] = Calendar::fromDays($registration);
                $moved = Calendar::toDays($year, $month, min($peakDay, Calendar::daysInMonth($year, $month)));
                $moved = $moved >= $this->today ? Calendar::advanceMonths($moved, -1, $peakDay) : $moved;

                if ($moved >= $client['created'] && $moved < $this->today) {
                    $registration = $moved;
                    $anchor = $peakDay;
                    $counts['peak']++;
                }
            }

            $domain = strtolower(self::COMPANY_WORDS[$id % count(self::COMPANY_WORDS)]).$id.'.example.net';
            [$state, $plan] = $cycle === 'onetime'
                ? $this->oneTimePlan($registration)
                : $this->periodPlan($registration, $anchor, BillingCycle::from($cycle), $catalog['leads'][$cycle] ?? 14);

            foreach ($plan as $issue => $records) {
                foreach ($records as $record) {
                    $plans[$issue][] = pack('V', $id).$record;
                }
            }

            $status = $state['status'];
            $counts[$status]++;
            $registered = Calendar::dateTime($registration, 36_000);
            $this->writer->add('services', [
                'id' => $id, 'client_id' => $client['id'], 'product_id' => $product['id'], 'product_name' => $product['name'], 'status' => $status,
                'cycle' => $cycle, 'currency' => $client['currency'], 'quantity' => 1, 'domain' => $domain,
                'first_payment_amount' => $amount, 'recurring_amount' => $cycle === 'onetime' ? 0 : $amount,
                'registration_date' => Calendar::date($registration), 'billing_anchor_day' => $cycle === 'onetime' ? null : $anchor,
                'next_due_date' => $state['nextDue'] === null ? null : Calendar::date($state['nextDue']),
                'next_invoice_date' => $state['nextInvoice'] === null ? null : Calendar::date($state['nextInvoice']),
                'renewals_invoiced' => $state['renewals'], 'activated_at' => $registered,
                'suspended_at' => $status === 'suspended' ? Calendar::dateTime($state['since'], 300) : null,
                'suspension_reason' => $status === 'suspended' ? 'overdue' : null,
                'termination_date' => $status === 'terminated' ? Calendar::date($state['since']) : null,
                'payment_gateway' => 'bank_transfer', 'created_at' => $registered,
                'updated_at' => $status === 'active' ? $registered : Calendar::dateTime($state['since'], 300),
            ]);

            $byClient[$client['id']][] = $id;
            $rows[$id] = ['id' => $id, 'client' => $clientIndex, 'name' => $product['name'], 'domain' => $domain, 'amount' => $amount, 'currency' => $client['currency']];
        }

        $this->writer->flush();
        ksort($plans);
        $this->stats['services'] = count($owners);
        $this->stats['services_active'] = $counts['active'];
        $this->stats['services_suspended'] = $counts['suspended'];
        $this->stats['services_terminated'] = $counts['terminated'];
        $this->stats['services_peak_anchor'] = $counts['peak'];
        $this->stats['peak_period_start'] = Calendar::date($peakAnchorDays);
        ($this->log)("services: {$this->stats['services']} ({$counts['active']} active, {$counts['suspended']} suspended, {$counts['terminated']} terminated)");

        return ['byClient' => $byClient, 'plans' => $plans, 'rows' => $rows];
    }

    /**
     * Invoice records per issue day for one recurring service, and the service state they leave.
     * Record: pack('V7') of period start, period end, due day, outcome (1 paid, 2 unpaid, 3 cancelled), paid or
     * cancelled day, order flag, renewal number.
     *
     * @return array{0: array{status: string, nextDue: ?int, nextInvoice: ?int, renewals: int, since: int}, 1: array<int, list<string>>}
     */
    private function periodPlan(int $registration, int $anchor, BillingCycle $cycle, int $lead): array
    {
        $yesterday = $this->today - 1;
        $windowStart = $this->today - 365;
        $months = (int) $cycle->months();
        $plan = [];
        $start = $registration;
        $paidThrough = $registration;
        $renewals = 0;
        $state = ['status' => 'active', 'since' => 0];

        for ($period = 0; ; $period++) {
            $end = Calendar::advanceMonths($start, $months, $anchor);
            $issue = $period === 0 ? $registration : $start - $lead;

            if ($issue > $yesterday) {
                break;
            }

            $renewals += $period === 0 ? 0 : 1;
            $outcome = 1;
            $settled = 0;

            if ($period === 0) {
                $settled = $registration;
            } elseif ($start > $yesterday) {
                // Not due yet: about a third are paid early (saved cards, prompt payers).
                if (mt_rand(1, 100) <= 35) {
                    $settled = $issue + mt_rand(0, $yesterday - $issue);
                } else {
                    $outcome = 2;
                }
            } elseif ($yesterday - $start < 60) {
                if (mt_rand(1, 1000) <= 60) {
                    $outcome = 2;
                } else {
                    $settled = min($yesterday, mt_rand(1, 100) <= 85 ? $start - mt_rand(0, min($lead, $start - $issue)) : $start + mt_rand(1, 6));
                }
            } elseif (mt_rand(1, 1000) <= 25) {
                $outcome = 3;
                $settled = min($yesterday, $start + mt_rand(7, 30));
            } else {
                $settled = min($yesterday, mt_rand(1, 100) <= 85 ? $start - mt_rand(0, min($lead, $start - $issue)) : $start + mt_rand(1, 6));
            }

            if ($issue >= $windowStart) {
                $plan[$issue][] = pack('V7', $start, $end, $period === 0 ? $registration : $start, $outcome, $settled, $period === 0 ? 1 : 0, $renewals);
            }

            if ($outcome === 1) {
                $paidThrough = $end;
                $start = $end;

                continue;
            }

            if ($outcome === 3) {
                // Cancelled for non-payment: the service ended; nothing more is invoiced.
                return [['status' => 'terminated', 'nextDue' => $start, 'nextInvoice' => $end, 'renewals' => $renewals, 'since' => $settled], $plan];
            }

            // Unpaid: renewals wait for it (one open renewal at a time); suspended once 8+ days overdue by yesterday.
            $overdue = $this->today - $start;
            $state = $overdue >= 8 ? ['status' => 'suspended', 'since' => $start + 7] : $state;

            return [['status' => $state['status'], 'nextDue' => $start, 'nextInvoice' => $end, 'renewals' => $renewals, 'since' => $state['since']], $plan];
        }

        return [['status' => 'active', 'nextDue' => $paidThrough, 'nextInvoice' => $start, 'renewals' => $renewals, 'since' => 0], $plan];
    }

    /**
     * @return array{0: array{status: string, nextDue: ?int, nextInvoice: ?int, renewals: int, since: int}, 1: array<int, list<string>>}
     */
    private function oneTimePlan(int $registration): array
    {
        $plan = $registration >= $this->today - 365 ? [$registration => [pack('V7', $registration, $registration, $registration, 1, $registration, 1, 0)]] : [];

        return [['status' => 'active', 'nextDue' => null, 'nextInvoice' => null, 'renewals' => 0, 'since' => 0], $plan];
    }

    /**
     * Invoices in issue-date order (so numbers follow dates), each with its line, service link and payment.
     *
     * @param  list<array{id: int, user: int, currency: string, name: string, email: string, created: int, snapshot: string}>  $clients
     * @param  array{byClient: array<int, list<int>>, plans: array<int, list<string>>, rows: array<int, array{id: int, client: int, name: string, domain: string, amount: int, currency: string}>}  $services
     */
    private function invoices(array $clients, array $services): void
    {
        $settings = app(BillingSettings::class);
        $numbers = app(DocumentNumbers::class);
        $reset = $settings->string('number_reset', 'never');
        $increment = max(1, $settings->int('number_increment', 1));
        $format = $settings->string('number_format', '{NUMBER}');
        $format = str_contains($format, '{NUMBER}') ? $format : $format.'{NUMBER}';
        $datedFormat = str_contains($format, '{YEAR}') || str_contains($format, '{MONTH}') || str_contains($format, '{DAY}');
        $beforeDays = max(0, $settings->int('reminders.before_due_days', 7));
        $overdueDays = array_values(array_filter($settings->intList('reminders.overdue_days'), fn (int $days): bool => $days > 0));
        sort($overdueDays);
        $seller = (string) json_encode(app(SellerDetails::class)->snapshot());
        $yesterday = $this->today - 1;
        $next = [];
        $counts = ['paid' => 0, 'unpaid' => 0, 'overdue' => 0, 'cancelled' => 0, 'transactions' => 0];

        foreach ($services['plans'] as $issue => $records) {
            $period = match ($reset) {
                'yearly' => substr(Calendar::date($issue), 0, 4),
                'monthly' => substr(Calendar::date($issue), 0, 7),
                default => '',
            };
            $next[$period] ??= $this->reserveSequence($period);
            $issueDate = $datedFormat ? CarbonImmutable::parse(Calendar::date($issue)) : CarbonImmutable::today();

            foreach ($records as $record) {
                ['s' => $serviceId, 'start' => $start, 'end' => $end, 'due' => $due, 'outcome' => $outcome, 'settled' => $settled, 'order' => $isOrder]
                    = unpack('Vs/Vstart/Vend/Vdue/Voutcome/Vsettled/Vorder/Vrenewal', $record);
                $service = $services['rows'][$serviceId];
                $client = $clients[$service['client']];
                $id = $this->ids['invoices']++;
                $number = $numbers->format($format, $next[$period], $issueDate);
                $next[$period] += $increment;
                $amount = $service['amount'];
                $issuedAt = $isOrder ? Calendar::dateTime($issue, mt_rand(32_400, 64_800)) : Calendar::dateTime($issue, mt_rand(300, 1_800));
                $settledAt = $outcome === 2 ? null : Calendar::dateTime($settled, $settled === $issue && $isOrder ? 66_000 : mt_rand(28_800, 79_200));
                $paid = $outcome === 1;
                $status = ['', 'paid', 'unpaid', 'cancelled'][$outcome];
                $counts[$status]++;
                $counts['overdue'] += $outcome === 2 && $due < $this->today ? 1 : 0;
                [$log, $overdueAt] = $this->reminders($issue, $due, $outcome === 2 ? $yesterday : ($settled - 1), $beforeDays, $overdueDays);
                $description = $service['name'].' - '.$service['domain'].($isOrder && $start === $end ? '' : ' ('.Calendar::date($start).' - '.Calendar::date($end - 1).')');

                $this->writer->add('invoices', [
                    'id' => $id, 'client_id' => $client['id'], 'number' => $number, 'status' => $status, 'kind' => 'standard',
                    'source' => $isOrder ? 'order' : 'system', 'currency' => $service['currency'], 'issue_date' => Calendar::date($issue),
                    'due_date' => Calendar::date($due), 'paid_at' => $paid ? $settledAt : null, 'cancelled_at' => $outcome === 3 ? $settledAt : null,
                    'subtotal' => $amount, 'total' => $amount, 'amount_paid' => $paid ? $amount : 0, 'balance' => $outcome === 2 ? $amount : 0,
                    'payment_gateway' => 'bank_transfer', 'client_snapshot' => $client['snapshot'], 'seller_snapshot' => $seller,
                    'reminder_log' => $log === [] ? null : (string) json_encode($log),
                    'overdue_notified_at' => $outcome === 2 ? $overdueAt : null, 'version' => 1,
                    'created_at' => $issuedAt, 'updated_at' => $settledAt ?? $issuedAt,
                ]);
                $this->writer->add('invoice_items', [
                    'id' => $this->ids['invoice_items']++, 'invoice_id' => $id, 'type' => 'service', 'billable_id' => $serviceId,
                    'description' => mb_substr($description, 0, 255), 'quantity' => 1, 'unit_amount' => $amount, 'amount' => $amount,
                    'taxable' => true, 'tax_amount' => 0, 'period_start' => Calendar::date($start), 'period_end' => Calendar::date($end), 'sort_order' => 0,
                    // Cancelled invoices release their guard (InvoiceSettlement::releaseGuards), so the period could be invoiced again.
                    'period_guard' => $outcome === 3 ? null : 'service:'.$serviceId.':'.Calendar::date($start),
                    'created_at' => $issuedAt, 'updated_at' => $issuedAt,
                ]);
                $this->writer->add('service_invoices', [
                    'id' => $this->ids['service_invoices']++, 'service_id' => $serviceId, 'invoice_id' => $id, 'kind' => $isOrder ? 'order' : 'renewal',
                    'period_start' => Calendar::date($start), 'period_end' => Calendar::date($end), 'amount' => $amount, 'discount' => 0,
                    'status' => ['', 'paid', 'open', 'cancelled'][$outcome], 'created_at' => $issuedAt, 'updated_at' => $settledAt ?? $issuedAt,
                ]);

                if ($paid) {
                    $this->writer->add('transactions', [
                        'id' => $this->ids['transactions']++, 'client_id' => $client['id'], 'invoice_id' => $id, 'gateway' => 'bank_transfer',
                        'gateway_txn_id' => 'LT-'.$id, 'type' => 'payment', 'status' => 'succeeded', 'amount_in' => $amount, 'amount_out' => 0, 'fee' => 0,
                        'currency' => $service['currency'], 'refundable_remaining' => $amount, 'description' => 'Invoice payment',
                        'occurred_at' => $settledAt, 'created_at' => $settledAt, 'updated_at' => $settledAt,
                    ]);
                    $counts['transactions']++;
                }
            }
        }

        foreach ($next as $period => $nextNumber) {
            DB::table('billing_sequences')->where('sequence', DocumentNumbers::INVOICE)->where('period', $period)
                ->update(['next_number' => $nextNumber, 'updated_at' => $this->now]);
        }

        $this->writer->flush();
        $this->stats['invoices'] = $counts['paid'] + $counts['unpaid'] + $counts['cancelled'];
        $this->stats['invoices_paid'] = $counts['paid'];
        $this->stats['invoices_unpaid'] = $counts['unpaid'];
        $this->stats['invoices_overdue'] = $counts['overdue'];
        $this->stats['invoices_cancelled'] = $counts['cancelled'];
        $this->stats['transactions'] = $counts['transactions'];
        ($this->log)("invoices: {$this->stats['invoices']} ({$counts['paid']} paid, {$counts['unpaid']} unpaid of which {$counts['overdue']} overdue, {$counts['cancelled']} cancelled)");
    }

    /**
     * The sequence row for a reset period, locked for this transaction like DocumentNumbers::next(); returns the
     * next number to use.
     */
    private function reserveSequence(string $period): int
    {
        DB::table('billing_sequences')->insertOrIgnore([
            'sequence' => DocumentNumbers::INVOICE, 'period' => $period, 'next_number' => 1, 'created_at' => $this->now, 'updated_at' => $this->now,
        ]);

        return (int) DB::table('billing_sequences')->where('sequence', DocumentNumbers::INVOICE)->where('period', $period)->lockForUpdate()->value('next_number');
    }

    /**
     * Reminder log and overdue marker as the daily runs up to $lastRun (BillingAutomation::reminders) left them.
     *
     * @param  list<int>  $overdueDays
     * @return array{0: array<string, string>, 1: ?string}
     */
    private function reminders(int $issue, int $due, int $lastRun, int $beforeDays, array $overdueDays): array
    {
        $log = [];

        // BillingAutomation::reminderStage(): before_due once the window opens, if issued before it; then one
        // overdue_<n> per threshold reached, each on the day it was reached.
        if ($beforeDays > 0 && $issue <= $due - $beforeDays && $lastRun >= $due - $beforeDays) {
            $log['before_due'] = Calendar::date($due - $beforeDays);
        }

        foreach ($overdueDays as $index => $threshold) {
            if ($lastRun - $due >= $threshold) {
                $log['overdue_'.($index + 1)] = Calendar::date($due + $threshold);
            }
        }

        return [$log, $lastRun > $due ? Calendar::dateTime($due + 1, 300) : null];
    }

    /**
     * About one ticket for every two clients over the year, with a short client and staff conversation; older
     * tickets are mostly closed.
     *
     * @param  list<array{id: int, user: int, currency: string, name: string, email: string, created: int, snapshot: string}>  $clients
     * @param  array{byClient: array<int, list<int>>, plans: array<int, list<string>>, rows: array<int, array{id: int, client: int, name: string, domain: string, amount: int, currency: string}>}  $services
     * @param  list<array{id: int, name: string, email: string}>  $staff
     */
    private function tickets(array $clients, array $services, array $staff): void
    {
        $departments = DB::table('support_departments')->where('is_hidden', false)->orderBy('sort_order')->pluck('id')->all();
        $generator = app(TicketNumberGenerator::class);
        $used = array_flip(DB::table('support_tickets')->pluck('number')->all());
        $tickets = 0;
        $messages = 0;

        foreach ($clients as $client) {
            $roll = mt_rand(1, 100);
            $count = $roll <= 55 ? 0 : ($roll <= 88 ? 1 : mt_rand(2, 4));

            for ($t = 0; $t < $count; $t++) {
                $id = $this->ids['support_tickets']++;
                $opened = max($client['created'], $this->today - mt_rand(0, 364));
                $openedAt = $opened * 86400 + mt_rand(25_200, 79_200);
                $age = $this->today - $opened;
                $status = $age > 14 ? (mt_rand(1, 100) <= 95 ? 'closed' : 'on_hold') : ['open', 'answered', 'customer_reply', 'in_progress', 'closed'][mt_rand(0, 4)];
                $number = $generator->render(CarbonImmutable::createFromTimestampUTC($openedAt), $id);
                $number = isset($used[$number]) ? mb_substr($number, 0, 20 - strlen('-'.$id)).'-'.$id : $number;
                $used[$number] = true;
                // Client and staff alternate: an open ticket has the client's first message, one awaiting staff ends
                // with the client, every other status ends with staff.
                $replies = match ($status) {
                    'open' => 1, 'customer_reply' => 3, default => [2, 4][mt_rand(0, 1)]
                };
                $serviceIds = $services['byClient'][$client['id']] ?? [];
                $at = $openedAt;
                $times = ['client' => null, 'staff' => null, 'first_staff' => null];
                $agent = $staff[mt_rand(0, count($staff) - 1)];

                $rowsForMessages = [];

                for ($m = 0; $m < $replies; $m++) {
                    $fromClient = $m % 2 === 0;
                    $at += $m === 0 ? 0 : mt_rand(600, 30_000);
                    $stamp = gmdate('Y-m-d H:i:s', min($at, time() - 60));
                    $body = $fromClient ? self::CLIENT_LINES[mt_rand(0, count(self::CLIENT_LINES) - 1)] : self::STAFF_LINES[mt_rand(0, count(self::STAFF_LINES) - 1)];
                    $times[$fromClient ? 'client' : 'staff'] = $stamp;
                    $times['first_staff'] ??= $fromClient ? null : $stamp;
                    $rowsForMessages[] = [
                        'id' => $this->ids['support_ticket_messages']++, 'ticket_id' => $id, 'type' => 'reply', 'author_type' => $fromClient ? 'client' : 'staff',
                        'user_id' => $fromClient ? $client['user'] : $agent['id'], 'author_name' => $fromClient ? $client['name'] : $agent['name'],
                        'author_email' => $fromClient ? $client['email'] : $agent['email'], 'body' => $body,
                        'body_html' => '<p>'.str_replace("\n", '<br>', e($body)).'</p>', 'source' => 'web',
                        'created_at' => $stamp, 'updated_at' => $stamp,
                    ];
                }

                $last = $rowsForMessages[count($rowsForMessages) - 1]['created_at'];

                // The hourly auto-close (support.auto_close_hours, 72 by default) has already closed answered tickets
                // that went quiet, as it would have on a live install.
                if ($status === 'answered' && strtotime($last.' UTC') < time() - 72 * 3600) {
                    $status = 'closed';
                }
                $this->writer->add('support_tickets', [
                    'id' => $id, 'number' => $number, 'access_key' => Str::random(32), 'department_id' => $departments[mt_rand(0, count($departments) - 1)],
                    'client_id' => $client['id'], 'user_id' => $client['user'], 'requester_name' => $client['name'], 'requester_email' => $client['email'],
                    'subject' => self::TICKET_SUBJECTS[mt_rand(0, count(self::TICKET_SUBJECTS) - 1)], 'status' => $status,
                    'priority' => ['low', 'medium', 'medium', 'medium', 'high', 'urgent'][mt_rand(0, 5)], 'source' => 'web',
                    'service_id' => $serviceIds !== [] && mt_rand(0, 1) === 1 ? $serviceIds[mt_rand(0, count($serviceIds) - 1)] : null,
                    'assigned_to' => $status === 'open' ? null : $agent['id'], 'cc' => '[]', 'tags' => '[]', 'version' => 1,
                    'first_staff_reply_at' => $times['first_staff'], 'last_reply_at' => $last, 'last_client_reply_at' => $times['client'],
                    'last_staff_reply_at' => $times['staff'], 'closed_at' => $status === 'closed' ? $last : null, 'closed_by' => $status === 'closed' ? 'staff' : null,
                    'created_at' => gmdate('Y-m-d H:i:s', $openedAt), 'updated_at' => $last,
                ]);

                foreach ($rowsForMessages as $row) {
                    $this->writer->add('support_ticket_messages', $row);
                }

                $tickets++;
                $messages += count($rowsForMessages);
            }
        }

        $this->writer->flush();
        $this->stats['tickets'] = $tickets;
        $this->stats['ticket_messages'] = $messages;
        ($this->log)("tickets: {$tickets} with {$messages} messages");
    }

    /**
     * Explicit ids were inserted; move PostgreSQL's sequences past them (SQLite's AUTOINCREMENT follows by itself).
     */
    private function resetSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->ids) as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM {$table}), 1))");
        }
    }

    /**
     * @template T of array{weight: int}
     *
     * @param  list<T>  $items
     * @return T
     */
    private function weighted(array $items): array
    {
        $roll = mt_rand(1, array_sum(array_column($items, 'weight')));

        foreach ($items as $item) {
            $roll -= $item['weight'];

            if ($roll <= 0) {
                return $item;
            }
        }

        return $items[count($items) - 1];
    }

    /**
     * @param  array<string, int>  $prices
     */
    private function cycleFor(array $prices, int $total): string
    {
        $available = array_intersect_key(self::CYCLE_WEIGHTS, $prices);

        if ($available === []) {
            return (string) array_key_first($prices);
        }

        $roll = mt_rand(1, array_sum($available));

        foreach ($available as $cycle => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return $cycle;
            }
        }

        return (string) array_key_last($available);
    }
}
