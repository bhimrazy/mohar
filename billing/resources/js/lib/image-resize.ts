/**
 * Browser-side downscaling of identity photos before upload (#288). The server keeps its own checks and re-encodes
 * everything; this only cuts upload size. Any failure resolves to the original file so the server decides.
 */

export const MAX_LONG_EDGE = 2800;
export const JPEG_QUALITY = 0.85;
/** A file at or under this size, within the edge limit, is uploaded as it is. */
export const SKIP_BYTES = 1_000_000;
/** Above this the browser does not try to decode; the server decides. */
export const MAX_DECODE_BYTES = 40_000_000;
/** How much of the file is read to find its pixel size and EXIF orientation. */
const HEADER_BYTES = 262_144;

export type Size = { width: number; height: number };

export type ResizeResult = {
    file: File;
    /** True when the returned file is a new, resized one. */
    resized: boolean;
    original: { bytes: number } & Size;
    output: { bytes: number } & Size;
    /** Why the original was kept; null when resized. */
    reason:
        | null
        | 'small'
        | 'unsupported'
        | 'failed'
        | 'not-smaller'
        | 'too-large';
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

/**
 * Reads the EXIF orientation (1-8) from a JPEG header. 1 when the file has no EXIF; null when the header cannot be
 * read (truncated or malformed), so a caller can fail closed.
 */
export function readJpegOrientation(buffer: ArrayBuffer): number | null {
    const view = new DataView(buffer);
    const length = view.byteLength;

    if (length < 4 || view.getUint16(0) !== 0xffd8) {
        return null;
    }

    let offset = 2;

    while (offset + 4 <= length) {
        if (view.getUint8(offset) !== 0xff) {
            return null;
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

    return null;
}

function exifOrientation(
    view: DataView,
    tiff: number,
    end: number,
): number | null {
    const limit = Math.min(end, view.byteLength);

    if (tiff + 8 > limit) {
        return null;
    }

    const little = view.getUint16(tiff) === 0x4949;

    if (!little && view.getUint16(tiff) !== 0x4d4d) {
        return null;
    }

    const ifd = tiff + view.getUint32(tiff + 4, little);

    if (ifd + 2 > limit) {
        return null;
    }

    const entries = view.getUint16(ifd, little);

    for (let i = 0; i < entries; i++) {
        const entry = ifd + 2 + i * 12;

        if (entry + 12 > limit) {
            return null;
        }

        if (view.getUint16(entry, little) === 0x0112) {
            const value = view.getUint16(entry + 8, little);

            return value >= 1 && value <= 8 ? value : 1;
        }
    }

    return 1;
}

/** Stored pixel size (before any EXIF turn) read from the header of a JPEG, PNG or WebP; null when not found. */
export function readImageSize(buffer: ArrayBuffer): Size | null {
    const view = new DataView(buffer);
    const length = view.byteLength;

    if (length >= 24 && view.getUint32(0) === 0x89504e47) {
        return { width: view.getUint32(16), height: view.getUint32(20) };
    }

    if (
        length >= 30 &&
        view.getUint32(0) === 0x52494646 &&
        view.getUint32(8) === 0x57454250
    ) {
        const kind = view.getUint32(12);

        if (kind === 0x56503820) {
            return {
                width: view.getUint16(26, true) & 0x3fff,
                height: view.getUint16(28, true) & 0x3fff,
            };
        }

        if (kind === 0x5650384c) {
            const bits = view.getUint32(21, true);

            return {
                width: (bits & 0x3fff) + 1,
                height: ((bits >> 14) & 0x3fff) + 1,
            };
        }

        if (kind === 0x56503858) {
            return {
                width:
                    (view.getUint8(24) |
                        (view.getUint8(25) << 8) |
                        (view.getUint8(26) << 16)) +
                    1,
                height:
                    (view.getUint8(27) |
                        (view.getUint8(28) << 8) |
                        (view.getUint8(29) << 16)) +
                    1,
            };
        }

        return null;
    }

    if (length < 4 || view.getUint16(0) !== 0xffd8) {
        return null;
    }

    let offset = 2;

    while (offset + 9 <= length) {
        if (view.getUint8(offset) !== 0xff) {
            return null;
        }

        const marker = view.getUint8(offset + 1);

        if (
            marker >= 0xc0 &&
            marker <= 0xcf &&
            marker !== 0xc4 &&
            marker !== 0xc8 &&
            marker !== 0xcc
        ) {
            return {
                height: view.getUint16(offset + 5),
                width: view.getUint16(offset + 7),
            };
        }

        if (marker === 0xda || marker === 0xd9) {
            return null;
        }

        offset += 2 + view.getUint16(offset + 2);
    }

    return null;
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
    /** Pixel size of the photo as shown (orientation applied), before any resize done while decoding. */
    full: Size;
    /** True when the pixels are upright (the browser applied the EXIF orientation). */
    upright: boolean;
    close: () => void;
};

/**
 * Decodes once. With createImageBitmap the browser applies the EXIF orientation itself and, when the pixel size and
 * orientation are known from the header, decodes straight at the target size. The `<img>` path is used only where
 * createImageBitmap is missing or refuses the file.
 */
async function decode(
    file: File,
    header: { size: Size | null; orientation: number | null },
): Promise<Decoded> {
    if (typeof createImageBitmap === 'function') {
        const options: ImageBitmapOptions = { imageOrientation: 'from-image' };

        // The resize options are given only for an unturned photo, where header size and bitmap size agree.
        if (header.size && header.orientation === 1) {
            const target = fitWithin(header.size.width, header.size.height);

            if (
                target.width !== header.size.width ||
                target.height !== header.size.height
            ) {
                options.resizeWidth = target.width;
                options.resizeHeight = target.height;
                options.resizeQuality = 'high';
            }
        }

        try {
            const bitmap = await createImageBitmap(file, options);

            return {
                source: bitmap,
                width: bitmap.width,
                height: bitmap.height,
                full:
                    options.resizeWidth && header.size
                        ? header.size
                        : { width: bitmap.width, height: bitmap.height },
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

        // Without the orientation option we cannot be sure the photo is upright. Only a JPEG whose header says
        // "no rotation" is trusted; a rotated or unreadable one is left to the server, which applies EXIF itself.
        return {
            source: image,
            width: image.naturalWidth,
            height: image.naturalHeight,
            full: { width: image.naturalWidth, height: image.naturalHeight },
            upright: file.type !== 'image/jpeg' || header.orientation === 1,
            close: () => undefined,
        };
    } finally {
        URL.revokeObjectURL(url);
    }
}

type Context2D = CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D;

function hasTransparency(context: Context2D, size: Size): boolean {
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

export function keepOriginal(
    file: File,
    reason: ResizeResult['reason'],
    size: Size = { width: 0, height: 0 },
): ResizeResult {
    const info = { bytes: file.size, ...size };

    return { file, resized: false, original: info, output: info, reason };
}

export async function resizeImage(file: File): Promise<ResizeResult> {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        // HEIC and anything else: let the server decide.
        return keepOriginal(file, 'unsupported');
    }

    if (file.size > MAX_DECODE_BYTES) {
        return keepOriginal(file, 'too-large');
    }

    let header: { size: Size | null; orientation: number | null };

    try {
        const buffer = await file.slice(0, HEADER_BYTES).arrayBuffer();
        header = {
            size: readImageSize(buffer),
            orientation:
                file.type === 'image/jpeg' ? readJpegOrientation(buffer) : 1,
        };
    } catch {
        header = { size: null, orientation: null };
    }

    // A small file with a known size needs no decoding at all.
    if (header.size) {
        const known =
            header.orientation !== null && header.orientation >= 5
                ? { width: header.size.height, height: header.size.width }
                : header.size;

        if (!needsResize(file.size, known.width, known.height)) {
            return keepOriginal(file, 'small', known);
        }
    }

    let decoded: Decoded;

    try {
        decoded = await decode(file, header);
    } catch {
        return keepOriginal(file, 'unsupported');
    }

    try {
        if (!decoded.upright) {
            return keepOriginal(file, 'unsupported');
        }

        const from = decoded.full;

        if (!needsResize(file.size, from.width, from.height)) {
            return keepOriginal(file, 'small', from);
        }

        const to = fitWithin(from.width, from.height);
        const canvas: HTMLCanvasElement | OffscreenCanvas =
            typeof OffscreenCanvas === 'function'
                ? new OffscreenCanvas(to.width, to.height)
                : Object.assign(document.createElement('canvas'), {
                      width: to.width,
                      height: to.height,
                  });
        const context = canvas.getContext('2d', {
            willReadFrequently: true,
        }) as Context2D | null;

        if (!context) {
            return keepOriginal(file, 'failed', from);
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
            return keepOriginal(file, 'failed', from);
        }

        if (blob.size >= file.size && to.width === from.width) {
            return keepOriginal(file, 'not-smaller', from);
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
        return keepOriginal(file, 'failed');
    } finally {
        decoded.close();
    }
}

/**
 * Resizes and hands the result to `place` (which puts it into the file input). If `place` throws (older Safari, a
 * WebView without DataTransfer), the original stays in the input and the result says `failed`. Never rejects.
 */
export async function prepareFile(
    file: File,
    place: (file: File) => void,
): Promise<ResizeResult> {
    let result: ResizeResult;

    try {
        result = await resizeImage(file);
    } catch {
        return keepOriginal(file, 'failed');
    }

    if (result.resized) {
        try {
            place(result.file);
        } catch {
            return keepOriginal(file, 'failed', result.original);
        }
    }

    return result;
}

export function formatBytes(bytes: number): string {
    if (bytes >= 1_000_000) {
        return `${(bytes / 1_000_000).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1000))} KB`;
}
