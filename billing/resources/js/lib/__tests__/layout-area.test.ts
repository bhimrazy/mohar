import { describe, expect, it } from 'vitest';
import { showsPublicPageInClientArea } from '../layout-area';

const client = {
    auth: { user: { id: 1 } },
    clientAccounts: [{ id: 7, name: 'Demo Labs' }],
};
const staff = { auth: { user: { id: 2 } }, clientAccounts: [] };
const guest = { auth: { user: null }, clientAccounts: [] };

describe('showsPublicPageInClientArea', () => {
    it.each([
        'public/support/kb/index',
        'public/support/kb/article',
        'public/support/kb/category',
        'public/support/announcements/index',
        'public/support/announcements/show',
        'public/support/network',
    ])('keeps a signed-in client in the client area on %s', (page) => {
        expect(showsPublicPageInClientArea(page, client)).toBe(true);
    });

    it.each([
        'public/support/tickets/show',
        'public/support/tickets/merged',
        'public/support/tickets/submitted',
        'public/support/tickets/create',
    ])(
        'always shows the access-key ticket page %s on the public layout',
        (page) => {
            expect(showsPublicPageInClientArea(page, client)).toBe(false);
        },
    );

    it('uses the public layout for guests', () => {
        expect(
            showsPublicPageInClientArea('public/support/kb/index', guest),
        ).toBe(false);
    });

    it('uses the public layout for staff without a client account', () => {
        expect(
            showsPublicPageInClientArea('public/support/kb/index', staff),
        ).toBe(false);
    });

    it('leaves the store on the public layout', () => {
        expect(showsPublicPageInClientArea('public/store/index', client)).toBe(
            false,
        );
    });
});
