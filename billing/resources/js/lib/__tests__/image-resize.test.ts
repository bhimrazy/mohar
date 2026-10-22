import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    MAX_LONG_EDGE,
    SKIP_BYTES,
    MAX_DECODE_BYTES,
    fitWithin,
    keepOriginal,
    prepareFile,
    readImageSize,
    resizeImage,
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
    bytes.push(0xff, 0xda, 0, 2);

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

    it('answers 1 for a JPEG without EXIF or with an invalid value', () => {
        expect(
            readJpegOrientation(
                new Uint8Array([0xff, 0xd8, 0xff, 0xda, 0, 2]).buffer,
            ),
        ).toBe(1);
        expect(readJpegOrientation(jpegWithOrientation(9))).toBe(1);
    });

    it('answers null, never 1, when the header cannot be read', () => {
        expect(
            readJpegOrientation(new Uint8Array([1, 2, 3, 4, 5]).buffer),
        ).toBeNull();
        expect(
            readJpegOrientation(jpegWithOrientation(6).slice(0, 20)),
        ).toBeNull();
    });
});

describe('formatBytes', () => {
    it('uses KB under a megabyte and MB above', () => {
        expect(formatBytes(640_000)).toBe('640 KB');
        expect(formatBytes(6_200_000)).toBe('6.2 MB');
    });
});

/** A JPEG header: optional EXIF orientation, then a SOF0 with the given pixel size. */
function jpegBytes(
    width: number,
    height: number,
    orientation?: number,
): Uint8Array {
    const exif =
        orientation === undefined
            ? new Uint8Array([0xff, 0xd8])
            : new Uint8Array(jpegWithOrientation(orientation).slice(0, -4));
    const sof = [
        0xff,
        0xc0,
        0,
        17,
        8,
        height >> 8,
        height & 255,
        width >> 8,
        width & 255,
        3,
        1,
        0x22,
        0,
        2,
        0x11,
        1,
        3,
        0x11,
        1,
    ];
    const out = new Uint8Array(exif.length + sof.length + 4);
    out.set(exif);
    out.set(sof, exif.length);
    out.set([0xff, 0xda, 0, 2], exif.length + sof.length);

    return out;
}

function jpegFile(bytes: Uint8Array, size = bytes.length): File {
    const file = new File([bytes as BlobPart], 'id.jpg', {
        type: 'image/jpeg',
    });

    return Object.defineProperty(file, 'size', { value: size });
}

describe('readImageSize', () => {
    it('reads a JPEG SOF', () => {
        expect(
            readImageSize(jpegBytes(5600, 4000).buffer as ArrayBuffer),
        ).toEqual({ width: 5600, height: 4000 });
    });

    it('reads a PNG IHDR', () => {
        const png = new DataView(new ArrayBuffer(24));
        png.setUint32(0, 0x89504e47);
        png.setUint32(16, 3000);
        png.setUint32(20, 2000);
        expect(readImageSize(png.buffer)).toEqual({
            width: 3000,
            height: 2000,
        });
    });

    it('answers null for unknown bytes', () => {
        expect(readImageSize(new Uint8Array(40).buffer)).toBeNull();
    });
});

describe('resizeImage with a stubbed browser', () => {
    const drawn: unknown[][] = [];

    function stubBrowser(
        options: { bitmap?: () => unknown; image?: boolean } = {},
    ) {
        const createImageBitmap = vi.fn(async (_file: Blob, _opts?: unknown) =>
            (
                options.bitmap ??
                (() => ({ width: 2800, height: 2000, close: vi.fn() }))
            )(),
        );

        class FakeCanvas {
            constructor(
                public width: number,
                public height: number,
            ) {}

            getContext() {
                return {
                    drawImage: (...args: unknown[]) => drawn.push(args),
                    getImageData: () => ({
                        data: new Uint8ClampedArray([0, 0, 0, 255]),
                    }),
                    fillRect: vi.fn(),
                };
            }

            async convertToBlob(opts: { type: string }) {
                return new Blob([new Uint8Array(300_000)], { type: opts.type });
            }
        }

        vi.stubGlobal(
            'createImageBitmap',
            options.bitmap === undefined && options.image
                ? undefined
                : createImageBitmap,
        );
        vi.stubGlobal('OffscreenCanvas', FakeCanvas);
        vi.stubGlobal(
            'URL',
            Object.assign(URL, {
                createObjectURL: () => 'blob:x',
                revokeObjectURL: () => undefined,
            }),
        );
        vi.stubGlobal(
            'Image',
            class {
                src = '';
                naturalWidth = 4000;
                naturalHeight = 5600;
                async decode() {}
            },
        );

        return createImageBitmap;
    }

    afterEach(() => {
        vi.unstubAllGlobals();
        drawn.length = 0;
    });

    it('asks the browser to apply the EXIF orientation and decodes at the target size', async () => {
        const create = stubBrowser({
            bitmap: () => ({ width: 2800, height: 2000, close: vi.fn() }),
        });
        const result = await resizeImage(
            jpegFile(jpegBytes(5600, 4000), 6_000_000),
        );

        expect(create).toHaveBeenCalledTimes(1);
        expect(create.mock.calls[0][1]).toMatchObject({
            imageOrientation: 'from-image',
            resizeWidth: 2800,
            resizeHeight: 2000,
        });
        expect(result.resized).toBe(true);
        expect(result.original).toMatchObject({ width: 5600, height: 4000 });
        expect(result.output).toMatchObject({ width: 2800, height: 2000 });
        expect(result.file.type).toBe('image/jpeg');
    });

    it('does not give resize sizes for a turned photo and reports the turned size', async () => {
        const create = stubBrowser({
            bitmap: () => ({ width: 4000, height: 5600, close: vi.fn() }),
        });
        const result = await resizeImage(
            jpegFile(jpegBytes(5600, 4000, 6), 6_000_000),
        );

        expect(create.mock.calls[0][1]).toEqual({
            imageOrientation: 'from-image',
        });
        expect(result.original).toMatchObject({ width: 4000, height: 5600 });
        expect(result.output).toMatchObject({ width: 2000, height: 2800 });
    });

    it('skips a small file without decoding it', async () => {
        const create = stubBrowser();
        const result = await resizeImage(
            jpegFile(jpegBytes(2000, 1500), 400_000),
        );

        expect(create).not.toHaveBeenCalled();
        expect(result).toMatchObject({ resized: false, reason: 'small' });
    });

    it('leaves a file above the decode ceiling to the server without decoding', async () => {
        const create = stubBrowser();
        const result = await resizeImage(
            jpegFile(jpegBytes(6000, 6000), MAX_DECODE_BYTES + 1),
        );

        expect(create).not.toHaveBeenCalled();
        expect(result.reason).toBe('too-large');
    });

    it('leaves a rotated JPEG to the server when only the <img> path exists', async () => {
        stubBrowser({ image: true });
        const result = await resizeImage(
            jpegFile(jpegBytes(5600, 4000, 6), 6_000_000),
        );

        expect(result).toMatchObject({ resized: false, reason: 'unsupported' });
        expect(drawn).toHaveLength(0);
    });

    it('also leaves a JPEG with an unreadable header to the server on the <img> path', async () => {
        stubBrowser({ image: true });
        const result = await resizeImage(
            jpegFile(new Uint8Array([0xff, 0xd8, 1, 2, 3, 4, 5, 6]), 6_000_000),
        );

        expect(result.reason).toBe('unsupported');
    });

    it('falls back to the original when the encoder gives no usable blob', async () => {
        stubBrowser();
        vi.stubGlobal(
            'OffscreenCanvas',
            class {
                getContext() {
                    return {
                        drawImage: vi.fn(),
                        getImageData: vi.fn(),
                        fillRect: vi.fn(),
                    };
                }

                async convertToBlob() {
                    return new Blob([], { type: 'image/png' });
                }
            },
        );
        const result = await resizeImage(
            jpegFile(jpegBytes(5600, 4000), 6_000_000),
        );

        expect(result).toMatchObject({ resized: false, reason: 'failed' });
    });
});

describe('prepareFile', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('keeps the original and says failed when the input cannot take the new file', async () => {
        vi.stubGlobal('createImageBitmap', async () => ({
            width: 2800,
            height: 2000,
            close: () => undefined,
        }));
        vi.stubGlobal(
            'OffscreenCanvas',
            class {
                getContext() {
                    return {
                        drawImage: () => undefined,
                        getImageData: () => ({ data: [] }),
                        fillRect: () => undefined,
                    };
                }

                async convertToBlob(o: { type: string }) {
                    return new Blob([new Uint8Array(1000)], { type: o.type });
                }
            },
        );
        const file = jpegFile(jpegBytes(5600, 4000), 6_000_000);
        const result = await prepareFile(file, () => {
            throw new Error('DataTransfer is not available');
        });

        expect(result.file).toBe(file);
        expect(result).toMatchObject({ resized: false, reason: 'failed' });
    });

    it('never rejects, even when the resize itself throws', async () => {
        const file = jpegFile(jpegBytes(10, 10));
        Object.defineProperty(file, 'slice', {
            value: () => {
                throw new Error('boom');
            },
        });
        vi.stubGlobal('createImageBitmap', async () => {
            throw new Error('no');
        });
        const result = await prepareFile(file, () => undefined);

        expect(result.file).toBe(file);
        expect(result.resized).toBe(false);
        expect(keepOriginal(file, 'failed').resized).toBe(false);
    });
});
