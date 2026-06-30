import jQuery from 'jquery';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DATATABLE_STUB, loadStub } from './helpers.js';

// The real shipped datatable.js (escaping + custom-action dispatch + bulk-button injection + filters/views).
const { acEsc, acRunAction, acInjectBulkActions, acCollectFilters, acApplyView } = loadStub(
    DATATABLE_STUB,
    '{ acEsc, acRunAction, acInjectBulkActions, acCollectFilters, acApplyView }',
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

describe('acCollectFilters', () => {
    function setup(barHtml) {
        document.body.innerHTML = '<table id="t1"></table>' + barHtml;
        return document.getElementById('t1');
    }

    it('collects select + date-range controls of the matching bar into a filter payload', () => {
        const table = setup(
            '<div data-ac-filters="t1">'
            + '<select data-ac-filter="status"><option value="active" selected>A</option></select>'
            + '<input data-ac-filter="created_at" data-ac-filter-part="from" value="2026-01-01">'
            + '<input data-ac-filter="created_at" data-ac-filter-part="to" value="2026-02-01">'
            + '</div>',
        );

        expect(acCollectFilters(table)).toEqual({
            filter: { status: 'active', created_at: { from: '2026-01-01', to: '2026-02-01' } },
        });
    });

    it('omits empty controls and only reads its own bar (matched by table id)', () => {
        const table = setup(
            '<div data-ac-filters="t1"><select data-ac-filter="status"><option value="" selected></option></select>'
            + '<input data-ac-filter="created_at" data-ac-filter-part="from" value=""></div>'
            // a second table's bar must NOT leak into t1's payload
            + '<div data-ac-filters="t2"><select data-ac-filter="kind"><option value="x" selected>x</option></select></div>',
        );

        expect(acCollectFilters(table)).toEqual({ filter: {} });
    });

    it('returns an empty payload when the table has no filter bar', () => {
        const table = setup('');
        expect(acCollectFilters(table)).toEqual({ filter: {} });
    });
});

describe('acApplyView', () => {
    function bar() {
        document.body.innerHTML =
            '<div data-ac-filters="t1">'
            + '<select data-ac-filter="status"><option value="">All</option><option value="active">A</option></select>'
            + '<input data-ac-filter="created_at" data-ac-filter-part="from" value="old">'
            + '<input data-ac-filter="created_at" data-ac-filter-part="to" value="old">'
            + '</div>';
        return document.querySelector('[data-ac-filters="t1"]');
    }

    it('sets each control from a saved view (select + date parts) and round-trips with acCollectFilters', () => {
        const filterBar = bar();
        acApplyView(filterBar, JSON.stringify({ status: 'active', created_at: { from: '2026-01-01', to: '2026-02-01' } }));

        expect(document.querySelector('[data-ac-filter="status"]').value).toBe('active');
        expect(document.querySelector('[data-ac-filter-part="from"]').value).toBe('2026-01-01');
        expect(document.querySelector('[data-ac-filter-part="to"]').value).toBe('2026-02-01');
        // What was applied is exactly what gets collected back for the next request.
        expect(acCollectFilters(document.getElementById('t1') || { id: 't1' }))
            .toEqual({ filter: { status: 'active', created_at: { from: '2026-01-01', to: '2026-02-01' } } });
    });

    it('clears controls absent from the view, and survives malformed JSON', () => {
        acApplyView(bar(), JSON.stringify({ status: 'active' })); // no created_at → its inputs reset
        expect(document.querySelector('[data-ac-filter="status"]').value).toBe('active');
        expect(document.querySelector('[data-ac-filter-part="from"]').value).toBe('');

        expect(() => acApplyView(bar(), 'not json')).not.toThrow();
        expect(document.querySelector('[data-ac-filter="status"]').value).toBe(''); // all reset on no data
    });
});
