<?php

return [
    /** Off on fresh installs (owner, 2026-10-21); hosts turn it on in Admin → Settings → Identity verification. */
    'enabled' => false,

    /** Prefix added to a phone number entered without one. */
    'phone_country_code' => '+977',

    /** Largest image a client may upload, in kilobytes. */
    'max_upload_kb' => 5120,

    /**
     * Largest image, in pixels (width × height), that is decoded for re-encoding; checked from the header first. 25
     * megapixels takes a 24 MP phone photo (5712 × 4284). Peaks are per request, not per container, so decodes are
     * serialised by a cache lock (ImageSanitiser::LOCK). Measured container peaks: docs/product/kyc.md §5.
     */
    'max_image_pixels' => 25_000_000,

    /**
     * The cap for a progressive or four-channel (CMYK) JPEG, read from its frame header: libjpeg buffers all its DCT
     * coefficients while decoding, outside PHP's memory limit. A CMYK progressive JPEG at 20 MP peaks at 325 MB
     * in the 512 MB container (production image, served), at 25 MP at about 374 MB.
     */
    'max_buffered_jpeg_pixels' => 20_000_000,

    /**
     * A larger image is scaled down to this many pixels before it is turned and stored. 16 MP keeps an identity
     * document readable (300 dpi or more over an ID card).
     */
    'stored_image_pixels' => 16_000_000,

    /**
     * A re-encoded image larger than max_upload_kb is encoded again, from the same decoded pixels: JPEG or WebP at
     * quality 90, then 75, then 75 scaled to each of these pixel counts (an opaque PNG becomes a JPEG; a PNG with
     * transparency stays PNG and is only scaled). It is refused only when the last step is still too large.
     */
    'step_down_pixels' => [12_000_000, 8_000_000],

    /**
     * WebP is scaled down to this many pixels before it is encoded: libwebp's encoder allocates outside PHP's memory
     * limit and grows with the output. A detailed 25 MP WebP peaks at about 300 MB in
     * the container with 8 MP, against 455–465 MB at 16 MP (production image, served).
     */
    'stored_webp_pixels' => 8_000_000,

    /** Seconds an upload waits for another upload's image decode to finish before it is refused ("try again"). */
    'image_decode_wait_seconds' => 5,

    /** Seconds kyc:reencode-images waits for an upload's decode before it leaves that document for its next run. */
    'reencode_decode_wait_seconds' => 30,

    /** Days documents are kept after the client account is closed (owner, 2026-10-21). */
    'retention_days' => 90,

    /** Most documents the daily retention task deletes in one run; the rest wait for the next run. */
    'prune_batch' => 500,

    /**
     * Orphan sweep (#298): image files no document refers to are deleted once older than orphan_min_age_hours (at
     * least 24). One run looks at most at orphan_scan_limit files and deletes at most orphan_delete_limit.
     */
    'orphan_min_age_hours' => 24,
    'orphan_scan_limit' => 5000,
    'orphan_delete_limit' => 200,

    /**
     * Circuit breaker: a run that finds more unreferenced files than this, or any while there are no documents at all,
     * deletes nothing and reports it (a restored database, or another install sharing the bucket).
     */
    'orphan_breaker' => 20,
];
