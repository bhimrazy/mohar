<?php

test('web responses carry the security headers with a script nonce', function () {
    $response = $this->get(route('login'))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");

    preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $nonce);
    expect($response->getContent())->toContain('nonce="'.$nonce[1].'"');

    $response->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
});

test('HSTS is sent over HTTPS', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('pages with a token in the URL never send a referrer', function () {
    $this->get(route('platform.invitations.show', 'some-token'))->assertHeader('Referrer-Policy', 'no-referrer');
});

test('security headers are sent on 404s and authentication redirects', function () {
    $this->get('/definitely-not-a-page')->assertNotFound()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($this->get('/definitely-not-a-page')->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");

    $this->get(route('admin.dashboard'))->assertRedirect(route('login'))
        ->assertHeader('X-Frame-Options', 'DENY');
    expect($this->get(route('admin.dashboard'))->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
});
