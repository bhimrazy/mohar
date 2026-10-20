/*
 * Direction A, revision 1: prices, tax statements and payment marks computed from the preview
 * database's Catalog and Domains prices (minor units), the way PriceBook / DomainPricing /
 * TaxDisplay / GatewayDirectory would hand them to the page. Runs before shared.js.
 *
 * Query parameters: currency=USD|NPR|INR (default NPR), tax=exclusive|inclusive (default exclusive).
 */
(function () {
    const params = new URLSearchParams(location.search);
    const currency = params.get('currency') || document.body.dataset.currency || 'NPR';
    const taxMode = params.get('tax') || 'exclusive';
    document.body.dataset.currency = currency;

    const CUR = {
        USD: { sym: '$', locale: 'en-US', tax: null },
        NPR: { sym: 'Rs ', locale: 'en-IN', tax: { name: 'VAT', rate: 13 } },
        INR: { sym: '₹', locale: 'en-IN', tax: { name: 'GST', rate: 18 } },
    }[currency];

    const inclusive = taxMode === 'inclusive' && CUR.tax;
    const gross = (minor) => (inclusive ? Math.round(minor * (1 + CUR.tax.rate / 100)) : minor);
    const money = (minor) =>
        CUR.sym +
        new Intl.NumberFormat(CUR.locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
            gross(minor) / 100,
        );
    const perMonth = (minor, months) => money(Math.round(minor / months));

    // TaxDisplay::statement() per currency (spec §5.4).
    const taxStatement = !CUR.tax
        ? 'Tax added at checkout where applicable'
        : inclusive
          ? `Prices include ${CUR.tax.rate}% ${CUR.tax.name}`
          : `+${CUR.tax.rate}% ${CUR.tax.name} at checkout`;

    // Catalog prices (minor units): m = monthly, a = annually, b = every 2 years.
    const P = {
        Starter: { USD: { m: 399, a: 4099, b: 7699 }, NPR: { m: 60900, a: 618900, b: 1164900 }, INR: { m: 37900, a: 386900, b: 727900 } },
        Pro: { USD: { m: 799, a: 8199, b: 15900 }, NPR: { m: 121900, a: 1238900, b: 2331900 }, INR: { m: 75900, a: 774900, b: 1456900 } },
        Business: { USD: { m: 1499, a: 15900, b: 28900 }, NPR: { m: 227900, a: 2323900, b: 4373900 }, INR: { m: 142900, a: 1452900, b: 2733900 } },
    };
    const VPS = { USD: { a: 12900, setup: 500 }, NPR: { a: 1859900, setup: 75970 }, INR: { a: 1162900, setup: 47482 } }[currency];
    const SSL = { USD: 2899, NPR: 440900, INR: 275900 }[currency];
    const TLDS = {
        USD: [['.com', 1299, 1499, 'Popular'], ['.net', 1499, 1699], ['.org', 1399, 1599], ['.io', 3999, 4499, 'New'], ['.co.uk', 999, 1099]],
        NPR: [['.com', 197900, 227900, 'Popular'], ['.net', 227900, 258900], ['.org', 212900, 242900], ['.io', 607900, 683900, 'New'], ['.co.uk', 151900, 166900]],
        INR: [['.com', 123900, 142900, 'Popular'], ['.net', 142900, 161900], ['.org', 132900, 151900], ['.io', 379900, 427900, 'New'], ['.co.uk', 94900, 104900]],
    }[currency];

    const save = (p) => Math.round((1 - p.a / (p.m * 12)) * 100);
    const lowestShared = Math.min(...Object.values(P).map((x) => Math.round(x[currency].b / 24)));

    // Header and sheet "from" prices for shared.js.
    window.mHeaderPrices = {
        shared: money(lowestShared),
        vps: perMonth(VPS.a, 12),
        ssl: money(SSL),
        cart: 2,
    };
    window.mTaxStatement = taxStatement;

    // GatewayDirectory::acceptanceMarks(currency): eSewa and Khalti are enabled for NPR only.
    const marks = [];
    if (currency === 'NPR') {
        marks.push({ logo: '../../../../public/images/gateways/esewa.png', label: 'eSewa' });
        marks.push({ logo: '../../../../public/images/gateways/khalti.svg', label: 'Khalti', h: 26 });
    }
    marks.push({ icon: 'card', label: 'Card', placeholder: true });
    marks.push({ icon: 'bank', label: 'Bank transfer' });
    window.mPayMarks = marks;

    const icon = (n) => `<svg class="icon" aria-hidden="true"><use href="#i-${n}"/></svg>`;
    const fill = (id, html) => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = html;
    };
    window.mPayStrip = () =>
        marks
            .map((m) =>
                m.logo
                    ? `<span class="pay-logo"><img src="${m.logo}" alt="${m.label}"${m.h ? ` style="height:${m.h}px"` : ''}></span>`
                    : `<span class="pay-logo${m.placeholder ? ' placeholder' : ''}">${icon(m.icon)}${m.label}${m.placeholder ? '<span class="ph-tag">Text until official logo</span>' : ''}</span>`,
            )
            .join('');

    document.addEventListener('DOMContentLoaded', () => {
        const pro = P.Pro[currency];
        fill(
            'hero-plan-price',
            `<div class="price"><span class="from">From</span><span class="amt num">${perMonth(pro.b, 24)}</span><span class="per">/mo</span></div>
             <p class="billed">Billed ${money(pro.b)} every 2 years</p>
             <p class="billed">Renews at ${money(pro.b)} every 2 years</p>
             <p class="billed">Or ${money(pro.m)} monthly</p>
             <p class="tax-line">${taxStatement}</p>`,
        );

        fill(
            'tld-tiles',
            TLDS.map(
                ([ext, reg, ren, badge]) => `
                <div class="tld-tile"><span class="ext">${ext}${badge ? ` <span class="badge ${badge === 'New' ? 'badge-info' : 'badge-accent'}">${badge}</span>` : ''}</span>
                    <span class="num reg">${money(reg)}<small>/yr</small></span>
                    <span class="num ren">Renews at ${money(ren)}</span></div>`,
            ).join('') + `<a class="tld-tile more" href="#"><span class="link-arrow">All domain prices ${icon('right')}</span><span class="ren">Register, renew and transfer</span></a>`,
        );

        const card = (name, desc, feats, featured) => {
            const p = P[name][currency];
            return `
            <article class="card plan${featured ? ' featured' : ''}" aria-label="${name}">
                ${featured ? '<span class="badge ribbon" style="background:var(--primary);color:var(--primary-foreground)">Most popular</span>' : ''}
                <div><h3>${name}</h3><p class="desc">${desc}</p></div>
                <div class="price-block">
                    <div class="price"><span class="amt num">${perMonth(p.a, 12)}</span><span class="per">/mo</span></div>
                    <p class="billed">Billed ${money(p.a)} annually <span class="badge badge-success">Save ${save(p)}%</span></p>
                    <p class="billed">Renews at ${money(p.a)} annually</p>
                </div>
                <ul class="features">${feats.map((f) => `<li>${icon('check')}${f}</li>`).join('')}</ul>
                <a class="btn ${featured ? 'btn-primary' : 'btn-outline'} btn-lg btn-block" href="#">Configure and order</a>
            </article>`;
        };
        fill(
            'plan-cards',
            card('Starter', 'For a single site or portfolio', ['1 website', '10 GB SSD storage']) +
                card('Pro', 'For growing businesses with several sites', ['10 websites', '50 GB SSD storage', 'Free domain'], true) +
                card('Business', 'For busy shops and agencies', ['Unlimited websites', '200 GB NVMe storage', 'Daily backups']),
        );
        const maxSave = Math.max(...Object.values(P).map((x) => save(x[currency])));
        fill('save-badge', `Save up to ${maxSave}%`);
        document.querySelectorAll('[data-tax-statement]').forEach((el) => (el.textContent = taxStatement));

        fill('gc-vps', `From <strong class="num">${perMonth(VPS.a, 12)}</strong>/mo · 2 plans<br><span class="num">Setup fee ${money(VPS.setup)}</span>`);
        fill('gc-ssl', `<strong class="num">${money(SSL)}</strong> one-time · 1 certificate`);
        const cheapest = TLDS.reduce((a, b) => (b[1] < a[1] ? b : a));
        fill('gc-dom', `From <strong class="num">${money(cheapest[1])}</strong>/yr · 5 extensions`);
        fill('cta-from', money(lowestShared));
        fill('pay-strip', window.mPayStrip());
        fill(
            'pay-note',
            `Shown for ${currency}. ${currency === 'NPR' ? 'eSewa and Khalti use their official logos.' : 'eSewa and Khalti take NPR only, so they are not shown.'}`,
        );
    });
})();
