<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\Namecheap\NamecheapSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The namecheap simulator over its TLS port, as the XML API reference describes it: the global parameters and the
 * allow-list, errors inside HTTP 200, availability, the registration, transfer, renewal and reactivation lifecycle
 * with its async states, and the scenarios the driver must handle (premium, insufficient funds, an unknown outcome
 * after commit, a timeout after commit).
 */
beforeEach(function () {
    $this->scenario = Processes::start(NamecheapSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * One API call (POST, a form body), with the global parameters added; returns the parsed XML (namespace removed).
 *
 * @param  array<string, string|int>  $params
 */
function ncCall(string $command, array $params = [], array $globals = []): SimpleXMLElement
{
    $response = ncRaw($command, $params, $globals);

    return simplexml_load_string(str_replace(' xmlns="http://api.namecheap.com/xml.response"', '', $response->body()));
}

/**
 * @param  array<string, string|int>  $params
 * @param  array<string, string|null>  $globals  overrides for the global parameters; null leaves one out
 */
function ncRaw(string $command, array $params = [], array $globals = []): Response
{
    $all = array_filter([
        'ApiUser' => NamecheapSimulator::USER_NAME, 'ApiKey' => NamecheapSimulator::API_KEY, 'UserName' => NamecheapSimulator::USER_NAME,
        'ClientIp' => NamecheapSimulator::CLIENT_IP, 'Command' => $command, ...$globals, ...$params,
    ], fn ($value) => $value !== null);

    return Http::withOptions(['verify' => test()->scenario->ca()->bundlePath()])->timeout(5)->asForm()->post(test()->scenario->apiUrl().'/xml.response', $all);
}

/**
 * @return array<string, string>
 */
function ncContacts(string $email = 'asha@example.test', string $phone = '+977.9841234567'): array
{
    $fields = [];

    foreach (['Registrant', 'Tech', 'Admin', 'AuxBilling'] as $role) {
        foreach (['FirstName' => 'Asha', 'LastName' => 'Shrestha', 'Address1' => '1 Durbar Marg', 'City' => 'Kathmandu', 'StateProvince' => 'Bagmati', 'PostalCode' => '44600', 'Country' => 'NP', 'Phone' => $phone, 'EmailAddress' => $email] as $field => $value) {
            $fields[$role.$field] = $value;
        }
    }

    return $fields;
}

function ncRegister(string $name, array $extra = []): SimpleXMLElement
{
    return ncCall('namecheap.domains.create', ['DomainName' => $name, 'Years' => 1, 'Nameservers' => 'ns1.host.example.test,ns2.host.example.test', ...ncContacts(), ...$extra]);
}

function ncError(SimpleXMLElement $xml): ?int
{
    return (string) $xml['Status'] === 'ERROR' ? (int) $xml->Errors->Error['Number'] : null;
}

test('every call needs the global parameters, the key and the whitelisted address; errors come inside HTTP 200', function () {
    $wrongKey = ncRaw('namecheap.domains.check', ['DomainList' => 'asha.com'], ['ApiKey' => 'wrong']);
    $balance = $this->scenario->simulator();

    expect($wrongKey->status())->toBe(200)
        ->and(ncError(ncCall('namecheap.domains.check', ['DomainList' => 'asha.com'], ['ApiKey' => 'wrong'])))->toBe(1011102)
        ->and(ncError(ncCall('namecheap.domains.check', ['DomainList' => 'asha.com'], ['ClientIp' => null])))->toBe(1010105)
        ->and(ncError(ncCall('namecheap.domains.check', ['DomainList' => 'asha.com'], ['ClientIp' => '198.51.100.7'])))->toBe(1011150)
        ->and(ncError(ncCall('namecheap.domains.check', ['DomainList' => 'asha.com'], ['UserName' => 'someone-else'])))->toBe(1011147)
        ->and(ncError(ncCall('namecheap.nothing.here')))->toBe(1010900);

    $balance->actions()['ip_allowed'](['allowed' => false], $this->scenario);
    expect(ncError(ncCall('namecheap.domains.check', ['DomainList' => 'asha.com'])))->toBe(1011150);

    expect($this->scenario->log()->entries('domains_check')[0]['input']['ApiKey'])->not->toBe(NamecheapSimulator::API_KEY);
});

test('availability answers each name: free, taken, premium with its prices, unsupported extension, registry down', function () {
    ncRegister('mine-sim.com');
    $this->scenario->simulator()->actions()['registry_down'](['tld' => 'net'], $this->scenario);

    $xml = ncCall('namecheap.domains.check', ['DomainList' => 'free-name.com,mine-sim.com,taken-elsewhere.com,premium-gold.com,free-name.net,thing.invalid']);
    $rows = [];

    foreach ($xml->CommandResponse->DomainCheckResult as $row) {
        $rows[(string) $row['Domain']] = $row;
    }

    expect((string) $rows['free-name.com']['Available'])->toBe('true')
        ->and((string) $rows['mine-sim.com']['Available'])->toBe('false')
        ->and((string) $rows['taken-elsewhere.com']['Available'])->toBe('false')
        ->and((string) $rows['premium-gold.com']['IsPremiumName'])->toBe('true')
        ->and((string) $rows['premium-gold.com']['PremiumRegistrationPrice'])->toBe('120.00')
        ->and((string) $rows['free-name.net']['ErrorNo'])->toBe('3031510')
        ->and((string) $rows['thing.invalid']['ErrorNo'])->toBe('2030280');

    $tooMany = implode(',', array_map(fn (int $i): string => "n{$i}.com", range(1, 51)));
    expect(ncError(ncCall('namecheap.domains.check', ['DomainList' => $tooMany])))->toBe(2011170);
});

test('registration completes, charges the balance, refuses a taken name and a missing contact role, and checks extension details', function () {
    $created = ncRegister('asha-sim.com', ['AddFreeWhoisguard' => 'yes', 'WGEnabled' => 'yes'])->CommandResponse->DomainCreateResult;

    expect((string) $created['Registered'])->toBe('true')
        ->and((string) $created['NonRealTimeDomain'])->toBe('false')
        ->and((string) $created['ChargedAmount'])->toBe('10.98')
        ->and((string) $created['WhoisguardEnable'])->toBe('true')
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(NamecheapSimulator::BALANCE - 1_098)
        ->and(ncError(ncRegister('asha-sim.com')))->toBe(3019166)
        ->and(ncError(ncRegister('taken-elsewhere.com')))->toBe(3019166);

    $contacts = ncContacts();
    unset($contacts['AuxBillingPhone']);
    expect(ncError(ncCall('namecheap.domains.create', ['DomainName' => 'no-aux.com', 'Years' => 1, ...$contacts])))->toBe(2010302)
        ->and(ncError(ncRegister('bad-phone.com', ncContacts(phone: '9841234567'))))->toBe(2011170)
        ->and(ncError(ncRegister('thing.us')))->toBe(2011170)
        ->and((string) ncRegister('thing.us', ['RegistrantNexus' => 'C11', 'RegistrantPurpose' => 'P3'])['Status'])->toBe('OK')
        ->and(ncError(ncRegister('xn--thing.com')))->toBe(2010302);
});

test('an asynchronous registration is not in the account until the registry answers, and a failure refunds it', function () {
    $created = ncRegister('slow-one.com')->CommandResponse->DomainCreateResult;

    expect((string) $created['Registered'])->toBe('false')
        ->and((string) $created['NonRealTimeDomain'])->toBe('true')
        ->and(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'slow-one.com'])))->toBe(2016166)
        ->and(count(ncCall('namecheap.domains.getList', ['SearchTerm' => 'slow-one'])->CommandResponse->DomainGetListResult->Domain))->toBe(0)
        ->and(ncError(ncRegister('slow-one.com')))->toBe(3019166);

    $this->scenario->simulator()->actions()['settle_order'](['domain' => 'slow-one.com', 'outcome' => 'active'], $this->scenario);
    expect((string) ncCall('namecheap.domains.getInfo', ['DomainName' => 'slow-one.com'])['Status'])->toBe('OK');

    ncRegister('slow-two.com');
    $this->scenario->simulator()->actions()['settle_order'](['domain' => 'slow-two.com', 'outcome' => 'failed'], $this->scenario);

    expect($this->scenario->state()->get('config', 'balance'))->toBe(NamecheapSimulator::BALANCE - 1_098)
        ->and(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'slow-two.com'])))->toBe(2019166);
});

test('insufficient funds and a premium name are refused without charging', function () {
    $this->scenario->simulator()->actions()['set_balance'](['cents' => 500], $this->scenario);

    expect(ncError(ncRegister('poor.com')))->toBe(2033409)
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(500);

    $this->scenario->simulator()->actions()['set_balance'](['cents' => 50_000], $this->scenario);
    expect(ncError(ncRegister('premium-gold.com')))->toBe(2011170);

    $bought = ncRegister('premium-gold.com', ['IsPremiumDomain' => 'True', 'PremiumPrice' => '120.00']);
    expect((string) $bought['Status'])->toBe('OK')
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(50_000 - 12_000);
});

test('info, list, lock, name servers and privacy read and change the domain; a name held elsewhere is not ours', function () {
    ncRegister('asha-sim.com');
    ncRegister('asha-sim.co.uk');

    $info = ncCall('namecheap.domains.getInfo', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainGetInfoResult;
    expect((string) $info['Status'])->toBe('Locked')
        ->and((string) $info['IsOwner'])->toBe('true')
        ->and((string) $info->DomainDetails->ExpiredDate)->toBe(gmdate('m/d/Y', strtotime('+1 year')))
        ->and((string) $info->Whoisguard['Enabled'])->toBe('False')
        ->and((string) $info->DnsDetails->Nameserver[0])->toBe('ns1.host.example.test');

    expect((string) ncCall('namecheap.domains.setRegistrarLock', ['DomainName' => 'asha-sim.com', 'LockAction' => 'UNLOCK'])->CommandResponse->DomainSetRegistrarLockResult['IsSuccess'])->toBe('true')
        ->and((string) ncCall('namecheap.domains.getRegistrarLock', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainGetRegistrarLockResult['RegistrarLockStatus'])->toBe('false')
        ->and(ncError(ncCall('namecheap.domains.setRegistrarLock', ['DomainName' => 'asha-sim.co.uk', 'LockAction' => 'LOCK'])))->toBe(2030280)
        ->and(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'taken-elsewhere.com'])))->toBe(2016166)
        ->and(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'nobody-has-this.com'])))->toBe(2019166);

    // Multi-label extension: SLD and TLD are split by the caller, and a split that cuts the extension is refused.
    expect((string) ncCall('namecheap.domains.dns.setCustom', ['SLD' => 'asha-sim', 'TLD' => 'co.uk', 'Nameservers' => 'a.dns.example.test,b.dns.example.test'])->CommandResponse->DomainDNSSetCustomResult['Update'])->toBe('true')
        ->and(ncError(ncCall('namecheap.domains.dns.setCustom', ['SLD' => 'asha-sim.co', 'TLD' => 'uk', 'Nameservers' => 'a.dns.example.test,b.dns.example.test'])))->toBe(2011170)
        ->and(ncError(ncCall('namecheap.domains.dns.setCustom', ['SLD' => 'asha-sim', 'TLD' => 'com', 'Nameservers' => 'only-one.example.test'])))->toBe(2011170)
        ->and((string) ncCall('namecheap.domains.dns.getList', ['SLD' => 'asha-sim', 'TLD' => 'co.uk'])->CommandResponse->DomainDNSGetListResult->Nameserver[1])->toBe('b.dns.example.test');

    $privacyId = (string) $info->Whoisguard->ID;
    expect((string) ncCall('namecheap.whoisguard.enable', ['WhoisguardID' => $privacyId, 'ForwardedToEmail' => 'asha@example.test'])->CommandResponse->WhoisguardEnableResult['IsSuccess'])->toBe('true')
        ->and((string) ncCall('namecheap.domains.getInfo', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainGetInfoResult->Whoisguard['Enabled'])->toBe('True')
        ->and(ncError(ncCall('namecheap.whoisguard.enable', ['WhoisguardID' => $privacyId, 'ForwardedToEmail' => 'not-an-email'])))->toBe(2011170)
        ->and((string) ncCall('namecheap.whoisguard.disable', ['WhoisguardID' => $privacyId])->CommandResponse->WhoisguardDisableResult['IsSuccess'])->toBe('true');

    $list = ncCall('namecheap.domains.getList', ['PageSize' => 10, 'SearchTerm' => 'asha-sim'])->CommandResponse;
    expect(count($list->DomainGetListResult->Domain))->toBe(2)
        ->and((string) $list->Paging->TotalItems)->toBe('2')
        ->and(ncError(ncCall('namecheap.domains.getList', ['PageSize' => 5])))->toBe(2011170);
});

test('contacts are read per role, set as all four, and a registrant change starts the 60-day lock', function () {
    ncRegister('asha-sim.com');

    $contacts = ncCall('namecheap.domains.getContacts', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainContactsResult;
    expect((string) $contacts->Registrant->FirstName)->toBe('Asha')
        ->and((string) $contacts->AuxBilling->EmailAddress)->toBe('asha@example.test');

    $partial = ncContacts();
    unset($partial['TechCity']);
    expect(ncError(ncCall('namecheap.domains.setContacts', ['DomainName' => 'asha-sim.com', ...$partial])))->toBe(2010302);

    ncCall('namecheap.domains.setContacts', ['DomainName' => 'asha-sim.com', ...ncContacts('new@example.test')]);

    expect((string) ncCall('namecheap.domains.getContacts', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainContactsResult->Registrant->EmailAddress)->toBe('new@example.test')
        ->and($this->scenario->state()->get('domains', 'asha-sim.com')['lock_until'])->toBeGreaterThan(time() + 59 * 86_400);
});

test('renewal adds years to the expiry, refuses an expired name, and reactivation restores it', function () {
    ncRegister('asha-sim.com');

    $renewed = ncCall('namecheap.domains.renew', ['DomainName' => 'asha-sim.com', 'Years' => 2])->CommandResponse->DomainRenewResult;
    expect((string) $renewed['Renew'])->toBe('true')
        ->and((string) $renewed->DomainDetails->ExpiredDate)->toBe(gmdate('m/d/Y', strtotime('+3 years')))
        ->and(ncError(ncCall('namecheap.domains.reactivate', ['DomainName' => 'asha-sim.com'])))->toBe(2020167);

    $this->scenario->simulator()->actions()['expire'](['domain' => 'asha-sim.com', 'days_ago' => 10], $this->scenario);

    $info = ncCall('namecheap.domains.getInfo', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainGetInfoResult;
    expect((string) $info['Status'])->toBe('Expired')
        ->and(ncError(ncCall('namecheap.domains.renew', ['DomainName' => 'asha-sim.com', 'Years' => 1])))->toBe(2020166)
        ->and(count(ncCall('namecheap.domains.getList', ['ListType' => 'EXPIRED'])->CommandResponse->DomainGetListResult->Domain))->toBe(1);

    $balance = $this->scenario->state()->get('config', 'balance');
    $reactivated = ncCall('namecheap.domains.reactivate', ['DomainName' => 'asha-sim.com', 'YearsToAdd' => 1])->CommandResponse->DomainReactivateResult;

    expect((string) $reactivated['IsSuccess'])->toBe('true')
        ->and($this->scenario->state()->get('config', 'balance'))->toBe($balance - 1_098 - 8_000)
        ->and((string) ncCall('namecheap.domains.getInfo', ['DomainName' => 'asha-sim.com'])->CommandResponse->DomainGetInfoResult->DomainDetails->ExpiredDate)->toBe(gmdate('m/d/Y', strtotime('+1 year')));
});

test('a transfer needs the EPP code (plain or base64), waits on the other registrar, and completes into the account', function () {
    $transfer = fn (array $extra = []) => ncCall('namecheap.domains.transfer.create', ['DomainName' => 'taken-elsewhere.com', 'Years' => 1, ...$extra]);

    expect(ncError($transfer()))->toBe(2010302)
        ->and(ncError($transfer(['EPPCode' => 'wrong-code'])))->toBe(2011170)
        ->and(ncError(ncCall('namecheap.domains.transfer.create', ['DomainName' => 'free-name.com', 'Years' => 1, 'EPPCode' => 'x'])))->toBe(3019166)
        ->and(ncError(ncCall('namecheap.domains.transfer.create', ['DomainName' => 'thing.xyz', 'Years' => 1, 'EPPCode' => 'x'])))->toBe(2030280);

    $created = $transfer(['EPPCode' => 'base64:'.base64_encode(NamecheapSimulator::FOREIGN_EPP_CODE)])->CommandResponse->DomainTransferCreateResult;
    $id = (string) $created['TransferID'];

    expect((string) $created['Transfer'])->toBe('true')
        ->and((string) $created['StatusID'])->toBe('-1')
        ->and(ncError($transfer(['EPPCode' => NamecheapSimulator::FOREIGN_EPP_CODE])))->toBe(3019166)
        ->and((string) ncCall('namecheap.domains.transfer.getStatus', ['TransferID' => $id])->CommandResponse->DomainTransferGetStatusResult['StatusID'])->toBe('-1')
        ->and((string) ncCall('namecheap.domains.transfer.getList', ['SearchTerm' => 'taken-elsewhere'])->CommandResponse->TransferGetListResult->Transfer['ID'])->toBe($id)
        ->and(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'taken-elsewhere.com'])))->toBe(2016166);

    $this->scenario->simulator()->actions()['set_transfer_status'](['domain' => 'taken-elsewhere.com', 'status_id' => -22], $this->scenario);
    expect((string) ncCall('namecheap.domains.transfer.getStatus', ['TransferID' => $id])->CommandResponse->DomainTransferGetStatusResult['Status'])->toBe('Waiting for the authorization code');

    $this->scenario->simulator()->actions()['settle_transfer'](['domain' => 'taken-elsewhere.com', 'outcome' => 'complete'], $this->scenario);

    expect((string) ncCall('namecheap.domains.transfer.getStatus', ['TransferID' => $id])->CommandResponse->DomainTransferGetStatusResult['StatusID'])->toBe('5')
        ->and((string) ncCall('namecheap.domains.getInfo', ['DomainName' => 'taken-elsewhere.com'])['Status'])->toBe('OK');
});

test('a cancelled transfer refunds its charge and frees the name for a new attempt', function () {
    ncCall('namecheap.domains.transfer.create', ['DomainName' => 'taken-elsewhere.com', 'Years' => 1, 'EPPCode' => NamecheapSimulator::FOREIGN_EPP_CODE]);
    $this->scenario->simulator()->actions()['settle_transfer'](['domain' => 'taken-elsewhere.com', 'outcome' => 'cancel'], $this->scenario);

    expect($this->scenario->state()->get('config', 'balance'))->toBe(NamecheapSimulator::BALANCE)
        ->and((string) ncCall('namecheap.domains.transfer.create', ['DomainName' => 'taken-elsewhere.com', 'Years' => 1, 'EPPCode' => NamecheapSimulator::FOREIGN_EPP_CODE])['Status'])->toBe('OK');
});

test('next_error answers an unknown-outcome error and, with commit, still runs the change', function () {
    $actions = $this->scenario->simulator()->actions();

    $actions['next_error'](['number' => 3011511, 'message' => 'Unknown response from the provider.', 'commit' => true], $this->scenario);
    expect(ncError(ncRegister('half-run.com')))->toBe(3011511)
        ->and((string) ncCall('namecheap.domains.getInfo', ['DomainName' => 'half-run.com'])['Status'])->toBe('OK');

    $actions['next_error'](['number' => 3011511, 'message' => 'Unknown response from the provider.'], $this->scenario);
    expect(ncError(ncRegister('not-run.com')))->toBe(3011511)
        ->and(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'not-run.com'])))->toBe(2019166)
        ->and((string) ncRegister('not-run.com')['Status'])->toBe('OK');
});

test('a timeout after commit leaves the registration done, and a rate limit is HTTP 429', function () {
    $this->scenario->faults()->add('domains_create', Fault::commitThenHang(3), 1);

    try {
        Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->timeout(1)->asForm()->post($this->scenario->apiUrl().'/xml.response', [
            'ApiUser' => NamecheapSimulator::USER_NAME, 'ApiKey' => NamecheapSimulator::API_KEY, 'UserName' => NamecheapSimulator::USER_NAME, 'ClientIp' => NamecheapSimulator::CLIENT_IP,
            'Command' => 'namecheap.domains.create', 'DomainName' => 'hang-sim.com', 'Years' => 1, ...ncContacts(),
        ]);
        $this->fail('The call should have timed out.');
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())->toContain('cURL error 28');
    }

    expect((string) ncCall('namecheap.domains.getInfo', ['DomainName' => 'hang-sim.com'])['Status'])->toBe('OK');

    $this->scenario->faults()->add('domains_check', Fault::rateLimit(2), 1);
    expect(ncRaw('namecheap.domains.check', ['DomainList' => 'a.com'])->status())->toBe(429);
});

test('reset wipes the account and reseeds it', function () {
    ncRegister('asha-sim.com');
    $this->scenario->simulator()->actions()['reset']([], $this->scenario);

    expect(ncError(ncCall('namecheap.domains.getInfo', ['DomainName' => 'asha-sim.com'])))->toBe(2019166)
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(NamecheapSimulator::BALANCE)
        ->and(ncCall('namecheap.domains.getTldList')->CommandResponse->Tlds->Tld[0]['Name'] ?? null)->not->toBeNull();
});
