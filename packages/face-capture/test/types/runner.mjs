import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(__dirname, '../../../..');
const pkgRoot = path.resolve(repoRoot, 'packages/face-capture');
const typesFixtureDir = __dirname;
const tscBin = path.resolve(repoRoot, 'node_modules/.bin/tsc');

test('TypeScript compiler is installed and functional', () => {
  assert.ok(fs.existsSync(tscBin), `tsc binary not found at ${tscBin}`);
  const version = execFileSync(tscBin, ['-v'], { encoding: 'utf8' }).trim();
  assert.match(version, /^Version \d+\.\d+\.\d+/, `Unexpected tsc version output: ${version}`);
});

test('Pack face-capture and verify isolated consumer type checks', async (t) => {
  const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'fc-types-fixture-'));
  const packDest = path.join(tmpDir, 'pack');
  fs.mkdirSync(packDest, { recursive: true });

  try {
    // 1. Pack the package from root using npm pack
    await t.test('1. pack tarball from package definition', () => {
      execFileSync('npm', ['pack', './packages/face-capture', '--pack-destination', packDest], {
        cwd: repoRoot,
        stdio: 'pipe',
      });
      const files = fs.readdirSync(packDest);
      const tarball = files.find((f) => f.startsWith('face-capture-') && f.endsWith('.tgz'));
      assert.ok(tarball, `No face-capture tarball generated in ${packDest}`);
    });

    const tarball = fs.readdirSync(packDest).find((f) => f.startsWith('face-capture-') && f.endsWith('.tgz'));
    const tarballPath = path.join(packDest, tarball);

    // 2. Extract into isolated consumer fixture
    const consumerDir = path.join(tmpDir, 'consumer');
    fs.mkdirSync(consumerDir, { recursive: true });
    const consumerNodeModules = path.join(consumerDir, 'node_modules');
    fs.mkdirSync(consumerNodeModules, { recursive: true });

    await t.test('2. extract tarball into isolated consumer node_modules', () => {
      execFileSync('tar', ['-xzf', tarballPath, '-C', consumerDir]);
      const unpackedPkgDir = path.join(consumerDir, 'package');
      assert.ok(fs.existsSync(unpackedPkgDir), 'Expected package/ directory in extracted tarball');

      const installedPkgDir = path.join(consumerNodeModules, 'face-capture');
      fs.renameSync(unpackedPkgDir, installedPkgDir);

      // Verify files in packed tarball
      assert.ok(fs.existsSync(path.join(installedPkgDir, 'package.json')), 'package.json missing from tarball');
      assert.ok(fs.existsSync(path.join(installedPkgDir, 'index.d.ts')), 'index.d.ts missing from tarball');
      assert.ok(fs.existsSync(path.join(installedPkgDir, 'dist/face-capture.js')), 'dist/face-capture.js missing');
      assert.ok(fs.existsSync(path.join(installedPkgDir, 'dist/config.js')), 'dist/config.js missing');

      // Ensure no source leakages
      assert.ok(!fs.existsSync(path.join(installedPkgDir, 'src')), 'Source directory leaked into tarball');
      assert.ok(!fs.existsSync(path.join(installedPkgDir, 'test')), 'Test directory leaked into tarball');

      // Copy consumer fixture files
      fs.copyFileSync(path.join(typesFixtureDir, 'package.json'), path.join(consumerDir, 'package.json'));
      fs.copyFileSync(path.join(typesFixtureDir, 'tsconfig.json'), path.join(consumerDir, 'tsconfig.json'));
      fs.copyFileSync(path.join(typesFixtureDir, 'consumer.ts'), path.join(consumerDir, 'consumer.ts'));
    });

    // 3. Verify actual runtime exports before type compilation
    await t.test('3. verify actual runtime exports match declared contract', async () => {
      const installedPkgDir = path.join(consumerNodeModules, 'face-capture');
      const mainModule = await import(pathToFileURL(path.join(installedPkgDir, 'dist/face-capture.js')).href);
      const configModule = await import(pathToFileURL(path.join(installedPkgDir, 'dist/config.js')).href);

      // Main exports
      const mainExports = Object.keys(mainModule).sort();
      const expectedMainExports = [
        'DEFAULT_STRINGS',
        'FaceCaptureElement',
        'checkConsentGating',
        'mapCameraError',
        'parseConfig',
        'validateConfig',
      ].sort();
      assert.deepEqual(mainExports, expectedMainExports, 'Main runtime exports mismatch');

      // Config subpath exports
      const configExports = Object.keys(configModule).sort();
      const expectedConfigExports = [
        'checkConsentGating',
        'mapCameraError',
        'parseConfig',
        'validateConfig',
      ].sort();
      assert.deepEqual(configExports, expectedConfigExports, 'Config runtime exports mismatch');

      // Verify FaceCaptureElement prototype properties and methods
      const proto = mainModule.FaceCaptureElement.prototype;
      const protoProps = Object.getOwnPropertyNames(proto);
      for (const expected of [
        'template',
        'strings',
        'setStrings',
        'hasPendingSave',
        'apiUrl',
        'token',
        'collection',
        'mode',
        'personId',
        'modelBaseUrl',
        'autostart',
        'start',
        'stop',
        'retry',
      ]) {
        assert.ok(protoProps.includes(expected), `FaceCaptureElement.prototype missing ${expected}`);
      }

      // Verify static properties
      assert.equal(typeof mainModule.FaceCaptureElement.template, 'string', 'FaceCaptureElement.template should be string');
      assert.ok(Array.isArray(mainModule.FaceCaptureElement.observedAttributes), 'observedAttributes should be array');
    });

    // 4. Compile isolated consumer with TypeScript compiler
    await t.test('4. compile isolated consumer against tarball declarations', () => {
      const consumerTsconfig = path.join(consumerDir, 'tsconfig.json');
      const out = execFileSync(tscBin, ['-p', consumerTsconfig], {
        cwd: consumerDir,
        encoding: 'utf8',
      });
      assert.equal(out.trim(), '', `Expected zero diagnostics from tsc, got: ${out}`);
    });

    // 5. Negative gate verification: unannotated type errors fail compilation
    await t.test('5. verify negative type gate fails on invalid assignments without @ts-expect-error', () => {
      const invalidConsumer = path.join(consumerDir, 'invalid-consumer.ts');
      fs.writeFileSync(
        invalidConsumer,
        `import { FaceCaptureElement } from 'face-capture';
const el = document.createElement('face-capture');
el.mode = 'completely-invalid-mode-string';
`
      );

      const invalidTsconfig = path.join(consumerDir, 'tsconfig-invalid.json');
      fs.writeFileSync(
        invalidTsconfig,
        JSON.stringify(
          {
            compilerOptions: {
              target: 'ES2022',
              module: 'NodeNext',
              moduleResolution: 'NodeNext',
              lib: ['ES2022', 'DOM', 'DOM.Iterable'],
              strict: true,
              noEmit: true,
            },
            include: ['invalid-consumer.ts'],
          },
          null,
          2
        )
      );

      assert.throws(
        () => {
          execFileSync(tscBin, ['-p', invalidTsconfig], {
            cwd: consumerDir,
            encoding: 'utf8',
            stdio: 'pipe',
          });
        },
        (err) => {
          const stderr = err.stdout || err.stderr || err.message;
          return stderr.includes('Type \'"completely-invalid-mode-string"\' is not assignable to type');
        },
        'Expected tsc to reject invalid mode assignment without @ts-expect-error'
      );
    });
  } finally {
    fs.rmSync(tmpDir, { recursive: true, force: true });
  }
});
