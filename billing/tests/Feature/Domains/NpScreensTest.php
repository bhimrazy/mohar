<?php

use App\Modules\Domains\Actions\SubmitToRegistrar;
use App\Modules\Domains\Models\Domain;
use App\Modules\Domains\Models\NpDocument;
use App\Modules\Domains\Models\Tld;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The .np request on the domain pages: staff get signed document links and the workflow dates; clients get the
 * step and what is missing, never a document link.
 */
beforeEach(function () {
    Storage::fake(NpDocument::DISK);
    [, $this->client] = actingAsClient();
    $tld = Tld::factory()->extension('.com.np')->priced()->create(['registrar_key' => 'np_manual']);
    $this->domain = Domain::factory()->pending()->named('asha', $tld)->create(['client_id' => $this->client->id, 'registrar_key' => 'np_manual']);
    app(SubmitToRegistrar::class)->handle($this->domain);
    $this->post(route('client.domains.np.documents.store', $this->domain->id), ['applicant_type' => 'organisation', 'kind' => 'request_letter', 'file' => fakePng('letter.png', 10, 10)]);
});

test('clients see the step and the missing documents, without document links', function () {
    $this->get(route('client.domains.show', $this->domain->id))
        ->assertInertia(fn (Assert $page) => $page->component('client/domains/show')
            ->where('np.stage', 'awaiting_documents')
            ->where('np.missing', ['registration_certificate'])
            ->where('np.documents.0.url', null)
            ->missing('np.hostmaster_email'));
});

test('staff see signed document links and whether they may act', function () {
    actingAsStaff(['domains.domains.view', 'domains.np.review']);

    $this->get(route('admin.domains.show', $this->domain))
        ->assertInertia(fn (Assert $page) => $page->component('admin/domains/show')
            ->where('can.np_review', true)
            ->where('np.overdue', false)
            ->where('np.documents.0.url', fn (string $url): bool => str_contains($url, 'signature=') && str_contains($url, 'expires=')));
});
