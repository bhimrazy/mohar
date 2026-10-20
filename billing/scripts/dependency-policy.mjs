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

/**
 * External images a Dockerfile builds from, for the pinning and cooldown checks: a `# syntax=<image>` parser
 * directive, `FROM <image>` (not `scratch`, not an earlier stage) and `--from=<image>` in `COPY` / `RUN --mount`. Stage names and numeric stage indexes
 * are skipped. Line continuations are joined and comments ignored. A reference that uses a build argument
 * (`$VAR`) is returned unchanged, so it fails the digest check instead of escaping it.
 *
 * @param {string} text
 * @returns {string[]} references in file order, e.g. "node:24.21.0-trixie-slim@sha256:…"
 */
export function dockerfileImages(text) {
    const stages = new Set();
    const images = [];

    // Parser directives (`# syntax=<frontend image>`) are only valid before the first instruction or comment;
    // BuildKit pulls the syntax frontend as an image, so it is checked like a FROM.
    for (const line of text.split(/\r?\n/)) {
        const directive = line.match(/^#\s*([a-z]+)\s*=\s*(\S+)\s*$/i);
        if (!directive) {
            break;
        }
        if (directive[1].toLowerCase() === 'syntax') {
            images.push(directive[2]);
        }
    }

    const lines = text
        .replace(/\\[ \t]*\r?\n/g, ' ')
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line !== '' && !line.startsWith('#'));

    const external = (reference) =>
        reference.toLowerCase() !== 'scratch' &&
        !/^\d+$/.test(reference) &&
        !stages.has(reference.toLowerCase());

    for (const line of lines) {
        const from = line.match(
            /^FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+(\S+))?/i,
        );
        if (from) {
            if (external(from[1])) {
                images.push(from[1]);
            }
            if (from[2]) {
                stages.add(from[2].toLowerCase());
            }
            continue;
        }
        const references = [];
        if (/^(COPY|ADD)\b/i.test(line)) {
            references.push(
                ...[...line.matchAll(/\s--from=(\S+)/gi)].map((m) => m[1]),
            );
        }
        if (/^RUN\b/i.test(line)) {
            for (const [, mount] of line.matchAll(/\s--mount=(\S+)/gi)) {
                references.push(
                    ...[...mount.matchAll(/(?:^|,)from=([^,]+)/gi)].map(
                        (m) => m[1],
                    ),
                );
            }
        }
        images.push(...references.filter(external));
    }

    return images;
}

/**
 * Whether a repository path is a Dockerfile or Containerfile (`Dockerfile`, `Dockerfile.prod`, `worker.Dockerfile`,
 * `Containerfile`), not its `.dockerignore`.
 *
 * @param {string} path
 * @returns {boolean}
 */
export function isDockerfile(path) {
    const name = path.split('/').pop() ?? '';
    return (
        !name.endsWith('.dockerignore') &&
        /^(Dockerfile|Containerfile)([.-].+)?$|\.(Dockerfile|Containerfile)$/.test(
            name,
        )
    );
}

/**
 * The `image:` values of a compose or workflow file, for the pinning and cooldown checks. Every `image:` line is
 * returned, whatever follows the value (CRLF line ends too), so nothing escapes the check by its formatting.
 * `own` marks the app's own image (built from deploy/Dockerfile or pushed by us), which is not a third-party
 * dependency: only a value that is wholly `${VAR}` or `${VAR:-mohar-app:<tag>}` and carries a `# own image` comment
 * counts, so a third-party image can be hidden neither behind a variable nor in its default.
 *
 * @param {string} text
 * @returns {{image: string, own: boolean}[]}
 */
export function composeImages(text) {
    const images = [];
    for (const line of text.split(/\r?\n/)) {
        const entry = line.match(/^\s*image:\s*(.*)$/);
        if (!entry) {
            continue;
        }
        const rest = entry[1].trim();
        const value = rest.match(/^(['"]?)([^\s'"#]+)\1/);
        const image = value ? value[2] : rest;
        const comment = value ? rest.slice(value[0].length).trim() : '';
        images.push({
            image,
            own:
                /^\$\{[A-Z0-9_]+(:-mohar-app:[A-Za-z0-9._-]+)?\}$/.test(
                    image,
                ) && /^#\s*own image\b/.test(comment),
        });
    }

    return images;
}

/**
 * Direct dependencies of a package.json that are not pinned to one exact version (ADR 0007): ranges, tags, URLs and
 * workspaces. An `npm:<name>@<version>` alias passes when its version is exact.
 *
 * @param {object|null} packageJson
 * @returns {string[]} "name@spec" of every dependency that is not exact
 */
export function directPinProblems(packageJson) {
    const exact = /^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/;
    const problems = [];
    for (const section of [
        'dependencies',
        'devDependencies',
        'optionalDependencies',
    ]) {
        for (const [name, spec] of Object.entries(
            packageJson?.[section] ?? {},
        )) {
            const version = String(spec).startsWith('npm:')
                ? String(spec).replace(/^npm:(@?[^@]+)@/, '')
                : String(spec);
            if (!exact.test(version)) {
                problems.push(`${name}@${spec}`);
            }
        }
    }

    return problems;
}
