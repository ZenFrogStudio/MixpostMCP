// Copies the ffmpeg/ffprobe binaries that ffmpeg-static and ffprobe-static downloaded for this
// machine into extras/ffmpeg/, which electron-builder ships next to the app (NATIVEPHP_EXTRAS_PATH).
// Run by build.sh; safe to run by hand: node scripts/copy-ffmpeg.mjs
import { chmodSync, copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const target = join(dirname(fileURLToPath(import.meta.url)), '..', 'extras', 'ffmpeg');
const exe = process.platform === 'win32' ? '.exe' : '';

const ffmpeg = require('ffmpeg-static');
const ffprobe = require('ffprobe-static').path;

for (const source of [ffmpeg, ffprobe]) {
    if (!source || !existsSync(source)) {
        console.error(`ffmpeg binary missing: ${source}. Run "npm install" in desktop/ first.`);
        process.exit(1);
    }
}

mkdirSync(target, { recursive: true });

copyFileSync(ffmpeg, join(target, `ffmpeg${exe}`));
copyFileSync(ffprobe, join(target, `ffprobe${exe}`));
// ffmpeg is GPL; the licence text has to travel with the binary.
copyFileSync(join(dirname(ffmpeg), `ffmpeg${exe}.LICENSE`), join(target, 'LICENSE.ffmpeg.txt'));

if (process.platform !== 'win32') {
    chmodSync(join(target, 'ffmpeg'), 0o755);
    chmodSync(join(target, 'ffprobe'), 0o755);
}

console.log(`ffmpeg + ffprobe copied to ${target}`);
