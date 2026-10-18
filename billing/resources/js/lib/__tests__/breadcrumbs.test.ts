import { describe, expect, it } from 'vitest';
import { pageTitleFromDocument, resolveBreadcrumbs } from '../breadcrumbs';

const navigation = [
    {
        title: 'General',
        items: [
            { title: 'Dashboard', href: 'http://localhost/admin', icon: 'LayoutGrid', active: false },
        ],
    },
    {
        title: 'Clients',
        items: [
            { title: 'Clients', href: 'http://localhost/admin/clients', icon: 'Users', active: false },
        ],
    },
    {
        title: 'Setup',
        items: [
            { title: 'Products', href: 'http://localhost/admin/catalog/products', icon: 'Package', active: true },
        ],
    },
];

describe('resolveBreadcrumbs', () => {
    it('derives the trail from the active sidebar item when the page declares none', () => {
        expect(
            resolveBreadcrumbs({ declared: [], navigation, currentUrl: '/admin/catalog/products', pageTitle: 'Products' }),
        ).toEqual([{ title: 'Setup' }, { title: 'Products', href: 'http://localhost/admin/catalog/products' }]);
    });

    it('appends the page title below the section and keeps the section linked', () => {
        expect(
            resolveBreadcrumbs({ declared: [], navigation, currentUrl: '/admin/catalog/products/1/edit', pageTitle: 'Starter' }),
        ).toEqual([
            { title: 'Setup' },
            { title: 'Products', href: 'http://localhost/admin/catalog/products' },
            { title: 'Starter', href: '/admin/catalog/products/1/edit' },
        ]);
    });

    it('completes a declared trail that stops above the current page', () => {
        const clients = navigation.map((group) => ({
            ...group,
            items: group.items.map((item) => ({ ...item, active: item.title === 'Clients' })),
        }));

        expect(
            resolveBreadcrumbs({
                declared: [{ title: 'Clients', href: '/admin/clients' }],
                navigation: clients,
                currentUrl: '/admin/clients/1',
                pageTitle: 'Demo Labs',
            }),
        ).toEqual([
            { title: 'Clients', href: '/admin/clients' },
            { title: 'Demo Labs', href: '/admin/clients/1' },
        ]);
    });

    it('ignores query strings when comparing the current page', () => {
        expect(
            resolveBreadcrumbs({ declared: [], navigation, currentUrl: '/admin/catalog/products?status=active', pageTitle: 'Active products' }),
        ).toHaveLength(2);
    });

    it('returns nothing when no item is active and nothing is declared', () => {
        expect(resolveBreadcrumbs({ declared: [], navigation: [], currentUrl: '/admin/search', pageTitle: null })).toEqual([]);
    });
});

describe('pageTitleFromDocument', () => {
    it.each([
        ['Products · Mohar', 'Mohar', 'Products'],
        ['Mohar', 'Mohar', null],
        ['', 'Mohar', null],
    ])('reads %s', (documentTitle, brand, expected) => {
        expect(pageTitleFromDocument(documentTitle, brand)).toBe(expected);
    });
});
