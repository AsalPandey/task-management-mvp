import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import vm from 'node:vm';

function worker() {
    const handlers = {}, notices = [], posts = [], opened = [], deleted = [], assets = [];
    const clients = [{ url: 'https://example.test/company/tasks', focus: async () => clients[0], postMessage: data => posts.push(data) }];
    const self = { location: { origin: 'https://example.test' }, registration: { scope: 'https://example.test/company/', showNotification: async (title, options) => notices.push({ title, options }) },
        clients: { claim: async () => {}, matchAll: async () => clients, openWindow: async target => opened.push(target) },
        skipWaiting: async () => {}, addEventListener: (type, handler) => { handlers[type] = handler; } };
    const caches = { open: async () => ({ addAll: async paths => assets.push(...paths) }), keys: async () => ['task-management-static-%2Fcompany%2F-v2', 'task-management-static-%2Fother%2F-v3', 'unrelated-cache'], delete: async key => deleted.push(key) };
    vm.runInNewContext(fs.readFileSync('public/service-worker.js', 'utf8'), { self, caches, URL, Response, fetch: async () => { throw Error('Offline'); } });
    const dispatch = async (type, fields = {}) => { let promise; handlers[type]({ ...fields, waitUntil: p => { promise = p; } }); await promise; };
    return { handlers, dispatch, notices, posts, opened, deleted, assets };
}
test('R44 worker validates push and click destinations and keeps other application caches', async () => {
    const w = worker(); await w.dispatch('install'); expect(w.assets.every(path => path.startsWith('/company/'))).toBe(true);
    await w.dispatch('activate'); expect(w.deleted).toEqual(['task-management-static-%2Fcompany%2F-v2']);
    for (const target of ['https://attacker.test/secret', '/other/tasks', '/company/logout', '//attacker.test/']) {
        await w.dispatch('push', { data: { json: () => ({ title:'Update', data:{target, token:'must-not-forward'} }) } });
        expect(w.notices.at(-1).options.data).toEqual({ target:'/company/notifications/all' });
        await w.dispatch('notificationclick', { notification: { close: () => {}, data: {target} } });
        expect(w.opened.at(-1)).toBe('/company/notifications/all');
    }
    expect(w.posts.some(p=>p.type==='REVALIDATE')).toBe(true);
    await w.dispatch('notificationclick', { notification: { close: () => {}, data: {target:'/company/projects'} } });
    expect(w.opened.at(-1)).toBe('/company/projects'); // another page opens; existing draft page is never navigated
});
test('R44 worker ignores mutation and authenticated JSON requests', () => {
    const w=worker(); let intercepted=false;
    for(const request of [
        {method:'POST',url:'https://example.test/company/tasks',destination:''},
        {method:'GET',url:'https://example.test/company/client/freshness',destination:'',mode:'cors'},
        {method:'GET',url:'https://example.test/other/js/app.js',destination:'script'},
    ]) w.handlers.fetch({request,respondWith:()=>{intercepted=true}});
    expect(intercepted).toBe(false);
});
