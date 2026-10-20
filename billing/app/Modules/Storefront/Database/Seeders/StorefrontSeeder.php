<?php

namespace App\Modules\Storefront\Database\Seeders;

use App\Modules\Core\Database\Seeders\DemoSeeder;
use App\Modules\Storefront\Models\Revision;
use App\Modules\Storefront\Support\SiteContent;

/**
 * Demo website: a published home page with example host copy. A fresh install has none and shows the neutral
 * placeholder until the host writes their own. Safe to re-run (skips when any version exists).
 */
class StorefrontSeeder extends DemoSeeder
{
    public function run(): void
    {
        if (Revision::query()->exists()) {
            return;
        }

        $content = SiteContent::defaults();
        $content['hero'] = [
            'eyebrow' => 'Web hosting · Domains · SSL',
            'headline' => 'Reliable hosting for your website',
            'subtext' => "Fast, secure hosting with clear prices and real people to help. Start with a domain, pick a plan, and you're online today.",
            'domain_search' => true,
        ];
        $content['why_us'] = [
            ['icon' => 'clock', 'title' => 'Help every day', 'text' => 'Tickets answered around the clock, phone support Sunday to Friday.'],
            ['icon' => 'shield', 'title' => 'Secure by default', 'text' => 'Free SSL on every site, daily malware scans and isolated accounts.'],
            ['icon' => 'refresh', 'title' => 'Free site transfer', 'text' => 'We move your website and email from your current host.'],
            ['icon' => 'card', 'title' => 'Pay your way', 'text' => 'Pay by card, bank transfer or local wallets, in your currency.'],
        ];
        $content['trust_facts'] = [
            ['label' => 'Hosting since', 'value' => '2014'],
            ['label' => 'Data centres', 'value' => 'Kathmandu, Frankfurt'],
            ['label' => 'Pay in', 'value' => 'NPR, INR or USD'],
        ];
        $content['seo']['description'] = 'Web hosting, domains and SSL with clear prices, local payment options and support every day.';

        Revision::query()->create([
            'status' => Revision::PUBLISHED,
            'content' => SiteContent::normalise($content),
            'schema_version' => SiteContent::SCHEMA,
            'published_at' => now(),
        ]);
    }
}
