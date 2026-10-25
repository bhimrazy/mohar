<?php

namespace App\Contracts\Reports;

/**
 * Marker for a report whose export carries personal or tax identifiers in bulk (ADR 0025 §4). The registry reads it
 * from the class when the report is registered, without building the report: the permission `reports.{key}.export`
 * is then sensitive (never granted by a wildcard), and every export needs a recent password confirmation.
 *
 * @api
 */
interface HasSensitiveExport {}
