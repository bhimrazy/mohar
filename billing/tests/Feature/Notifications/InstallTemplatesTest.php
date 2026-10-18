<?php

use App\Modules\Notifications\Install\DefaultTemplates;
use App\Modules\Notifications\Models\EmailTemplate;
use App\Modules\Notifications\Models\EmailTemplateTranslation;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Support\Install\ReferenceDataRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * The default templates are reference data: `php artisan app:install` installs them in production through the
 * ReferenceDataRegistry (the only way to install them), and re-running never overwrites staff edits.
 */

beforeEach(function () {
    Storage::fake('objects');
    Http::preventStrayRequests();
});

it('is registered as a reference-data installer', function () {
    expect(app(ReferenceDataRegistry::class)->has(DefaultTemplates::KEY))->toBeTrue()
        ->and(app(ReferenceDataRegistry::class)->get(DefaultTemplates::KEY))->toBeInstanceOf(DefaultTemplates::class);
});

it('installs the default templates with app:install in production', function () {
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $status = Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test']);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    $catalog = app(TemplateCatalog::class)->all();
    expect($status)->toBe(0)
        ->and(EmailTemplate::query()->count())->toBe(count($catalog))
        ->and(EmailTemplateTranslation::query()->where('locale', 'en')->count())->toBe(count($catalog));
});

it('is idempotent and keeps staff-edited wording on a re-run', function () {
    $first = app(DefaultTemplates::class)->install();
    $template = EmailTemplate::query()->where('key', 'invoice.created')->sole();
    $template->translations()->where('locale', 'en')->update(['subject' => 'Our own subject', 'body' => 'Our own words']);
    $template->update(['is_enabled' => false]);

    $second = app(DefaultTemplates::class)->install();
    Artisan::call('app:install', ['--no-interaction' => true, '--admin-email' => 'owner@example.test']);

    expect($first->created)->toBe(count(app(TemplateCatalog::class)->all()))
        ->and($second->created + $second->updated)->toBe(0)
        ->and($second->unchanged)->toBe($first->created)
        ->and($template->fresh()->is_enabled)->toBeFalse()
        ->and($template->translations()->where('locale', 'en')->sole()->only(['subject', 'body']))->toBe(['subject' => 'Our own subject', 'body' => 'Our own words']);
});
