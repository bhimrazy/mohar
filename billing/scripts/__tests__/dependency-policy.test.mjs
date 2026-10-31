import { describe, expect, it } from 'vitest';
import {
    buildkitImages,
    composeImages,
    composerAuditConfigChanges,
    npmrcAuditConfigChanges,
    composerDistProblems,
    directPinProblems,
    digestBelongsToTag,
    dockerfileImages,
    isComposeFile,
    isDockerfile,
    isExcepted,
    npmEntryProblems,
    npmIntegrityProblem,
    npmTarballUrl,
    parseSecurityExceptions,
} from '../dependency-policy.mjs';

const POSTGRES_INDEX =
    'sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873';
const POSTGRES_AMD64 =
    'sha256:d8703cd7fba306b9fec9268ecedfa8a966846c053036a60e3635791957eb2f66';
const RUSTFS_INDEX =
    'sha256:8cc9801755448b71a786705ce76692c77e14936cccd87cf2fc31842e58f4d1ff';

describe('digestBelongsToTag', () => {
    const tag = {
        indexDigest: POSTGRES_INDEX,
        platformDigests: [POSTGRES_AMD64],
    };

    it('accepts the multi-arch index digest and per-platform digests', () => {
        expect(digestBelongsToTag(POSTGRES_INDEX, tag)).toBe(true);
        expect(digestBelongsToTag(POSTGRES_AMD64, tag)).toBe(true);
    });

    it('rejects a digest of another image pinned under the tag', () => {
        expect(digestBelongsToTag(RUSTFS_INDEX, tag)).toBe(false);
    });

    it('rejects malformed digests and unknown tags', () => {
        expect(digestBelongsToTag('sha256:abc', tag)).toBe(false);
        expect(digestBelongsToTag(POSTGRES_INDEX, { indexDigest: null })).toBe(
            false,
        );
    });
});

describe('npmEntryProblems', () => {
    const clsx = {
        name: 'clsx',
        version: '2.1.1',
        resolved: 'https://registry.npmjs.org/clsx/-/clsx-2.1.1.tgz',
        integrity: 'sha512-abc',
    };

    it('accepts the canonical registry tarball, including scoped and aliased packages', () => {
        expect(npmEntryProblems(clsx)).toEqual([]);
        expect(npmTarballUrl('@voidzero-dev/vite-plus-core', '0.3.3')).toBe(
            'https://registry.npmjs.org/@voidzero-dev/vite-plus-core/-/vite-plus-core-0.3.3.tgz',
        );
    });

    it('rejects a tarball of another package or version', () => {
        expect(
            npmEntryProblems({
                ...clsx,
                resolved: 'https://registry.npmjs.org/zod/-/zod-4.6.7.tgz',
            }),
        ).toHaveLength(1);
        expect(
            npmEntryProblems({
                ...clsx,
                resolved: 'https://registry.npmjs.org/clsx/-/clsx-2.1.2.tgz',
            }),
        ).toHaveLength(1);
    });

    it('rejects other hosts and entries without resolved or integrity', () => {
        expect(
            npmEntryProblems({
                ...clsx,
                resolved: 'https://evil.example/clsx/-/clsx-2.1.1.tgz',
            }),
        ).toHaveLength(1);
        expect(
            npmEntryProblems({ name: 'clsx', version: '2.1.1' }),
        ).toHaveLength(2);
    });
});

describe('npmIntegrityProblem', () => {
    it('requires the lock integrity to equal the registry integrity', () => {
        expect(
            npmIntegrityProblem('sha512-a', { integrity: 'sha512-a' }),
        ).toBeNull();
        expect(
            npmIntegrityProblem('sha512-b', { integrity: 'sha512-a' }),
        ).toContain('does not match');
        expect(npmIntegrityProblem('sha512-a', undefined)).toContain(
            'not found',
        );
    });
});

describe('composerDistProblems', () => {
    const published = {
        dist: {
            url: 'https://api.github.com/repos/laravel/framework/zipball/c829b49',
            reference: 'c829b49',
        },
        source: {
            url: 'https://github.com/laravel/framework.git',
            reference: 'c829b49',
        },
    };

    it('accepts the archive and commit packagist publishes', () => {
        expect(
            composerDistProblems(structuredClone(published), published),
        ).toEqual([]);
    });

    it('rejects a swapped archive URL or commit reference', () => {
        const swapped = structuredClone(published);
        swapped.dist.url =
            'https://api.github.com/repos/evil/framework/zipball/deadbee';
        swapped.source.reference = 'deadbee';
        expect(composerDistProblems(swapped, published)).toHaveLength(2);
    });
});

describe('security exceptions', () => {
    const body = [
        'Fixes GHSA-xxxx.',
        'Security-Exception: laravel/framework@v13.36.1',
        'security-exception: @scope/pkg@1.2.3, lodash@4.17.22',
    ].join('\n');

    it('parses name@version lines, scoped names and lists', () => {
        expect([...parseSecurityExceptions(body)]).toEqual([
            'laravel/framework@13.36.1',
            '@scope/pkg@1.2.3',
            'lodash@4.17.22',
        ]);
    });

    it('waives only the listed name and version', () => {
        const exceptions = parseSecurityExceptions(body);
        expect(
            isExcepted(exceptions, {
                name: 'laravel/framework',
                version: '13.36.1',
            }),
        ).toBe(true);
        expect(
            isExcepted(exceptions, {
                name: 'laravel/framework',
                version: '13.37.0',
            }),
        ).toBe(false);
        expect(isExcepted(exceptions, { name: 'zod', version: '4.6.7' })).toBe(
            false,
        );
        expect(parseSecurityExceptions('').size).toBe(0);
    });
});

describe('dockerfileImages', () => {
    const node = `node:24.22.1-trixie-slim@${POSTGRES_INDEX}`;
    const composer = `composer:2.10.3@${RUSTFS_INDEX}`;

    it('returns external FROM and --from images, not stages, scratch or stage indexes', () => {
        const dockerfile = [
            '# FROM commented:out',
            `FROM --platform=$BUILDPLATFORM ${node} AS assets`,
            'FROM assets AS build',
            `COPY --from=${composer} /usr/bin/composer /usr/bin/composer`,
            'COPY --from=assets /app/public/build /app/public/build',
            'COPY --from=0 /a /b',
            `RUN --mount=type=bind,from=${node},source=/x,target=/y true`,
            'FROM scratch',
        ].join('\n');

        expect(dockerfileImages(dockerfile)).toEqual([node, composer, node]);
    });

    it('treats stage names case-insensitively and joins line continuations', () => {
        const dockerfile = `FROM ${node} as Base\nfrom base\nCOPY \\\n    --from=${composer} /c /c`;

        expect(dockerfileImages(dockerfile)).toEqual([node, composer]);
    });

    it('keeps unpinned and build-argument references so the digest check fails them', () => {
        expect(
            dockerfileImages(
                'ARG PHP=8.4\nFROM php:${PHP}-cli\nFROM debian:trixie-slim',
            ),
        ).toEqual(['php:${PHP}-cli', 'debian:trixie-slim']);
    });
});

describe('dockerfileImages parser directives', () => {
    it('checks a # syntax= frontend like a FROM, only before the first instruction', () => {
        const dockerfile = [
            '# syntax=docker/dockerfile:1',
            '# escape=\\',
            'FROM scratch',
            '# syntax=ignored/after:instructions',
        ].join('\n');

        expect(dockerfileImages(dockerfile)).toEqual(['docker/dockerfile:1']);
    });
});

describe('isDockerfile', () => {
    it('matches Dockerfile and Containerfile names anywhere, not their ignore files', () => {
        expect(
            [
                'deploy/Dockerfile',
                'deploy/Dockerfile.prod',
                'tools/worker.Dockerfile',
                'Containerfile',
                'deploy/Dockerfile.dockerignore',
                'billing/docs/dockerfile-notes.md',
            ].filter(isDockerfile),
        ).toEqual([
            'deploy/Dockerfile',
            'deploy/Dockerfile.prod',
            'tools/worker.Dockerfile',
            'Containerfile',
        ]);
    });
});

describe('composeImages', () => {
    const pinned = `postgres:18.6-alpine@${POSTGRES_INDEX}`;

    it('returns every image line, with own set only for the marked app image', () => {
        const compose = [
            `    image: ${pinned}`,
            '    image: ${APP_IMAGE:-mohar-app:local} # own image',
            '    image: ${APP_IMAGE_BLUE} # own image',
            "    image: 'nginx:latest'",
            '    image: ${OTHER_IMAGE:-x}',
        ].join('\n');

        expect(composeImages(compose)).toEqual([
            { image: pinned, own: false },
            { image: '${APP_IMAGE:-mohar-app:local}', own: true },
            { image: '${APP_IMAGE_BLUE}', own: true },
            { image: 'nginx:latest', own: false },
            { image: '${OTHER_IMAGE:-x}', own: false },
        ]);
    });

    it('never treats a third-party default or a plain image as own, even when marked', () => {
        expect(
            composeImages(
                [
                    '    image: ${APP_IMAGE:-nginx:latest} # own image',
                    '    image: nginx:latest # own image',
                    '    image: ${APP_IMAGE:-ghcr.io/x/y:1} # own image',
                ].join('\n'),
            ).map((entry) => entry.own),
        ).toEqual([false, false, false]);
    });

    it('still finds an image line with CRLF endings or trailing text', () => {
        expect(
            composeImages(
                'services:\r\n  a:\r\n    image: nginx:latest\r\n  b:\r\n    image: redis:7 trailing text\r\n',
            ),
        ).toEqual([
            { image: 'nginx:latest', own: false },
            { image: 'redis:7', own: false },
        ]);
    });
});

describe('directPinProblems', () => {
    it('accepts exact versions and an exact npm: alias, and lists everything else', () => {
        expect(
            directPinProblems({
                dependencies: {
                    astro: '7.3.8',
                    vite: 'npm:@voidzero-dev/vite-plus-core@0.3.3',
                    pre: '1.0.0-rc.1',
                },
                devDependencies: {
                    a: '^1.2.3',
                    b: '~1.2.3',
                    c: 'latest',
                    d: '1.x',
                    e: 'npm:x@^2.0.0',
                    f: 'github:o/r',
                },
            }),
        ).toEqual([
            'a@^1.2.3',
            'b@~1.2.3',
            'c@latest',
            'd@1.x',
            'e@npm:x@^2.0.0',
            'f@github:o/r',
        ]);
    });
});

describe('isComposeFile', () => {
    it('matches the production and load-test compose files, not docs or other YAML', () => {
        expect(
            [
                'deploy/compose.prod.yaml',
                'deploy/compose.dev.yaml',
                'loadtest/compose.loadtest.yaml',
                'loadtest/profiles/small.env',
                'billing/compose.yaml',
                '.github/workflows/load-test.yml',
                'docs/loadtest/compose.yaml',
            ].filter(isComposeFile),
        ).toEqual([
            'deploy/compose.prod.yaml',
            'deploy/compose.dev.yaml',
            'loadtest/compose.loadtest.yaml',
        ]);
    });
});

describe('buildkitImages', () => {
    const pinned = `moby/buildkit:v0.34.0@${POSTGRES_INDEX}`;

    it('reads an inline driver-opts value', () => {
        expect(buildkitImages(`  driver-opts: image=${pinned}`)).toEqual([
            pinned,
        ]);
        expect(
            buildkitImages(`  driver-opts: 'image=moby/buildkit:latest'`),
        ).toEqual(['moby/buildkit:latest']);
    });

    it('reads every image= line of a block scalar, and only that block', () => {
        const workflow = [
            '      with:',
            '        driver-opts: |',
            '          network=host',
            `          image=${pinned} # pinned`,
            '',
            '          image=moby/buildkit:master',
            '        install: true',
            '        labels: org.opencontainers.image=x',
        ].join('\n');

        expect(buildkitImages(workflow)).toEqual([
            pinned,
            'moby/buildkit:master',
        ]);
    });

    it('returns nothing without driver-opts', () => {
        expect(
            buildkitImages('    image: postgres:18\n    driver: docker'),
        ).toEqual([]);
    });
});

describe('composerAuditConfigChanges', () => {
    const base = JSON.stringify({
        config: { 'sort-packages': true, audit: { abandoned: 'report' } },
        repositories: [{ type: 'path', url: '../tools/simulators' }],
    });

    it('names config.audit and repositories when they change, and nothing for other keys or a reordering', () => {
        const muted = JSON.parse(base);
        muted.config.audit.ignore = ['GHSA-1234'];
        muted.repositories.push({ 'packagist.org': false });
        const cosmetic = JSON.stringify({
            repositories: [{ url: '../tools/simulators', type: 'path' }],
            config: { audit: { abandoned: 'report' }, 'sort-packages': false },
            description: 'x',
        });

        expect(composerAuditConfigChanges(base, JSON.stringify(muted))).toEqual(
            ['config.audit', 'repositories'],
        );
        expect(composerAuditConfigChanges(base, cosmetic)).toEqual([]);
        const policy = JSON.parse(base);
        policy.config.policy = { advisories: { audit: 'ignore' } };
        policy.config['disable-tls'] = true;
        expect(
            composerAuditConfigChanges(base, JSON.stringify(policy)),
        ).toEqual(['config.disable-tls', 'config.policy']);
        expect(composerAuditConfigChanges(base, '{not json')).toContain(
            '(unparsable composer.json)',
        );
    });
});

describe('npmrcAuditConfigChanges', () => {
    const base = 'save-exact=true\nmin-release-age=7\n';

    it('names a registry, scoped registry, TLS, proxy or audit setting that is added, changed or removed', () => {
        expect(
            npmrcAuditConfigChanges(
                base,
                `${base}registry=https://npm.example.test/\n@acme:registry = https://x.test/\nstrict-ssl=false\naudit-level=critical\n`,
            ),
        ).toEqual(['@acme:registry', 'audit-level', 'registry', 'strict-ssl']);
        expect(npmrcAuditConfigChanges(`${base}cafile=/a.pem\n`, base)).toEqual(
            ['cafile'],
        );
    });

    it('reads keys the way npm does (quotes, spaces, array suffix) and rejects any key outside the allowlist', () => {
        expect(
            npmrcAuditConfigChanges(
                base,
                `${base}"registry"=https://evil-quoted.invalid/\n  'omit' = dev\nuserconfig=./evil.ini\nglobalconfig=/tmp/g\nca[]=x\n[section]\nsomething-new=1\n`,
            ),
        ).toEqual([
            '[section]',
            'ca',
            'globalconfig',
            'omit',
            'registry',
            'something-new',
            'userconfig',
        ]);
    });

    it('allows the keys this project uses, and ignores comments', () => {
        expect(
            npmrcAuditConfigChanges(
                base,
                '# registry=https://evil.test/\nsave-exact=true\nmin-release-age=7\nfund=false\n',
            ),
        ).toEqual([]);
        expect(npmrcAuditConfigChanges(null, null)).toEqual([]);
    });
});
