import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

function javascriptFiles(directory) {
    return readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
        const path = join(directory, entry.name);
        return entry.isDirectory() ? javascriptFiles(path) : /\.(js|mjs)$/.test(path) ? [path] : [];
    });
}

const files = ['vite.config.js', 'playwright.config.js', 'scripts/check-js-syntax.mjs',
    ...['resources/js', 'public/js', 'tests/Browser'].flatMap(javascriptFiles)];
for (const path of files) {
    const result = spawnSync(process.execPath, ['--check', path], { stdio: 'inherit' });
    if (result.error || result.status !== 0) {
        if (result.error) console.error(result.error.message);
        process.exit(1);
    }
}
console.log(`JavaScript syntax passed: ${files.length} files.`);
