import { beforeEach, describe, expect, it, vi } from 'vitest';
import { loadStub, MEDIA_PICKER_STUB } from './helpers.js';

// The real shipped media-picker.js — exercised end to end to prove a malicious filename can't break out of
// the markup (the stored-DOM-XSS fix lives in the nested esc()/fieldTile()).
const { initMediaPicker } = loadStub(MEDIA_PICKER_STUB, '{ initMediaPicker }');

const tick = () => new Promise((r) => setTimeout(r, 0));

// A library item whose name + url carry an XSS payload.
const EVIL = { id: 7, name: '"><img onerror=alert(1)>', url: 'x" onerror="alert(2)', is_image: true };

function scaffold() {
    document.body.innerHTML = `
        <div data-ac-media-collection data-ac-name="photos" data-ac-multiple="1">
            <div data-ac-media-items></div>
            <button id="open">Add</button>
        </div>
        <div id="acMediaPicker" data-ac-list-url="/admin/media/list" data-ac-upload-url="/admin/media/upload">
            <div class="row" data-ac-picker-grid></div>
            <input data-ac-picker-search>
            <div data-ac-picker-dropzone></div>
            <input type="file" data-ac-picker-input>
        </div>`;
}

beforeEach(() => {
    scaffold();
    window.toastr = { success: vi.fn(), error: vi.fn() };
    global.fetch = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ data: [EVIL] }) });
});

describe('media picker XSS hardening', () => {
    // We assert on DOM STRUCTURE, not the serialized string: a failed escape would break the payload out
    // of the title/src attribute into a LIVE <img onerror=…> element. Proper escaping keeps it inert text
    // inside the attribute. (String-matching innerHTML is unreliable — jsdom doesn't re-escape </> in attrs.)
    it('escapes the filename in the library grid (no live injected element)', async () => {
        initMediaPicker();

        const modal = document.getElementById('acMediaPicker');
        const show = new Event('show.bs.modal');
        show.relatedTarget = document.getElementById('open');
        modal.dispatchEvent(show);
        await tick();

        const grid = modal.querySelector('[data-ac-picker-grid]');
        expect(grid.querySelectorAll('img')).toHaveLength(1);       // only the legit tile image…
        expect(grid.querySelectorAll('[onerror]')).toHaveLength(0); // …no broken-out onerror handler
        expect(grid.querySelector('img').getAttribute('onerror')).toBeNull();
    });

    it('escapes the url when a picked item is added to the field', async () => {
        initMediaPicker();

        const modal = document.getElementById('acMediaPicker');
        const show = new Event('show.bs.modal');
        show.relatedTarget = document.getElementById('open');
        modal.dispatchEvent(show);
        await tick();

        modal.querySelector('.ac-pick').click(); // select the evil item

        const items = document.querySelector('[data-ac-media-items]');
        expect(items.querySelector('input[type=hidden]').value).toBe('7'); // hidden id input added
        expect(items.querySelectorAll('[onerror]')).toHaveLength(0);        // url didn't break out of src=
        expect(items.querySelector('img').getAttribute('onerror')).toBeNull();
    });
});
