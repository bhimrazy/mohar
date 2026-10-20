#!/usr/bin/env node
// Print the slowest test files from a JUnit report (Pest/ParaTest --log-junit), so CI logs show where suite time goes.
//   node scripts/slowest-tests.mjs <junit.xml> [count]
// Times are the sum of each file's test cases; a parallel run's wall time is roughly the total divided by workers.
import { readFileSync } from 'node:fs';

const [file, count = '10'] = process.argv.slice(2);

if (!file) {
    console.error('usage: node scripts/slowest-tests.mjs <junit.xml> [count]');
    process.exit(2);
}

const attribute = (tag, name) =>
    tag.match(new RegExp(`\\s${name}="([^"]*)"`))?.[1];
const totals = new Map();
let all = 0;

for (const [tag] of readFileSync(file, 'utf8').matchAll(/<testcase\s[^>]*>/g)) {
    const path = (
        attribute(tag, 'file') ??
        attribute(tag, 'class') ??
        '?'
    ).split('::')[0];
    const seconds = Number(attribute(tag, 'time') ?? 0);
    totals.set(path, (totals.get(path) ?? 0) + seconds);
    all += seconds;
}

console.log(`Test time ${all.toFixed(1)} s in ${totals.size} files; slowest:`);

for (const [path, seconds] of [...totals]
    .sort((a, b) => b[1] - a[1])
    .slice(0, Number(count))) {
    console.log(`${seconds.toFixed(1).padStart(8)} s  ${path}`);
}
