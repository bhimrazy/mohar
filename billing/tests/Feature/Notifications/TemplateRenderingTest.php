<?php

use App\Modules\Core\Models\Client;
use App\Modules\Core\Support\Settings;
use App\Modules\Notifications\Data\EmailRequest;
use App\Modules\Notifications\Enums\TemplateAudience;
use App\Modules\Notifications\Models\EmailMessage;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Rendering\TemplateEngine;
use App\Modules\Notifications\Rendering\TemplateSyntaxError;
use App\Modules\Notifications\Services\EmailComposer;
use App\Modules\Notifications\Services\NotificationMailer;
use App\Modules\Notifications\Services\RecipientResolver;
use App\Modules\Notifications\Services\SampleContext;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';

it('escapes merge fields in the HTML part (criterion 6)', function () {
    $engine = new TemplateEngine;

    $html = $engine->render('Hi {{ client.first_name }}', ['client' => ['first_name' => '<b>Ann</b> [x](http://evil.test)']])->output;

    expect($html)->toContain('&lt;b&gt;Ann&lt;/b&gt;')
        ->not->toContain('<b>Ann</b>')
        ->toContain('\[x\]');

    $composed = app(EmailComposer::class)->composeSource('Hello', 'Hi {{ client.first_name }}', ['client' => ['first_name' => '<b>Ann</b>']], 'en');
    expect($composed->html)->toContain('&lt;b&gt;Ann&lt;/b&gt;')->not->toContain('<b>Ann</b>');
});

it('only leaves pre-sanitised *_html fields unescaped', function () {
    $result = (new TemplateEngine)->render('{{ reply.body_html | raw }} {{ client.name | raw }}', [
        'reply' => ['body_html' => '<p>ok</p>'],
        'client' => ['name' => '<i>x</i>'],
    ]);

    expect($result->output)->toContain('<p>ok</p>')->toContain('&lt;i&gt;x&lt;/i&gt;')
        ->and($result->warnings)->toHaveCount(1);
});

it('renders conditionals, loops and filters without executing code', function () {
    $source = <<<'TPL'
{% if invoice.paid %}paid{% else %}open{% endif %} · {% for item in items %}{{ item.name | upper }};{% endfor %} · {{ amount | money('USD') }} · {{ missing | default('n/a') }} · {{ day | date('d/m/Y') }}
TPL;

    $result = (new TemplateEngine)->render($source, [
        'invoice' => ['paid' => false],
        'items' => [['name' => 'a'], ['name' => 'b']],
        'amount' => 1250,
        'day' => '2026-10-18',
    ], false);

    expect($result->output)->toBe('open · A;B; · $12.50 · n/a · 18/10/2026');
});

it('treats PHP and Blade syntax in templates as plain text', function () {
    $output = (new TemplateEngine)->render('<?php echo 1; ?> @php echo 2; @endphp {{ config }}', [], false)->output;

    expect($output)->toBe('<?php echo 1; ?> @php echo 2; @endphp ');
});

it('reports unknown variables as warnings and renders them empty', function () {
    $result = (new TemplateEngine)->render('Hi {{ client.nickname }}!', ['client' => ['first_name' => 'Ann']]);

    expect($result->output)->toBe('Hi !')
        ->and($result->warnings)->toBe(['Unknown variable "client.nickname".']);
});

it('rejects broken syntax', function (string $source) {
    expect(fn () => (new TemplateEngine)->validate($source))->toThrow(TemplateSyntaxError::class);
})->with([
    'unclosed if (criterion 7)' => ['{% if invoice.balance %}Pay now'],
    'stray endfor' => ['{% endfor %}'],
    'unknown tag' => ['{% include "x" %}'],
    'unknown filter' => ['{{ name | system }}'],
    'unclosed placeholder' => ['Hi {{ client.first_name'],
    'method call' => ['{{ client.delete() }}'],
]);

it('renders every seeded template from the sample fixtures without warnings (criterion 20)', function () {
    seedEmailTemplates();
    $composer = app(EmailComposer::class);
    $samples = app(SampleContext::class);

    $warnings = EmailTemplate::query()->with('translations')->orderBy('key')->get()
        ->mapWithKeys(fn (EmailTemplate $template): array => [
            $template->key => $composer->compose($template, 'en', $samples->build($template->audience === TemplateAudience::Staff))->warnings,
        ])
        ->filter();

    expect(EmailTemplate::query()->count())->toBeGreaterThan(60)
        ->and($warnings->all())->toBe([]);
});

it('uses the recipient language when a translation exists, else the default locale (criterion 8)', function () {
    Mail::fake();
    $template = EmailTemplate::factory()->withBody('Hello {{ client.first_name }}', 'Body')->create(['key' => 'custom.greeting']);
    $template->translations()->create(['locale' => 'de', 'subject' => 'Hallo {{ client.first_name }}', 'body' => 'Text']);

    $german = Client::factory()->create(['language' => 'de', 'first_name' => 'Jonas']);
    $french = Client::factory()->create(['language' => 'fr', 'first_name' => 'Chloé']);

    foreach ([$german, $french] as $client) {
        app(NotificationMailer::class)->send(new EmailRequest('custom.greeting', [app(RecipientResolver::class)->owner($client)], ['client' => ['first_name' => $client->first_name]], 'locale-test|'.$client->id, $client->id));
    }

    expect(EmailMessage::query()->where('client_id', $german->id)->value('subject'))->toBe('Hallo Jonas')
        ->and(EmailMessage::query()->where('client_id', $french->id)->value('subject'))->toBe('Hello Chloé');
});

it('keeps support subjects in the fixed ticket format', function () {
    seedEmailTemplates();
    $template = EmailTemplate::query()->where('key', 'ticket.reply')->firstOrFail();
    $template->translations()->update(['subject' => 'Something custom']);

    $composed = app(EmailComposer::class)->compose($template->fresh('translations'), 'en', ['ticket' => ['number' => '123456', 'subject' => 'Mail down']]);

    expect($composed->subject)->toBe('[#123456] Mail down');
});

it('formats money with the currency symbol and lakh grouping for INR and NPR', function (string $currency, string $expected) {
    expect((new TemplateEngine)->render("{{ amount | money('{$currency}') }}", ['amount' => 12345600], false)->output)->toBe($expected);
})->with([
    ['USD', '$123,456.00'],
    ['INR', '₹1,23,456.00'],
    ['NPR', 'Rs 1,23,456.00'],
]);

it('formats dates with the configured format and falls back to the default for unknown values', function () {
    $settings = app(Settings::class);
    $render = fn (): string => app(EmailComposer::class)->composeSource('{{ d | date }}', 'x', ['d' => '2026-10-18'], 'en')->subject;

    $settings->set('core.date_format', 'd/m/Y');
    expect($render())->toBe('18/10/2026');

    $settings->set('core.date_format', 'Y-m-d H:i:s <script>');
    expect($render())->toBe('Oct 18, 2026');
});
