/**
 * Extracts the route table from the NestJS controllers and diffs it against
 * `php artisan route:list --json`, so a missed or misspelled path is caught
 * mechanically rather than by eye.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';

const NEST_SRC = 'd:/projects/gss/graspcraft/graspcraft_backend/src';
const LARAVEL = 'd:/projects/gss/graspcraft/graspcraft_laravel';

function walk(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) out.push(...walk(full));
    else if (entry.endsWith('.controller.ts') && !entry.endsWith('.spec.ts')) out.push(full);
  }
  return out;
}

const nestRoutes = new Set();

for (const file of walk(NEST_SRC)) {
  const lines = readFileSync(file, 'utf8').split('\n');
  let prefix = null;

  for (const raw of lines) {
    const line = raw.trim();
    if (line.startsWith('//') || line.startsWith('*')) continue;

    const ctrl = line.match(/@Controller\(\s*'([^']*)'\s*\)/) || line.match(/@Controller\(\s*\)/);
    if (ctrl) { prefix = ctrl[1] ?? ''; continue; }

    const m = line.match(/@(Get|Post|Patch|Delete)\(\s*(?:'([^']*)')?\s*\)/);
    if (m && prefix !== null) {
      const method = m[1].toUpperCase();
      const path = m[2] ?? '';
      // normalise :param -> {param} and strip trailing slashes
      const full = [prefix, path].filter(Boolean).join('/').replace(/:([A-Za-z_]+)/g, '{$1}').replace(/\/+$/, '');
      nestRoutes.add(`${method} api/${full}`.replace(/\/$/, ''));
    }
  }
}

const laravelJson = JSON.parse(
  execSync('php artisan route:list --json', { cwd: LARAVEL, encoding: 'utf8', maxBuffer: 1 << 24 })
);

const laravelRoutes = new Set();
for (const r of laravelJson) {
  if (!r.uri.startsWith('api')) continue;
  for (const method of r.method.split('|')) {
    if (method === 'HEAD') continue;
    laravelRoutes.add(`${method} ${r.uri.replace(/\/$/, '')}`);
  }
}

const missing = [...nestRoutes].filter((r) => !laravelRoutes.has(r)).sort();
const extra = [...laravelRoutes].filter((r) => !nestRoutes.has(r)).sort();

console.log(`nest routes:    ${nestRoutes.size}`);
console.log(`laravel routes: ${laravelRoutes.size}`);

if (missing.length) {
  console.log(`\nMISSING FROM LARAVEL (${missing.length}):`);
  missing.forEach((r) => console.log('  ' + r));
}

if (extra.length) {
  console.log(`\nEXTRA IN LARAVEL (${extra.length}):`);
  extra.forEach((r) => console.log('  ' + r));
}

if (!missing.length && !extra.length) console.log('\nROUTE TABLES MATCH');
