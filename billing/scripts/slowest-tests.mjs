#!/usr/bin/env node
// Print the slowest test files and test cases from JUnit reports (Pest/ParaTest --log-junit), so CI logs show where
// suite time goes.
//   node scripts/slowest-tests.mjs <junit.xml>... [--files=10] [--cases=20]
// File times are the sum of their test cases; a parallel run's wall time is roughly the total divided by workers.
// Several reports (e.g. the shards of one suite) are added up.
import { readFileSync } from 'node:fs';

const options = { files: 10, cases: 20 };
const reports = [];

for (const argument of process.argv.slice(2)) {
    const option = argument.match(/^--(files|cases)=(\d+)$/);

    if (option) {
        options[option[1]] = Number(option[2]);
    } else if (/^\d+$/.test(argument)) {
        options.files = Number(argument); // the old positional [count]
    } else {
        reports.push(argument);
    }
}

if (reports.length === 0) {
    console.error(
        'usage: node scripts/slowest-tests.mjs <junit.xml>... [--files=10] [--cases=20]',
    );
    process.exit(2);
}

const attribute = (tag, name) =>
    tag.match(new RegExp(`\\s${name}="([^"]*)"`))?.[1];
const decodeEntities = (text) =>
    text
        .replaceAll('&quot;', '"')
        .replaceAll('&apos;', "'")
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll('&amp;', '&');
const totals = new Map();
const cases = [];
let all = 0;

for (const report of reports) {
    for (const [tag] of readFileSync(report, 'utf8').matchAll(
        /<testcase\s[^>]*>/g,
    )) {
        const path = (
            attribute(tag, 'file') ??
            attribute(tag, 'class') ??
            '?'
        ).split('::')[0];
        const seconds = Number(attribute(tag, 'time') ?? 0);
        totals.set(path, (totals.get(path) ?? 0) + seconds);
        cases.push([
            seconds,
            path,
            decodeEntities(attribute(tag, 'name') ?? '?'),
        ]);
        all += seconds;
    }
}

const relative = (path) => path.replace(/^.*?\/(?=(tests|tools)\/)/, '');

console.log(
    `Test time ${all.toFixed(1)} s in ${totals.size} files; slowest files:`,
);

for (const [path, seconds] of [...totals]
    .sort((a, b) => b[1] - a[1])
    .slice(0, options.files)) {
    console.log(`${seconds.toFixed(1).padStart(8)} s  ${relative(path)}`);
}

if (options.cases > 0) {
    console.log('Slowest test cases:');

    for (const [seconds, path, name] of cases
        .sort((a, b) => b[0] - a[0])
        .slice(0, options.cases)) {
        console.log(
            `${seconds.toFixed(2).padStart(8)} s  ${relative(path)} > ${name.slice(0, 140)}`,
        );
    }
}
