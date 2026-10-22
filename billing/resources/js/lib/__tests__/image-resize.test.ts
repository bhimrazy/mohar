import { describe, expect, it } from 'vitest';
import {
    MAX_LONG_EDGE,
    SKIP_BYTES,
    fitWithin,
    formatBytes,
    needsResize,
    outputType,
    readJpegOrientation,
} from '../image-resize';

/** A JPEG header with one EXIF segment holding the given orientation. */
function jpegWithOrientation(value: number, little = true): ArrayBuffer {
    const bytes: number[] = [0xff, 0xd8, 0xff, 0xe1, 0x00, 0x22];
    const u16 = (n: number) => (little ? [n & 255, n >> 8] : [n >> 8, n & 255]);
    const u32 = (n: number) =>
        little
            ? [n & 255, (n >> 8) & 255, 0, 0]
            : [0, 0, (n >> 8) & 255, n & 255];

    bytes.push(0x45, 0x78, 0x69, 0x66, 0, 0); // "Exif\0\0"
    bytes.push(
        ...(little ? [0x49, 0x49] : [0x4d, 0x4d]),
        ...u16(42),
        ...u32(8),
    );
    bytes.push(...u16(1)); // one entry
    bytes.push(...u16(0x0112), ...u16(3), ...u32(1), ...u16(value), 0, 0);
    bytes.push(...u32(0)); // no next IFD
    bytes.push(0xff, 0xda);

    return new Uint8Array(bytes).buffer;
}

describe('fitWithin', () => {
    it('keeps the ratio and caps the long edge', () => {
        expect(fitWithin(5600, 4000)).toEqual({
            width: MAX_LONG_EDGE,
            height: 2000,
        });
        expect(fitWithin(4000, 8000)).toEqual({
            width: 1400,
            height: MAX_LONG_EDGE,
        });
    });

    it('never enlarges a small image', () => {
        expect(fitWithin(1200, 800)).toEqual({ width: 1200, height: 800 });
        expect(fitWithin(MAX_LONG_EDGE, 100)).toEqual({
            width: MAX_LONG_EDGE,
            height: 100,
        });
    });

    it('keeps a thin image at least one pixel wide', () => {
        expect(fitWithin(30000, 2)).toEqual({
            width: MAX_LONG_EDGE,
            height: 1,
        });
    });
});

describe('needsResize', () => {
    it('skips a small, light file', () => {
        expect(needsResize(SKIP_BYTES, 2000, 1500)).toBe(false);
    });

    it('resizes by dimensions or by weight', () => {
        expect(needsResize(200_000, MAX_LONG_EDGE + 1, 100)).toBe(true);
        expect(needsResize(SKIP_BYTES + 1, 1000, 800)).toBe(true);
    });
});

describe('outputType', () => {
    it('keeps transparency as PNG and uses JPEG otherwise', () => {
        expect(outputType(true)).toBe('image/png');
        expect(outputType(false)).toBe('image/jpeg');
    });
});

describe('readJpegOrientation', () => {
    it.each([1, 3, 6, 8])(
        'reads orientation %i in both byte orders',
        (value) => {
            expect(readJpegOrientation(jpegWithOrientation(value, true))).toBe(
                value,
            );
            expect(readJpegOrientation(jpegWithOrientation(value, false))).toBe(
                value,
            );
        },
    );

    it('answers 1 for no EXIF, an invalid value or a file that is not a JPEG', () => {
        expect(
            readJpegOrientation(
                new Uint8Array([0xff, 0xd8, 0xff, 0xda]).buffer,
            ),
        ).toBe(1);
        expect(readJpegOrientation(jpegWithOrientation(9))).toBe(1);
        expect(
            readJpegOrientation(new Uint8Array([1, 2, 3, 4, 5]).buffer),
        ).toBe(1);
    });

    it('does not read past a truncated header', () => {
        expect(readJpegOrientation(jpegWithOrientation(6).slice(0, 20))).toBe(
            1,
        );
    });
});

describe('formatBytes', () => {
    it('uses KB under a megabyte and MB above', () => {
        expect(formatBytes(640_000)).toBe('640 KB');
        expect(formatBytes(6_200_000)).toBe('6.2 MB');
    });
});
