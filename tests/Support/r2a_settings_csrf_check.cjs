const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/settings.blade.php'), 'utf8');
// Substitute server-rendered string values; execute the actual settings handler.
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\{\{[\s\S]*?\}\}/g, 'rendered-value');
const handlers = {};
const meta = { content: 'old-token' };
const hiddenToken = { value: 'old-token' };
const elements = new Map();
const element = id => {
    if (!elements.has(id)) elements.set(id, {
        value: 'test-value', checked: true, style: {}, reset() {},
        addEventListener(event, handler) { handlers[`${id}:${event}`] = handler; },
    });
    return elements.get(id);
};
const requests = [];
vm.runInNewContext(script, {
    document: {
        addEventListener(event, handler) { handler(); },
        querySelector() { return meta; },
        querySelectorAll(selector) { return selector === 'input[name="_token"]' ? [hiddenToken] : []; },
        getElementById: element,
    },
    fetch: async (url, options) => {
        requests.push(options);
        return { ok: true, json: async () => ({ csrf_token: 'new-token', user: { name: 'Test' } }) };
    },
});
(async () => {
    await handlers['passwordForm:submit']({ preventDefault() {} });
    assert.equal(requests[0].headers['X-CSRF-TOKEN'], 'old-token');
    assert.equal(meta.content, 'new-token');
    assert.equal(hiddenToken.value, 'new-token');
    await handlers['savePreferencesBtn:click']();
    assert.equal(requests[1].headers['X-CSRF-TOKEN'], 'new-token');
    console.log('Settings password change -> next AJAX request uses rotated CSRF token: PASS');
    await checkPushToken();
})().catch(error => { console.error(error); process.exitCode = 1; });

async function checkPushToken() {
    const pushSource = fs.readFileSync(path.join(__dirname, '../../public/js/pwa.js'), 'utf8');
    const pushMeta = { content: 'old-token' };
    const pushRequests = [];
    let ready;
    let testClick;
    const button = { addEventListener(event, handler) { testClick = handler; } };
    const registration = { pushManager: { getSubscription: async () => ({ endpoint: 'https://push.example.test/test' }) } };
    vm.runInNewContext(pushSource, {
        URL,
        document: {
            body: { dataset: { userId: '1' } },
            querySelector(selector) { return selector.startsWith('meta') ? pushMeta : null; },
            querySelectorAll(selector) { return selector === '[data-push-test]' ? [button] : []; },
            addEventListener(event, handler) { ready = handler; },
        },
        window: { isSecureContext: true, location: { href: 'http://localhost/settings', hostname: 'localhost' }, PushManager: {}, Notification: {}, addEventListener() {} },
        navigator: { serviceWorker: { register: async () => registration, ready: Promise.resolve() } },
        localStorage: { getItem: () => '1' },
        fetch: async (url, options) => {
            pushRequests.push(options);
            return { ok: true, json: async () => ({ configured: false }) };
        },
    });
    await ready();
    assert.equal(pushRequests[0].headers['X-CSRF-TOKEN'], 'old-token');
    pushMeta.content = 'rotated-token';
    await testClick();
    assert.equal(pushRequests[1].headers['X-CSRF-TOKEN'], 'rotated-token');
    console.log('Push control after password change uses current CSRF token: PASS');
}
