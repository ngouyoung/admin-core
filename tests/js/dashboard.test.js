import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DASHBOARD_STUB, loadStub } from './helpers.js';

// The real shipped dashboard.js — exercising the customize/save layout flow.
const { initCustomize } = loadStub(DASHBOARD_STUB, '{ initCustomize }');

const tick = () => new Promise((r) => setTimeout(r, 0));

beforeEach(() => {
    document.body.innerHTML = '';
    window.toastr = { success: vi.fn() };
    global.fetch = vi.fn().mockResolvedValue({ ok: true });
});

describe('customize: hidden widgets survive a reorder-only save', () => {
    it('seeds the hidden set from data-ac-hidden so a save does not resurrect previously-hidden widgets', async () => {
        // The server excludes hidden widget "b" from the DOM (only a, c render) but tells the client it's
        // hidden via data-ac-hidden. A reorder-only save (b never touched this session) must still submit b.
        document.body.innerHTML = `
            <button data-ac-customize>Customize</button>
            <div data-ac-customize-actions class="d-none">
                <button data-ac-customize-save>Save</button>
                <button data-ac-customize-cancel>Cancel</button>
            </div>
            <div class="row" data-ac-dashboard data-ac-layout-url="/admin/dashboard/layout" data-ac-hidden='["b"]'>
                <div data-ac-widget="a"></div>
                <div data-ac-widget="c"></div>
            </div>`;

        initCustomize();
        document.querySelector('[data-ac-customize]').click(); // enter customize mode
        document.querySelector('[data-ac-customize-save]').click();
        await tick();

        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body.order).toEqual(['a', 'c']);   // the DOM order
        expect(body.hidden).toEqual(['b']);        // the previously-hidden widget is PRESERVED, not wiped
    });

    it('adds a newly-hidden widget to the seeded set', async () => {
        document.body.innerHTML = `
            <button data-ac-customize>Customize</button>
            <div data-ac-customize-actions class="d-none"><button data-ac-customize-save>Save</button></div>
            <div class="row" data-ac-dashboard data-ac-layout-url="/x" data-ac-hidden='["b"]'>
                <div data-ac-widget="a"><button class="ac-widget-hide"></button></div>
                <div data-ac-widget="c"></div>
            </div>`;

        initCustomize();
        document.querySelector('[data-ac-customize]').click();
        document.querySelector('.ac-widget-hide').click(); // hide "a" this session
        document.querySelector('[data-ac-customize-save]').click();
        await tick();

        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body.hidden.sort()).toEqual(['a', 'b']); // both the freshly-hidden and the previously-hidden
    });
});
