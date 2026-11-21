import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const pkgRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

// --ignore-scripts: pretest already built dist/, and prepack would rebuild it
// while the other test files are importing it.
test('npm pack ships the built dist and excludes sources', () => {
  const json = execFileSync('npm', ['pack', '--dry-run', '--json', '--ignore-scripts'], {
    cwd: pkgRoot,
    encoding: 'utf8',
  });
  const packed = JSON.parse(json);
  // npm <= 11 returns an array of pack results, npm 12 an object keyed by name.
  const pack = Array.isArray(packed) ? packed[0] : Object.values(packed)[0];
  const files = new Set(pack.files.map((file) => file.path));

  for (const required of [
    'dist/face-capture.js',
    'dist/config.js',
    'dist/vendor-js/client.mjs',
    'index.d.ts',
    'package.json',
    'README.md',
  ]) {
    assert.ok(files.has(required), `${required} missing from tarball`);
  }

  assert.ok(
    [...files].some((file) => /^dist\/vendor-js\/browser-face\/.+\.mjs$/.test(file)),
    'no browser-face module in tarball'
  );

  // package.json "files" lists only dist/**, index.d.ts and README.md.
  const leaked = [...files].filter((file) => /^(test|scripts|examples)\//.test(file));
  assert.deepEqual(leaked, [], 'source files leaked into tarball');
});
