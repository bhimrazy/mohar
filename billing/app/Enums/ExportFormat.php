<?php

namespace App\Enums;

/**
 * File formats of the report export pipeline (ADR 0025 §3). CSV and PDF now; XLSX is a third case with its own
 * writer once the IRD portal needs an upload.
 *
 * @open Open enum (ADR 0010): consumers keep a default branch, because a case may be added in a minor release.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Pdf = 'pdf';
}
