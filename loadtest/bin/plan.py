#!/usr/bin/env python3
"""The job matrix of .github/workflows/load-test.yml, as JSON on stdout.

    python3 loadtest/bin/plan.py --label loadtest/label-runs.json    the `load-test` label: the PR's committed runs
    python3 loadtest/bin/plan.py --dispatch                          Actions -> Run workflow: the inputs, from INPUT_* env

Every value is checked against a fixed pattern before it reaches a shell, and the label path never chooses the
runner or relaxes --require-fit: those stay with the workflow. Standard library only.
"""
import json
import os
import re
import sys

PROFILES = ('small', 'medium', 'large')
VOLUMES = ('tiny', 'small', 'medium', 'large')
MAX_LABEL_RUNS = 3
RULES = {
    'name': r'[a-z0-9][a-z0-9-]{0,39}',
    'levels': r'[1-9][0-9]{0,3}( [1-9][0-9]{0,3}){0,11}',
    'level_duration': r'[1-9][0-9]{0,2}[sm]',
    'warmup': r'[1-9][0-9]{0,2}[sm]',
    'start_spread': r'[0-9]{1,3}',
    'think': r'[0-9]{1,2} [0-9]{1,3}',
}


def run(entry, defaults):
    out = {'name': '', 'profile': 'small', 'volume': 'large', 'levels': '', 'level_duration': '', 'start_spread': '',
           'warmup': '', 'think': '', **defaults}
    for key, value in entry.items():
        if key not in out:
            sys.exit(f'unknown key {key!r}')
        out[key] = str(value).strip()
    if out['profile'] not in PROFILES or out['volume'] not in VOLUMES:
        sys.exit(f"profile must be one of {PROFILES} and volume one of {VOLUMES}: {out['profile']!r}, {out['volume']!r}")
    for key, pattern in RULES.items():
        if out[key] != '' and not re.fullmatch(pattern, out[key]):
            sys.exit(f'{key} {out[key]!r} does not match {pattern}')
    if out['think']:
        low, high = map(int, out['think'].split())
        if low > high:
            sys.exit(f"think {out['think']!r}: the smaller number first")
    out['name'] = out['name'] or out['profile']
    return out


def main():
    if len(sys.argv) == 3 and sys.argv[1] == '--label':
        with open(sys.argv[2]) as handle:
            runs = json.load(handle).get('runs') or []
        if not 1 <= len(runs) <= MAX_LABEL_RUNS:
            sys.exit(f'{sys.argv[2]}: 1 to {MAX_LABEL_RUNS} runs')
        matrix = [run(entry, {}) for entry in runs]
    elif sys.argv[1:] == ['--dispatch']:
        env = os.environ.get
        profile = env('INPUT_PROFILE') or 'small'
        names = list(PROFILES) if profile == 'all' else [profile]
        matrix = [run({'profile': p, 'volume': env('INPUT_VOLUME') or 'large', 'levels': env('INPUT_LEVELS', ''),
                       'level_duration': env('INPUT_LEVEL_DURATION', '')}, {}) for p in names]
    else:
        sys.exit(__doc__)
    if len({entry['name'] for entry in matrix}) != len(matrix):
        sys.exit('run names must be unique (they name the artifacts)')
    print(json.dumps(matrix))


if __name__ == '__main__':
    main()
