import { describe, expect, it } from 'vitest';
import {
    hasSetupFees,
    matrixErrors,
    matrixFromRows,
    rowsFromMatrix,
} from '../pricing-matrix';

const currencies = [
    { code: 'USD', name: 'US Dollar', prefix: '$', is_default: true },
];
const cycles = [
    { value: 'monthly', label: 'Monthly', months: 1 },
    { value: 'annually', label: 'Annually', months: 12 },
];

describe('rowsFromMatrix', () => {
    it('sends parsed minor units for valid input', () => {
        expect(
            rowsFromMatrix({
                'USD|monthly': {
                    enabled: true,
                    amount: '1,299.50',
                    setup_fee: '5',
                },
            }),
        ).toEqual([
            {
                currency: 'USD',
                cycle: 'monthly',
                enabled: true,
                amount: 129950,
                setup_fee: 500,
            },
        ]);
    });

    it.each(['abc', '12,50', '1.2.3', '-'])(
        'sends invalid price %s unchanged so the server rejects it',
        (input) => {
            const [row] = rowsFromMatrix({
                'USD|monthly': { enabled: true, amount: input, setup_fee: '' },
            });

            expect(row.amount).toBe(input);
        },
    );

    it('sends null for an offered cell without a price and 0 for an empty setup fee', () => {
        expect(
            rowsFromMatrix({
                'USD|monthly': { enabled: true, amount: ' ', setup_fee: '' },
            }),
        ).toEqual([
            {
                currency: 'USD',
                cycle: 'monthly',
                enabled: true,
                amount: null,
                setup_fee: 0,
            },
        ]);
    });

    it('sends an invalid setup fee unchanged', () => {
        expect(
            rowsFromMatrix({
                'USD|monthly': {
                    enabled: true,
                    amount: '10',
                    setup_fee: 'xyz',
                },
            })[0].setup_fee,
        ).toBe('xyz');
    });

    it('drops empty cells that are off and cycles outside the allowed list', () => {
        expect(
            rowsFromMatrix(
                {
                    'USD|monthly': {
                        enabled: false,
                        amount: '',
                        setup_fee: '',
                    },
                    'USD|annually': {
                        enabled: false,
                        amount: '99',
                        setup_fee: '',
                    },
                    'USD|onetime': {
                        enabled: true,
                        amount: '5',
                        setup_fee: '',
                    },
                },
                ['monthly', 'annually'],
            ),
        ).toEqual([
            {
                currency: 'USD',
                cycle: 'annually',
                enabled: false,
                amount: 9900,
                setup_fee: 0,
            },
        ]);
    });
});

describe('matrixFromRows', () => {
    it('shows stored prices and leaves missing cells empty instead of 0.00', () => {
        const matrix = matrixFromRows(
            [
                {
                    currency: 'USD',
                    cycle: 'annually',
                    enabled: true,
                    amount: 9900,
                    setup_fee: 0,
                },
            ],
            currencies,
            cycles,
        );

        expect(matrix['USD|annually']).toEqual({
            enabled: true,
            amount: '99.00',
            setup_fee: '',
        });
        expect(matrix['USD|monthly']).toEqual({
            enabled: false,
            amount: '',
            setup_fee: '',
        });
    });

    it('detects setup fees', () => {
        expect(
            hasSetupFees([
                {
                    currency: 'USD',
                    cycle: 'monthly',
                    enabled: true,
                    amount: 1,
                    setup_fee: 0,
                },
            ]),
        ).toBe(false);
        expect(
            hasSetupFees([
                {
                    currency: 'USD',
                    cycle: 'monthly',
                    enabled: true,
                    amount: 1,
                    setup_fee: 5,
                },
            ]),
        ).toBe(true);
    });
});

describe('matrixErrors', () => {
    it('maps server errors back to the cell and input', () => {
        const submitted = rowsFromMatrix({
            'USD|monthly': { enabled: true, amount: 'abc', setup_fee: 'x' },
        });

        expect(
            matrixErrors(
                {
                    'prices.0.amount': 'Enter a valid price.',
                    'prices.0.setup_fee': 'Bad fee.',
                    name: 'ignored',
                },
                submitted,
            ),
        ).toEqual({
            'USD|monthly': {
                amount: 'Enter a valid price.',
                setup_fee: 'Bad fee.',
            },
        });
    });
});
