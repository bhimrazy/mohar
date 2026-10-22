/**
 * Browser-side downscaling of identity photos before upload (#288). The server keeps its own checks and re-encodes
 * everything; this only cuts upload size. Any failure resolves to the original file so the server decides.
 */

export const MAX_LONG_EDGE = 2800;
export const JPEG_QUALITY = 0.85;
/** A file at or under this size, within the edge limit, is uploaded as it is. */
export const SKIP_BYTES = 1_000_000;

export type Size = { width: number; height: number };

export type ResizeResult = {
    file: File;
    /** True when the returned file is a new, resized one. */
    resized: boolean;
    original: { bytes: number } & Size;
    output: { bytes: number } & Size;
    /** Why the original was kept; null when resized. */
    reason: null | 'small' | 'unsupported' | 'failed' | 'not-smaller';
};

/** Target pixel size: the long edge is capped, the ratio kept, and a small image is never enlarged. */
export function fitWithin(
    width: number,
    height: number,
    maxEdge: number = MAX_LONG_EDGE,
): Size {
    const long = Math.max(width, height);

    if (long <= maxEdge || width < 1 || height < 1) {
        return { width, height };
    }

    const scale = maxEdge / long;

    return {
        width: Math.max(1, Math.round(width * scale)),
        height: Math.max(1, Math.round(height * scale)),
    };
}

/** Whether a file needs processing at all: bigger than the edge limit, or heavier than the skip size. */
export function needsResize(
    bytes: number,
    width: number,
    height: number,
): boolean {
    return Math.max(width, height) > MAX_LONG_EDGE || bytes > SKIP_BYTES;
}

/** The type to encode as: PNG and WebP with transparency stay PNG, everything else becomes JPEG. */
export function outputType(hasAlpha: boolean): 'image/png' | 'image/jpeg' {
    return hasAlpha ? 'image/png' : 'image/jpeg';
}

/** Reads the EXIF orientation (1-8) from a JPEG header; 1 when absent or unreadable. */
export function readJpegOrientation(buffer: ArrayBuffer): number {
    const view = new DataView(buffer);
    const length = view.byteLength;

    if (length < 4 || view.getUint16(0) !== 0xffd8) {
        return 1;
    }

    let offset = 2;

    while (offset + 4 <= length) {
        if (view.getUint8(offset) !== 0xff) {
            return 1;
        }

        const marker = view.getUint8(offset + 1);

        if (marker === 0xda || marker === 0xd9) {
            return 1;
        }

        const size = view.getUint16(offset + 2);

        if (marker === 0xe1 && offset + 4 + 6 <= length) {
            const start = offset + 4;

            if (view.getUint32(start) === 0x45786966) {
                return exifOrientation(view, start + 6, offset + 2 + size);
            }
        }

        offset += 2 + size;
    }

    return 1;
}

function exifOrientation(view: DataView, tiff: number, end: number): number {
    const limit = Math.min(end, view.byteLength);

    if (tiff + 8 > limit) {
        return 1;
    }

    const little = view.getUint16(tiff) === 0x4949;

    if (!little && view.getUint16(tiff) !== 0x4d4d) {
        return 1;
    }

    const ifd = tiff + view.getUint32(tiff + 4, little);

    if (ifd + 2 > limit) {
        return 1;
    }

    const entries = view.getUint16(ifd, little);

    for (let i = 0; i < entries; i++) {
        const entry = ifd + 2 + i * 12;

        if (entry + 12 > limit) {
            return 1;
        }

        if (view.getUint16(entry, little) === 0x0112) {
            const value = view.getUint16(entry + 8, little);

            return value >= 1 && value <= 8 ? value : 1;
        }
    }

    return 1;
}

function baseName(name: string): string {
    const dot = name.lastIndexOf('.');

    return dot > 0 ? name.slice(0, dot) : name || 'photo';
}

function extension(type: string): string {
    return type === 'image/png' ? 'png' : 'jpg';
}

type Decoded = {
    source: CanvasImageSource;
    width: number;
    height: number;
    /** True when the pixels are upright (the browser applied the EXIF orientation). */
    upright: boolean;
    close: () => void;
};

async function decode(file: File): Promise<Decoded> {
    if (typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file, {
                imageOrientation: 'from-image',
            });

            return {
                source: bitmap,
                width: bitmap.width,
                height: bitmap.height,
                upright: true,
                close: () => bitmap.close(),
            };
        } catch {
            // Fall through to the image element.
        }
    }

    const url = URL.createObjectURL(file);

    try {
        const image = new Image();
        image.src = url;
        await image.decode();

        // Without createImageBitmap's orientation option we cannot be sure the browser turned the photo upright.
        // A rotated JPEG is left to the server, which applies the EXIF orientation itself.
        const upright =
            file.type !== 'image/jpeg' ||
            readJpegOrientation(await file.slice(0, 65536).arrayBuffer()) === 1;

        return {
            source: image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            upright,
            close: () => undefined,
        };
    } finally {
        URL.revokeObjectURL(url);
    }
}

function hasTransparency(
    context: CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D,
    size: Size,
): boolean {
    const { data } = context.getImageData(0, 0, size.width, size.height);

    for (let i = 3; i < data.length; i += 4) {
        if (data[i] < 255) {
            return true;
        }
    }

    return false;
}

async function encode(
    canvas: HTMLCanvasElement | OffscreenCanvas,
    type: string,
): Promise<Blob | null> {
    if ('convertToBlob' in canvas) {
        return canvas.convertToBlob({ type, quality: JPEG_QUALITY });
    }

    return new Promise((resolve) => canvas.toBlob(resolve, type, JPEG_QUALITY));
}

function original(file: File, reason: ResizeResult['reason']): ResizeResult {
    const none = { bytes: file.size, width: 0, height: 0 };

    return {
        file,
        resized: false,
        original: none,
        output: none,
        reason,
    };
}

export async function resizeImage(file: File): Promise<ResizeResult> {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        // HEIC and anything else: let the server decide.
        return original(file, 'unsupported');
    }

    let decoded: Decoded;

    try {
        decoded = await decode(file);
    } catch {
        return original(file, 'unsupported');
    }

    if (!decoded.upright) {
        decoded.close();

        return original(file, 'unsupported');
    }

    const from: Size = { width: decoded.width, height: decoded.height };

    try {
        if (!needsResize(file.size, from.width, from.height)) {
            return {
                ...original(file, 'small'),
                original: { bytes: file.size, ...from },
                output: { bytes: file.size, ...from },
            };
        }

        const to = fitWithin(from.width, from.height);
        const canvas: HTMLCanvasElement | OffscreenCanvas =
            typeof OffscreenCanvas === 'function'
                ? new OffscreenCanvas(to.width, to.height)
                : Object.assign(document.createElement('canvas'), {
                      width: to.width,
                      height: to.height,
                  });
        const context = canvas.getContext('2d') as
            | CanvasRenderingContext2D
            | OffscreenCanvasRenderingContext2D
            | null;

        if (!context) {
            return original(file, 'failed');
        }

        context.imageSmoothingQuality = 'high';
        context.drawImage(decoded.source, 0, 0, to.width, to.height);

        const type = outputType(
            file.type !== 'image/jpeg' && hasTransparency(context, to),
        );

        if (type === 'image/jpeg') {
            // JPEG has no alpha: repaint on white so a transparent edge is not black.
            context.globalCompositeOperation = 'destination-over';
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, to.width, to.height);
        }

        const blob = await encode(canvas, type);

        if (!blob || blob.type !== type) {
            return original(file, 'failed');
        }

        if (blob.size >= file.size && to.width === from.width) {
            return {
                ...original(file, 'not-smaller'),
                original: { bytes: file.size, ...from },
                output: { bytes: file.size, ...from },
            };
        }

        return {
            file: new File(
                [blob],
                `${baseName(file.name)}.${extension(type)}`,
                {
                    type,
                    lastModified: file.lastModified,
                },
            ),
            resized: true,
            original: { bytes: file.size, ...from },
            output: { bytes: blob.size, ...to },
            reason: null,
        };
    } catch {
        return original(file, 'failed');
    } finally {
        decoded.close();
    }
}

export function formatBytes(bytes: number): string {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}
