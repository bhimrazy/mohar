<?php

namespace App\Support;

/**
 * The kernel API version (ADR 0010): one semver for everything in docs/architecture/kernel-api.txt.
 *
 * The release PR bumps it, never a feature PR, so parallel kernel PRs do not conflict on it. Before 1.0.0 every
 * release whose kernel changelog has entries bumps the minor (breaking changes included, ADR 0010 "Pre-1.0 window");
 * from 1.0.0 a release bumps the major when an entry is marked breaking, otherwise the minor.
 *
 * @api
 */
final class Kernel
{
    public const API_VERSION = '0.1.0';
}
