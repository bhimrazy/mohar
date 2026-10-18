<?php

namespace App\Enums;

/**
 * Result of one step of a connection check (ConnectionCheckStep).
 *
 * Closed enum (ADR 0010): the checklist renders every case.
 */
enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
