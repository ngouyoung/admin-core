import jQuery from 'jquery';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DATATABLE_STUB, loadStub } from './helpers.js';

// The real shipped datatable.js (escaping + custom-action dispatch + bulk-button injection).
const { acEsc, acRunAction, acInjectBulkActions } = loadStub(
    DATATABLE_STUB,
    '{ acEsc, acRunAction, acInjectBulkActions }',
);

const tick = () => new Promise((r) => setTimeout(r, 0));

beforeEach(() => {
    document.body.innerHTML = '';
    window.jQuery = jQuery;
    window.toastr = { success: vi.fn(), error: vi.fn() };
    window.Swal = { fire: vi.fn().mockResolvedValue({ value: true }) };
    jQuery.ajax = vi.fn((opts) => { if (opts.success) opts.success({ message: 'srv ok' }); });
});

describe('acEsc', () => {
    it('escapes the HTML-significant characters', () => {
        expect(acEsc('<img onerror=alert(1)>')).toBe('&lt;img onerror=alert(1)&gt;');
        expect(acEsc(`a&b"c'd`)).toBe('a&amp;b&quot;c&#39;d');
    });

    it('treats null/undefined as empty', () => {
        expect(acEsc(null)).toBe('');
        expect(acEsc(undefined)).toBe('');
    });
});

describe('acRunAction', () => {
    it('does nothing for an empty selection', () => {
        acRunAction('/admin/x/action/go', [], '', {});
        expect(jQuery.ajax).not.toHaveBeenCalled();
    });

    it('POSTs the ids (no confirm) and toasts the server message', () => {
        acRunAction('/admin/x/action/go', ['1', '2'], '', { actionDone: 'Done' });

        expect(jQuery.ajax).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'POST', url: '/admin/x/action/go', data: { ids: ['1', '2'] } }),
        );
        expect(window.toastr.success).toHaveBeenCalledWith('srv ok');
        expect(window.Swal.fire).not.toHaveBeenCalled();
    });

    it('confirms first, then POSTs when the user accepts', async () => {
        window.Swal.fire = vi.fn().mockResolvedValue({ value: true });
        acRunAction('/u', ['9'], 'Really?', { confirmYes: 'Yes', cancel: 'No' });

        expect(window.Swal.fire).toHaveBeenCalled();
        await tick();
        expect(jQuery.ajax).toHaveBeenCalledWith(expect.objectContaining({ data: { ids: ['9'] } }));
    });

    it('does NOT POST when the confirm is cancelled', async () => {
        window.Swal.fire = vi.fn().mockResolvedValue({ value: false });
        acRunAction('/u', ['9'], 'Really?', {});

        await tick();
        expect(jQuery.ajax).not.toHaveBeenCalled();
    });

    it('toasts an error when the request fails', () => {
        jQuery.ajax = vi.fn((opts) => opts.error({ responseJSON: { message: 'nope' } }));
        acRunAction('/u', ['1'], '', { error: 'fallback' });

        expect(window.toastr.error).toHaveBeenCalledWith('nope');
    });
});

describe('acInjectBulkActions', () => {
    function card() {
        document.body.innerHTML =
            '<div class="card"><div class="card-header"><button id="bulk-delete"></button></div>'
            + '<div class="card-body"><table id="t"></table></div></div>';
        return document.getElementById('t');
    }

    it('injects an escaped, hidden button before bulk-delete', () => {
        const table = card();
        acInjectBulkActions(table, {
            actions: [{ label: '<b>Pay</b>', icon: 'bi bi-cash', color: 'success', url: '/u', confirm: '' }],
        });

        const btns = document.querySelectorAll('.ac-bulk-action');
        expect(btns).toHaveLength(1);
        expect(btns[0].classList.contains('btn-success')).toBe(true);
        expect(btns[0].classList.contains('d-none')).toBe(true);          // hidden until rows selected
        expect(btns[0].getAttribute('data-ac-url')).toBe('/u');
        expect(btns[0].nextElementSibling.id).toBe('bulk-delete');         // placed before bulk-delete
        expect(btns[0].innerHTML).toContain('&lt;b&gt;Pay');               // label escaped…
        expect(btns[0].innerHTML).not.toContain('<b>Pay');                 // …not raw HTML (no XSS)
    });

    it('is idempotent — re-injecting does not duplicate', () => {
        const table = card();
        const cfg = { actions: [{ label: 'Go', color: 'primary', url: '/u', confirm: '' }] };
        acInjectBulkActions(table, cfg);
        acInjectBulkActions(table, cfg);

        expect(document.querySelectorAll('.ac-bulk-action')).toHaveLength(1);
    });

    it('does nothing when there are no actions', () => {
        const table = card();
        acInjectBulkActions(table, { actions: [] });
        expect(document.querySelectorAll('.ac-bulk-action')).toHaveLength(0);
    });
});
