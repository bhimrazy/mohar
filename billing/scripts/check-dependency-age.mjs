#!/usr/bin/env node
/**
 * Dependency cooldown + pinning check (docs/devops/dependency-policy.md).
 *
 * Fails when anything we install was published less than MIN_RELEASE_AGE_DAYS (default 7) days ago:
 *   - Composer packages  (billing/composer.lock     → repo.packagist.org/p2 "time")
 *   - npm packages       (billing/ and docs-site/ package-lock.json → registry.npmjs.org time[version]; direct
 *                         dependencies in their package.json must be exact versions)
 *   - GitHub Actions     (.github/**: `uses: owner/repo@<sha> # <tag>` → release date; SHA must match the tag)
 *   - Docker images      (billing/compose.yaml, workflow and deploy/*.yaml `image:`; `# syntax=`, `FROM` and
 *                         `--from=` of every Dockerfile/Containerfile → Docker Hub tag push date; digest required)
 *   - Tools              (billing/.nvmrc → nodejs.org; setup-php `tools: composer:X` → GitHub release)
 * and when anything is not pinned or not bound to what is checked: action SHA = tag, image digest published
 * under its tag (index or platform digest), npm tarball URL + integrity = registry tarball of that name@version,
 * Composer dist/source URL + reference = packagist's for that version.
 *
 * Default: only items added or changed versus a git base (the PR base). `--all` checks everything.
 *
 *   node scripts/check-dependency-age.mjs [--all] [--base=<git-ref>] [--min-days=<n>]
 *
 * Env: MIN_RELEASE_AGE_DAYS, DEPENDENCY_AGE_BASE (git ref, default: merge-base with develop),
 *      DEPENDENCY_AGE_EXCEPTIONS ("Security-Exception: name@version" lines, or "name@version, ..."): waives the
 *      cooldown for exactly those items (CI passes them from the PR body when the security-exception label is set),
 *      GITHUB_TOKEN (API limits), GITHUB_STEP_SUMMARY (waived items are listed there),
 *      DEPENDENCY_AGE_CACHE (cache file, default storage/framework/cache/dependency-age.json).
 * Exit codes: 0 ok · 1 policy violation · 2 could not verify (network/registry error).
 */
import { execFileSync } from 'node:child_process';
import {
    existsSync,
    mkdirSync,
    readFileSync,
    readdirSync,
    writeFileSync,
} from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    composerDistProblems,
    directPinProblems,
    digestBelongsToTag,
    composeImages,
    dockerfileImages,
    isDockerfile,
    isExcepted,
    npmEntryProblems,
    npmIntegrityProblem,
    parseSecurityExceptions,
} from './dependency-policy.mjs';

const DAY_MS = 86_400_000;
const appRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot =
    git(['rev-parse', '--show-toplevel'], appRoot)?.trim() ??
    join(appRoot, '..');
const appDir = relative(repoRoot, appRoot) || '.';
/** Directories with their own npm lockfile and .nvmrc: the app and the documentation site. */
const npmDirs = [appDir, 'docs-site'];

const args = process.argv.slice(2);
const option = (name) =>
    args
        .find((arg) => arg.startsWith(`--${name}=`))
        ?.split('=')
        .slice(1)
        .join('=');
const checkAll = args.includes('--all');
const exceptionText = process.env.DEPENDENCY_AGE_EXCEPTIONS ?? '';
const exceptions = parseSecurityExceptions(
    /security-exception:/i.test(exceptionText)
        ? exceptionText
        : `Security-Exception: ${exceptionText}`,
);
const minDays = Number(
    option('min-days') ?? process.env.MIN_RELEASE_AGE_DAYS ?? 7,
);
const cachePath =
    process.env.DEPENDENCY_AGE_CACHE ??
    join(appRoot, 'storage', 'framework', 'cache', 'dependency-age.json');
const inActions = process.env.GITHUB_ACTIONS === 'true';

if (!Number.isFinite(minDays) || minDays < 0) {
    fail(2, `Invalid minimum release age: ${minDays}`);
}

/** @type {Record<string, string>} immutable "kind|name|version|ref" → ISO publish date (local only; not cached in CI) */
const cache = readJson(cachePath) ?? {};
/** Pinning / integrity violations: always enforced. */
const violations = [];
/** @type {{item: Item, message: string}[]} cooldown violations: only these can be waived, per item. */
const ageViolations = [];
const errors = [];

// ---------------------------------------------------------------- collect

/**
 * @typedef {{kind: 'composer'|'npm'|'action'|'image'|'tool', name: string, version: string, ref?: string, file: string, integrity?: string, dist?: object, source?: object}} Item
 * @param {(path: string) => string|null} read  file reader relative to the repository root
 * @param {() => string[]} listWorkflowFiles
 * @param {() => string[]} listRepoFiles  every repository path (Dockerfiles anywhere, compose files in deploy/)
 * @returns {Item[]}
 */
function collect(read, listWorkflowFiles, listRepoFiles) {
    const items = [];
    const composerLock = parseJson(read(`${appDir}/composer.lock`));
    for (const pkg of [
        ...(composerLock?.packages ?? []),
        ...(composerLock?.['packages-dev'] ?? []),
    ]) {
        if (
            pkg.dist?.type === 'path' &&
            pkg.name === 'mohar/simulators' &&
            pkg.dist?.url === 'tools/simulators'
        ) {
            continue; // the first-party simulators package in this repository (ADR 0016), not a third-party release
        }
        items.push({
            kind: 'composer',
            name: pkg.name,
            version: pkg.version,
            ref: `${pkg.dist?.url ?? ''}#${pkg.dist?.reference ?? ''}#${pkg.source?.reference ?? ''}`,
            dist: pkg.dist,
            source: pkg.source,
            file: `${appDir}/composer.lock`,
        });
    }

    // The app and the documentation site (docs-site/) each have their own npm lockfile.
    for (const npmDir of npmDirs) {
        const npmLock = parseJson(read(`${npmDir}/package-lock.json`));
        for (const [path, entry] of Object.entries(npmLock?.packages ?? {})) {
            if (path === '' || entry.link || entry.inBundle) {
                continue; // the root, workspace links and files shipped inside another tarball
            }
            const name =
                entry.name ??
                path.slice(
                    path.lastIndexOf('node_modules/') + 'node_modules/'.length,
                );
            items.push({
                kind: 'npm',
                name,
                version: entry.version,
                ref: `${entry.resolved ?? ''}#${entry.integrity ?? ''}`,
                resolved: entry.resolved,
                integrity: entry.integrity,
                file: `${npmDir}/package-lock.json`,
            });
        }
    }

    const repoFiles = listRepoFiles();
    for (const file of [
        `${appDir}/compose.yaml`,
        ...listWorkflowFiles(),
        ...repoFiles.filter((path) => /^deploy\/.*\.ya?ml$/.test(path)),
    ]) {
        const text = read(file);
        if (text === null) {
            continue;
        }
        for (const { image, own } of composeImages(text)) {
            if (own) {
                continue;
            }
            items.push({
                kind: 'image',
                name: image.split('@')[0],
                version: image.split('@')[1] ?? '',
                file,
            });
        }
        for (const [, target, comment] of text.matchAll(
            /^\s*-?\s*uses:\s*['"]?([^\s'"#]+)['"]?[ \t]*(?:#[ \t]*(\S+))?/gm,
        )) {
            if (target.startsWith('./')) {
                continue;
            }
            const [name, ref = ''] = target.split('@');
            items.push({
                kind: 'action',
                name,
                version: comment ?? '',
                ref,
                file,
            });
        }
        for (const [, version] of text.matchAll(
            /^\s*tools:.*\bcomposer:([0-9][^\s,#'"]*)/gm,
        )) {
            items.push({ kind: 'tool', name: 'composer', version, file });
        }
    }

    for (const file of repoFiles.filter(isDockerfile)) {
        const text = read(file);
        if (text === null) {
            continue;
        }
        for (const image of new Set(dockerfileImages(text))) {
            items.push({
                kind: 'image',
                name: image.split('@')[0],
                version: image.split('@')[1] ?? '',
                file,
            });
        }
    }

    for (const npmDir of npmDirs) {
        const nvmrc = read(`${npmDir}/.nvmrc`);
        if (nvmrc !== null) {
            items.push({
                kind: 'tool',
                name: 'node',
                version: nvmrc.trim().replace(/^v/, ''),
                file: `${npmDir}/.nvmrc`,
            });
        }
    }

    return items;
}

const key = (item) =>
    `${item.kind}|${item.name}|${item.version}|${item.ref ?? ''}`;

const headItems = collect(
    (path) =>
        existsSync(join(repoRoot, path))
            ? readFileSync(join(repoRoot, path), 'utf8')
            : null,
    () =>
        workflowFiles(
            readdirRecursive(join(repoRoot, '.github')).map((path) =>
                relative(repoRoot, path),
            ),
        ),
    () =>
        (
            git(
                ['ls-files', '--cached', '--others', '--exclude-standard'],
                repoRoot,
            ) ?? ''
        )
            .split('\n')
            .filter(Boolean),
);

let baseLabel = 'all locked items';
let candidates = headItems;
if (!checkAll) {
    const base = resolveBase();
    baseLabel = `changes since ${base}`;
    const baseFiles =
        git(['ls-tree', '-r', '--name-only', base, '--', '.github'], repoRoot)
            ?.split('\n')
            .filter(Boolean) ?? [];
    const baseRepoFiles =
        git(['ls-tree', '-r', '--name-only', base], repoRoot)
            ?.split('\n')
            .filter(Boolean) ?? [];
    const baseKeys = new Set(
        collect(
            (path) => git(['show', `${base}:${path}`], repoRoot, true),
            () => workflowFiles(baseFiles),
            () => baseRepoFiles,
        ).map(key),
    );
    candidates = headItems.filter((item) => !baseKeys.has(key(item)));
}

// ---------------------------------------------------------------- pinning (offline, every item)

for (const npmDir of npmDirs) {
    const packageJsonPath = join(repoRoot, npmDir, 'package.json');
    if (!existsSync(packageJsonPath)) {
        continue;
    }
    for (const problem of directPinProblems(
        parseJson(readFileSync(packageJsonPath, 'utf8')),
    )) {
        violations.push(
            `${npmDir}/package.json: ${problem} must be one exact version`,
        );
    }
}

for (const item of headItems) {
    if (
        item.kind === 'action' &&
        !item.name.startsWith('docker://') &&
        !/^[0-9a-f]{40}$/.test(item.ref ?? '')
    ) {
        violations.push(
            `${item.file}: action ${item.name}@${item.ref} must be pinned to a full commit SHA`,
        );
    }
    if (
        item.kind === 'action' &&
        /^[0-9a-f]{40}$/.test(item.ref ?? '') &&
        item.version === ''
    ) {
        violations.push(
            `${item.file}: action ${item.name}@${item.ref} needs a "# <tag>" version comment on the same line`,
        );
    }
    if (
        (item.kind === 'image' || item.name.startsWith('docker://')) &&
        !/^sha256:[0-9a-f]{64}$/.test(
            item.kind === 'image' ? item.version : (item.ref ?? ''),
        )
    ) {
        violations.push(
            `${item.file}: image ${item.name} must be pinned as <name>:<tag>@sha256:<digest>`,
        );
    }
    if (item.kind === 'npm') {
        for (const problem of npmEntryProblems(item)) {
            violations.push(
                `${item.file}: npm ${item.name}@${item.version} ${problem}`,
            );
        }
    }
    if (item.kind === 'composer' && /^dev-|-dev$/.test(item.version)) {
        violations.push(
            `${item.file}: composer ${item.name}@${item.version} is a development version`,
        );
    }
}

// ---------------------------------------------------------------- age (network, candidates only)

const unique = [
    ...new Map(candidates.map((item) => [key(item), item])).values(),
];
console.log(
    `Dependency cooldown: ${minDays} day(s) · ${baseLabel} · ${unique.length} item(s) to verify`,
);

const now = Date.now();
await mapLimit(unique, 8, async (item) => {
    let published;
    try {
        published = await publishedAt(item);
    } catch (error) {
        if (error.policy) {
            violations.push(error.message);
            return;
        }
        errors.push(
            `${label(item)}: could not verify publish date — ${error.message}`,
        );
        return;
    }
    if (published === null) {
        return; // offline-only item (e.g. an unpinned action already reported above)
    }
    const ageDays = (now - Date.parse(published)) / DAY_MS;
    const line = `${label(item).padEnd(70)} ${published.slice(0, 10)}  ${ageDays.toFixed(1).padStart(6)} d`;
    if (ageDays < minDays) {
        ageViolations.push({
            item,
            message: `${label(item)} was published ${published} (${ageDays.toFixed(1)} days ago, minimum ${minDays})`,
        });
        console.log(`  ✗ ${line}`);
    } else if (!checkAll) {
        console.log(`  ✓ ${line}`);
    }
});
writeJson(cachePath, cache);

// ---------------------------------------------------------------- report

for (const message of errors) {
    annotate('error', message);
}
for (const message of violations) {
    annotate('error', message);
}
const waived = ageViolations.filter(({ item }) => isExcepted(exceptions, item));
const enforcedAge = ageViolations.filter(
    ({ item }) => !isExcepted(exceptions, item),
);
for (const { message } of waived) {
    annotate(
        'warning',
        `${message} — waived by Security-Exception (CTO approval required)`,
    );
}
for (const { message } of enforcedAge) {
    annotate('error', message);
}
writeSummary(waived);
if (errors.length > 0) {
    fail(
        2,
        `${errors.length} item(s) could not be verified (network or registry failure, or an unprovable image digest).`,
    );
}
const enforced = violations.length + enforcedAge.length;
if (enforced > 0) {
    fail(
        1,
        `${enforced} dependency policy violation(s). See docs/devops/dependency-policy.md (cooldown only: "security-exception" label + "Security-Exception: name@version" lines + CTO approval).`,
    );
}
console.log('Dependency policy: OK');

// ---------------------------------------------------------------- sources

/** @returns {Promise<string|null>} ISO publish date */
async function publishedAt(item) {
    const cacheKey = key(item);
    if (cache[cacheKey]) {
        return cache[cacheKey];
    }
    let date = null;
    if (item.kind === 'npm') {
        const doc = await fetchJson(
            `https://registry.npmjs.org/${item.name.replace('/', '%2f')}`,
        );
        const integrityProblem = npmIntegrityProblem(
            item.integrity,
            doc.versions?.[item.version]?.dist,
        );
        if (integrityProblem) {
            policyError(
                `${item.file}: npm ${item.name}@${item.version} ${integrityProblem}`,
            );
        }
        date =
            doc.time?.[item.version] ??
            missing(`version ${item.version} not found on registry.npmjs.org`);
    } else if (item.kind === 'composer') {
        const published = await packagistEntry(item.name, item.version);
        const problems = composerDistProblems(item, published);
        if (problems.length > 0) {
            policyError(
                `${item.file}: composer ${item.name}@${item.version} ${problems.join('; ')}`,
            );
        }
        date =
            published.time ??
            missing(
                `no publish time for ${item.name} ${item.version} on packagist`,
            );
    } else if (item.kind === 'action') {
        if (!/^[0-9a-f]{40}$/.test(item.ref ?? '') || item.version === '') {
            return null;
        }
        date = await actionReleaseDate(item);
    } else if (item.kind === 'image') {
        return dockerHubDate(item); // tags move: never cached
    } else if (item.kind === 'tool' && item.name === 'node') {
        const release = (
            await fetchJson('https://nodejs.org/dist/index.json')
        ).find((entry) => entry.version === `v${item.version}`);
        date = release
            ? `${release.date}T00:00:00Z`
            : missing(`Node.js v${item.version} not found on nodejs.org`);
    } else if (item.kind === 'tool' && item.name === 'composer') {
        date = (
            await fetchJson(
                `https://api.github.com/repos/composer/composer/releases/tags/${item.version}`,
                githubHeaders(),
            )
        ).published_at;
    }
    cache[cacheKey] = date;
    return date;
}

async function packagistEntry(name, version) {
    const doc = await fetchJson(`https://repo.packagist.org/p2/${name}.json`);
    let previous = {};
    for (const raw of doc.packages?.[name] ?? []) {
        // composer/2.0 minified metadata: each entry only lists fields that changed from the previous one.
        const entry = { ...previous, ...raw };
        for (const [field, value] of Object.entries(raw)) {
            if (value === '__unset') {
                delete entry[field];
            }
        }
        previous = entry;
        if (entry.version === version) {
            return entry;
        }
    }
    return missing(`version ${version} not found on repo.packagist.org`);
}

async function actionReleaseDate(item) {
    const repo = item.name.split('/').slice(0, 2).join('/');
    const headers = githubHeaders();
    const ref = await fetchJson(
        `https://api.github.com/repos/${repo}/git/ref/tags/${item.version}`,
        headers,
    );
    let sha = ref.object.sha;
    if (ref.object.type === 'tag') {
        sha = (
            await fetchJson(
                `https://api.github.com/repos/${repo}/git/tags/${sha}`,
                headers,
            )
        ).object.sha;
    }
    if (sha !== item.ref) {
        policyError(
            `${item.file}: ${item.name}@${item.ref} does not match tag ${item.version} (${sha})`,
        );
    }
    try {
        return (
            await fetchJson(
                `https://api.github.com/repos/${repo}/releases/tags/${item.version}`,
                headers,
            )
        ).published_at;
    } catch (error) {
        if (error.status !== 404) {
            throw error;
        }
        return (
            await fetchJson(
                `https://api.github.com/repos/${repo}/commits/${item.ref}`,
                headers,
            )
        ).commit.committer.date;
    }
}

/**
 * The pinned digest must be what the registry serves for the tag right now — the multi-arch index digest
 * (registry `Docker-Content-Digest`) or one of its platform manifests (Docker Hub tag API). Only then is the
 * tag's push date the digest's publish date. Anything else (another image's digest, a re-pushed tag) cannot
 * be proven and fails closed.
 */
async function dockerHubDate(item) {
    const [image, tag = 'latest'] = item.name.split(':');
    if (/^[^/]+\.[^/]+\//.test(image)) {
        return missing(
            `registry for ${image} is not supported (Docker Hub only) — verify manually`,
        );
    }
    const repo = image.includes('/') ? image : `library/${image}`;
    const [indexDigest, doc] = await Promise.all([
        registryTagDigest(repo, tag),
        fetchJson(`https://hub.docker.com/v2/repositories/${repo}/tags/${tag}`),
    ]);
    const platformDigests = (doc.images ?? [])
        .map((image) => image.digest)
        .filter(Boolean);
    if (!digestBelongsToTag(item.version, { indexDigest, platformDigests })) {
        return missing(
            `${item.file}: ${item.version} is not the digest of ${item.name} (registry serves ${indexDigest}) — cannot prove which image or how old it is; re-pin to the tag's current digest`,
        );
    }

    return doc.tag_last_pushed ?? doc.last_updated;
}

async function registryTagDigest(repo, tag) {
    const { token } = await fetchJson(
        `https://auth.docker.io/token?service=registry.docker.io&scope=repository:${repo}:pull`,
    );
    const response = await fetch(
        `https://registry-1.docker.io/v2/${repo}/manifests/${tag}`,
        {
            method: 'HEAD', // HEAD requests do not count against Docker Hub pull limits
            headers: {
                Authorization: `Bearer ${token}`,
                Accept: [
                    'application/vnd.oci.image.index.v1+json',
                    'application/vnd.docker.distribution.manifest.list.v2+json',
                    'application/vnd.oci.image.manifest.v1+json',
                    'application/vnd.docker.distribution.manifest.v2+json',
                ].join(', '),
            },
            signal: AbortSignal.timeout(30_000),
        },
    );
    if (!response.ok) {
        throw new Error(
            `HTTP ${response.status} from registry-1.docker.io for ${repo}:${tag}`,
        );
    }

    return response.headers.get('docker-content-digest');
}

// ---------------------------------------------------------------- helpers

async function fetchJson(url, headers = {}) {
    let lastError;
    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            const response = await fetch(url, {
                headers: {
                    'User-Agent': 'billing-dependency-age-check',
                    Accept: 'application/json',
                    ...headers,
                },
                signal: AbortSignal.timeout(30_000),
            });
            if (response.ok) {
                return await response.json();
            }
            lastError = Object.assign(
                new Error(`HTTP ${response.status} from ${url}`),
                { status: response.status },
            );
            if (response.status < 500 && response.status !== 429) {
                break;
            }
        } catch (error) {
            lastError = new Error(
                `${error.cause?.code ?? error.name}: ${error.message} (${url})`,
            );
        }
        await new Promise((resolve) => setTimeout(resolve, attempt * 1000));
    }
    throw lastError;
}

function githubHeaders() {
    const token = process.env.GITHUB_TOKEN ?? process.env.GH_TOKEN;
    return {
        'X-GitHub-Api-Version': '2022-11-28',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };
}

function missing(message) {
    throw new Error(message);
}

/** A binding/integrity violation: thrown (never cached) so it is reported on every run. */
function policyError(message) {
    throw Object.assign(new Error(message), { policy: true });
}

function writeSummary(waivedItems) {
    const summaryPath = process.env.GITHUB_STEP_SUMMARY;
    const listed = [...exceptions];
    if (!summaryPath || (listed.length === 0 && waivedItems.length === 0)) {
        return;
    }
    const rows = waivedItems.map(({ message }) => `| ${message} |`);
    const text = [
        '### Dependency cooldown — security exceptions',
        '',
        `Listed in the PR: ${listed.map((entry) => `\`${entry}\``).join(', ') || '—'}`,
        '',
        '| Waived (CTO approval required) |',
        '| --- |',
        ...(rows.length > 0 ? rows : ['| none needed |']),
        '',
    ].join('\n');
    try {
        writeFileSync(summaryPath, text, { flag: 'a' });
    } catch {
        // the summary is informational
    }
}

function resolveBase() {
    const ref = option('base') ?? process.env.DEPENDENCY_AGE_BASE;
    if (ref) {
        return (
            git(
                ['rev-parse', '--verify', `${ref}^{commit}`],
                repoRoot,
            )?.trim() ??
            fail(
                2,
                `Base ref "${ref}" not found. Fetch it, pass --base=<ref>, or use --all.`,
            )
        );
    }
    for (const candidate of [
        'develop',
        'origin/develop',
        'main',
        'origin/main',
    ]) {
        const mergeBase = git(
            ['merge-base', 'HEAD', candidate],
            repoRoot,
        )?.trim();
        if (mergeBase) {
            return mergeBase;
        }
    }
    return fail(
        2,
        'Could not determine a git base (no develop/main). Pass --base=<ref> or use --all.',
    );
}

function git(gitArgs, cwd, quiet = false) {
    try {
        return execFileSync('git', gitArgs, {
            cwd,
            encoding: 'utf8',
            stdio: ['ignore', 'pipe', quiet ? 'ignore' : 'pipe'],
            maxBuffer: 64 * 1024 * 1024,
        });
    } catch {
        return null;
    }
}

function workflowFiles(paths) {
    return paths.filter((path) =>
        /^\.github\/(workflows\/[^/]+|actions\/.+\/action)\.ya?ml$/.test(path),
    );
}

function readdirRecursive(dir) {
    if (!existsSync(dir)) {
        return [];
    }
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
        entry.isDirectory()
            ? readdirRecursive(join(dir, entry.name))
            : [join(dir, entry.name)],
    );
}

async function mapLimit(list, limit, worker) {
    const queue = [...list];
    await Promise.all(
        Array.from({ length: Math.min(limit, queue.length) }, async () => {
            while (queue.length > 0) {
                await worker(queue.shift());
            }
        }),
    );
}

function label(item) {
    const version =
        item.kind === 'image' ? item.version.slice(0, 19) : item.version;
    return `${item.kind} ${item.name}@${version}`;
}

function annotate(level, message) {
    console.log(
        inActions
            ? `::${level}::${message}`
            : `${level.toUpperCase()}: ${message}`,
    );
}

/**
 * @param {number} code
 * @param {string} message
 * @returns {never}
 */
function fail(code, message) {
    annotate('error', message);
    process.exit(code);
}

function parseJson(text) {
    return text === null ? null : JSON.parse(text);
}

function readJson(path) {
    try {
        return JSON.parse(readFileSync(path, 'utf8'));
    } catch {
        return null;
    }
}

function writeJson(path, data) {
    try {
        mkdirSync(dirname(path), { recursive: true });
        writeFileSync(path, JSON.stringify(data));
    } catch {
        // the cache is an optimisation only
    }
}
