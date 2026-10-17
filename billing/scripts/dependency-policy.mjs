/**
 * Pure (offline) rules used by check-dependency-age.mjs — kept separate so they are unit-tested
 * (scripts/__tests__/dependency-policy.test.mjs) without network access.
 */

const NPM_REGISTRY = 'https://registry.npmjs.org/';

/**
 * The only tarball URL npm may install for `name@version` (scoped names keep the scope in the path,
 * the file name uses the bare name).
 *
 * @param {string} name
 * @param {string} version
 * @returns {string}
 */
export function npmTarballUrl(name, version) {
    const bareName = name.split('/').pop();

    return `${NPM_REGISTRY}${name}/-/${bareName}-${version}.tgz`;
}

/**
 * Offline check of a package-lock entry: the tarball npm installs must be the registry tarball of exactly
 * the name and version whose publish date we verify, and it must carry an integrity hash.
 *
 * @param {{name: string, version: string, resolved?: string, integrity?: string}} entry
 * @returns {string[]} problems
 */
export function npmEntryProblems(entry) {
    const problems = [];
    const expected = npmTarballUrl(entry.name, entry.version);
    if (!entry.resolved) {
        problems.push(`has no resolved tarball URL`);
    } else if (entry.resolved !== expected) {
        problems.push(
            `resolves to ${entry.resolved}, expected ${expected} (tarball must match the locked name and version)`,
        );
    }
    if (!entry.integrity) {
        problems.push('has no integrity hash');
    }

    return problems;
}

/**
 * The lockfile integrity must equal the registry's published integrity for that version.
 *
 * @param {string|undefined} lockIntegrity
 * @param {{integrity?: string, shasum?: string}|undefined} registryDist
 * @returns {string|null} problem
 */
export function npmIntegrityProblem(lockIntegrity, registryDist) {
    if (!registryDist) {
        return 'version not found on the registry';
    }
    if (!registryDist.integrity) {
        return 'registry publishes no integrity hash for this version';
    }

    return lockIntegrity === registryDist.integrity
        ? null
        : `integrity ${lockIntegrity} does not match the registry (${registryDist.integrity})`;
}

/**
 * composer.lock `dist`/`source` must be what packagist publishes for that version (URL and commit reference),
 * so the archive Composer downloads is the release whose date we verify.
 *
 * @param {{dist?: {url?: string, reference?: string}, source?: {url?: string, reference?: string}}} locked
 * @param {{dist?: {url?: string, reference?: string}, source?: {url?: string, reference?: string}}} published
 * @returns {string[]} problems
 */
export function composerDistProblems(locked, published) {
    const problems = [];
    for (const field of ['dist', 'source']) {
        const lockedValue = locked[field];
        if (!lockedValue) {
            continue;
        }
        const publishedValue = published[field];
        if (!publishedValue) {
            problems.push(`${field} is not published on packagist`);
            continue;
        }
        for (const property of ['url', 'reference']) {
            if (lockedValue[property] !== publishedValue[property]) {
                problems.push(
                    `${field}.${property} ${lockedValue[property]} does not match packagist (${publishedValue[property]})`,
                );
            }
        }
    }

    return problems;
}

/**
 * A pinned image digest is accepted only when the registry says the tag currently points at it — either the
 * multi-arch index digest or one of its per-platform manifest digests.
 *
 * @param {string} pinnedDigest
 * @param {{indexDigest?: string|null, platformDigests?: string[]}} tag
 * @returns {boolean}
 */
export function digestBelongsToTag(pinnedDigest, tag) {
    if (!/^sha256:[0-9a-f]{64}$/.test(pinnedDigest)) {
        return false;
    }

    return (
        pinnedDigest === tag.indexDigest ||
        (tag.platformDigests ?? []).includes(pinnedDigest)
    );
}

/**
 * `Security-Exception: <name>@<version>` lines (PR body or DEPENDENCY_AGE_EXCEPTIONS). Several per line may be
 * separated by commas or spaces. A leading "v" in versions is ignored.
 *
 * @param {string} text
 * @returns {Set<string>} normalised "name@version"
 */
export function parseSecurityExceptions(text) {
    const exceptions = new Set();
    for (const [, list] of (text ?? '').matchAll(
        /^\s*Security-Exception:\s*(.+)$/gim,
    )) {
        for (const token of list.split(/[\s,]+/).filter(Boolean)) {
            const separator = token.lastIndexOf('@');
            if (separator > 0) {
                exceptions.add(
                    normalise(
                        token.slice(0, separator),
                        token.slice(separator + 1),
                    ),
                );
            }
        }
    }

    return exceptions;
}

/**
 * @param {Set<string>} exceptions
 * @param {{name: string, version: string}} item
 * @returns {boolean}
 */
export function isExcepted(exceptions, item) {
    return exceptions.has(normalise(item.name, item.version));
}

function normalise(name, version) {
    return `${name.toLowerCase()}@${String(version).replace(/^v/, '')}`;
}
