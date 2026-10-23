<?php

namespace Mohar\Simulators\Providers\Whmcs;

use DateTimeImmutable;
use DateTimeZone;
use Mohar\Simulators\Kernel\Scenario;

/**
 * One WHMCS installation's client list and settings. Clients are documents keyed by a zero-padded id, so the state's key
 * order is id order. Instants are stored as UTC epoch seconds and shown in the scenario's source time zone.
 */
final class WhmcsAccount
{
    /** 2024-01-01 00:00:00 UTC: generated clients are created at BASE + id * 5400 s. */
    private const BASE = 1_704_067_200;

    private const FIRST = ['Asha', 'Bikram', 'Chandra', 'Dipa', 'Eshan', 'Fulmaya', 'Gopal', 'Hema', 'Indra', 'Janaki', 'Kiran', 'Laxmi'];

    private const LAST = ['Shrestha', 'Gurung', 'Tamang', 'Rai', 'Thapa', 'Basnet', 'Karki', 'Sherpa', 'Adhikari', 'Maharjan', 'Poudel'];

    public const SETTINGS = ['timezone', 'ip_restricted', 'access_key', 'denied_actions', 'single_item_as_object', 'stringify_numbers'];

    public function __construct(private readonly Scenario $scenario) {}

    public function scenario(): Scenario
    {
        return $this->scenario;
    }

    public function setting(string $name): mixed
    {
        return $this->scenario->state()->get('config', $name, self::defaults()[$name] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'timezone' => WhmcsSimulator::DEFAULT_TIMEZONE, 'ip_restricted' => false, 'access_key' => null, 'denied_actions' => [],
            'single_item_as_object' => false, 'stringify_numbers' => false, 'next_id' => 1, 'schedule' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings  names from SETTINGS
     */
    public function configure(array $settings): void
    {
        $unknown = array_diff(array_keys($settings), self::SETTINGS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown setting: '.implode(', ', $unknown).'.');
        }

        if (isset($settings['timezone'])) {
            try {
                new DateTimeZone((string) $settings['timezone']);
            } catch (\Exception) {
                throw new \InvalidArgumentException('Unknown time zone.');
            }
        }

        foreach ($settings as $name => $value) {
            $this->scenario->state()->put('config', $name, $value);
        }
    }

    /**
     * Append $count generated clients, each merged with $overrides (a stored field name => value).
     *
     * @param  array<string, mixed>  $overrides
     * @return list<int> the new ids
     */
    public function addClients(int $count, array $overrides = []): array
    {
        return $this->scenario->state()->transaction(function () use ($count, $overrides): array {
            $state = $this->scenario->state();
            $id = (int) $state->get('config', 'next_id', 1);
            $ids = [];

            for ($i = 0; $i < $count; $i++, $id++) {
                $state->put('clients', self::key($id), [...self::generate($id), ...$overrides, 'id' => $id]);
                $ids[] = $id;
            }

            $state->put('config', 'next_id', $id);

            return $ids;
        });
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function update(int $id, array $fields): void
    {
        $this->scenario->state()->transaction(function () use ($id, $fields): void {
            $client = $this->find($id) ?? throw new \InvalidArgumentException("No client {$id}.");
            $this->scenario->state()->put('clients', self::key($id), [...$client, ...$fields, 'id' => $id]);
        });
    }

    public function remove(int $id): void
    {
        $this->scenario->state()->delete('clients', self::key($id));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $client = $this->scenario->state()->get('clients', self::key($id));

        return is_array($client) ? $client : null;
    }

    /**
     * @return list<array<string, mixed>> in id order
     */
    public function all(): array
    {
        return array_values($this->scenario->state()->all('clients'));
    }

    /**
     * Queue a change for the moment the Nth `GetClients` call arrives, before it is answered: how a client appears or
     * disappears while the importer is walking the pages.
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<int>  $removeIds
     */
    public function scheduleChange(int $atCall, int $addCount, array $overrides, array $removeIds): void
    {
        $this->scenario->state()->transaction(function () use ($atCall, $addCount, $overrides, $removeIds): void {
            $schedule = (array) $this->setting('schedule');
            $schedule[] = ['at_call' => $atCall, 'add_count' => $addCount, 'overrides' => $overrides, 'remove_ids' => $removeIds];
            $this->scenario->state()->put('config', 'schedule', $schedule);
        });
    }

    /** Run the queued changes whose call number has been reached. */
    public function applySchedule(int $call): void
    {
        $this->scenario->state()->transaction(function () use ($call): void {
            $due = $pending = [];

            foreach ((array) $this->setting('schedule') as $change) {
                if ($change['at_call'] <= $call) {
                    $due[] = $change;
                } else {
                    $pending[] = $change;
                }
            }

            if ($due === []) {
                return;
            }

            $this->scenario->state()->put('config', 'schedule', $pending);

            foreach ($due as $change) {
                array_map($this->remove(...), $change['remove_ids']);
                $this->addClients($change['add_count'], $change['overrides']);
            }
        });
    }

    public function reset(): void
    {
        $this->scenario->state()->transaction(function (): void {
            foreach (['clients', 'config', 'calls'] as $namespace) {
                foreach (array_keys($this->scenario->state()->all($namespace)) as $key) {
                    $this->scenario->state()->delete($namespace, (string) $key);
                }
            }

            $this->scenario->faults()->clear();
            $this->seed();
        });
    }

    public function seed(): void
    {
        $this->addClients(5);
    }

    /** An instant as the source server shows it. */
    public function datetime(?int $epoch): string
    {
        return $epoch === null ? '0000-00-00 00:00:00' : (new DateTimeImmutable('@'.$epoch))->setTimezone($this->zone())->format('Y-m-d H:i:s');
    }

    public function date(int $epoch): string
    {
        return (new DateTimeImmutable('@'.$epoch))->setTimezone($this->zone())->format('Y-m-d');
    }

    private function zone(): DateTimeZone
    {
        return new DateTimeZone((string) $this->setting('timezone'));
    }

    private static function key(int $id): string
    {
        return sprintf('%010d', $id);
    }

    /**
     * @return array<string, mixed>
     */
    private static function generate(int $id): array
    {
        $first = self::FIRST[$id % count(self::FIRST)];
        $last = self::LAST[intdiv($id, count(self::FIRST)) % count(self::LAST)];
        $hash = md5("whmcs-sim-{$id}");
        $created = self::BASE + $id * 5_400;

        return [
            'id' => $id,
            'uuid' => sprintf('%s-%s-4%s-8%s-%s', substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12)),
            'firstname' => $first,
            'lastname' => $last,
            'companyname' => $id % 3 === 0 ? "{$last} Traders {$id}" : '',
            'email' => strtolower("{$first}.{$last}.{$id}@example.test"),
            'phonecc' => 977,
            'phonenumber' => (string) (9_841_000_000 + $id),
            'address1' => "{$id} Durbar Marg",
            'address2' => '',
            'city' => 'Kathmandu',
            'state' => 'Bagmati',
            'postcode' => '44600',
            'countrycode' => 'NP',
            'country' => 'Nepal',
            'tax_id' => $id % 5 === 0 ? sprintf('%09d', 300_000_000 + $id) : '',
            'notes' => '',
            'groupid' => $id % 4 === 0 ? 1 : 0,
            'status' => match (true) {
                $id % 25 === 0 => 'Closed',
                $id % 10 === 0 => 'Inactive',
                default => 'Active',
            },
            'credit' => '0.00',
            'currency' => 1,
            'currency_code' => 'NPR',
            'defaultgateway' => '',
            'language' => 'english',
            'created_at' => $created,
            'lastlogin_at' => $id % 3 === 0 ? null : $created + 86_400,
        ];
    }
}
