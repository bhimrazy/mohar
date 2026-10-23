<?php

namespace App\Support\Reports;

use App\Enums\ExportFormat;

/**
 * Writes one export format to a stream (ADR 0025 §3). Adding a format is an enum case plus a writer.
 */
interface ExportWriter
{
    public function format(): ExportFormat;

    public function contentType(): string;

    public function extension(): string;

    /** True when the whole file is built before the response starts (a failure then gives an error response). */
    public function buffered(): bool;

    /**
     * @param  resource  $stream
     */
    public function write(ExportContext $context, $stream): void;
}
