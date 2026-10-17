#!/usr/bin/env node
/**
 * Dependency cooldown + pinning check (docs/devops/DEPENDENCY_POLICY.md).
 *
 * Fails when anything we install was published less than MIN_RELEASE_AGE_DAYS (default 7) days ago:
 *   - Composer packages  (billing/composer.lock     → repo.packagist.org/p2 "time")
 *   - npm packages       (billing/package-lock.json → registry.npmjs.org time[version])
 *   - GitHub Actions     (.github/**: `uses: owner/repo@<sha> # <tag>` → release date; SHA must match the tag)
 *   - Docker images      (billing/compose.yaml + workflow `image:` → Docker Hub tag push date; digest required)
 *   - Tools              (billing/.nvmrc → nodejs.org; setup-php `tools: composer:X` → GitHub release)
 * and when an action or image is not pinned (SHA / digest) — that part runs offline on every item.
 *
 * Default: only items added or changed versus a git base (the PR base). `--all` checks everything.
 *
 *   node scripts/check-dependency-age.mjs [--all] [--base=<git-ref>] [--min-days=<n>] [--report-only]
 *
 * Env: MIN_RELEASE_AGE_DAYS, DEPENDENCY_AGE_BASE (git ref, default: merge-base with develop),
 *      DEPENDENCY_AGE_EXCEPTION=1 (security-exception PR label: cooldown reported, not enforced; pinning still is),
 *      GITHUB_TOKEN (API limits),
 *      DEPENDENCY_AGE_CACHE (cache file, default node_modules/.cache/dependency-age.json).
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

const DAY_MS = 86_400_000;
const appRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot =
    git(['rev-parse', '--show-toplevel'], appRoot)?.trim() ??
    join(appRoot, '..');
const appDir = relative(repoRoot, appRoot) || '.';

const args = process.argv.slice(2);
const option = (name) =>
    args
        .find((arg) => arg.startsWith(`--${name}=`))
        ?.split('=')
        .slice(1)
        .join('=');
const checkAll = args.includes('--all');
const reportOnly =
    args.includes('--report-only') ||
    process.env.DEPENDENCY_AGE_EXCEPTION === '1';
const minDays = Number(
    option('min-days') ?? process.env.MIN_RELEASE_AGE_DAYS ?? 7,
);
const cachePath =
    process.env.DEPENDENCY_AGE_CACHE ??
    join(appRoot, 'node_modules', '.cache', 'dependency-age.json');
const inActions = process.env.GITHUB_ACTIONS === 'true';

if (!Number.isFinite(minDays) || minDays < 0) {
    fail(2, `Invalid minimum release age: ${minDays}`);
}

/** @type {Record<string, string>} immutable "kind|name|version" → ISO publish date */
const cache = readJson(cachePath) ?? {};
/** Pinning / integrity violations: always enforced. */
const violations = [];
/** Cooldown violations: waived (reported only) by the security-exception label. */
const ageViolations = [];
const errors = [];

// ---------------------------------------------------------------- collect

/**
 * @typedef {{kind: 'composer'|'npm'|'action'|'image'|'tool', name: string, version: string, ref?: string, file: string}} Item
 * @param {(path: string) => string|null} read  file reader relative to the repository root
 * @param {() => string[]} listWorkflowFiles
 * @returns {Item[]}
 */
function collect(read, listWorkflowFiles) {
    const items = [];
    const composerLock = parseJson(read(`${appDir}/composer.lock`));
    for (const pkg of [
        ...(composerLock?.packages ?? []),
        ...(composerLock?.['packages-dev'] ?? []),
    ]) {
        items.push({
            kind: 'composer',
            name: pkg.name,
            version: pkg.version,
            file: `${appDir}/composer.lock`,
        });
    }

    const npmLock = parseJson(read(`${appDir}/package-lock.json`));
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
            ref: entry.resolved,
            file: `${appDir}/package-lock.json`,
        });
    }

    for (const file of [`${appDir}/compose.yaml`, ...listWorkflowFiles()]) {
        const text = read(file);
        if (text === null) {
            continue;
        }
        for (const [, image] of text.matchAll(
            /^\s*image:\s*['"]?([^\s'"#]+)/gm,
        )) {
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

    const nvmrc = read(`${appDir}/.nvmrc`);
    if (nvmrc !== null) {
        items.push({
            kind: 'tool',
            name: 'node',
            version: nvmrc.trim().replace(/^v/, ''),
            file: `${appDir}/.nvmrc`,
        });
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
    const baseKeys = new Set(
        collect(
            (path) => git(['show', `${base}:${path}`], repoRoot, true),
            () => workflowFiles(baseFiles),
        ).map(key),
    );
    candidates = headItems.filter((item) => !baseKeys.has(key(item)));
}

// ---------------------------------------------------------------- pinning (offline, every item)

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
    if (
        item.kind === 'npm' &&
        item.ref &&
        !item.ref.startsWith('https://registry.npmjs.org/')
    ) {
        violations.push(
            `${item.file}: npm ${item.name}@${item.version} is not installed from registry.npmjs.org (${item.ref})`,
        );
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
        ageViolations.push(
            `${label(item)} was published ${published} (${ageDays.toFixed(1)} days ago, minimum ${minDays})`,
        );
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
for (const message of ageViolations) {
    annotate(reportOnly ? 'warning' : 'error', message);
}
if (errors.length > 0) {
    fail(
        2,
        `${errors.length} item(s) could not be verified (network or registry failure). Re-run, or check connectivity to the registries.`,
    );
}
const enforced = violations.length + (reportOnly ? 0 : ageViolations.length);
if (enforced > 0) {
    fail(
        1,
        `${enforced} dependency policy violation(s). See docs/devops/DEPENDENCY_POLICY.md (cooldown only: "security-exception" label + CTO approval).`,
    );
}
if (ageViolations.length > 0) {
    console.log(
        `\n${ageViolations.length} cooldown violation(s) reported only (security-exception): CTO approval required on the PR.`,
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
        date =
            doc.time?.[item.version] ??
            missing(`version ${item.version} not found on registry.npmjs.org`);
    } else if (item.kind === 'composer') {
        date = await packagistTime(item.name, item.version);
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

async function packagistTime(name, version) {
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
            return (
                entry.time ??
                missing(`no publish time for ${name} ${version} on packagist`)
            );
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
        // Thrown, not cached: a mismatch must be reported on every run.
        throw Object.assign(
            new Error(
                `${item.file}: ${item.name}@${item.ref} does not match tag ${item.version} (${sha})`,
            ),
            { policy: true },
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

/** The pinned digest is at least as old as the tag's latest push, so that push date is a safe lower bound. */
async function dockerHubDate(item) {
    const [image, tag = 'latest'] = item.name.split(':');
    if (/^[^/]+\.[^/]+\//.test(image)) {
        return missing(
            `registry for ${image} is not supported (Docker Hub only) — verify manually`,
        );
    }
    const repo = image.includes('/') ? image : `library/${image}`;
    const doc = await fetchJson(
        `https://hub.docker.com/v2/repositories/${repo}/tags/${tag}`,
    );
    const pushed = doc.tag_last_pushed ?? doc.last_updated;
    if (
        doc.digest !== item.version &&
        Date.now() - Date.parse(pushed) < minDays * DAY_MS
    ) {
        return missing(
            `${item.name} was re-pushed on ${pushed} and the pinned digest is no longer current — cannot prove its age`,
        );
    }
    return pushed;
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
