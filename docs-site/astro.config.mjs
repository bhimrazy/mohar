// Docs site for hosts who install and run the billing app. Stock Starlight theme with the brand colour, font and mark.
import starlight from '@astrojs/starlight';
import { defineConfig } from 'astro/config';
import starlightImageZoom from 'starlight-image-zoom';
import { product } from './src/product.mjs';

// Where the site is served. GitHub Pages project sites live at https://<owner>.github.io/<repo>/.
// For a custom domain, build with DOCS_SITE=https://docs.example.com DOCS_BASE=/ (see README.md).
const site = process.env.DOCS_SITE ?? 'https://bhimrazy.github.io';
const base = process.env.DOCS_BASE ?? '/whmcs';

export default defineConfig({
    site,
    base,
    trailingSlash: 'always',
    integrations: [
        starlight({
            title: product.name,
            description: `Install, set up and run ${product.name}, billing and automation software for web hosting companies.`,
            logo: { src: './src/assets/mark.svg' },
            favicon: '/favicon.svg',
            customCss: ['@fontsource-variable/inter', './src/styles/theme.css'],
            plugins: [starlightImageZoom()],
            pagination: true,
            lastUpdated: false,
            credits: false,
            sidebar: [
                { label: 'Introduction', link: '/' },
                {
                    label: 'Get started',
                    items: [
                        'get-started/requirements',
                        'get-started/install-docker',
                        'get-started/install-server',
                        'get-started/first-sign-in',
                    ],
                },
                {
                    label: 'Set up',
                    items: [
                        'setup/company-and-brand',
                        'setup/currencies',
                        'setup/tax',
                        'setup/products',
                        {
                            label: 'Payment gateways',
                            items: [
                                'setup/payment-gateways',
                                'setup/stripe',
                                'setup/esewa',
                                'setup/khalti',
                                'setup/bank-transfer',
                            ],
                        },
                        'setup/cpanel-server',
                        'setup/import-cpanel-accounts',
                    ],
                },
                {
                    label: 'Day to day',
                    items: ['daily/invoices', 'daily/automation', 'daily/support'],
                },
                {
                    label: 'Run your install',
                    items: ['operate/upgrade', 'operate/backups', 'operate/security', 'operate/troubleshooting'],
                },
            ],
        }),
    ],
});
