#!/usr/bin/env python3
"""Turn one load-test run's raw files into summary.json and summary.md (loadtest/README.md).

    python3 loadtest/bin/summarize.py loadtest/results/<run>
    python3 loadtest/bin/summarize.py --combine out/ run-a/ run-b/ ...    one table across runs (the workflow)
    python3 loadtest/bin/summarize.py --check-level <run>/k6-50.json       one level: prints it, exits 3 if saturated

Only numbers that were measured: every value comes from a k6 summary, docker stats, PostgreSQL, the PDF bench or the
automation log of this run. Standard library only.
"""
import json
import re
import sys
from pathlib import Path

PAGES_SHOWN = [
    'client_dashboard', 'client_invoices', 'client_invoice', 'client_invoice_pdf', 'client_services', 'client_service',
    'client_tickets', 'client_ticket', 'login', 'home', 'store', 'store_product', 'cart_add', 'cart', 'checkout',
    'knowledgebase', 'admin_dashboard', 'admin_clients', 'admin_clients_search', 'admin_client', 'admin_invoices',
    'admin_invoice', 'admin_services', 'admin_tickets', 'admin_ticket', 'admin_transactions', 'admin_orders',
    'api_admin_search', 'api_client_search', 'api_store_quote',
]
# The comfortable limit used for "fits": p95 of all requests under this, and under 1% errors. The sizing guide
# (billing/docs/devops/sizing.md) uses this line for its "fits" verdicts and reports the stricter per-page target,
# p95 under 200 ms, separately.
P95_LIMIT_MS = 1000
ERROR_LIMIT = 0.01
SATURATED = 3  # exit code of --check-level for a level past the comfortable limit (bin/run stops the ramp on it)


def comfortable(level):
    return level['p95_ms'] < P95_LIMIT_MS and level['error_rate'] < ERROR_LIMIT


def saturation(levels):
    """The last comfortable level before the first one that is not, in the order they ran."""
    first = next((i for i, level in enumerate(levels) if not comfortable(level)), None)
    before = levels[:first] if first is not None else levels
    return {
        'last_comfortable_vus': before[-1]['vus'] if before else None,
        'first_failing_vus': levels[first]['vus'] if first is not None else None,
        'reason': None if first is None else ', '.join(
            ([f"p95 {levels[first]['p95_ms']} ms >= {P95_LIMIT_MS} ms"] if levels[first]['p95_ms'] >= P95_LIMIT_MS else [])
            + ([f"errors {levels[first]['error_rate'] * 100:.2f}% >= {ERROR_LIMIT * 100:.0f}%"] if levels[first]['error_rate'] >= ERROR_LIMIT else [])),
    }


def load(path, default=None):
    try:
        return json.loads(Path(path).read_text())
    except (OSError, ValueError):
        return default


def ndjson(path):
    rows = []
    try:
        for line in Path(path).read_text().splitlines():
            try:
                rows.append(json.loads(line))
            except ValueError:
                pass
    except OSError:
        pass
    return rows


def values(metrics, name):
    return (metrics.get(name) or {}).get('values') or {}


def web_level(path):
    data = load(path)
    if not data:
        return None
    metrics = data['metrics']
    duration = values(metrics, 'http_req_duration')
    level = {
        'requests': int(values(metrics, 'http_reqs').get('count', 0)),
        'rps': round(values(metrics, 'http_reqs').get('rate', 0), 1),
        'p50_ms': round(duration.get('med', 0)),
        'p95_ms': round(duration.get('p(95)', 0)),
        'p99_ms': round(duration.get('p(99)', 0)),
        'error_rate': round(values(metrics, 'http_req_failed').get('rate', 0), 4),
        'rate_limited': int(values(metrics, 'rate_limited').get('passes', 0)),
        'content_errors': int(values(metrics, 'content_errors').get('count', 0)),
        'seconds': round(data.get('state', {}).get('testRunDurationMs', 0) / 1000, 1),
        'scenarios': {},
        'pages': {},
    }
    for name in ('client', 'storefront', 'admin', 'api'):
        d = values(metrics, f'http_req_duration{{scenario:{name}}}')
        if d:
            level['scenarios'][name] = {
                'requests': int(values(metrics, f'http_reqs{{scenario:{name}}}').get('count', 0)),
                'p50_ms': round(d.get('med', 0)), 'p95_ms': round(d.get('p(95)', 0)), 'p99_ms': round(d.get('p(99)', 0)),
                'error_rate': round(values(metrics, f'http_req_failed{{scenario:{name}}}').get('rate', 0), 4),
            }
    for key, metric in metrics.items():
        match = re.fullmatch(r'http_req_duration\{page:([a-z_]+)\}', key)
        if not match:
            continue
        page = match.group(1)
        count = int(values(metrics, f'http_reqs{{page:{page}}}').get('count', 0))
        if count == 0:
            continue
        d = metric['values']
        level['pages'][page] = {
            'count': count, 'p50_ms': round(d.get('med', 0)), 'p95_ms': round(d.get('p(95)', 0)), 'p99_ms': round(d.get('p(99)', 0)),
            'max_ms': round(d.get('max', 0)), 'error_rate': round(values(metrics, f'http_req_failed{{page:{page}}}').get('rate', 0), 4),
            'content_errors': int(values(metrics, f'content_errors{{page:{page}}}').get('count', 0)),
        }
    return level


def to_mb(text):
    match = re.match(r'([\d.]+)\s*([KMGT]?i?B)', text.strip())
    if not match:
        return 0.0
    number, unit = float(match.group(1)), match.group(2)
    scale = {'B': 1 / 1048576, 'KiB': 1 / 1024, 'kB': 1 / 1024, 'KB': 1 / 1024, 'MiB': 1, 'MB': 1, 'GiB': 1024, 'GB': 1024, 'TiB': 1048576}
    return number * scale.get(unit, 1)


def service_of(container, project):
    name = container[len(project) + 1:] if container.startswith(project + '-') else container
    name = re.sub(r'-run-[0-9a-f]+$', '', name)
    return re.sub(r'-\d+$', '', name)


def resources(rows, project):
    """Per phase and service: average and peak CPU (% of one core, summed over replicas) and peak memory (MB)."""
    samples = {}
    for row in rows:
        stat = row.get('s') or {}
        phase = row.get('phase') or 'unknown'
        service = service_of(stat.get('Name', ''), project)
        cpu = float(str(stat.get('CPUPerc', '0')).rstrip('%') or 0)
        memory = to_mb(str(stat.get('MemUsage', '0B')).split('/')[0])
        bucket = samples.setdefault(phase, {}).setdefault(service, {})
        cell = bucket.setdefault(row['t'], [0.0, 0.0])
        cell[0] += cpu
        cell[1] += memory
    out = {}
    for phase, services in samples.items():
        out[phase] = {}
        for service, by_time in services.items():
            cpus = [v[0] for v in by_time.values()]
            memories = [v[1] for v in by_time.values()]
            out[phase][service] = {
                'cpu_avg_pct': round(sum(cpus) / len(cpus), 1), 'cpu_max_pct': round(max(cpus), 1),
                'mem_max_mb': round(max(memories)), 'samples': len(cpus),
            }
    return out


def drain_per_queue(rows, start):
    """Seconds from the drain start until each queue first reached zero."""
    if not rows:
        return {}
    seen = set()
    for row in rows:
        seen.update((row.get('queues') or {}).keys())
    done = {}
    for row in rows:
        queues = row.get('queues') or {}
        for queue in seen:
            if queue not in done and queues.get(queue, 0) == 0:
                done[queue] = round(row['t'] - start, 1)
    return done


def summarize(run):
    run = Path(run)
    meta = load(run / 'meta.json', {})
    seed = load(run / 'seed.json', {})
    levels = []
    for vus in meta.get('levels', []):
        level = web_level(run / f'k6-{vus}.json')
        if level:
            levels.append({'vus': int(vus), **level})
    usage = resources(ndjson(run / 'docker-stats.ndjson'), meta.get('project', 'mohar-loadtest'))
    cap = (meta.get('profile_cpus') or 0) * 100
    for level in levels:
        phase = usage.get(f"web-{level['vus']}", {})
        stack = sum(r['cpu_avg_pct'] for service, r in phase.items() if service != 'k6')
        level['cpu'] = {
            'stack_avg_pct': round(stack, 1) if phase else None,
            'stack_avg_of_cap_pct': round(100 * stack / cap, 1) if cap and phase else None,
            'app_avg_pct': (phase.get('app-blue') or {}).get('cpu_avg_pct'),
            'postgres_avg_pct': (phase.get('postgres') or {}).get('cpu_avg_pct'),
            'k6_avg_pct': (phase.get('k6') or {}).get('cpu_avg_pct'),
        }
    phases = ndjson(run / 'phases.ndjson')
    drain_start = next((p['t'] for p in phases if p['phase'] == 'drain'), None)
    drain = load(run / 'drain.json', {})
    queued = load(run / 'queues-after.json', {}) or {}
    tasks = load(run / 'automation-runs.json', []) or []
    automation_ms = sum(int(task.get('duration_ms') or 0) for task in tasks)
    jobs = sum(int(n) for n in queued.values())
    summary = {
        'meta': meta,
        'seed': {k: v for k, v in seed.items() if k not in ('tables', 'invariants')},
        'invariants_broken': sum((seed.get('invariants') or {}).values()) if seed else None,
        'database': load(run / 'database.json', {}),
        'web': levels,
        'max_comfortable_vus': max((level['vus'] for level in levels if comfortable(level)), default=None),
        'saturation': saturation(levels),
        'resources': usage,
        'pdf': load(run / 'pdf.json', {}),
        'automation': {
            'exit_code': (load(run / 'automation-exit.json', {}) or {}).get('exit_code'),
            'seconds': round(automation_ms / 1000, 1),
            'tasks': sorted(({'task': t['task'], 'status': t['status'], 'seconds': round(int(t.get('duration_ms') or 0) / 1000, 2), 'summary': t.get('summary')} for t in tasks),
                            key=lambda t: -t['seconds']),
            'jobs_queued': queued,
        },
        'drain': {
            **drain,
            'jobs': jobs,
            'jobs_per_second': round(jobs / drain['seconds'], 1) if drain.get('seconds') else None,
            'emails_per_second': round(drain.get('emails_sent', 0) / drain['seconds'], 1) if drain.get('seconds') else None,
            'per_queue_seconds': drain_per_queue(ndjson(run / 'drain.ndjson'), drain_start) if drain_start else {},
        },
        'pg_top_web': (load(run / 'pg-top-web.json', []) or [])[:8],
        'pg_top_automation': (load(run / 'pg-top-automation.json', []) or [])[:8],
        'pg_database': load(run / 'pg-database-stats.json', {}),
        'provisioning': load(run / 'provisioning.json', {}),
    }
    (run / 'summary.json').write_text(json.dumps(summary, indent=2))
    (run / 'summary.md').write_text(markdown(summary))
    return summary


def fmt(value, suffix=''):
    return '–' if value is None else f'{value:,}{suffix}' if isinstance(value, int) else f'{value}{suffix}'


def markdown(s):
    meta, seed = s['meta'], s['seed']
    lines = [
        f"## Load test: {meta.get('profile')} profile, {meta.get('volume')} volume ({meta.get('run')})",
        '',
        f"Runner {meta.get('host_cpus')} CPU ({meta.get('host_cpu_model') or 'model not recorded'}) / {meta.get('host_memory_mb')} MB; profile {meta.get('profile_cpus')} vCPU / {meta.get('profile_memory')}; "
        f"host cap: {meta.get('host_cap')}; PHP threads {meta.get('php_threads')}; virtual users arrive over {fmt(meta.get('start_spread_s'), ' s')}; "
        f"think time {meta.get('think') or 'per page (1–3 s mostly)'}; levels of {meta.get('level_duration')}; commit {meta.get('git_sha')}."
        + (' **The runner is smaller than this profile: these numbers are for the runner, not the profile.**' if meta.get('runner_smaller_than_profile') else ''),
        '',
        f"Seeded {fmt(seed.get('clients'))} clients, {fmt(seed.get('services'))} services, {fmt(seed.get('invoices'))} invoices, "
        f"{fmt(seed.get('transactions'))} payments, {fmt(seed.get('tickets'))} tickets in {fmt(seed.get('seconds'))} s; "
        f"invariants broken: {fmt(s.get('invariants_broken'))}; database {fmt(round((s['database'].get('database_bytes') or 0) / 1048576))} MB.",
        '',
        '### Web (mixed: 50% client area, 30% storefront and cart, 10% admin, 10% JSON)',
        '',
        '| VUs | req/s | p50 ms | p95 ms | p99 ms | errors | 429s | content errors | stack CPU % of cap | app CPU % | PostgreSQL CPU % | k6 CPU % |',
        '| --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: |',
    ]
    for level in s['web']:
        cpu = level.get('cpu') or {}
        lines.append(f"| {level['vus']} | {level['rps']} | {level['p50_ms']} | {level['p95_ms']} | {level['p99_ms']} | "
                     f"{level['error_rate'] * 100:.2f}% | {level['rate_limited']} | {level['content_errors']} | "
                     f"{fmt(cpu.get('stack_avg_of_cap_pct'))} | {fmt(cpu.get('app_avg_pct'))} | {fmt(cpu.get('postgres_avg_pct'))} | {fmt(cpu.get('k6_avg_pct'))} |")
    sat = s['saturation']
    lines += ['', f"Comfortable: p95 < {P95_LIMIT_MS} ms and errors < 1%. CPU columns are averages over the level, % of one core (k6 is outside the cap).", '',
              f"**Saturation:** last comfortable level {fmt(sat['last_comfortable_vus'])} VUs; first failing level {fmt(sat['first_failing_vus'])} VUs"
              + (f" ({sat['reason']})" if sat['reason'] else ' (none of the levels run)')
              + (f"; levels planned but not run: {' '.join(meta['levels_skipped'])}" if meta.get('levels_skipped') else '') + '.', '']
    knee = [level for level in s['web'] if level['vus'] in (sat['last_comfortable_vus'], sat['first_failing_vus'])] or s['web'][-1:]
    for level in knee:
        lines += [f"### Pages at {level['vus']} VUs", '', '| page | n | p50 | p95 | p99 | errors |', '| --- | --: | --: | --: | --: | --: |']
        for page in PAGES_SHOWN:
            p = level['pages'].get(page)
            if p:
                lines.append(f"| {page} | {p['count']} | {p['p50_ms']} | {p['p95_ms']} | {p['p99_ms']} | {p['error_rate'] * 100:.1f}% |")
        lines.append('')
    web_phases = sorted((name for name in s['resources'] if re.fullmatch(r'web-\d+', name)), key=lambda name: int(name[4:]))
    for phase in web_phases[-1:] + [name for name in ('automation', 'drain') if name in s['resources']]:
        lines += [f"### Resources during {phase}", '', '| service | CPU avg % | CPU max % | memory max MB |', '| --- | --: | --: | --: |']
        for service, r in sorted(s['resources'][phase].items()):
            lines.append(f"| {service} | {r['cpu_avg_pct']} | {r['cpu_max_pct']} | {r['mem_max_mb']} |")
        lines.append('')
    pdf, automation, drain = s['pdf'], s['automation'], s['drain']
    lines += [
        '### Background work', '',
        f"- Invoice PDF: {fmt(pdf.get('ms_p50'))} ms p50, {fmt(pdf.get('ms_p95'))} ms p95, {fmt(pdf.get('renders_per_second_one_process'))} renders/s per PHP process, "
        f"up to {fmt(pdf.get('peak_memory_mb_per_render_max'))} MB per render, {fmt(pdf.get('pdf_kb_avg'))} KB per file.",
        *([f"- **`automation:run` exited with {automation['exit_code']} before it finished**"
            + (' (killed: usually the scheduler container\'s memory limit)' if automation['exit_code'] == 137 else '')
            + '. The billing-day figures below cover only the tasks it recorded.'] if automation.get('exit_code') else []),
        f"- Billing-day automation: {fmt(automation['seconds'])} s in total; slowest: "
        + ', '.join(f"{t['task']} {t['seconds']} s ({t['summary']})" for t in automation['tasks'][:4]) + '.',
        f"- Jobs it queued: {json.dumps(automation['jobs_queued'])}. Drain: {fmt(drain.get('jobs'))} jobs in {fmt(drain.get('seconds'))} s "
        f"({fmt(drain.get('jobs_per_second'))} jobs/s, {fmt(drain.get('emails_per_second'))} e-mails/s, {fmt(drain.get('emails_sent'))} sent); "
        f"per queue: {json.dumps(drain.get('per_queue_seconds'))}; failed jobs: {fmt(drain.get('failed_jobs'))}.",
        f"- Provisioning: {s['provisioning'].get('status', '–')} ({s['provisioning'].get('reason', '')}).",
        '',
        '### Slowest queries (total time, web load)', '',
    ]
    for q in s['pg_top_web'][:5]:
        lines.append(f"- {q['total_ms']:,} ms total, {q['calls']:,} calls, {q['mean_ms']} ms mean: `{q['query'][:160]}`")
    lines += ['', '### Slowest queries (automation)', '']
    for q in s['pg_top_automation'][:5]:
        lines.append(f"- {q['total_ms']:,} ms total, {q['calls']:,} calls, {q['mean_ms']} ms mean: `{q['query'][:160]}`")
    return '\n'.join(lines) + '\n'


def combine(out, runs):
    out = Path(out)
    out.mkdir(parents=True, exist_ok=True)
    summaries = [load(Path(r) / 'summary.json') for r in runs]
    summaries = [s for s in summaries if s]
    lines = ['## Load test: all profiles', '', '| profile | volume | think | runner | last comfortable VUs | req/s there | first failing VUs | automation s | drain jobs/s | PDF ms p50 |',
             '| --- | --- | --- | --- | --: | --: | --: | --: | --: | --: |']
    for s in summaries:
        meta = s['meta']
        sat = s.get('saturation') or {}
        best = next((level for level in s['web'] if level['vus'] == sat.get('last_comfortable_vus')), None)
        lines.append(f"| {meta.get('profile')} | {meta.get('volume')} | {meta.get('think') or 'default'} | {meta.get('host_cpus')} CPU / {meta.get('host_memory_mb')} MB"
                     f"{' (smaller than profile)' if meta.get('runner_smaller_than_profile') else ''} | {fmt(sat.get('last_comfortable_vus'))} | "
                     f"{fmt(best['rps'] if best else None)} | {fmt(sat.get('first_failing_vus'))} | {fmt(s['automation']['seconds'])} | {fmt(s['drain'].get('jobs_per_second'))} | {fmt(s['pdf'].get('ms_p50'))} |")
    (out / 'summary.md').write_text('\n'.join(lines) + '\n')
    (out / 'summary.json').write_text(json.dumps(summaries, indent=2))


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == '--check-level':
        level = web_level(sys.argv[2])
        if level is None:
            sys.exit(f'no k6 summary in {sys.argv[2]}')
        print(f"  {level['rps']} req/s, p50 {level['p50_ms']} ms, p95 {level['p95_ms']} ms, p99 {level['p99_ms']} ms, "
              f"errors {level['error_rate'] * 100:.2f}%{'' if comfortable(level) else ' (past the comfortable limit)'}")
        sys.exit(0 if comfortable(level) else SATURATED)
    elif len(sys.argv) > 2 and sys.argv[1] == '--combine':
        combine(sys.argv[2], sys.argv[3:])
    elif len(sys.argv) == 2:
        summarize(sys.argv[1])
        print((Path(sys.argv[1]) / 'summary.md').read_text())
    else:
        sys.exit(__doc__)
