<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Kernel\Fault;
use Mohar\Simulators\Kernel\Processes;
use Mohar\Simulators\Providers\ResellerClub\ResellerClubSimulator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The resellerclub simulator over its TLS port, as the HTTP API reference describes it: authentication and the
 * allow-list, the error envelope, repeated-key lists, customers and contacts, the order lifecycle with its async
 * states, and the scenarios the driver must handle (premium, unavailable, insufficient funds, a transfer waiting for
 * its auth code, a timeout after commit, a rate limit).
 */
beforeEach(function () {
    $this->scenario = Processes::start(ResellerClubSimulator::class);
});

afterEach(function () {
    Processes::stop($this->scenario);
});

/**
 * One API call, with the lists as repeated keys (`ns=a&ns=b`), as the API expects.
 *
 * @param  array<string, string|int|list<string>>  $params
 */
function rcCall(string $method, string $path, array $params = [], ?string $key = ResellerClubSimulator::API_KEY): Response
{
    $pairs = [];

    foreach (['auth-userid' => ResellerClubSimulator::RESELLER_ID, 'api-key' => $key, ...$params] as $name => $value) {
        foreach ((array) $value as $item) {
            $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $item);
        }
    }

    $encoded = implode('&', $pairs);
    $request = Http::withOptions(['verify' => test()->scenario->ca()->bundlePath()])->timeout(5);
    $url = test()->scenario->apiUrl().'/api/'.$path;

    return $method === 'GET'
        ? $request->get($url.'?'.$encoded)
        : $request->withBody($encoded, 'application/x-www-form-urlencoded')->post($url);
}

/**
 * A customer with one contact used for all four roles; returns [customer id, contact id].
 *
 * @return array{0: string, 1: string}
 */
function rcCustomer(string $email = 'asha@example.test'): array
{
    $customer = (string) rcCall('POST', 'customers/v2/signup.json', [
        'username' => $email, 'passwd' => 'Kathmandu2026', 'name' => 'Asha Shrestha', 'company' => 'N A', 'address-line-1' => '1 Durbar Marg',
        'city' => 'Kathmandu', 'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44600', 'phone-cc' => '977', 'phone' => '15550100', 'lang-pref' => 'en',
    ])->json();
    $contact = (string) rcCall('POST', 'contacts/add.json', [
        'name' => 'Asha Shrestha', 'company' => 'N A', 'email' => $email, 'address-line-1' => '1 Durbar Marg', 'city' => 'Kathmandu',
        'country' => 'NP', 'zipcode' => '44600', 'phone-cc' => '977', 'phone' => '15550100', 'customer-id' => $customer, 'type' => 'Contact',
    ])->json();

    return [$customer, $contact];
}

/**
 * @param  array<string, string|int|list<string>>  $extra
 * @return array<string, string|int|list<string>>
 */
function rcOrder(string $domain, string $customer, string $contact, array $extra = []): array
{
    return [
        'domain-name' => $domain, 'years' => 1, 'ns' => ['ns1.host.example.test', 'ns2.host.example.test'], 'customer-id' => $customer,
        'reg-contact-id' => $contact, 'admin-contact-id' => $contact, 'tech-contact-id' => $contact, 'billing-contact-id' => $contact,
        'invoice-option' => 'NoInvoice', 'auto-renew' => 'false', ...$extra,
    ];
}

test('every call needs the reseller id and API key, and refusals use the error envelope with HTTP 500', function () {
    $refused = rcCall('GET', 'domains/available.json', ['domain-name' => 'asha', 'tlds' => 'com'], key: 'wrong-key');

    expect($refused->status())->toBe(500)
        ->and($refused->json())->toBe(['status' => 'ERROR', 'message' => 'Access Denied. You are not allowed to perform this action.'])
        ->and(rcCall('GET', 'domains/register.json')->json('message'))->toBe('This action must be sent with the POST method.');

    $this->scenario->simulator()->actions()['ip_allowed'](['allowed' => false], $this->scenario);
    expect(rcCall('GET', 'domains/available.json', ['domain-name' => 'asha', 'tlds' => 'com'])->json('message'))->toStartWith('Access Denied');

    expect($this->scenario->log()->entries('available')[0]['input']['api-key'])->not->toBe(ResellerClubSimulator::API_KEY);
});

test('availability answers each name and extension: available, ours, elsewhere, premium with its costs, unknown', function () {
    [$customer, $contact] = rcCustomer();
    rcCall('POST', 'domains/register.json', rcOrder('mine-sim.com', $customer, $contact));
    $this->scenario->simulator()->actions()['registry_down'](['tld' => 'net'], $this->scenario);

    $answer = rcCall('GET', 'domains/available.json', ['domain-name' => ['mine-sim', 'taken-elsewhere', 'premium-gold', 'free-name'], 'tlds' => ['com', 'net']])->json();

    expect($answer['mine-sim.com']['status'])->toBe('regthroughus')
        ->and($answer['taken-elsewhere.com']['status'])->toBe('regthroughothers')
        ->and($answer['free-name.com']['status'])->toBe('available')
        ->and($answer['free-name.com'])->not->toHaveKey('costHash')
        ->and($answer['premium-gold.com']['costHash'])->toBe(['create' => '120.00', 'renew' => '120.00', 'transfer' => '120.00', 'sellingcurrencysymbol' => 'USD'])
        ->and($answer['free-name.net']['status'])->toBe('unknown')
        ->and($answer)->toHaveCount(8);
});

test('customers are unique by username, and contact checks answer the field map in one message', function () {
    [$customer] = rcCustomer();

    $again = rcCall('POST', 'customers/v2/signup.json', [
        'username' => 'ASHA@example.test', 'passwd' => 'Kathmandu2026', 'name' => 'Asha', 'company' => 'N A', 'address-line-1' => 'x', 'city' => 'Kathmandu',
        'state' => 'Bagmati', 'country' => 'NP', 'zipcode' => '44600', 'phone-cc' => '977', 'phone' => '15550100', 'lang-pref' => 'en',
    ]);
    $invalid = rcCall('POST', 'contacts/add.json', [
        'name' => 'Asha', 'company' => 'N A', 'email' => 'asha@example.test', 'address-line-1' => 'x', 'city' => 'Kathmandu', 'country' => 'Nepal',
        'zipcode' => '44600', 'phone-cc' => '+977', 'phone' => '15550100', 'customer-id' => $customer, 'type' => 'Contact',
    ]);

    expect($again->json('message'))->toContain('already exists')
        ->and(rcCall('GET', 'customers/details.json', ['username' => 'asha@example.test'])->json('customerid'))->toBe($customer)
        ->and($invalid->status())->toBe(500)
        ->and($invalid->json('message'))->toBe('{telnocc=The country calling code must be 1 to 3 digits, country=Use a two-letter ISO country code}')
        ->and($this->scenario->state()->all('contacts'))->toHaveCount(1)
        ->and($this->scenario->log()->entries('customer_signup')[0]['input']['passwd'])->not->toBe('Kathmandu2026');
});

test('a registration completes at once, keeps the repeated name servers, and its details and search show the order', function () {
    [$customer, $contact] = rcCustomer();

    $registered = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact, ['years' => 2]))->json();
    $details = rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'asha-sim.com', 'options' => ['OrderDetails', 'NsDetails', 'ContactIds']])->json();
    $search = rcCall('GET', 'domains/search.json', ['no-of-records' => 10, 'page-no' => 1, 'domain-name' => 'asha-sim.com'])->json();

    expect($registered['actionstatus'])->toBe('Success')
        ->and($registered['entityid'])->toBe($details['orderid'])
        ->and($registered)->not->toHaveKey('invoiceid')
        ->and($details['currentstatus'])->toBe('Active')
        ->and((int) $details['endtime'])->toBe((int) (new DateTimeImmutable('@'.$details['creationtime']))->modify('+2 years')->format('U'))
        ->and([$details['ns1'], $details['ns2'], $details['noOfNameServers']])->toBe(['ns1.host.example.test', 'ns2.host.example.test', '2'])
        ->and($details['registrantcontactid'])->toBe($contact)
        ->and($details['domsecret'])->toBeString()->not->toBe('')
        ->and(rcCall('GET', 'domains/orderid.json', ['domain-name' => 'asha-sim.com'])->json())->toBe((int) $registered['entityid'])
        ->and($search['recsindb'])->toBe('1')
        ->and($search['1']['orders.orderid'])->toBe($registered['entityid'])
        ->and($search['1']['entity.currentstatus'])->toBe('Active')
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(ResellerClubSimulator::BALANCE - 2_100);
});

test('a taken name, a premium name without its attribute and an empty balance are refused, and charge nothing', function () {
    [$customer, $contact] = rcCustomer();

    $taken = rcCall('POST', 'domains/register.json', rcOrder('taken-elsewhere.com', $customer, $contact));
    $premium = rcCall('POST', 'domains/register.json', rcOrder('premium-gold.com', $customer, $contact));
    $this->scenario->simulator()->actions()['set_balance'](['cents' => 500], $this->scenario);
    $broke = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact));

    expect($taken->json('message'))->toContain('already registered')
        ->and($premium->json('message'))->toContain('premium')
        ->and($broke->status())->toBe(500)
        ->and($broke->json('message'))->toStartWith('Insufficient funds')
        ->and($this->scenario->state()->all('orders'))->toBe([])
        ->and($this->scenario->state()->get('config', 'balance'))->toBe(500)
        ->and(rcCall('POST', 'domains/register.json', rcOrder('premium-gold.com', $customer, $contact, ['attr-name1' => 'premium', 'attr-value1' => 'true']))->json('message'))->toStartWith('Insufficient funds');
});

test('an extension with required details refuses an order without them, and one without a role needs -1', function () {
    [$customer, $contact] = rcCustomer();

    $missing = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com.au', $customer, $contact));
    $complete = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com.au', $customer, $contact, [
        'attr-name1' => 'id-type', 'attr-value1' => 'ABN', 'attr-name2' => 'id', 'attr-value2' => '51 824 753 556',
        'attr-name3' => 'policyReason', 'attr-value3' => '1', 'attr-name4' => 'isAUWarranty', 'attr-value4' => 'true',
    ]));
    $uk = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.co.uk', $customer, $contact));
    $ukMinus = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.co.uk', $customer, $contact, ['admin-contact-id' => '-1', 'tech-contact-id' => '-1', 'billing-contact-id' => '-1']));

    expect($missing->json('message'))->toContain('id-type')
        ->and($complete->json('actionstatus'))->toBe('Success')
        ->and($uk->json('message'))->toContain('send -1 for admin-contact-id')
        ->and($ukMinus->json('actionstatus'))->toBe('Success');
});

test('a slow registry leaves the order InActive and pending until it settles', function () {
    [$customer, $contact] = rcCustomer();

    $pending = rcCall('POST', 'domains/register.json', rcOrder('slow-asha.com', $customer, $contact))->json();
    $before = rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'slow-asha.com', 'options' => 'OrderDetails'])->json();
    $this->scenario->simulator()->actions()['settle_order'](['domain' => 'slow-asha.com'], $this->scenario);
    $after = rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'slow-asha.com', 'options' => 'All'])->json();

    expect($pending['actionstatus'])->toBe('PendingExecution')
        ->and([$before['currentstatus'], $before['endtime']])->toBe(['InActive', ''])
        ->and($after['currentstatus'])->toBe('Active')
        ->and((int) $after['endtime'])->toBeGreaterThan(time());
});

test('a transfer without its auth code waits for it, then for the registry, then is ours', function () {
    [$customer, $contact] = rcCustomer();

    $this->scenario->simulator()->actions()['add_foreign_domain'](['domain' => 'asha-sim.ca', 'auth_code' => 'Ca-Code-1'], $this->scenario);
    $upfront = rcCall('POST', 'domains/transfer.json', rcOrder('asha-sim.ca', $customer, $contact, ['ns' => [], 'billing-contact-id' => '-1']));
    $transfer = rcCall('POST', 'domains/transfer.json', rcOrder(ResellerClubSimulator::FOREIGN_DOMAIN, $customer, $contact, ['ns' => []]))->json();
    $wrong = rcCall('POST', 'domains/transfer/submit-auth-code.json', ['order-id' => $transfer['entityid'], 'auth-code' => 'guess']);

    expect($upfront->json('message'))->toContain('needs the authorization code')
        ->and($transfer['status'])->toBe('NoError')
        ->and($transfer['actionstatus'])->toBe('PendingExecution')
        ->and(fn () => $this->scenario->simulator()->actions()['settle_order'](['domain' => ResellerClubSimulator::FOREIGN_DOMAIN], $this->scenario))->toThrow(InvalidArgumentException::class)
        ->and($wrong->json('message'))->toContain('not valid')
        ->and(rcCall('POST', 'domains/transfer/submit-auth-code.json', ['order-id' => $transfer['entityid'], 'auth-code' => ResellerClubSimulator::FOREIGN_AUTH_CODE])->json())->toBe('success')
        ->and(rcCall('GET', 'domains/details-by-name.json', ['domain-name' => ResellerClubSimulator::FOREIGN_DOMAIN, 'options' => 'OrderDetails'])->json('currentstatus'))->toBe('InActive');

    $this->scenario->simulator()->actions()['settle_order'](['domain' => ResellerClubSimulator::FOREIGN_DOMAIN], $this->scenario);
    $available = rcCall('GET', 'domains/available.json', ['domain-name' => 'taken-elsewhere', 'tlds' => 'com'])->json();

    expect(rcCall('GET', 'domains/details-by-name.json', ['domain-name' => ResellerClubSimulator::FOREIGN_DOMAIN, 'options' => 'OrderDetails'])->json('currentstatus'))->toBe('Active')
        ->and($available['taken-elsewhere.com']['status'])->toBe('regthroughus');
});

test('a renewal must name the current expiry, so a second one sent with the old date is refused', function () {
    [$customer, $contact] = rcCustomer();
    $order = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact))->json('entityid');
    $expiry = rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'asha-sim.com', 'options' => 'OrderDetails'])->json('endtime');

    $renewed = rcCall('POST', 'domains/renew.json', ['order-id' => $order, 'years' => 1, 'exp-date' => $expiry, 'invoice-option' => 'NoInvoice', 'auto-renew' => 'false']);
    $again = rcCall('POST', 'domains/renew.json', ['order-id' => $order, 'years' => 1, 'exp-date' => $expiry, 'invoice-option' => 'NoInvoice', 'auto-renew' => 'false']);

    expect($renewed->json('actionstatus'))->toBe('Success')
        ->and($again->json('message'))->toContain('does not match the current expiry')
        ->and((int) rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'asha-sim.com', 'options' => 'OrderDetails'])->json('endtime'))
        ->toBe((int) (new DateTimeImmutable('@'.$expiry))->modify('+1 year')->format('U'));
});

test('name servers, the theft lock, privacy, contacts and restore change the order; refusals change nothing', function () {
    [$customer, $contact] = rcCustomer();
    $order = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact))->json('entityid');
    $inOrder = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.in', $customer, $contact))->json('entityid');
    $newContact = (string) rcCall('POST', 'contacts/add.json', [
        'name' => 'Bikash Thapa', 'company' => 'Thapa Traders', 'email' => 'bikash@example.test', 'address-line-1' => '2 New Road', 'city' => 'Pokhara',
        'country' => 'NP', 'zipcode' => '33700', 'phone-cc' => '977', 'phone' => '61550100', 'customer-id' => $customer, 'type' => 'Contact',
    ])->json();

    rcCall('POST', 'domains/modify-ns.json', ['order-id' => $order, 'ns' => ['ns1.new.example.test', 'ns2.new.example.test', 'ns3.new.example.test']]);
    rcCall('POST', 'domains/enable-theft-protection.json', ['order-id' => $order]);
    rcCall('POST', 'domains/modify-privacy-protection.json', ['order-id' => $order, 'protect-privacy' => 'true', 'reason' => 'Client request']);
    rcCall('POST', 'domains/modify-contact.json', ['order-id' => $order, 'reg-contact-id' => $newContact, 'admin-contact-id' => $contact, 'tech-contact-id' => $contact, 'billing-contact-id' => $contact]);
    $details = rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'asha-sim.com', 'options' => 'All'])->json();

    expect($details['noOfNameServers'])->toBe('3')
        ->and($details['orderstatus'])->toBe(['transferlock'])
        ->and(rcCall('GET', 'domains/locks.json', ['order-id' => $order])->json('transferlock'))->toBeTrue()
        ->and($details['isprivacyprotected'])->toBe('true')
        ->and($details['registrantcontactid'])->toBe($newContact)
        ->and($details['domainstatus'])->toBe(['sixtydaylock'])
        ->and(rcCall('POST', 'domains/modify-privacy-protection.json', ['order-id' => $inOrder, 'protect-privacy' => 'true', 'reason' => 'x'])->json('message'))->toContain('not offered for .in')
        ->and(rcCall('POST', 'domains/modify-ns.json', ['order-id' => $order, 'ns' => '10.0.0.1'])->json('message'))->toContain('valid name server')
        ->and(rcCall('GET', 'contacts/details.json', ['contact-id' => $newContact])->json('emailaddr'))->toBe('bikash@example.test');

    rcCall('POST', 'domains/disable-theft-protection.json', ['order-id' => $order]);
    $this->scenario->simulator()->actions()['set_status'](['domain' => 'asha-sim.com', 'status' => 'Pending Delete Restorable'], $this->scenario);

    expect(rcCall('GET', 'domains/locks.json', ['order-id' => $order])->body())->toBe('{}')
        ->and(rcCall('POST', 'domains/modify-ns.json', ['order-id' => $order, 'ns' => 'ns1.other.example.test'])->json('message'))->toContain('No active domain order')
        ->and(rcCall('POST', 'domains/restore.json', ['order-id' => $order, 'invoice-option' => 'NoInvoice'])->json('actionstatus'))->toBe('Success')
        ->and(rcCall('GET', 'domains/details-by-name.json', ['domain-name' => 'asha-sim.com', 'options' => 'OrderDetails'])->json('currentstatus'))->toBe('Active');
});

test('a registration that commits and then hangs is in the account when the client gives up, and a retry is refused', function () {
    [$customer, $contact] = rcCustomer();
    $this->scenario->faults()->add('register', Fault::commitThenHang(4), nth: 1);

    expect(fn () => Http::withOptions(['verify' => $this->scenario->ca()->bundlePath()])->timeout(1)
        ->withBody(http_build_query(['auth-userid' => ResellerClubSimulator::RESELLER_ID, 'api-key' => ResellerClubSimulator::API_KEY, ...rcOrder('asha-sim.com', $customer, $contact, ['ns' => 'ns1.host.example.test'])]), 'application/x-www-form-urlencoded')
        ->post($this->scenario->apiUrl().'/api/domains/register.json'))->toThrow(ConnectionException::class);

    expect($this->scenario->log()->count('register'))->toBe(1)
        ->and(rcCall('GET', 'domains/search.json', ['no-of-records' => 10, 'page-no' => 1, 'domain-name' => 'asha-sim.com'])->json('recsindb'))->toBe('1')
        ->and(rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact))->json('message'))->toContain('already registered');
});

test('a rate limit refuses the call before it runs', function () {
    [$customer, $contact] = rcCustomer();
    $this->scenario->faults()->add('register', Fault::rateLimit(30), nth: 1);

    $limited = rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact));

    expect($limited->status())->toBe(429)
        ->and($limited->header('Retry-After'))->toBe('30')
        ->and($this->scenario->state()->all('orders'))->toBe([])
        ->and(rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact))->json('actionstatus'))->toBe('Success');
});

test('a reset wipes the account like the daily demo reset, and keeps the seeded registry', function () {
    [$customer, $contact] = rcCustomer();
    rcCall('POST', 'domains/register.json', rcOrder('asha-sim.com', $customer, $contact));

    $this->scenario->simulator()->actions()['reset']([], $this->scenario);

    expect($this->scenario->state()->all('orders'))->toBe([])
        ->and($this->scenario->state()->all('customers'))->toBe([])
        ->and(rcCall('GET', 'domains/available.json', ['domain-name' => ['asha-sim', 'taken-elsewhere'], 'tlds' => 'com'])->json())
        ->toMatchArray(['asha-sim.com' => ['status' => 'available', 'classkey' => 'dotcom'], 'taken-elsewhere.com' => ['status' => 'regthroughothers', 'classkey' => 'dotcom']]);
});

test('values() reads a list sent as repeated names from the query and a form body', function () {
    $request = new Request('POST', '/api/domains/register.json', [], [], 'ns=a.example.test&ns=b.example.test&years=1', rawQuery: 'tlds=com&tlds=net');

    expect($request->values('ns'))->toBe(['a.example.test', 'b.example.test'])
        ->and($request->values('tlds'))->toBe(['com', 'net'])
        ->and($request->values('missing'))->toBe([]);
});
