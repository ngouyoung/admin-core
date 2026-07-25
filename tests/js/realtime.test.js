import { beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// The real shipped realtime.js — exercised end to end to prove: a broadcast optimistically bumps the bell, the
// store is the source of truth (initial + reconnect re-sync), and it is fully inert without Echo / a channel.
const here = dirname(fileURLToPath(import.meta.url));
const SRC = readFileSync(
    resolve(here, '../../stubs/frontend/resources/js/realtime.js.stub'),
    'utf8',
);

// Fresh module scope each load, exposing the named functions (mirrors select-ajax.test.js).
function loadFresh() {
    // eslint-disable-next-line no-new-func
    return new Function(`${SRC}\n;return { startRealtime, syncFromStore, setUnread, currentUnread };`)();
}

function bellHtml(attrs = 'data-ac-channel="admin-core.notifications.App-Models-User.1" data-ac-unread-url="/admin/notifications/unread"') {
    return `<div data-ac-bell ${attrs}>`
        + '<span data-ac-bell-count data-count="0" style="display:none">0</span>'
        + '</div>';
}

// Fake Echo whose .private(channel).listen(event, cb) captures the handler + records the subscribed channel.
function fakeEcho() {
    const captured = { channel: null, event: null, cb: null, bind: vi.fn() };
    return {
        captured,
        Echo: {
            private(channel) {
                captured.channel = channel;
                return {
                    listen(event, cb) {
                        captured.event = event;
                        captured.cb = cb;
                        return this;
                    },
                };
            },
            connector: { pusher: { connection: { bind: captured.bind } } },
        },
    };
}

beforeEach(() => {
    document.body.innerHTML = bellHtml();
    delete window.Echo;
    window.toastr = { info: vi.fn() };
    // Default fetch: store reports 3 unread.
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ unread: 3 }) }));
});

describe('realtime bell', () => {
    it('subscribes to the bell\'s private channel and listens for .notification.created', () => {
        const { startRealtime } = loadFresh();
        const { Echo, captured } = fakeEcho();
        window.Echo = Echo;

        startRealtime();

        expect(captured.channel).toBe('admin-core.notifications.App-Models-User.1');
        expect(captured.event).toBe('.notification.created'); // default when no data-ac-event bridged
        // subscribes on connect AND binds a reconnect re-sync
        expect(captured.bind).toHaveBeenCalledWith('connected', expect.any(Function));
    });

    it('listens for the server-configured event bridged via data-ac-event (no silent publish/listen divergence)', () => {
        document.body.innerHTML = bellHtml(
            'data-ac-channel="admin-core.notifications.App-Models-User.1" data-ac-event="notification.new" data-ac-unread-url="/admin/notifications/unread"',
        );
        const { startRealtime } = loadFresh();
        const { Echo, captured } = fakeEcho();
        window.Echo = Echo;

        startRealtime();

        expect(captured.event).toBe('.notification.new'); // honours the config, leading '.' = raw event
    });

    it('optimistically bumps + reveals the badge and toasts on each broadcast', () => {
        const { startRealtime } = loadFresh();
        const { Echo, captured } = fakeEcho();
        window.Echo = Echo;
        startRealtime();

        const badge = document.querySelector('[data-ac-bell-count]');
        expect(badge.style.display).toBe('none'); // hidden at zero

        captured.cb({ title: 'Order shipped', body: 'x' });
        expect(badge.textContent).toBe('1');
        expect(badge.dataset.count).toBe('1');
        expect(badge.style.display).toBe('');       // revealed
        expect(window.toastr.info).toHaveBeenCalledWith('Order shipped');

        captured.cb({ title: 'Another' });
        expect(badge.textContent).toBe('2');        // accumulates optimistically
    });

    it('clamps the badge text to 9+ past nine', () => {
        const { setUnread } = loadFresh();
        setUnread(42);
        const badge = document.querySelector('[data-ac-bell-count]');
        expect(badge.textContent).toBe('9+');
        expect(badge.dataset.count).toBe('42');     // true count preserved in data
        expect(badge.style.display).toBe('');
    });

    it('re-syncs the AUTHORITATIVE count from the store, overriding the optimistic value', async () => {
        const { startRealtime, syncFromStore } = loadFresh();
        const { Echo, captured } = fakeEcho();
        window.Echo = Echo;
        startRealtime();

        captured.cb({ title: 'x' });                // optimistic → 1
        expect(document.querySelector('[data-ac-bell-count]').textContent).toBe('1');

        await syncFromStore();                      // store says 3 → reconciled to the truth
        const badge = document.querySelector('[data-ac-bell-count]');
        expect(badge.textContent).toBe('3');
        expect(badge.dataset.count).toBe('3');
        expect(global.fetch).toHaveBeenCalledWith('/admin/notifications/unread', expect.objectContaining({
            credentials: 'same-origin',
        }));
    });

    it('hides the badge when the store reports zero unread', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ unread: 0 }) }));
        const { setUnread, syncFromStore } = loadFresh();
        setUnread(5); // pretend it was showing 5
        await syncFromStore();
        const badge = document.querySelector('[data-ac-bell-count]');
        expect(badge.textContent).toBe('0');
        expect(badge.style.display).toBe('none');
    });

    it('is inert without Echo, and without a channel — never throws, never subscribes', () => {
        const first = loadFresh();
        expect(() => first.startRealtime()).not.toThrow(); // no window.Echo

        // Echo present but the bell exposes no channel (broadcast disabled server-side)
        document.body.innerHTML = bellHtml('');
        const { Echo, captured } = fakeEcho();
        window.Echo = Echo;
        const second = loadFresh();
        second.startRealtime();
        expect(captured.channel).toBeNull(); // never subscribed
    });

    it('does not fetch when the bell exposes no sync URL', async () => {
        document.body.innerHTML = bellHtml('data-ac-channel="c"'); // channel but no unread-url
        const { syncFromStore } = loadFresh();
        await syncFromStore();
        expect(global.fetch).not.toHaveBeenCalled();
    });
});
