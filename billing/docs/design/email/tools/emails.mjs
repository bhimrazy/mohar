// Sample content for the five reference emails. Shared by all three directions so the owner compares design,
// not copy. Amounts are formatted the way Money formats them: ₹ and Rs use lakh grouping (1,28,486.66).
// Dates use the human format "1 Nov 2026".
//
// Message structure (see email-design.md, "Voice and messages"):
//   subject · preheader · status · amount/title · greeting · opening (one sentence) · action ·
//   key facts · what was bought · reassurance · help line · footer

const base = 'https://billing.example.com';

export const company = {
    name: 'Mohar',
    tagline: 'Web hosting billing, sealed and settled.',
    legal: 'Mohar Cloud Pvt. Ltd.',
    address:
        'Office 502, Baner Business Bay, Baner Road, Pune, Maharashtra 411045, India',
    helpUrl: `${base}/support`,
    prefsUrl: `${base}/account/email-preferences`,
    supportEmail: 'support@example.com',
    // Sender: display name is the legal name; billing mail comes from a monitored billing alias (never noreply),
    // Reply-To is support, so "reply to this email" reaches a person.
    from: 'Mohar Cloud Pvt. Ltd. <billing@example.com>',
    replyTo: 'support@example.com',
};

export const emails = {
    'invoice-issued': {
        subject:
            'Invoice INV-2026-0142 from Mohar: ₹1,28,486.66 due 1 Nov 2026',
        preheader:
            'Pay by 1 Nov to keep your server, hosting and domain running. The PDF invoice is attached.',
        kind: 'Invoice',
        ref: 'INV-2026-0142',
        status: { tone: 'accent', label: 'Due in 14 days' },
        title: 'Your invoice is ready',
        amount: {
            label: 'Amount due',
            value: '₹1,28,486.66',
            caption: 'Due 1 Nov 2026',
            when: '1 Nov 2026',
        },
        greeting: 'Hi Ananya,',
        opening: 'Here’s your invoice for the renewals starting 1 November.',
        cta: {
            label: 'Pay ₹1,28,486.66',
            url: `${base}/invoices/INV-2026-0142/pay`,
        },
        downloads: [
            {
                label: 'Download invoice',
                url: `${base}/invoices/INV-2026-0142.pdf`,
            },
        ],
        attachment: 'Invoice-INV-2026-0142.pdf',
        facts: [
            ['Invoice', 'INV-2026-0142'],
            ['Issued', '18 Oct 2026'],
            ['Due', '1 Nov 2026'],
            ['Billed to', 'Sharma Digital LLP<br>GSTIN 27AAKFS1234M1Z5'],
            ['Place of supply', 'Maharashtra (27)'],
        ],
        lines: {
            heading: 'What you’re paying for',
            items: [
                {
                    name: 'Dedicated Server DS-4',
                    meta: '1 Nov 2026 – 31 Oct 2027',
                    amount: '₹96,000.00',
                },
                {
                    name: 'Business Hosting Plus',
                    meta: 'sharmadigital.in · 12 months',
                    amount: '₹11,988.00',
                },
                {
                    name: 'Domain renewal',
                    meta: 'sharmadigital.in · 1 year',
                    amount: '₹899.00',
                },
            ],
            totals: [
                { label: 'Subtotal', amount: '₹1,08,887.00' },
                { label: 'CGST 9%', amount: '₹9,799.83' },
                { label: 'SGST 9%', amount: '₹9,799.83' },
                { label: 'Total due', amount: '₹1,28,486.66', strong: true },
            ],
        },
        note: 'Our GSTIN is 27AABCM4321K1Z2. GST is split into CGST and SGST because we and your business are both in Maharashtra.',
        help: 'Need a purchase order number or a different billing address on this invoice? Reply and we’ll reissue it.',
        timeline: [
            { label: 'Issued', date: '18 Oct', state: 'done' },
            { label: 'Due', date: '1 Nov', state: 'current' },
            { label: 'Paid', date: '—', state: 'upcoming' },
        ],
        reason: 'You’re receiving this because you are the billing contact for Sharma Digital LLP.',
    },

    'payment-receipt': {
        subject: 'Your receipt from Mohar #RCPT-2026-0311',
        preheader:
            'Thank you. Rs 18,645.00 paid with Khalti on 18 Oct 2026, and invoice INV-2026-0139 is settled.',
        kind: 'Receipt',
        ref: 'RCPT-2026-0311',
        status: { tone: 'success', label: 'Paid' },
        title: 'Payment received',
        amount: {
            label: 'Amount paid',
            value: 'Rs 18,645.00',
            caption: '18 Oct 2026, 2:14 pm',
            when: '18 Oct 2026, 2:14 pm',
        },
        greeting: 'Hi Suman,',
        opening:
            'Thank you. We’ve received your payment, and invoice INV-2026-0139 is paid in full.',
        // No button on a receipt: nothing to do, so the actions are quiet download links (owner reference).
        cta: null,
        downloads: [
            {
                label: 'Download receipt',
                url: `${base}/receipts/RCPT-2026-0311.pdf`,
            },
            {
                label: 'Download invoice',
                url: `${base}/invoices/INV-2026-0139.pdf`,
            },
        ],
        attachment: 'Receipt-RCPT-2026-0311.pdf',
        facts: [
            ['Receipt', 'RCPT-2026-0311'],
            ['Invoice', 'INV-2026-0139'],
            ['Payment method', '[[Khalti]] wallet 98•••••421'],
            ['Khalti transaction ID', '7xQ2mKpL9vR4'],
            ['Date', '18 Oct 2026, 2:14 pm NPT'],
        ],
        lines: {
            heading: 'What you paid for',
            items: [
                {
                    name: 'Cloud VPS 4 GB',
                    meta: 'vps.thapa.com.np · 12 months',
                    amount: 'Rs 16,500.00',
                },
                {
                    name: 'Daily backups',
                    meta: 'vps.thapa.com.np · 12 months',
                    amount: 'Rs 2,145.00',
                },
            ],
            totals: [
                { label: 'Total paid', amount: 'Rs 18,645.00', strong: true },
                { label: 'Still to pay', amount: 'Rs 0.00' },
            ],
        },
        note: 'Nothing more is due. Your Cloud VPS renews on 18 Oct 2027, and we’ll send that invoice two weeks before.',
        help: 'Something not right? Reply to this email and our billing team will look into it.',
        timeline: [
            { label: 'Issued', date: '11 Oct', state: 'done' },
            { label: 'Paid', date: '18 Oct', state: 'done' },
            { label: 'Renews', date: '18 Oct 2027', state: 'upcoming' },
        ],
        reason: 'You’re receiving this because a payment was made on your account.',
    },

    'overdue-reminder': {
        subject: 'Payment overdue: invoice INV-2026-0098 ($248.00)',
        preheader:
            'It was due on 15 Oct 2026. Please pay by 25 Oct to keep your VPS running.',
        kind: 'Invoice',
        ref: 'INV-2026-0098',
        status: { tone: 'danger', label: 'Overdue by 3 days' },
        title: 'Your invoice is overdue',
        amount: {
            label: 'Amount due',
            value: '$248.00',
            caption: 'Was due 15 Oct 2026',
            when: 'was due 15 Oct 2026',
        },
        greeting: 'Hi Daniel,',
        opening:
            'We haven’t received payment for invoice INV-2026-0098, which was due on 15 Oct 2026.',
        callout: {
            tone: 'warning',
            title: 'Please pay by 25 Oct 2026',
            text: 'If the invoice is still unpaid on that date, we’ll pause the services below. Your files and data stay safe for 30 days.',
        },
        cta: {
            label: 'Pay $248.00',
            url: `${base}/invoices/INV-2026-0098/pay`,
        },
        secondary: {
            label: 'Update payment method',
            url: `${base}/account/payment-methods`,
        },
        downloads: [
            {
                label: 'Download invoice',
                url: `${base}/invoices/INV-2026-0098.pdf`,
            },
        ],
        attachment: 'Invoice-INV-2026-0098.pdf',
        facts: [
            ['Invoice', 'INV-2026-0098'],
            ['Issued', '1 Oct 2026'],
            ['Was due', '15 Oct 2026'],
            ['Last attempt', '[[VISA]] •••• 4242 on 15 Oct, didn’t go through'],
        ],
        lines: {
            heading: 'Services on this invoice',
            items: [
                {
                    name: 'Cloud VPS 8 GB',
                    meta: 'vps.okafor.dev · Oct 2026',
                    amount: '$192.00',
                },
                {
                    name: 'Managed backups',
                    meta: 'vps.okafor.dev · Oct 2026',
                    amount: '$24.00',
                },
                {
                    name: 'Domain renewal',
                    meta: 'okafor.dev · 1 year',
                    amount: '$32.00',
                },
            ],
            totals: [{ label: 'Amount due', amount: '$248.00', strong: true }],
        },
        note: 'Already paid? Bank transfers can take up to two working days to reach us. Reply with your payment reference and we’ll check.',
        help: 'Need more time, or having trouble paying? Reply to this email and we’ll work it out with you.',
        timeline: [
            { label: 'Issued', date: '1 Oct', state: 'done' },
            { label: 'Due', date: '15 Oct', state: 'alert' },
            { label: 'Pause', date: '25 Oct', state: 'upcoming' },
        ],
        reason: 'You’re receiving this because you are the billing contact for this account.',
    },

    'password-reset': {
        subject: 'Reset your Mohar password',
        preheader:
            'This link expires in 60 minutes. If you didn’t ask for it, you can ignore this email.',
        kind: 'Security',
        ref: 'Account security',
        status: { tone: 'accent', label: 'Expires in 60 min' },
        title: 'Reset your password',
        greeting: 'Hi Priya,',
        opening:
            'We received a request to reset the password for <strong>priya.nair@example.com</strong>.',
        cta: {
            label: 'Reset password',
            url: `${base}/reset-password/9f3c2a7e41b8d6`,
        },
        facts: [
            ['Requested', '18 Oct 2026, 3:45 pm IST'],
            ['Device', 'Chrome on macOS'],
            ['Location', 'Mumbai, India (approximate)'],
            ['Link expires', '18 Oct 2026, 4:45 pm IST'],
        ],
        note: '<strong>Didn’t ask for this?</strong> Ignore this email and your password stays the same. Nobody can change it without this link.',
        fallbackLink: `${base}/reset-password/9f3c2a7e41b8d6`,
        help: 'If this wasn’t you, contact support@example.com straight away.',
        timeline: [
            { label: 'Requested', date: '3:45 pm', state: 'done' },
            { label: 'Link works', date: '60 min', state: 'current' },
            { label: 'Expires', date: '4:45 pm', state: 'upcoming' },
        ],
        reason: 'Security emails are always sent and can’t be turned off.',
    },

    'ticket-reply': {
        subject: '[#482913] Email not working on custom domain',
        preheader:
            'Sam from Technical Support replied: your MX records still point to your previous host.',
        kind: 'Support',
        ref: 'Ticket #482913',
        status: { tone: 'info', label: 'Waiting for your reply' },
        title: 'Email not working on custom domain',
        opening: 'Sam from Technical Support replied to your ticket.',
        message: {
            author: 'Sam Rivera',
            initials: 'SR',
            role: 'Technical Support',
            time: '18 Oct 2026, 11:02 am',
            html: [
                '<p>Hi Meera,</p>',
                '<p>Thanks for the screenshots, they helped. Your domain’s MX records still point to your previous host, so new mail never reaches the mailbox we set up.</p>',
                '<p>Please update these records at your DNS provider:</p>',
                '<pre>MX  10  mx1.example.net\nMX  20  mx2.example.net</pre>',
                '<p>Changes usually take effect within an hour. Reply here once they’re updated and I’ll check delivery from our side.</p>',
                '<p>Sam</p>',
            ],
            plain: 'Hi Meera,\n\nThanks for the screenshots, they helped. Your domain’s MX records still point to your previous host, so new mail never reaches the mailbox we set up.\n\nPlease update these records at your DNS provider:\n\n    MX  10  mx1.example.net\n    MX  20  mx2.example.net\n\nChanges usually take effect within an hour. Reply here once they’re updated and I’ll check delivery from our side.\n\nSam',
        },
        cta: { label: 'View reply', url: `${base}/support/tickets/482913` },
        note: 'You can also reply to this email. Keep [#482913] in the subject so your reply reaches this ticket.',
        facts: [
            ['Ticket', '#482913'],
            ['Department', 'Technical Support'],
            ['Priority', 'Medium'],
            ['Opened', '17 Oct 2026'],
        ],
        help: 'Need help with something else? Visit the help centre to open a new ticket.',
        timeline: [
            { label: 'Opened', date: '17 Oct', state: 'done' },
            { label: 'Replied', date: '18 Oct', state: 'done' },
            { label: 'Your turn', date: 'Waiting', state: 'current' },
        ],
        reason: 'You’re receiving this because you opened ticket #482913.',
    },
};

// Stress sample for layout checks only (not part of the five): very long invoice number, product name and
// amount, and a three-line address. Rendered as invoice-issued-long.html in every direction.
export const stress = {
    ...emails['invoice-issued'],
    subject:
        'Invoice INV-2026-SHARMADIGITAL-000142-B for ₹12,34,56,789.00 is due 1 Nov 2026',
    ref: 'INV-2026-SHARMADIGITAL-000142-B',
    amount: {
        label: 'Amount due',
        value: '₹12,34,56,789.00',
        caption: 'Due 1 Nov 2026',
    },
    cta: {
        label: 'Pay ₹12,34,56,789.00',
        url: `${base}/invoices/INV-2026-SHARMADIGITAL-000142-B/pay`,
    },
    facts: [
        ['Invoice', 'INV-2026-SHARMADIGITAL-000142-B'],
        ['Issued', '18 Oct 2026'],
        ['Due', '1 Nov 2026'],
        [
            'Billed to',
            'Sharma Digital Infrastructure and Managed Services LLP<br>Unit 1204, Tower B, Cybercity Business Park<br>Magarpatta Road, Hadapsar, Pune 411013',
        ],
    ],
    lines: {
        heading: 'What you’re paying for',
        items: [
            {
                name: 'Dedicated Server DS-8 Enterprise with 2 × AMD EPYC 9354 and 1 TB ECC RAM',
                meta: 'datacenter-mumbai-1.sharmadigital-infrastructure.in · 1 Nov 2026 – 31 Oct 2027',
                amount: '₹10,46,23,397.46',
            },
            { name: 'Managed backups', meta: '36 months', amount: '₹1,000.00' },
        ],
        totals: [
            { label: 'Subtotal', amount: '₹10,46,24,397.46' },
            { label: 'CGST 9%', amount: '₹94,16,195.77' },
            { label: 'SGST 9%', amount: '₹94,16,195.77' },
            { label: 'Total due', amount: '₹12,34,56,789.00', strong: true },
        ],
    },
};
