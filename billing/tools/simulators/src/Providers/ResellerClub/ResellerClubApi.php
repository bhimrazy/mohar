<?php

namespace Mohar\Simulators\Providers\ResellerClub;

use Mohar\Simulators\Http\Request;
use Mohar\Simulators\Http\Response;
use Mohar\Simulators\Kernel\Scenario;

/**
 * One ResellerClub (LogicBoxes) reseller account and the registry behind it, as the HTTP API shows them: customers,
 * their contacts, and domain orders that move InActive → Active (and on to Pending Delete Restorable, Deleted).
 *
 * Every refusal is HTTP 500 with `{"status": "ERROR", "message": …}` and changes nothing; LogicBoxes has no error
 * codes, so a client tells refusals apart by their text. The messages here are this simulator's own wording. Values
 * come back as strings, times as epoch seconds, as the API does. Money is in US cents in the state, deducted from the
 * reseller balance with `invoice-option=NoInvoice`.
 *
 * Name rules a scenario can rely on (ResellerClubSimulator::seed() lists the rest): an SLD starting `premium-` is a
 * registry premium name; one starting `slow-` registers asynchronously (actionstatus pending until `settle_order`);
 * names in the `registry` namespace are held at another registrar.
 */
final class ResellerClubApi
{
    /** Order states the API reports in currentstatus. */
    public const STATUSES = ['InActive', 'Active', 'Suspended', 'Pending Delete Restorable', 'Deleted', 'Archived'];

    /**
     * Extensions this registry sells: the LogicBoxes product key (from the public Product Keys list; `.dev` is not on it,
     * so `dotdev` is assumed), prices in US cents, privacy, auth code needed with the order, roles it lacks.
     */
    public const TLDS = [
        'com' => ['key' => 'domcno', 'price' => 1_050, 'privacy' => true],
        'net' => ['key' => 'dotnet', 'price' => 1_250, 'privacy' => true],
        'org' => ['key' => 'domorg', 'price' => 1_150, 'privacy' => true],
        'xyz' => ['key' => 'dotxyz', 'price' => 300, 'privacy' => true],
        'in' => ['key' => 'dotin', 'price' => 800, 'privacy' => false],
        'co.in' => ['key' => 'thirdleveldotin', 'price' => 600, 'privacy' => false],
        'us' => ['key' => 'dotus', 'price' => 900, 'privacy' => false, 'attributes' => ['purpose' => '/^P[1-5]$/', 'category' => '/^C(11|12|21|31|32)$/']],
        'dev' => ['key' => 'dotdev', 'price' => 1_400, 'privacy' => true, 'attributes' => ['tnc' => '/^[yY]$/']],
        'com.au' => ['key' => 'thirdleveldotau', 'price' => 1_300, 'privacy' => false, 'auth_code_upfront' => true, 'attributes' => ['id-type' => '/^(ACN|ABN|TM|Other)$/', 'id' => '/^\S.{0,63}$/', 'policyReason' => '/^[12]$/', 'isAUWarranty' => '/^true$/']],
        'co.uk' => ['key' => 'thirdleveldotuk', 'price' => 700, 'privacy' => false, 'absent_roles' => ['admin', 'tech', 'billing']],
        'ca' => ['key' => 'dotca', 'price' => 1_200, 'privacy' => false, 'auth_code_upfront' => true, 'absent_roles' => ['billing']],
    ];

    public const PREMIUM_PRICE = 12_000;

    public const CONTACT_TYPES = ['Contact', 'CaContact', 'UkContact', 'AuContact', 'EuContact'];

    public function __construct(private readonly Scenario $scenario) {}

    /**
     * GET domains/available.json: `domain-name` and `tlds` repeated, the TLDs without their dot.
     */
    public function available(Request $request): Response
    {
        $answer = [];
        $down = (array) $this->scenario->state()->get('config', 'registry_down', []);

        foreach ($request->values('domain-name') as $sld) {
            foreach ($request->values('tlds') as $tld) {
                $name = strtolower($sld.'.'.$tld);

                if (! isset(self::TLDS[$tld]) || in_array($tld, $down, true)) {
                    $answer[$name] = ['status' => 'unknown', 'classkey' => self::TLDS[$tld]['key'] ?? 'unknown'];

                    continue;
                }

                $order = $this->orderByName($name);
                $status = match (true) {
                    $order !== null && ! in_array($order['currentstatus'], ['Deleted', 'Archived'], true) => 'regthroughus',
                    $this->foreign($name) !== null => 'regthroughothers',
                    default => 'available',
                };
                $answer[$name] = ['status' => $status, 'classkey' => self::TLDS[$tld]['key']];

                if ($status === 'available' && self::premium($name)) {
                    $answer[$name]['costHash'] = ['create' => self::dollars(self::PREMIUM_PRICE), 'renew' => self::dollars(self::PREMIUM_PRICE), 'transfer' => self::dollars(self::PREMIUM_PRICE), 'sellingcurrencysymbol' => 'USD'];
                }
            }
        }

        return Response::json($answer);
    }

    /**
     * POST customers/v2/signup.json: the username is an email, unique in the account. Answers the new id.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function signup(array $input): Response
    {
        $missing = self::missing($input, ['username', 'passwd', 'name', 'company', 'address-line-1', 'city', 'state', 'country', 'zipcode', 'phone-cc', 'phone', 'lang-pref']);

        if ($missing !== []) {
            return self::error('Required parameters are missing: '.implode(', ', $missing).'.');
        }

        if (filter_var($input['username'], FILTER_VALIDATE_EMAIL) === false) {
            return self::error('{username=The username must be an email address}');
        }

        $password = (string) $input['passwd'];

        if (strlen($password) < 9 || strlen($password) > 16 || preg_match('/[a-zA-Z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return self::error('{passwd=The password must be 9 to 16 characters with letters and digits}');
        }

        if (($invalid = self::invalidAddress($input)) !== []) {
            return self::error('{'.implode(', ', $invalid).'}');
        }

        return $this->scenario->state()->transaction(function () use ($input): Response {
            if ($this->customerByUsername((string) $input['username']) !== null) {
                return self::error('A customer with the username '.$input['username'].' already exists in this account.');
            }

            $id = (string) (14_000_000 + $this->scenario->state()->increment('ids', 'customer'));
            $this->scenario->state()->put('customers', $id, [
                'customerid' => $id, 'username' => strtolower((string) $input['username']), 'resellerid' => ResellerClubSimulator::RESELLER_ID,
                'name' => (string) $input['name'], 'company' => (string) $input['company'], 'useremail' => strtolower((string) $input['username']),
                'telnocc' => (string) $input['phone-cc'], 'telno' => (string) $input['phone'], 'address1' => (string) $input['address-line-1'],
                'city' => (string) $input['city'], 'state' => (string) $input['state'], 'country' => strtoupper((string) $input['country']),
                'zip' => (string) $input['zipcode'], 'langpref' => (string) $input['lang-pref'], 'customerstatus' => 'Active', 'creationdt' => (string) time(),
            ]);

            return self::value((int) $id);
        });
    }

    /**
     * GET customers/details.json?username=
     *
     * @param  array<array-key, mixed>  $input
     */
    public function customerDetails(array $input): Response
    {
        $customer = $this->customerByUsername((string) ($input['username'] ?? ''));

        return $customer === null ? self::error('No customer with this username exists in this account.') : Response::json($customer);
    }

    /**
     * POST contacts/add.json: a contact belongs to one customer. Contacts are never edited; a change is a new contact.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function addContact(array $input): Response
    {
        $missing = self::missing($input, ['name', 'company', 'email', 'address-line-1', 'city', 'country', 'zipcode', 'phone-cc', 'phone', 'customer-id', 'type']);

        if ($missing !== []) {
            return self::error('Required parameters are missing: '.implode(', ', $missing).'.');
        }

        if ($this->scenario->state()->get('customers', (string) $input['customer-id']) === null) {
            return self::error('Customer '.$input['customer-id'].' does not exist in this account.');
        }

        $invalid = self::invalidAddress($input);

        if (! in_array($input['type'], self::CONTACT_TYPES, true)) {
            $invalid[] = 'type=Unsupported contact type';
        }

        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) {
            $invalid[] = 'emailaddr=The email address is not valid';
        }

        if ($invalid !== []) {
            return self::error('{'.implode(', ', $invalid).'}');
        }

        $id = (string) (90_000_000 + $this->scenario->state()->increment('ids', 'contact'));
        $this->scenario->state()->put('contacts', $id, [
            'entityid' => $id, 'name' => (string) $input['name'], 'company' => (string) $input['company'], 'type' => (string) $input['type'],
            'emailaddr' => (string) $input['email'], 'telnocc' => (string) $input['phone-cc'], 'telno' => (string) $input['phone'],
            'address1' => (string) $input['address-line-1'], 'address2' => (string) ($input['address-line-2'] ?? ''), 'city' => (string) $input['city'],
            'state' => (string) ($input['state'] ?? ''), 'country' => strtoupper((string) $input['country']), 'zip' => (string) $input['zipcode'],
            'currentstatus' => 'Active', 'customerid' => (string) $input['customer-id'],
        ]);

        return self::value((int) $id);
    }

    /**
     * GET contacts/details.json?contact-id=
     *
     * @param  array<array-key, mixed>  $input
     */
    public function contactDetails(array $input): Response
    {
        $contact = $this->scenario->state()->get('contacts', (string) ($input['contact-id'] ?? ''));

        return is_array($contact) ? Response::json($contact) : self::error('Contact '.($input['contact-id'] ?? '').' does not exist in this account.');
    }

    /**
     * POST domains/register.json. A premium name needs `attr premium=true`; a TLD with required attributes refuses an
     * order without them. With an SLD starting `slow-` the registry answers later: actionstatus PendingExecution and
     * the order InActive until settle_order.
     */
    public function register(Request $request): Response
    {
        $input = $request->input();
        $missing = self::missing($input, ['domain-name', 'years', 'customer-id', 'reg-contact-id', 'admin-contact-id', 'tech-contact-id', 'billing-contact-id', 'invoice-option', 'auto-renew']);

        if ($missing !== [] || $request->values('ns') === []) {
            return self::error('Required parameters are missing: '.implode(', ', $request->values('ns') === [] ? [...$missing, 'ns'] : $missing).'.');
        }

        $name = strtolower((string) $input['domain-name']);
        [, $tld] = self::split($name);
        $years = (int) $input['years'];
        $attributes = self::attributes($input);

        if ($tld === null) {
            return self::error('The extension of '.$name.' is not offered by this registrar.');
        }

        if ($years < 1 || $years > 10) {
            return self::error('{years=Choose a registration term of 1 to 10 years}');
        }

        if (($invalid = $this->invalidOrderInput($request, $tld)) !== null) {
            return $invalid;
        }

        foreach (self::TLDS[$tld]['attributes'] ?? [] as $attribute => $pattern) {
            if (preg_match($pattern, $attributes[$attribute] ?? '') !== 1) {
                $given = $attributes[$attribute] ?? '';

                return self::error('The .'.$tld.' registry needs the extra detail '.$attribute.' for this order'.($given === '' ? '.' : ': "'.$given.'" is not accepted.'));
            }
        }

        return $this->scenario->state()->transaction(function () use ($request, $name, $tld, $years, $attributes): Response {
            if ($this->taken($name)) {
                return self::error('Domain '.$name.' is already registered and not available for registration.');
            }

            $premium = self::premium($name);

            if ($premium && ($attributes['premium'] ?? '') !== 'true') {
                return self::error($name.' is a premium domain name; send the premium attribute to accept its price.');
            }

            $price = ($premium ? self::PREMIUM_PRICE : self::TLDS[$tld]['price']) * $years;

            if (($funds = $this->charge($price)) !== null) {
                return $funds;
            }

            $async = str_starts_with($name, 'slow-');
            $order = $this->newOrder($request, $name, $tld, 'register', $async ? 'InActive' : 'Active', $async ? null : self::years(time(), $years));
            $order = [...$order, 'years' => $years, 'attributes' => $attributes, 'charged' => $price];
            $this->saveOrder($order);

            return $this->action($order, 'AddNewDomain', 'Registration of '.$name.' for '.$years.' '.($years === 1 ? 'year' : 'years'), $async ? 'PendingExecution' : 'Success', $async ? 'The registry has not confirmed the registration yet.' : 'Domain registration completed successfully.');
        });
    }

    /**
     * POST domains/transfer.json. The name must be held at another registrar. Without an auth code (where the TLD
     * allows that) the order waits for one (submit-auth-code); with the right code it waits for the registry
     * (`complete_transfer`). The answer says `"status": "NoError"` while the transfer waits.
     */
    public function transfer(Request $request): Response
    {
        $input = $request->input();
        $missing = self::missing($input, ['domain-name', 'customer-id', 'reg-contact-id', 'admin-contact-id', 'tech-contact-id', 'billing-contact-id', 'invoice-option', 'auto-renew']);

        if ($missing !== []) {
            return self::error('Required parameters are missing: '.implode(', ', $missing).'.');
        }

        $name = strtolower((string) $input['domain-name']);
        [, $tld] = self::split($name);
        $code = (string) ($input['auth-code'] ?? '');

        if ($tld === null) {
            return self::error('The extension of '.$name.' is not offered by this registrar.');
        }

        if (($invalid = $this->invalidOrderInput($request, $tld, nameserversRequired: false)) !== null) {
            return $invalid;
        }

        return $this->scenario->state()->transaction(function () use ($request, $name, $tld, $code): Response {
            $foreign = $this->foreign($name);
            $existing = $this->orderByName($name);

            if ($existing !== null && ! in_array($existing['currentstatus'], ['Deleted', 'Archived'], true)) {
                return self::error('An order for '.$name.' already exists in this account.');
            }

            if ($foreign === null) {
                return self::error($name.' is not registered, so it cannot be transferred.');
            }

            if ($code === '' && (self::TLDS[$tld]['auth_code_upfront'] ?? false)) {
                return self::error('The .'.$tld.' registry needs the authorization code with the transfer order.');
            }

            if ($code !== '' && ! hash_equals((string) $foreign['auth_code'], $code)) {
                return self::error('The authorization code for '.$name.' is not valid.');
            }

            if (($funds = $this->charge(self::TLDS[$tld]['price'])) !== null) {
                return $funds;
            }

            $order = [...$this->newOrder($request, $name, $tld, 'transfer', 'InActive', null), 'transfer_stage' => $code === '' ? 'awaiting_auth_code' : 'awaiting_registry', 'charged' => self::TLDS[$tld]['price']];
            $this->saveOrder($order);
            $waiting = $code === '' ? 'Waiting for the authorization code.' : 'Waiting for the current registrar to release the domain.';

            return $this->action($order, 'AddTransferDomain', 'Transfer of '.$name, 'PendingExecution', $waiting, ['status' => 'NoError']);
        });
    }

    /**
     * POST domains/transfer/submit-auth-code.json: answers "success" and moves the transfer on to the registry.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function submitAuthCode(array $input): Response
    {
        return $this->scenario->state()->transaction(function () use ($input): Response {
            $order = $this->order((string) ($input['order-id'] ?? ''));

            if ($order === null || ($order['transfer_stage'] ?? null) !== 'awaiting_auth_code') {
                return self::error('No transfer waiting for an authorization code has this order id.');
            }

            if (! hash_equals((string) ($this->foreign($order['domainname'])['auth_code'] ?? ''), (string) ($input['auth-code'] ?? ''))) {
                return self::error('The authorization code for '.$order['domainname'].' is not valid.');
            }

            $this->saveOrder([...$order, 'transfer_stage' => 'awaiting_registry']);

            return self::value('success');
        });
    }

    /**
     * GET domains/validate-transfer.json: true when the name is held elsewhere and could move here.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function validateTransfer(array $input): Response
    {
        $name = strtolower((string) ($input['domain-name'] ?? ''));

        $order = $this->orderByName($name);

        // The same test transfer() applies: held elsewhere, and no order here that still holds the name.
        return self::value($this->foreign($name) !== null && ($order === null || in_array($order['currentstatus'], ['Deleted', 'Archived'], true)));
    }

    /**
     * POST domains/renew.json. exp-date must be the order's current expiry (epoch), which also refuses a second renewal
     * sent with the old date.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function renew(array $input): Response
    {
        $missing = self::missing($input, ['order-id', 'years', 'exp-date', 'invoice-option', 'auto-renew']);

        if ($missing !== []) {
            return self::error('Required parameters are missing: '.implode(', ', $missing).'.');
        }

        return $this->scenario->state()->transaction(function () use ($input): Response {
            $order = $this->order((string) $input['order-id']);
            $years = (int) $input['years'];

            if ($order === null || ! in_array($order['currentstatus'], ['Active', 'Suspended'], true)) {
                return self::error('No active domain order has the id '.$input['order-id'].'.');
            }

            if ((string) $input['exp-date'] !== (string) $order['endtime']) {
                return self::error('The expiry date sent does not match the current expiry date of '.$order['domainname'].'.');
            }

            if ($years < 1 || $years > 10) {
                return self::error('{years=Choose a renewal term of 1 to 10 years}');
            }

            if (($funds = $this->charge((self::premium($order['domainname']) ? self::PREMIUM_PRICE : self::TLDS[$order['tld']]['price']) * $years)) !== null) {
                return $funds;
            }

            $order = [...$order, 'endtime' => (string) self::years((int) $order['endtime'], $years)];
            $this->saveOrder($order);

            return $this->action($order, 'RenewDomain', 'Renewal of '.$order['domainname'].' for '.$years.' '.($years === 1 ? 'year' : 'years'), 'Success', 'Domain renewed successfully.');
        });
    }

    /**
     * POST domains/restore.json: an order in Pending Delete Restorable becomes Active again (the expiry is unchanged).
     *
     * @param  array<array-key, mixed>  $input
     */
    public function restore(array $input): Response
    {
        return $this->scenario->state()->transaction(function () use ($input): Response {
            $order = $this->order((string) ($input['order-id'] ?? ''));

            if ($order === null || $order['currentstatus'] !== 'Pending Delete Restorable') {
                return self::error('No order in Pending Delete Restorable has the id '.($input['order-id'] ?? '').'.');
            }

            if (($funds = $this->charge(8_000)) !== null) {
                return $funds;
            }

            $order = [...$order, 'currentstatus' => 'Active'];
            $this->saveOrder($order);

            return $this->action($order, 'RestoreDomain', 'Restoration of '.$order['domainname'], 'Success', 'Domain restored successfully.');
        });
    }

    /**
     * GET domains/details-by-name.json with one or more `options` groups (All, OrderDetails, ContactIds, NsDetails,
     * DomainStatus), case-sensitive as in the API.
     */
    public function details(Request $request): Response
    {
        $order = $this->orderByName(strtolower((string) ($request->input()['domain-name'] ?? '')));
        $options = $request->values('options');

        if ($order === null) {
            return self::error('No domain order exists for '.($request->input()['domain-name'] ?? '').' in this account.');
        }

        if ($options === [] || array_diff($options, ['All', 'OrderDetails', 'ContactIds', 'NsDetails', 'DomainStatus']) !== []) {
            return self::error('{options=Choose from All, OrderDetails, ContactIds, NsDetails or DomainStatus}');
        }

        $all = in_array('All', $options, true);
        $answer = ['orderid' => $order['orderid'], 'entityid' => $order['orderid'], 'domainname' => $order['domainname'], 'description' => $order['domainname'], 'currentstatus' => $order['currentstatus']];

        if ($all || in_array('OrderDetails', $options, true)) {
            $answer += [
                'orderstatus' => $order['transferlock'] ? ['transferlock'] : [],
                'productkey' => self::TLDS[$order['tld']]['key'],
                'creationtime' => $order['creationtime'], 'endtime' => $order['endtime'], 'customerid' => $order['customerid'],
                'isImmediateReseller' => 'true', 'domsecret' => $order['domsecret'], 'isprivacyprotected' => $order['privacy'] ? 'true' : 'false',
                'recurring' => 'false',
            ];

            if (self::TLDS[$order['tld']]['privacy']) {
                $answer['privacyprotectedallowed'] = 'true';
            }
        }

        if ($all || in_array('ContactIds', $options, true)) {
            $answer += ['registrantcontactid' => $order['contacts']['reg'], 'admincontactid' => $order['contacts']['admin'], 'techcontactid' => $order['contacts']['tech'], 'billingcontactid' => $order['contacts']['billing']];
        }

        if ($all || in_array('NsDetails', $options, true)) {
            $answer['noOfNameServers'] = (string) count($order['ns']);

            foreach (array_values($order['ns']) as $index => $nameserver) {
                $answer['ns'.($index + 1)] = $nameserver;
            }
        }

        if ($all || in_array('DomainStatus', $options, true)) {
            $answer += ['domainstatus' => $order['domainstatus'], 'orderstatus' => $order['transferlock'] ? ['transferlock'] : []];
        }

        return Response::json($answer);
    }

    /**
     * GET domains/details.json?order-id= with `options` groups, as details-by-name but for an order id. An order of
     * another reseller is reported as missing, as the real API does for ids outside the caller's account.
     */
    public function detailsById(Request $request): Response
    {
        $order = $this->order((string) ($request->input()['order-id'] ?? ''));
        $options = $request->values('options');

        if ($order === null || ($order['resellerid'] ?? ResellerClubSimulator::RESELLER_ID) !== ResellerClubSimulator::RESELLER_ID) {
            return self::error('No domain order exists with order id '.($request->input()['order-id'] ?? '').' in this account.');
        }

        if ($options === [] || array_diff($options, ['All', 'OrderDetails']) !== []) {
            return self::error('{options=Choose from All or OrderDetails}');
        }

        return Response::json([
            'orderid' => $order['orderid'], 'entityid' => $order['orderid'], 'domainname' => $order['domainname'], 'description' => $order['domainname'],
            'currentstatus' => $order['currentstatus'], 'resellerid' => ResellerClubSimulator::RESELLER_ID, 'customerid' => $order['customerid'],
            'creationtime' => $order['creationtime'], 'endtime' => $order['endtime'], 'productkey' => self::TLDS[$order['tld']]['key'],
            'domsecret' => $order['domsecret'], 'isprivacyprotected' => $order['privacy'] ? 'true' : 'false',
        ]);
    }

    /**
     * An order that already exists in the account (control action `add_order`), not placed through the API.
     *
     * @return array<string, mixed>
     */
    public function importOrder(string $name, string $customerId, string $status, string $resellerId, int $expiresInDays): array
    {
        [, $tld] = self::split($name);

        if ($tld === null || ! in_array($status, self::STATUSES, true) || $customerId === '') {
            throw new \InvalidArgumentException('Give a domain with an offered extension, a customer_id and a known status.');
        }

        $order = [
            'orderid' => (string) (80_000_000 + $this->scenario->state()->increment('ids', 'order')), 'domainname' => $name, 'tld' => $tld, 'kind' => 'register',
            'customerid' => $customerId, 'resellerid' => $resellerId, 'contacts' => ['reg' => '0', 'admin' => '0', 'tech' => '0', 'billing' => '0'], 'ns' => [],
            'currentstatus' => $status, 'creationdt' => (string) time(), 'creationtime' => (string) time(), 'endtime' => (string) (time() + $expiresInDays * 86_400),
            'transferlock' => false, 'privacy' => false, 'domainstatus' => [], 'domsecret' => self::authCode(), 'transfer_stage' => null,
        ];
        $this->saveOrder($order);

        return $order;
    }

    /**
     * GET domains/orderid.json?domain-name=: the order id as a bare number.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function orderId(array $input): Response
    {
        $order = $this->orderByName(strtolower((string) ($input['domain-name'] ?? '')));

        return $order === null ? self::error('No domain order exists for '.($input['domain-name'] ?? '').' in this account.') : self::value((int) $order['orderid']);
    }

    /**
     * GET domains/search.json: `no-of-records` (10 to 500) and `page-no` are required; filters by domain-name (exact
     * here), customer-id, status and creation-date-start. Rows are keyed "1", "2", … with dotted column names.
     */
    public function search(Request $request): Response
    {
        $input = $request->input();
        $perPage = (int) ($input['no-of-records'] ?? 0);
        $page = (int) ($input['page-no'] ?? 0);

        if ($perPage < 10 || $perPage > 500 || $page < 1) {
            return self::error('{no-of-records=Ask for 10 to 500 records, page-no=Pages start at 1}');
        }

        $rows = array_values(array_filter($this->scenario->state()->all('orders'), function (array $order) use ($input, $request): bool {
            return (! isset($input['domain-name']) || $order['domainname'] === strtolower((string) $input['domain-name']))
                && (! isset($input['customer-id']) || $order['customerid'] === (string) $input['customer-id'])
                && ($request->values('status') === [] || in_array($order['currentstatus'], $request->values('status'), true))
                && (! isset($input['creation-date-start']) || (int) $order['creationdt'] > (int) $input['creation-date-start']);
        }));
        usort($rows, fn (array $a, array $b): int => (int) $a['orderid'] <=> (int) $b['orderid']);
        $answer = ['recsonpage' => '0', 'recsindb' => (string) count($rows)];

        foreach (array_slice($rows, ($page - 1) * $perPage, $perPage) as $index => $order) {
            $answer[(string) ($index + 1)] = [
                'entity.description' => $order['domainname'], 'orders.orderid' => $order['orderid'], 'entity.customerid' => $order['customerid'],
                'orders.creationdt' => $order['creationdt'], 'orders.creationtime' => $order['creationtime'], 'orders.endtime' => $order['endtime'],
                'entity.currentstatus' => $order['currentstatus'], 'entitytype.entitytypekey' => self::TLDS[$order['tld']]['key'],
                'orders.transferlock' => $order['transferlock'] ? '1' : '0', 'orders.privacyprotection' => $order['privacy'] ? '1' : '0',
            ];
            $answer['recsonpage'] = (string) ($index + 1);
        }

        return Response::json($answer);
    }

    /** POST domains/modify-ns.json: 1 to 13 hostnames, repeated `ns`. */
    public function modifyNameservers(Request $request): Response
    {
        $nameservers = array_map('strtolower', $request->values('ns'));

        if ($nameservers === [] || count($nameservers) > 13 || array_filter($nameservers, fn (string $host): bool => preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/', $host) !== 1) !== []) {
            return self::error('{ns=Send 1 to 13 valid name server host names}');
        }

        return $this->changeOrder($request->input(), 'ModNS', 'Name server change', fn (array $order): array => [...$order, 'ns' => $nameservers]);
    }

    /**
     * POST domains/enable-theft-protection.json and disable-theft-protection.json.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function theftProtection(array $input, bool $enabled): Response
    {
        return $this->changeOrder($input, $enabled ? 'AddTheftProtection' : 'DelTheftProtection', ($enabled ? 'Enable' : 'Disable').' theft protection', fn (array $order): array => [...$order, 'transferlock' => $enabled]);
    }

    /**
     * GET domains/locks.json?order-id=: `transferlock` and `customerlock` only while the lock is on, otherwise {}.
     *
     * @param  array<array-key, mixed>  $input
     */
    public function locks(array $input): Response
    {
        $order = $this->order((string) ($input['order-id'] ?? ''));

        if ($order === null) {
            return self::error('No domain order has the id '.($input['order-id'] ?? '').'.');
        }

        return $order['transferlock'] ? Response::json(['transferlock' => true, 'customerlock' => true]) : new Response(200, ['Content-Type' => 'application/json'], '{}');
    }

    /**
     * POST domains/modify-privacy-protection.json: refused for extensions without privacy (.in, .co.uk, …).
     *
     * @param  array<array-key, mixed>  $input
     */
    public function privacy(array $input): Response
    {
        $order = $this->order((string) ($input['order-id'] ?? ''));

        if ($order !== null && ! self::TLDS[$order['tld']]['privacy']) {
            return self::error('Privacy protection is not offered for .'.$order['tld'].' domain names.');
        }

        if (! in_array($input['protect-privacy'] ?? null, ['true', 'false'], true) || ($input['reason'] ?? '') === '') {
            return self::error('{protect-privacy=Send true or false, reason=Give a reason for the change}');
        }

        return $this->changeOrder($input, 'ModPrivacy', 'Privacy protection change', fn (array $order): array => [...$order, 'privacy' => $input['protect-privacy'] === 'true']);
    }

    /**
     * POST domains/modify-contact.json: the four contact ids, each of the order's customer (or -1 where the TLD has
     * no such role). A registrant change on a gTLD starts the 60-day transfer lock.
     */
    public function modifyContact(Request $request): Response
    {
        $order = $this->order((string) ($request->input()['order-id'] ?? ''));

        if ($order === null) {
            return self::error('No domain order has the id '.($request->input()['order-id'] ?? '').'.');
        }

        if (($invalid = $this->invalidContacts($request->input(), $order['customerid'], $order['tld'])) !== null) {
            return $invalid;
        }

        $input = $request->input();
        $contacts = ['reg' => (string) $input['reg-contact-id'], 'admin' => (string) $input['admin-contact-id'], 'tech' => (string) $input['tech-contact-id'], 'billing' => (string) $input['billing-contact-id']];
        $registrantChanged = $contacts['reg'] !== $order['contacts']['reg'] && ! str_contains($order['tld'], '.') && ! in_array($order['tld'], ['in', 'ca'], true);

        return $this->changeOrder($input, 'ModContact', 'Contact change', fn (array $order): array => [
            ...$order,
            'contacts' => $contacts,
            'domainstatus' => $registrantChanged ? array_values(array_unique([...$order['domainstatus'], 'sixtydaylock'])) : $order['domainstatus'],
        ]);
    }

    /**
     * Settle an order the registry answered asynchronously: a `slow-` registration, or a transfer waiting for the
     * registry. `active` makes it Active with an expiry; `failed` deletes it.
     */
    public function settle(string $name, string $outcome): string
    {
        return $this->scenario->state()->transaction(function () use ($name, $outcome): string {
            $order = $this->orderByName($name);

            if ($order === null || $order['currentstatus'] !== 'InActive' || (($order['transfer_stage'] ?? null) === 'awaiting_auth_code' && $outcome === 'active')) {
                throw new \InvalidArgumentException("No order for {$name} is waiting for the registry.");
            }

            $expiry = $order['kind'] === 'transfer'
                ? self::years((int) ($this->foreign($name)['endtime'] ?? time()), 1)
                : self::years(time(), (int) ($order['years'] ?? 1));
            if (! in_array($outcome, ['active', 'failed'], true)) {
                throw new \InvalidArgumentException("Unknown outcome {$outcome}; use active or failed.");
            }

            if ($outcome === 'failed') {
                // The registry refused it: the order ends and its charge goes back to the reseller balance.
                $this->scenario->state()->put('config', 'balance', (int) $this->scenario->state()->get('config', 'balance', 0) + (int) ($order['charged'] ?? 0));
            }

            $order = $outcome === 'active'
                ? [...$order, 'currentstatus' => 'Active', 'creationtime' => (string) time(), 'endtime' => (string) $expiry, 'transfer_stage' => null]
                : [...$order, 'currentstatus' => 'Deleted', 'transfer_stage' => null, 'charged' => 0];
            $this->saveOrder($order);

            if ($outcome === 'active' && $order['kind'] === 'transfer') {
                $this->scenario->state()->delete('registry', $name);
            }

            return $order['currentstatus'];
        });
    }

    /**
     * Move an order to another state, as time or staff at the registrar would (expiry into redemption, deletion).
     */
    public function setStatus(string $name, string $status): string
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown order status {$status}.");
        }

        return $this->scenario->state()->transaction(function () use ($name, $status): string {
            $order = $this->orderByName($name) ?? throw new \InvalidArgumentException("No order for {$name}.");
            $this->saveOrder([...$order, 'currentstatus' => $status]);

            return $status;
        });
    }

    /**
     * The order for a name, the newest when a name was ordered more than once.
     *
     * @return array<string, mixed>|null
     */
    public function orderByName(string $name): ?array
    {
        $id = $this->scenario->state()->get('names', $name);

        return $id === null ? null : $this->order((string) $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function order(string $id): ?array
    {
        $order = $id === '' ? null : $this->scenario->state()->get('orders', $id);

        return is_array($order) ? $order : null;
    }

    /**
     * A name held at another registrar: its auth code and registry expiry.
     *
     * @return array{auth_code: string, endtime: int}|null
     */
    public function foreign(string $name): ?array
    {
        $held = $this->scenario->state()->get('registry', $name);

        return is_array($held) ? ['auth_code' => (string) ($held['auth_code'] ?? ''), 'endtime' => (int) ($held['endtime'] ?? 0)] : null;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function changeOrder(array $input, string $type, string $description, \Closure $change): Response
    {
        return $this->scenario->state()->transaction(function () use ($input, $type, $description, $change): Response {
            $order = $this->order((string) ($input['order-id'] ?? ''));

            if ($order === null || ! in_array($order['currentstatus'], ['Active', 'Suspended'], true)) {
                return self::error('No active domain order has the id '.($input['order-id'] ?? '').'.');
            }

            $order = $change($order);
            $this->saveOrder($order);

            return $this->action($order, $type, $description.' for '.$order['domainname'], 'Success', 'The change was applied.');
        });
    }

    /**
     * The checks register and transfer share: the customer, its four contacts, and the name servers.
     */
    private function invalidOrderInput(Request $request, string $tld, bool $nameserversRequired = true): ?Response
    {
        $input = $request->input();

        if (! in_array($input['invoice-option'], ['NoInvoice', 'PayInvoice', 'KeepInvoice', 'OnlyAdd'], true) || ! in_array($input['auto-renew'], ['true', 'false'], true)) {
            return self::error('{invoice-option=Choose NoInvoice, PayInvoice, KeepInvoice or OnlyAdd, auto-renew=Send true or false}');
        }

        if ($this->scenario->state()->get('customers', (string) $input['customer-id']) === null) {
            return self::error('Customer '.$input['customer-id'].' does not exist in this account.');
        }

        $nameservers = $request->values('ns');

        if (($nameserversRequired || $nameservers !== []) && (count($nameservers) > 13 || array_filter($nameservers, fn (string $host): bool => preg_match('/^(?=.{1,253}$)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i', $host) !== 1) !== [])) {
            return self::error('{ns=Send 1 to 13 valid name server host names}');
        }

        return $this->invalidContacts($input, (string) $input['customer-id'], $tld);
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private function invalidContacts(array $input, string $customerId, string $tld): ?Response
    {
        $absent = self::TLDS[$tld]['absent_roles'] ?? [];

        foreach (['reg' => 'registrant', 'admin' => 'admin', 'tech' => 'tech', 'billing' => 'billing'] as $param => $role) {
            $id = (string) ($input[$param.'-contact-id'] ?? '');

            if (in_array($role, $absent, true)) {
                if ($id !== '-1') {
                    return self::error('.'.$tld.' domain names have no '.$role.' contact; send -1 for '.$param.'-contact-id.');
                }

                continue;
            }

            $contact = $this->scenario->state()->get('contacts', $id);

            if (! is_array($contact) || $contact['customerid'] !== $customerId) {
                return self::error('Contact '.$id.' ('.$param.'-contact-id) does not belong to customer '.$customerId.'.');
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function newOrder(Request $request, string $name, string $tld, string $kind, string $status, ?int $expiry): array
    {
        $input = $request->input();
        $id = (string) (80_000_000 + $this->scenario->state()->increment('ids', 'order'));

        return [
            'orderid' => $id, 'domainname' => $name, 'tld' => $tld, 'kind' => $kind, 'customerid' => (string) $input['customer-id'],
            'contacts' => ['reg' => (string) $input['reg-contact-id'], 'admin' => (string) $input['admin-contact-id'], 'tech' => (string) $input['tech-contact-id'], 'billing' => (string) $input['billing-contact-id']],
            'ns' => array_map('strtolower', $request->values('ns')), 'currentstatus' => $status, 'creationdt' => (string) time(),
            'creationtime' => $expiry === null ? '' : (string) time(), 'endtime' => $expiry === null ? '' : (string) $expiry,
            'transferlock' => false, 'privacy' => ($input['protect-privacy'] ?? 'false') === 'true', 'domainstatus' => [],
            'domsecret' => self::authCode(), 'transfer_stage' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function saveOrder(array $order): void
    {
        $this->scenario->state()->put('orders', $order['orderid'], $order);
        $this->scenario->state()->put('names', $order['domainname'], $order['orderid']);
    }

    /**
     * The hash map every mutating domain call answers.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, string>  $extra
     */
    private function action(array $order, string $type, string $description, string $status, string $statusDescription, array $extra = []): Response
    {
        // A status set with `next_actionstatus` replaces the next answer's (the call itself runs as usual).
        $override = $this->scenario->state()->get('config', 'next_actionstatus');

        if (is_string($override)) {
            $this->scenario->state()->delete('config', 'next_actionstatus');
        }

        return Response::json([...$extra,
            'description' => $order['domainname'], 'entityid' => $order['orderid'], 'actiontype' => $type, 'actiontypedesc' => $description,
            'eaqid' => (string) (70_000_000 + $this->scenario->state()->increment('ids', 'action')), 'actionstatus' => is_string($override) ? $override : $status, 'actionstatusdesc' => $statusDescription,
        ]);
    }

    /** Take $cents from the reseller balance, or the insufficient-funds refusal. */
    private function charge(int $cents): ?Response
    {
        $balance = (int) $this->scenario->state()->get('config', 'balance', 0);

        if ($balance < $cents) {
            return self::error('Insufficient funds in the reseller account: '.self::dollars($cents).' USD is needed and '.self::dollars($balance).' USD is available.');
        }

        $this->scenario->state()->put('config', 'balance', $balance - $cents);

        return null;
    }

    private function taken(string $name): bool
    {
        $order = $this->orderByName($name);

        return $this->foreign($name) !== null || ($order !== null && ! in_array($order['currentstatus'], ['Deleted', 'Archived'], true));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerByUsername(string $username): ?array
    {
        foreach ($this->scenario->state()->all('customers') as $customer) {
            if ($customer['username'] === strtolower($username)) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * `attr-name1`/`attr-value1`, … as a map; numbering must run from 1 without gaps, as the API requires.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, string>
     */
    private static function attributes(array $input): array
    {
        $attributes = [];

        for ($i = 1; isset($input['attr-name'.$i]); $i++) {
            $attributes[(string) $input['attr-name'.$i]] = (string) ($input['attr-value'.$i] ?? '');
        }

        return $attributes;
    }

    /**
     * The name split into its SLD and a TLD this registry sells (the longest match), or [name, null].
     *
     * @return array{0: string, 1: string|null}
     */
    public static function split(string $name): array
    {
        $tlds = array_keys(self::TLDS);
        usort($tlds, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($tlds as $tld) {
            $sld = str_ends_with($name, '.'.$tld) ? substr($name, 0, -strlen($tld) - 1) : '';

            if ($sld !== '' && ! str_contains($sld, '.')) {
                return [$sld, $tld];
            }
        }

        return [$name, null];
    }

    public static function premium(string $name): bool
    {
        return str_starts_with($name, 'premium-');
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $required
     * @return list<string>
     */
    private static function missing(array $input, array $required): array
    {
        return array_values(array_filter($required, fn (string $field): bool => ! isset($input[$field]) || trim((string) $input[$field]) === ''));
    }

    /**
     * Address checks shared by customers and contacts, keyed by the API's own field names.
     *
     * @param  array<array-key, mixed>  $input
     * @return list<string>
     */
    private static function invalidAddress(array $input): array
    {
        $invalid = [];

        if (preg_match('/^\d{1,3}$/', (string) $input['phone-cc']) !== 1) {
            $invalid[] = 'telnocc=The country calling code must be 1 to 3 digits';
        }

        if (preg_match('/^\d{4,12}$/', (string) $input['phone']) !== 1) {
            $invalid[] = 'telno=The phone number must be 4 to 12 digits';
        }

        if (preg_match('/^[A-Za-z]{2}$/', (string) $input['country']) !== 1) {
            $invalid[] = 'country=Use a two-letter ISO country code';
        }

        if (strlen((string) $input['zipcode']) > 10) {
            $invalid[] = 'zip=The postal code is longer than 10 characters';
        }

        return $invalid;
    }

    private static function years(int $from, int $years): int
    {
        return (int) (new \DateTimeImmutable('@'.$from))->modify("+{$years} years")->format('U');
    }

    private static function dollars(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function authCode(): string
    {
        return 'Rc'.bin2hex(random_bytes(5)).'#9';
    }

    /** A bare JSON value (an id, `true`, "success"), as the API answers calls that return one. */
    private static function value(int|bool|string $value): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($value));
    }

    public static function error(string $message): Response
    {
        return Response::json(['status' => 'ERROR', 'message' => $message], 500);
    }
}
