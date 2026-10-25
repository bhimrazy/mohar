<?php

/*
 * Secret shapes from the drivers we have or plan (Stripe, Khalti, eSewa, connectIPS, WHM/cPanel, ResellerClub,
 * Namecheap, OpenSRS, EPP) and the references support needs to keep reading. The other shape groups are the
 * RedactorShapes*Test files: pairs and labels, XML elements, void elements, and the time and memory budget.
 */

use App\Support\Integrations\Redactor;

test('secrets in headers are masked, scheme included', function (string $text, string $expected) {
    expect((new Redactor)->maskValues($text, []))->toBe($expected);
})->with([
    'stripe bearer' => ['Authorization: Bearer sk_test_51Habcdefgh', 'Authorization: [redacted]'],
    'khalti key' => ['Authorization: Key live_secret_key_68791341fdd94846a146f0457ff7b455', 'Authorization: [redacted]'],
    'whm api token' => ['Authorization: whm root:ABCDEF0123456789', 'Authorization: [redacted]'],
    'cpanel api token' => ['Authorization: cpanel bob:ABCDEF0123456789', 'Authorization: [redacted]'],
    'basic' => ['Proxy-Authorization: Basic dXNlcjpwYXNz', 'Proxy-Authorization: [redacted]'],
    'api key header' => ["X-Api-Key: KK1\r\nX-Request-Id: req_123", "X-Api-Key: [redacted]\r\nX-Request-Id: req_123"],
    'opensrs signature' => ['X-Username: reseller1 X-Signature: 9f86d081884c7d65', 'X-Username: reseller1 X-Signature: [redacted]'],
    'stripe webhook signature' => ['Stripe-Signature: t=1700000000,v1=abc123,v0=def456', 'Stripe-Signature: [redacted]'],
    'cookie line' => ["Cookie: theme=dark; cpsession=root%3aCPSESS1\r\nHost: whm.test", "Cookie: [redacted]\r\nHost: whm.test"],
    'set-cookie line' => ['Set-Cookie: whostmgrsession=WHM1; path=/; HttpOnly', 'Set-Cookie: [redacted]'],
    'curl credentials, quoted and attached' => ["curl -u 'root:Q1' -uroot:Q2 --user \"bob:Q3\" x", 'curl -u [redacted] -u[redacted] --user [redacted] x'],
    'session label values that are not ids' => ["Session: SESS1\r\nhash: \$2y\$10\$HASH1abc\nSession:\n  SESS2", "Session: [redacted]\r\nhash: [redacted]\nSession:\n  [redacted]"],
    'curl credentials' => ['curl -u root:CURLPW1 https://whm.test:2087 --user=bob:PW2 -u bob', 'curl -u [redacted] https://whm.test:2087 --user=[redacted] -u bob'],
]);

test('secrets in query strings and form bodies are masked, value tails included', function (string $text, string $expected) {
    expect((new Redactor)->maskValues($text, []))->toBe($expected);
})->with([
    'namecheap' => ['https://api.namecheap.com/xml.response?ApiUser=u1&ApiKey=NK1&UserName=u1&ClientIp=1.2.3.4&Command=namecheap.domains.check', 'https://api.namecheap.com/xml.response?ApiUser=u1&ApiKey=[redacted]&UserName=u1&ClientIp=1.2.3.4&Command=namecheap.domains.check'],
    'resellerclub' => ['auth-userid=12345&api-key=RK1&order-id=987', 'auth-userid=12345&api-key=[redacted]&order-id=987'],
    'connectips' => ['MERCHANTID=12&APPID=MER-12-APP-1&TXNID=T1&TXNAMT=1000&TOKEN=c2lnbmVk/YQ==', 'MERCHANTID=12&APPID=MER-12-APP-1&TXNID=T1&TXNAMT=1000&TOKEN=[redacted]'],
    'esewa' => ['total_amount=1130&transaction_uuid=240-1&product_code=EPAYTEST&signed_field_names=total_amount,transaction_uuid,product_code&signature=i94zsd3oXF6ZsSr/kGqT4sSzYQzjj1W/waxjWyRwaME=', 'total_amount=1130&transaction_uuid=240-1&product_code=EPAYTEST&signed_field_names=total_amount,transaction_uuid,product_code&signature=[redacted]'],
    'tail after a semicolon' => ['passwd=ab;cd&domain=a.np', 'passwd=[redacted]&domain=a.np'],
    'tail after a comma' => ['token=abc,def, user=x', 'token=[redacted], user=x'],
    'cookie attribute is not a tail' => ['session=abc; Path=/; HttpOnly', 'session=[redacted]; Path=/; HttpOnly'],
    'command-line flag' => ['whmapi1 createacct --password=S3cr3t username=bob', 'whmapi1 createacct --password=[redacted] username=bob'],
    'padded header' => ['X-Auth-Token:'.str_repeat(' ', 40).'T1', 'X-Auth-Token:'.str_repeat(' ', 40).'[redacted]'],
    'ampersand inside a secret' => ['secret_key=8gBm/:&EnhH.1/q&product_code=EPAYTEST', 'secret_key=[redacted]&product_code=EPAYTEST'],
    'session cookies by name' => ['cpsession=root:CP1 whostmgrsession=W1 JSESSIONID=J1', 'cpsession=[redacted] whostmgrsession=[redacted] JSESSIONID=[redacted]'],
    'long field name' => ['registrant_contact_extended_attributes_for_the_registry_admin_password=LONG1&id=5', 'registrant_contact_extended_attributes_for_the_registry_admin_password=[redacted]&id=5'],
    'array field' => ['password[]=P1&password_confirmation=P1x', 'password[]=[redacted]&password_confirmation=[redacted]'],
    'percent-encoded url' => ['next=https%3A%2F%2Fh.test%2F%3Fapi_key%3DENC1%26a%3Db', 'next=https%3A%2F%2Fh.test%2F%3Fapi_key%3D[redacted]%26a%3Db'],
]);

test('JSON after a label is masked by key: nested, arrays, escaped quotes, numbers', function (string $text, string $expected) {
    expect((new Redactor)->maskValues($text, []))->toBe($expected);
})->with([
    'nested' => ['HTTP 400: {"error":{"password":"a\"b\"c","id":"x1"}}', 'HTTP 400: {"error":{"password":"[redacted]","id":"x1"}}'],
    'array value' => ['Error: {"tokens":["A1","B2"],"count":2}', 'Error: {"tokens":[redacted],"count":2}'],
    'object value' => ['Error: {"credentials":{"user":"u","pass":"p"},"ok":false}', 'Error: {"credentials":[redacted],"ok":false}'],
    'number' => ['Error: {"otp":123456,"id":"x"}', 'Error: {"otp":[redacted],"id":"x"}'],
    'escaped json in text' => ['body={\"password\":\"S1\",\"order\":7}', 'body={\"password\":\"[redacted]\",\"order\":7}'],
    'unterminated' => ['Truncated: {"api_key":"K12', 'Truncated: {"api_key":"[redacted]'],
    'print_r dump' => ["Array\n(\n    [password] => PR1\n    [user] => bob\n)", "Array\n(\n    [password] => [redacted]\n    [user] => bob\n)"],
    'pretty-printed' => ["{\n  \"api_key\":\n    \"PRETTY1\",\n  \"id\": 5\n}x", "{\n  \"api_key\":\n    \"[redacted]\",\n  \"id\": 5\n}x"],
    'html-escaped' => ['<pre>{&quot;password&quot;:&quot;HTML1&quot;,&quot;id&quot;:5}</pre>', '<pre>{&quot;password&quot;:&quot;[redacted]&quot;,&quot;id&quot;:5}</pre>'],
    'php array dump' => ["array ('password' => 'PH1', 'user' => 'u')", "array ('password' => '[redacted]', 'user' => 'u')"],
]);

test('a JSON string value that is itself JSON is redacted by key', function () {
    $raw = (string) json_encode(['body' => (string) json_encode(['password' => 'P1', 'id' => 7]), 'message' => 'Rejected: api-key=K1']);

    expect((new Redactor)->maskValues($raw, []))->not->toContain('P1')->not->toContain('K1')->toContain('\"id\":7');
});

test('XML element values, OpenSRS items, attributes and CDATA are masked', function (string $text, string $expected) {
    expect((new Redactor)->maskValues($text, []))->toBe($expected);
})->with([
    'element' => ['<password>P456</password><user>u1</user>', '<password>[redacted]</password><user>u1</user>'],
    'epp auth info' => ['<domain:authInfo><domain:pw>2fooBAR</domain:pw></domain:authInfo><domain:name>a.com.np</domain:name>', '<domain:authInfo>[redacted]</domain:authInfo><domain:name>a.com.np</domain:name>'],
    'opensrs item' => ['<item key="reg_password">P789</item><item key="domain">a.com</item>', '<item key="reg_password">[redacted]</item><item key="domain">a.com</item>'],
    'cdata' => ['<item key="reg_password"><![CDATA[P<9>9]]></item>', '<item key="reg_password">[redacted]</item>'],
    'cut-off cdata' => ['<password><![CDATA[CD1', '<password>[redacted]'],
    'unclosed element masks the rest (fail closed)' => ['<password>P1<b>more</b><id>5</id>', '<password>[redacted]'],
    'attribute' => ['<login user="root" password="PX1" />', '<login user="root" password="[redacted]" />'],
    'name and value attributes' => ['<param name="api_key" value="V1"/><param name="domain" value="a.np"/>', '<param name="api_key" value="[redacted]"/><param name="domain" value="a.np"/>'],
    'url in an attribute' => ['<a href="https://x.test/cb?token=TQ1&amp;id=5">x</a>', '<a href="https://x.test/cb?token=[redacted]&amp;id=5">x</a>'],
    'pair in element text' => ['<error>Invalid api-key=K9</error>', '<error>Invalid api-key=[redacted]</error>'],
    'epp password change' => ['<login><clID>reg1</clID><pw>OLD1</pw><newPW>NEW1</newPW></login>', '<login><clID>reg1</clID><pw>[redacted]</pw><newPW>[redacted]</newPW></login>'],
    'end tag far after unrelated ones' => ['<password>'.str_repeat('<b>x</b>', 40).'</password><id>5</id>', '<password>[redacted]</password><id>5</id>'],
    'empty element' => ['<password></password><password/>', '<password></password><password/>'],
]);

test('URL userinfo and provider key shapes are masked wherever they appear', function (string $text, string $expected) {
    expect((new Redactor)->maskValues($text, []))->toBe($expected);
})->with([
    'userinfo with a raw @' => ['GET https://user:p@AT1@host.test/x?email=a@b.np', 'GET https://[redacted]@host.test/x?email=a@b.np'],
    'userinfo' => ['GET https://user:pa55@host.test/path failed', 'GET https://[redacted]@host.test/path failed'],
    'stripe key as user' => ['curl https://sk_test_abc:@api.stripe.com/v1/charges', 'curl https://[redacted]@api.stripe.com/v1/charges'],
    'stripe keys' => ['keys sk_live_51Habcdefgh rk_test_51Habcdefgh whsec_abcdefgh123', 'keys [redacted] [redacted] [redacted]'],
    'client secret keeps the intent id' => ['confirm pi_3Nabcdefgh_secret_XYZ12345678', 'confirm pi_3Nabcdefgh_secret_[redacted]'],
    'khalti secret key' => ['using test_secret_key_68791341fdd94846a146f0457ff7b455', 'using [redacted]'],
    'bare bearer token' => ['sent Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig', 'sent Bearer [redacted]'],
]);

test('ids, amounts and references support needs stay readable', function (string $text) {
    expect((new Redactor)->maskValues($text, []))->toBe($text);
})->with([
    'stripe ids' => ['charge ch_3Nabc failed for pi_3Nabcdefgh (cus_P1, evt_1, re_1), amount=5000, currency=npr, pk_live_abcdefgh123'],
    'khalti' => ['pidx=HT6o6PEZRWFJ5ygavzHWd5&total_amount=113000&transaction_id=GFq9PFS7b2iYvL8Lir9oXe&purchase_order_id=INV-42'],
    'esewa' => ['{"transaction_code":"000AE01","status":"COMPLETE","total_amount":1130.0,"transaction_uuid":"240-1","product_code":"EPAYTEST","ref_id":"0001TS9"} received'],
    'resellerclub' => ['Order: entityid=9001, auth-userid=12345, actionstatus: Success, description: Domain a.com.np renewed'],
    'namecheap' => ['<DomainCreateResult Domain="a.com" Registered="true" ChargedAmount="20.87" DomainID="9007" OrderID="196074" TransactionID="380716"/>'],
    'opensrs' => ['<item key="response_code">200</item><item key="id">3735281</item><item key="order_id">1585</item>'],
    'whm' => ['metadata: {"command":"createacct","result":1,"reason":"Account Creation Ok"} user=bob, domain=a.com.np, pkg=starter, ip=203.0.113.4'],
    'times and urls' => ['Rejected at 12:30:45 for https://r.test/a?b=c&id=5, Idempotency-Key: ik_123, X-Request-Id: req_9'],
    'email' => ['contact a@b.com or https://x.test/?email=a@b.com'],
    'reference ids after a Session label' => ['{"message":"Session: cs_live_a1B2c3D4 expired","error":"Session: pi_a1B2c3D4."}'],
    'an empty Cookie header keeps the next line' => ["Cookie:\r\nHost: keepme.test"],
    'void element with a secret name' => ['<!DOCTYPE html><form><input type="password" name="password" value=""><p>Forgot your password?</p>'],
    'flags without credentials' => ['ps -u root; tar -U x'],
    'unchanged json keeps its formatting' => ["{\n  \"id\": \"evt_1\",\n  \"data\": {}\n}"],
]);

test('payment payload text leaves get the string rules too, and ids stay', function () {
    expect((new Redactor)->redactPayment(['id' => 'pi_1', 'amount' => 113000, 'error' => 'Rejected: api-key=K1', 'raw' => '<password>P1</password>']))
        ->toBe(['id' => 'pi_1', 'amount' => 113000, 'error' => 'Rejected: api-key=[redacted]', 'raw' => '<password>[redacted]</password>']);
});
