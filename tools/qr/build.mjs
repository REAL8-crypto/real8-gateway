import { build } from 'esbuild';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = dirname(fileURLToPath(import.meta.url));
await build({
    absWorkingDir: directory,
    entryPoints: ['entry.js'],
    outfile: resolve(directory, '../../assets/js/qrcode-1.5.4.js'),
    bundle: true,
    format: 'iife',
    platform: 'browser',
    target: ['es2015'],
    minify: false,
    preserveSymlinks: true,
    legalComments: 'inline',
    banner: {js: '/* node-qrcode 1.5.4 + dijkstrajs 1.0.3, MIT. See bundled license notices. Build: tools/qr/build.mjs */'}
});
