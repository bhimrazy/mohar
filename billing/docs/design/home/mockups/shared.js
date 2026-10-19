/*
 * Shared public layout for the home page mockups: header (product groups, Domains and
 * Support menus, currency, cart, account), phone sheet and footer, rendered into every
 * direction so the header and footer are identical. Static design reference only.
 *
 * Query parameters (used for review and screenshots):
 *   theme=light|dark       colour mode (default: system)
 *   open=products|domains|support  open a header menu
 *   sheet=1                open the phone navigation sheet
 *   header=links|menu      products as one link per group, or one "Hosting" menu
 *   auth=1                 signed-in visitor (Client area instead of Log in)
 *   sections=a,b,c         show only these sections, in this order (data-section names)
 */
(function () {
    const params = new URLSearchParams(location.search);
    const theme = params.get('theme');
    if (theme === 'dark' || theme === 'light') {
        document.documentElement.dataset.theme = theme;
    }

    const ICONS = {
        search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        cart: '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
        chev: '<path d="m6 9 6 6 6-6"/>',
        right: '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        book: '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
        megaphone: '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        lifebuoy: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/>',
        mail: '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        phone: '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        pin: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        server: '<rect width="20" height="8" x="2" y="2" rx="2"/><rect width="20" height="8" x="2" y="14" rx="2"/><path d="M6 6h.01"/><path d="M6 18h.01"/>',
        cpu: '<rect width="16" height="16" x="4" y="4" rx="2"/><rect width="6" height="6" x="9" y="9" rx="1"/><path d="M15 2v2M15 20v2M2 15h2M2 9h2M20 15h2M20 9h2M9 2v2M9 20v2"/>',
        shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
        check: '<path d="M20 6 9 17l-5-5"/>',
        menu: '<path d="M4 12h16"/><path d="M4 6h16"/><path d="M4 18h16"/>',
        x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        user: '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        card: '<rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/>',
        bank: '<path d="M3 22h18"/><path d="M6 18v-7"/><path d="M10 18v-7"/><path d="M14 18v-7"/><path d="M18 18v-7"/><path d="m12 2 8 5H4z"/>',
        transfer: '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
        tag: '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5"/>',
        ticket: '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2M13 17v2M13 11v2"/>',
        message: '<path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/>',
        clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        zap: '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
        refresh: '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
        receipt: '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 17.5v-11"/>',
        grid: '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        cloud: '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
        info: '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        settings: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        grip: '<circle cx="9" cy="12" r="1"/><circle cx="9" cy="5" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="19" r="1"/>',
        eye: '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
        up: '<path d="m18 15-6-6-6 6"/>',
        down: '<path d="m6 9 6 6 6-6"/>',
        star: '<path d="M11.52 2.3a.53.53 0 0 1 .95 0l2.31 4.68a2.12 2.12 0 0 0 1.6 1.16l5.16.76a.53.53 0 0 1 .3.9l-3.74 3.64a2.12 2.12 0 0 0-.61 1.88l.88 5.14a.53.53 0 0 1-.77.56l-4.62-2.43a2.12 2.12 0 0 0-1.97 0L6.4 21.01a.53.53 0 0 1-.77-.56l.88-5.14a2.12 2.12 0 0 0-.61-1.88L2.16 9.8a.53.53 0 0 1 .3-.9l5.16-.76a2.12 2.12 0 0 0 1.6-1.16z"/>',
    };

    const sprite = Object.entries(ICONS)
        .map(([k, d]) => `<symbol id="i-${k}" viewBox="0 0 24 24">${d}</symbol>`)
        .join('');
    document.body.insertAdjacentHTML(
        'afterbegin',
        `<svg width="0" height="0" style="position:absolute" aria-hidden="true">${sprite}</svg>`,
    );

    const icon = (name, cls = '') => `<svg class="icon ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
    window.mIcon = icon;

    // Live data from the preview database (Catalog groups, cycle prices, spotlight TLDs).
    const currency = params.get('currency') || document.body.dataset.currency || 'USD';
    const PRICES = window.mHeaderPrices || {
        USD: { shared: '$3.21', vps: '$10.75', ssl: '$28.99', cart: 2 },
        NPR: { shared: 'Rs 485.38', vps: 'Rs 1,549.92', ssl: 'Rs 4,409.00', cart: 2 },
        INR: { shared: '₹303.29', vps: '₹969.08', ssl: '₹2,759.00', cart: 2 },
    }[currency];
    const GROUPS = [
        { key: 'shared', name: 'Shared Hosting', desc: 'Fast, secure hosting for every website', icon: 'server', per: '/mo', plans: 3 },
        { key: 'vps', name: 'VPS', desc: 'Root access on dedicated resources', icon: 'cpu', per: '/mo', plans: 2 },
        { key: 'ssl', name: 'SSL Certificates', desc: 'Encrypt traffic and earn the padlock', icon: 'shield', per: 'one-time', plans: 1 },
    ];
    window.mGroups = GROUPS;
    window.mPrices = PRICES;

    const mode = params.get('header') || document.body.dataset.header || 'links';
    const open = params.get('open');
    const signedIn = params.get('auth') === '1';
    const brand = 'Acme Hosting';

    const groupMenuItems = GROUPS.map(
        (g, i) => `
        <a class="menu-item${i === 0 ? ' hl' : ''}" href="#store-${g.key}" role="menuitem">
            <span class="mi-icon">${icon(g.icon)}</span>
            <span><span class="mi-title">${g.name}</span><span class="mi-desc">${g.desc}</span></span>
            <span class="mi-price">${g.per === '/mo' ? 'from' : 'one-time'}<span class="pl"><strong class="num">${PRICES[g.key]}</strong>${g.per === '/mo' ? '/mo' : ''}</span></span>
        </a>`,
    ).join('');

    const domainItems = [
        ['search', 'Register a domain', 'Search for a new name and see the price'],
        ['transfer', 'Transfer a domain', 'Move a domain you already own to us'],
        ['tag', 'Domain pricing', 'Register, renew and transfer prices for every extension'],
    ];
    const supportItems = [
        ['book', 'Knowledge base', 'Guides for email, DNS, billing and more', ''],
        ['megaphone', 'Announcements', 'News, new features and planned maintenance', ''],
        ['activity', 'Network status', 'Live status of our servers and services', '<span class="badge badge-warning"><span class="dot dot-warning"></span>1 open issue</span>'],
        ['ticket', 'Open a ticket', 'Get help from our support team', ''],
    ];
    const simpleItems = (items) =>
        items
            .map(
                ([ic, t, d, extra]) => `
        <a class="menu-item" href="#" role="menuitem">
            <span class="mi-icon">${icon(ic)}</span>
            <span><span class="mi-title">${t}${extra || ''}</span><span class="mi-desc">${d}</span></span>
        </a>`,
            )
            .join('');

    const navButton = (id, label) => `
        <button class="nav-link" type="button" aria-haspopup="true" aria-expanded="${open === id}" aria-controls="menu-${id}" data-menu="${id}">
            ${label}${icon('chev', 'chev')}
        </button>`;

    const productsNav =
        mode === 'menu'
            ? `<div class="nav-item">${navButton('products', 'Hosting')}
                <div class="menu menu-wide${open === 'products' ? ' open' : ''}" id="menu-products" role="menu">
                    <div>${groupMenuItems}</div>
                    <div class="menu-aside">
                        <span class="eyebrow">Not sure which?</span>
                        <p>Compare every plan side by side, with prices for each billing cycle.</p>
                        <a class="link-arrow" href="#">Compare all plans ${icon('right')}</a>
                        <hr style="border:0;border-top:1px solid var(--border);width:100%;margin:4px 0">
                        <span class="eyebrow">Need a domain too?</span>
                        <a class="link-arrow" href="#">Search domains ${icon('right')}</a>
                    </div>
                </div></div>`
            : GROUPS.map((g) => `<a class="nav-link" href="#store-${g.key}">${g.name}</a>`).join('');

    const account = signedIn
        ? `<a class="btn btn-outline btn-sm" href="#">${icon('user')}Client area</a>`
        : `<a class="btn btn-ghost btn-sm hide-sm" href="#">Log in</a><a class="btn btn-primary btn-sm hide-sm" href="#">Create account</a>`;

    const header = `
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="site-header">
        <div class="container bar">
            <a class="brand" href="#" aria-label="${brand} home">
                <span class="brand-mark">${icon('cloud')}</span><span>${brand}</span>
            </a>
            <nav class="nav nav-desktop" aria-label="Main">
                ${productsNav}
                <div class="nav-item">${navButton('domains', 'Domains')}
                    <div class="menu${open === 'domains' ? ' open' : ''}" id="menu-domains" role="menu" style="width:340px">${simpleItems(domainItems)}</div>
                </div>
                <div class="nav-item">${navButton('support', 'Support')}
                    <div class="menu${open === 'support' ? ' open' : ''}" id="menu-support" role="menu" style="width:360px">${simpleItems(supportItems)}
                        <div class="menu-foot"><span>Can't find it?</span><a class="link-arrow" href="#">Contact us ${icon('right')}</a></div>
                    </div>
                </div>
            </nav>
            <div class="header-tools">
                <button class="select btn-sm hide-sm" type="button" aria-label="Currency, ${currency}" style="height:32px">${currency}${icon('chev', 'chev')}</button>
                <a class="btn btn-ghost btn-sm" href="#" aria-label="Cart, ${PRICES.cart} items">${icon('cart')}<span class="hide-sm">Cart</span><span class="cart-count num">${PRICES.cart}</span></a>
                ${account}
                <button class="btn btn-outline btn-icon only-mobile" type="button" aria-label="Open menu" aria-expanded="${params.get('sheet') === '1'}" aria-controls="site-sheet" data-sheet>${icon('menu')}</button>
            </div>
        </div>
    </header>`;

    const sheetOpen = params.get('sheet') === '1';
    const sheet = `
    <div class="sheet-backdrop${sheetOpen ? ' open' : ''}" data-sheet-close></div>
    <div class="sheet${sheetOpen ? ' open' : ''}" id="site-sheet" role="dialog" aria-modal="true" aria-label="Menu">
        <div class="sheet-head">
            <span class="brand"><span class="brand-mark">${icon('cloud')}</span><span>${brand}</span></span>
            <button class="btn btn-ghost btn-icon" type="button" aria-label="Close menu" data-sheet-close>${icon('x')}</button>
        </div>
        <div class="sheet-body">
            <div class="sheet-group"><h4 class="eyebrow">Hosting</h4>
                ${GROUPS.map((g) => `<a class="sheet-link" href="#">${icon(g.icon)}${g.name}<span class="num">${g.per === '/mo' ? `from ${PRICES[g.key]}/mo` : `${PRICES[g.key]} one-time`}</span></a>`).join('')}
            </div>
            <div class="sheet-group"><h4 class="eyebrow">Domains</h4>
                ${domainItems.map(([ic, t]) => `<a class="sheet-link" href="#">${icon(ic)}${t}</a>`).join('')}
            </div>
            <div class="sheet-group"><h4 class="eyebrow">Support</h4>
                ${supportItems.map(([ic, t]) => `<a class="sheet-link" href="#">${icon(ic)}${t}${t === 'Network status' ? '<span class="num"><span class="badge badge-warning">1 open issue</span></span>' : ''}</a>`).join('')}
                <a class="sheet-link" href="#">${icon('message')}Contact us</a>
            </div>
        </div>
        <div class="sheet-foot">
            <label class="muted" style="font-size:13px;display:flex;align-items:center;justify-content:space-between">Currency
                <button class="select" type="button" aria-label="Currency, ${currency}">${currency}${icon('chev', 'chev')}</button></label>
            ${signedIn ? `<a class="btn btn-primary btn-lg btn-block" href="#">Client area</a>` : `<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><a class="btn btn-outline btn-lg" href="#">Log in</a><a class="btn btn-primary btn-lg" href="#">Create account</a></div>`}
        </div>
    </div>`;

    const statusStrip = document.body.dataset.statusStrip === 'off'
        ? ''
        : `
    <div class="status-strip" role="status">
        <div class="container">
            <span class="dot dot-warning"></span>
            <span><strong>Degraded performance on web03.</strong> Some sites respond slowly; email is not affected. Updated 2 hours ago.</span>
            <a href="#">View network status</a>
        </div>
    </div>`;

    const footer = `
    <footer class="site-footer">
        <div class="container footer-grid">
            <div class="company">
                <a class="brand" href="#"><span class="brand-mark">${icon('cloud')}</span><span style="color:var(--foreground)">${brand}</span></a>
                <p>Web hosting, domains and SSL for businesses in Nepal and beyond.</p>
                <div class="row">${icon('pin')}<span>Acme Hosting Pvt. Ltd.<br>Jhamsikhel Road, Lalitpur 44600, Nepal</span></div>
                <div class="row">${icon('mail')}<a href="#">support@example.com</a></div>
                <div class="row">${icon('phone')}<span><a href="#" class="num">+977 1-5900000</a> · Sun–Fri, 9:00–18:00</span></div>
                <div class="row">${icon('receipt')}<span>PAN 000000000 · Reg. no. 000000/00/000</span></div>
            </div>
            <div><h4>Hosting</h4><ul>${GROUPS.map((g) => `<li><a href="#">${g.name}</a></li>`).join('')}<li><a href="#">Compare all plans</a></li></ul></div>
            <div><h4>Domains</h4><ul><li><a href="#">Register a domain</a></li><li><a href="#">Transfer a domain</a></li><li><a href="#">Domain pricing</a></li></ul></div>
            <div><h4>Support</h4><ul><li><a href="#">Knowledge base</a></li><li><a href="#">Announcements</a></li><li><a href="#">Network status</a></li><li><a href="#">Open a ticket</a></li><li><a href="#">Contact us</a></li></ul></div>
            <div><h4>Account</h4><ul>${signedIn ? '<li><a href="#">Client area</a></li>' : '<li><a href="#">Log in</a></li><li><a href="#">Create account</a></li>'}<li><a href="#">Pay an invoice</a></li><li><a href="#">Reset password</a></li></ul></div>
        </div>
        <div class="container footer-bottom">
            <span>© 2026 Acme Hosting Pvt. Ltd. Prices exclude tax unless stated.</span>
            <nav class="legal" aria-label="Legal"><a href="#">Terms of service</a><a href="#">Privacy policy</a><a href="#">Refund policy</a></nav>
            <div class="tools"><span>Currency</span><button class="select btn-sm" type="button" aria-label="Currency, ${currency}" style="height:32px">${currency}${icon('chev', 'chev')}</button></div>
        </div>
    </footer>`;

    // Revision 1 footer (direction A and the 404): company profile, statutory IDs, link columns
    // including Company and legal pages, payment marks for the currency, tax statement.
    const tax = window.mTaxStatement || 'Tax added at checkout where applicable';
    const payStrip = window.mPayStrip ? window.mPayStrip() : '';
    const footerR1 = `
    <footer class="site-footer">
        <div class="container footer-r1">
            <div class="company">
                <a class="brand" href="#"><span class="brand-mark">${icon('cloud')}</span><span style="color:var(--foreground)">${brand}</span></a>
                <p>Web hosting, domains and SSL for businesses in Nepal and beyond.</p>
                <div class="row">${icon('pin')}<span><strong style="color:var(--foreground)">Acme Hosting Pvt. Ltd.</strong><br>Jhamsikhel Road, Lalitpur 44600, Bagmati, Nepal</span></div>
                <div class="row">${icon('mail')}<a href="#">support@example.com</a></div>
                <div class="row">${icon('phone')}<span><a href="#" class="num">+977 1-5900000</a> · WhatsApp <a href="#" class="num">+977 98-00000000</a></span></div>
                <div class="row">${icon('clock')}<span>Support Sun–Fri, 9:00–18:00 (Nepal time). Tickets every day.</span></div>
                <dl class="ids">
                    <div><dt>PAN/VAT no.</dt><dd class="num">000000000</dd></div>
                    <div><dt>Company reg. no.</dt><dd class="num">000000/00/000</dd></div>
                    <div><dt>E-commerce reg. no.</dt><dd class="num">0000/00</dd></div>
                </dl>
            </div>
            <div class="footer-cols">
                <div><h4>Hosting</h4><ul>${GROUPS.map((g) => `<li><a href="#">${g.name}</a></li>`).join('')}<li><a href="#">Compare all plans</a></li></ul></div>
                <div><h4>Domains</h4><ul><li><a href="#">Register a domain</a></li><li><a href="#">Transfer a domain</a></li><li><a href="#">Domain pricing</a></li></ul></div>
                <div><h4>Support</h4><ul><li><a href="#">Knowledge base</a></li><li><a href="#">Announcements</a></li><li><a href="#">Network status</a></li><li><a href="#">Open a ticket</a></li></ul></div>
                <div><h4>Account</h4><ul>${signedIn ? '<li><a href="#">Client area</a></li>' : '<li><a href="#">Log in</a></li><li><a href="#">Create account</a></li>'}<li><a href="#">Pay an invoice</a></li><li><a href="#">Reset password</a></li></ul></div>
                <div><h4>Company</h4><ul><li><a href="#">About us</a></li><li><a href="#">Contact us</a></li><li><a href="#">Terms of service</a></li><li><a href="#">Privacy policy</a></li><li><a href="#">Refund policy</a></li></ul></div>
            </div>
        </div>
        <div class="container footer-pay">
            <span class="eyebrow">We accept</span>
            <div class="pay-strip">${payStrip}</div>
            <p class="tax-note">${icon('info')}<span>Prices in ${currency}: ${tax}.</span></p>
        </div>
        <div class="container footer-bottom">
            <span>© 2026 Acme Hosting Pvt. Ltd.</span>
            <nav class="legal" aria-label="Social"><a href="#">Facebook</a><a href="#">Instagram</a><a href="#">LinkedIn</a></nav>
            <div class="tools"><span>Currency</span><button class="select btn-sm" type="button" aria-label="Currency, ${currency}" style="height:32px">${currency}${icon('chev', 'chev')}</button></div>
        </div>
    </footer>`;

    const slot = (id, html) => {
        const el = document.getElementById(id);
        if (el) el.outerHTML = html;
    };
    slot('site-header', header + (document.body.dataset.statusStrip === 'off' ? '' : statusStrip) + sheet);
    slot('site-footer', document.body.dataset.footer === 'r1' ? footerR1 : footer);

    // Replace [data-icon] placeholders in page markup.
    document.querySelectorAll('[data-icon]').forEach((el) => {
        el.outerHTML = icon(el.dataset.icon, el.className || '');
    });

    // Section order and visibility, the same way the admin setting drives the real page.
    const order = params.get('sections');
    const main = document.querySelector('main');
    if (main && !main.id) main.id = 'main';
    if (order && main) {
        const wanted = order.split(',');
        const all = [...main.querySelectorAll(':scope > [data-section]')];
        all.forEach((s) => s.remove());
        wanted.forEach((name) => {
            const s = all.find((x) => x.dataset.section === name);
            if (s) main.append(s);
        });
    }

    // Keyboard and pointer behaviour for the menus (Radix NavigationMenu in the real build).
    const closeAll = () => {
        document.querySelectorAll('[data-menu]').forEach((b) => b.setAttribute('aria-expanded', 'false'));
        document.querySelectorAll('.menu').forEach((m) => m.classList.remove('open'));
    };
    document.querySelectorAll('[data-menu]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const menu = document.getElementById(btn.getAttribute('aria-controls'));
            const wasOpen = btn.getAttribute('aria-expanded') === 'true';
            closeAll();
            if (!wasOpen) {
                btn.setAttribute('aria-expanded', 'true');
                menu.classList.add('open');
                menu.querySelector('a')?.focus();
            }
        });
    });
    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const openBtn = document.querySelector('[data-menu][aria-expanded="true"]');
            closeAll();
            openBtn?.focus();
            document.querySelectorAll('.sheet, .sheet-backdrop').forEach((s) => s.classList.remove('open'));
        }
    });
    document.querySelectorAll('[data-sheet]').forEach((b) =>
        b.addEventListener('click', () => {
            document.querySelectorAll('.sheet, .sheet-backdrop').forEach((s) => s.classList.add('open'));
            document.querySelector('.sheet [data-sheet-close]')?.focus();
        }),
    );
    document.querySelectorAll('[data-sheet-close]').forEach((b) =>
        b.addEventListener('click', () =>
            document.querySelectorAll('.sheet, .sheet-backdrop').forEach((s) => s.classList.remove('open')),
        ),
    );
})();
