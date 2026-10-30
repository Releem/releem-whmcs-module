// Node.js 22+ and Chrome/Chromium; no npm packages, HTTP access or installers needed.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawn} from 'node:child_process';

const repo = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = p => fs.readFileSync(path.join(repo, p), 'utf8');
const template = read('modules/servers/releem/clientarea.tpl');
const source = {
    wizard: template.match(/<script>([\s\S]*?)<\/script>/)[1],
    base: template.split('{if $releemPublicApiKey}')[1].split('{elseif')[0].replace(/\{literal\}[\s\S]*?\{\/literal\}/g, ''),
    hook: read('modules/addons/releem_setup/hooks.php').match(/<script type="text\/javascript">([\s\S]*?)<\/script>/)[1],
    home: read('modules/addons/releem_setup/releem_setup.php')
};
const css = template.match(/<style>([\s\S]*?)<\/style>/)[1] + read('modules/addons/releem_setup/assets/css/releem.css');
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'releem-browser-test-'));
const chrome = spawn(process.env.CHROME_BIN || 'google-chrome', [
    '--headless', '--no-sandbox', '--disable-gpu', '--disable-background-networking',
    '--disable-component-update', '--disable-sync', '--host-resolver-rules=MAP * ~NOTFOUND',
    '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'
], {stdio: ['ignore', 'ignore', 'pipe']});
let socket;
try {
    const endpoint = await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(Error('Chrome start timeout')), 10000);
        let stderr = '';
        chrome.on('error', e => { clearTimeout(timeout); reject(e); });
        chrome.stderr.on('data', b => {
            stderr += b;
            const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);
            if (match) { clearTimeout(timeout); resolve(match[1]); }
        });
    });
    socket = new WebSocket(endpoint);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let sequence = 0;
    const pending = new Map();
    socket.onmessage = e => {
        const message = JSON.parse(e.data);
        if (!pending.has(message.id)) return;
        const {resolve, reject, timer} = pending.get(message.id);
        clearTimeout(timer);
        pending.delete(message.id);
        message.error ? reject(Error(message.error.message)) : resolve(message.result);
    };
    function call(method, params = {}, sessionId) {
        const id = ++sequence;
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => { pending.delete(id); reject(Error(method + ' timed out')); }, 10000);
            pending.set(id, {resolve, reject, timer});
            socket.send(JSON.stringify({id, method, params, ...(sessionId ? {sessionId} : {})}));
        });
    }
    for (const suite of ['wizard', 'server_form']) {
        for (const width of [1440, 375]) {
            const {targetId} = await call('Target.createTarget', {url: 'about:blank'});
            const {sessionId} = await call('Target.attachToTarget', {targetId, flatten: true});
            await call('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false}, sessionId);
            await call('Page.enable', {}, sessionId);
            const {frameTree} = await call('Page.getFrameTree', {}, sessionId);
            const html = '<!doctype html><html><head><meta charset="utf-8"><base href="https://whmcs.example.invalid/"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\' \'unsafe-eval\'; style-src \'unsafe-inline\'; connect-src \'none\'"><style>' + css + '</style></head><body><div id="fixture"></div><pre id="results"></pre><script>const SOURCE='
                + JSON.stringify(source).replace(/</g, '\\u003c') + ';</script><script>'
                + read('tests/browser/' + suite + '.js') + '</script></body></html>';
            await call('Page.setDocumentContent', {frameId: frameTree.frame.id, html}, sessionId);
            let result;
            for (let i = 0; i < 200; i++) {
                const r = await call('Runtime.evaluate', {expression: 'document.title.startsWith("DONE") ? document.getElementById("results").textContent : ""', returnByValue: true}, sessionId);
                if (r.result.value) { result = JSON.parse(r.result.value); break; }
                await new Promise(resolve => setTimeout(resolve, 25));
            }
            if (!result) throw Error(suite + ' browser test timed out');
            console.log(`${suite} ${width}px: ${result.passed}/${result.total}`);
            for (const failed of result.results.filter(r => r.status === 'FAIL')) console.error('FAIL ' + failed.name + ': ' + failed.error);
            if (result.failed) process.exitCode = 1;
            await call('Target.closeTarget', {targetId});
        }
    }
} catch (e) {
    console.error(e.message);
    process.exitCode = 1;
} finally {
    if (socket) socket.close();
    chrome.kill();
    await new Promise(resolve => !chrome.pid || chrome.exitCode !== null || chrome.signalCode !== null ? resolve() : chrome.once('exit', resolve));
    fs.rmSync(profile, {recursive: true, force: true, maxRetries: 5, retryDelay: 100});
}
