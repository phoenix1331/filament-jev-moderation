// Filament plugin directory images, as JPEG (which Chrome's --screenshot flag
// cannot write), through the Chrome DevTools Protocol:
//   plugin-directory.jpg            main image, 16:9 at 2560x1440, light theme
//   plugin-directory-thumbnail.jpg  tighter 16:9 crop for the plugins list, 1920x1080
// Usage: node bin/directory-images.mjs <base-url> <out-dir> <chrome-binary>
// Called by bin/screenshots against the running workbench. Needs Node 22+ (global WebSocket).

import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [base, out, chrome] = process.argv.slice(2);
const port = 9300 + Math.floor(Math.random() * 500);
const profile = mkdtempSync(join(tmpdir(), 'jev-moderation-directory-'));

const browser = spawn(chrome, [
    '--headless=new', '--no-sandbox', '--disable-gpu', '--hide-scrollbars',
    `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, 'about:blank',
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function pageSocket() {
    for (let attempt = 0; attempt < 50; attempt++) {
        try {
            const targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json();
            const page = targets.find((target) => target.type === 'page');

            if (page) {
                return page.webSocketDebuggerUrl;
            }
        } catch {
            // Chrome is still starting.
        }

        await sleep(200);
    }

    throw new Error('Chrome did not start.');
}

const socket = new WebSocket(await pageSocket());
await new Promise((resolve) => socket.addEventListener('open', resolve, { once: true }));

let id = 0;
const pending = new Map();

socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);

    if (message.id && pending.has(message.id)) {
        pending.get(message.id)(message);
        pending.delete(message.id);
    }
});

const send = (method, params = {}) => new Promise((resolve, reject) => {
    const messageId = ++id;

    pending.set(messageId, (message) => (message.error ? reject(new Error(message.error.message)) : resolve(message.result)));
    socket.send(JSON.stringify({ id: messageId, method, params }));
});

async function visit(url) {
    await send('Page.navigate', { url });
    // Filament widgets load lazily after the page; give them time to render.
    await sleep(6000);
}

async function capture(file, clip) {
    const { data } = await send('Page.captureScreenshot', { format: 'jpeg', quality: 92, clip, captureBeyondViewport: false });

    writeFileSync(join(out, file), Buffer.from(data, 'base64'));
    console.log(`  docs/screenshots/${file}`);
}

try {
    await send('Page.enable');
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: 'light' }] });
    await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 720, deviceScaleFactor: 2, mobile: false });

    await visit(`${base}/_workbench`);
    await visit(`${base}/admin/moderation/queue`);

    await capture('plugin-directory.jpg', { x: 0, y: 0, width: 1280, height: 720, scale: 1 });
    // The content column without the sidebar: stat tiles and the top of the queue, still 16:9.
    await capture('plugin-directory-thumbnail.jpg', { x: 330, y: 85, width: 960, height: 540, scale: 1 });
} finally {
    socket.close();
    browser.kill();
    await sleep(300);
    rmSync(profile, { recursive: true, force: true });
}
