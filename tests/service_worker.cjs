'use strict';
// Deterministic offline regression check: bundled sprite is precached; private APIs are not.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const origin = 'https://example.test/chat/';
const handlers = new Map();
const stores = new Map([['chat-amn-old-version', new Map()]]);
let offline = false;
let networkCalls = 0;
const precached = [];
const caches = {
  async open(name) {
    if (!stores.has(name)) stores.set(name, new Map());
    const store = stores.get(name);
    return {
      async addAll(paths) {
        for (const relative of paths) {
          assert.match(relative, /^\.\/assets\//);
          assert.ok(fs.statSync(path.join(root, relative)).isFile());
          precached.push(relative);
          store.set(new URL(relative, origin).href, { ok: true, value: 'precache' });
        }
      },
      async put(key, response) { store.set(String(key), response); },
    };
  },
  async keys() { return [...stores.keys()]; },
  async delete(name) { return stores.delete(name); },
  async match(key) {
    for (const store of stores.values()) {
      if (store.has(String(key))) return store.get(String(key));
    }
    return undefined;
  },
};
const self = {
  location: { origin: new URL(origin).origin },
  registration: { scope: origin },
  addEventListener(type, handler) { handlers.set(type, handler); },
  skipWaiting() {},
  clients: { claim() {} },
};
const response = { ok: true, value: 'network', clone() { return this; } };
const mockFetch = async () => {
  networkCalls++;
  if (offline) throw Error('offline');
  return response;
};
vm.runInNewContext(fs.readFileSync(path.join(root, 'sw.js'), 'utf8'), {
  self, caches, fetch: mockFetch, URL,
}, { filename: 'sw.js' });

async function main() {
  let pending;
  handlers.get('install')({ waitUntil(promise) { pending = promise; } });
  await pending;
  assert.ok(precached.includes('./assets/img/ui-icons.svg'), 'local icon sprite is precached');
  assert.equal(new Set(precached).size, precached.length, 'no duplicate precache paths');
  assert.ok(!precached.some(p => /api\.php|data\//.test(p)), 'no private API or data precached');
  assert.ok(stores.has('chat-amn-local-static-v7'));
  handlers.get('activate')({ waitUntil(promise) { pending = promise; } });
  await pending;
  assert.ok(!stores.has('chat-amn-old-version'), 'old caches purged');

  const fetchEvent = (url, method = 'GET') => {
    let result;
    handlers.get('fetch')({
      request: { method, url },
      respondWith(promise) { result = promise; },
    });
    return result;
  };
  assert.equal(fetchEvent(origin + 'api.php?action=messages'), undefined, 'API not intercepted');
  assert.equal(fetchEvent(origin + 'data/users.json'), undefined, 'private data not intercepted');
  assert.equal(fetchEvent(origin + 'assets/img/ui-icons.svg', 'POST'), undefined, 'POST not intercepted');
  assert.equal(fetchEvent('https://elsewhere.test/assets/img/ui-icons.svg'), undefined, 'cross-origin ignored');
  assert.equal(networkCalls, 0);

  const assetUrl = origin + 'assets/img/ui-icons.svg';
  assert.equal((await fetchEvent(assetUrl)).value, 'network', 'sprite served from network while online');
  offline = true;
  assert.equal((await fetchEvent(assetUrl)).value, 'network', 'cached sprite available offline');
  assert.equal((await fetchEvent(origin + 'assets/img/icon.svg')).value, 'precache', 'other local icon precached');
  console.log(`service worker: ${precached.length} local assets; icon precache, offline fallback and private-URL bypass passed`);
}
main().catch(e => { console.error(e); process.exitCode = 1; });
