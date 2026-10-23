<?php

namespace App\Enums;

/**
 * The kind of input a report filter takes; the export pipeline derives the base validation rules from it
 * (ADR 0025 §1), and the owner's screen picks the control.
 *
 * @open Open enum (ADR 0010): consumers keep a default branch, because a case may be added in a minor release.
 */
enum FilterType: string
{
    /** A company calendar date, Y-m-d. */
    case Date = 'date';
    /** Free text, at most 255 characters. Not written to the activity log unless the filter says so. */
    case Text = 'text';
    /** One of the filter's options. */
    case Select = 'select';
    case Boolean = 'boolean';
}
