import { beforeEach, describe, expect, it } from 'vitest';
import { COMPUTE_STUB, loadStub } from './helpers.js';

// The real shipped compute.js (safe arithmetic evaluator + live field derivation). Loading it also binds the
// document input/change listeners on jsdom, so dispatching an event exercises the real wiring.
const { acComputeEval, acComputeResolve, acComputeScope } = loadStub(
    COMPUTE_STUB,
    '{ acComputeEval, acComputeResolve, acComputeScope }',
);

beforeEach(() => { document.body.innerHTML = ''; });

describe('acComputeEval', () => {
    const noVars = () => 0;

    it('evaluates arithmetic with correct precedence + parentheses + unary minus', () => {
        expect(acComputeEval('2 + 3 * 4', noVars)).toBe(14);
        expect(acComputeEval('(2 + 3) * 4', noVars)).toBe(20);
        expect(acComputeEval('10 / 4', noVars)).toBe(2.5);
        expect(acComputeEval('-3 + 5', noVars)).toBe(2);
        expect(acComputeEval('2.5 * 2', noVars)).toBe(5);
    });

    it('resolves field names via the resolver', () => {
        const vars = { qty: 3, unit_price: 2.5 };
        expect(acComputeEval('qty * unit_price', (n) => vars[n])).toBe(7.5);
        expect(acComputeEval('qty * conversion_factor', (n) => vars[n] ?? 0)).toBe(0); // unknown → 0
    });

    it('degrades safely — divide-by-zero → 0, malformed → best-effort, never throws or loops', () => {
        expect(acComputeEval('5 / 0', noVars)).toBe(0);
        expect(acComputeEval('', noVars)).toBe(0);
        expect(() => acComputeEval('qty * * 2 @#', () => 4)).not.toThrow();
        expect(acComputeEval('qty +', () => 4)).toBe(4); // trailing operator ignored
    });
});

describe('acComputeResolve', () => {
    it('reads a repeater-row field (name$="[qty]") and a flat field (name="qty")', () => {
        document.body.innerHTML =
            '<div id="row"><input name="lines[7][qty]" value="4"><input name="lines[7][unit_price]" value="2.5"></div>'
            + '<input name="discount" value="1.5">';
        const row = document.getElementById('row');

        expect(acComputeResolve(row, 'qty')).toBe(4);
        expect(acComputeResolve(row, 'unit_price')).toBe(2.5);
        expect(acComputeResolve(document, 'discount')).toBe(1.5);
        expect(acComputeResolve(row, 'missing')).toBe(0);   // absent → 0
    });

    it('does not confuse [qty] with [total_qty] (the "[" anchors the match)', () => {
        document.body.innerHTML = '<div id="r"><input name="l[0][total_qty]" value="99"><input name="l[0][qty]" value="4"></div>';
        expect(acComputeResolve(document.getElementById('r'), 'qty')).toBe(4);
    });
});

describe('live computation (real input listener)', () => {
    it('updates a per-row computed input as sibling fields change', () => {
        document.body.innerHTML =
            '<form><div data-ac-repeater-row>'
            + '<input name="lines[0][qty]" value="2">'
            + '<input name="lines[0][unit_price]" value="10">'
            + '<input name="lines[0][line_total]" readonly data-ac-compute="qty * unit_price" data-ac-compute-decimals="2">'
            + '</div></form>';

        acComputeScope(document); // initial seed
        const total = document.querySelector('[data-ac-compute]');
        expect(total.value).toBe('20.00');

        // Edit qty → the input listener recomputes THIS row live.
        const qty = document.querySelector('[name="lines[0][qty]"]');
        qty.value = '3';
        qty.dispatchEvent(new window.Event('input', { bubbles: true }));
        expect(total.value).toBe('30.00');
    });

    it('survives an out-of-range decimals attr without aborting the other fields (no RangeError)', () => {
        document.body.innerHTML =
            '<form>'
            + '<span data-ac-compute="5" data-ac-compute-decimals="-1">?</span>'   // toFixed(-1) would throw
            + '<span data-ac-compute="7">?</span>'                                  // must still compute
            + '</form>';

        expect(() => acComputeScope(document)).not.toThrow();
        const spans = document.querySelectorAll('[data-ac-compute]');
        expect(spans[0].textContent).toBe('5');  // decimals clamped to 0
        expect(spans[1].textContent).toBe('7');  // sibling not killed by the bad field
    });

    it('does not accumulate when a field references its own name (self-skip, stable)', () => {
        document.body.innerHTML =
            '<form><div data-ac-repeater-row>'
            + '<input name="l[0][qty]" value="3">'
            + '<input name="l[0][t]" data-ac-compute="t + qty" value="0">' // references its own name `t`
            + '</div></form>';

        acComputeScope(document);
        const t = document.querySelector('[name="l[0][t]"]');
        expect(t.value).toBe('3'); // t = (self skipped → 0) + qty = 3, not climbing

        const qty = document.querySelector('[name="l[0][qty]"]');
        qty.value = '4';
        qty.dispatchEvent(new window.Event('input', { bubbles: true }));
        qty.dispatchEvent(new window.Event('input', { bubbles: true }));
        expect(t.value).toBe('4'); // still qty, not 3+4+4…
    });

    it('settles chained computes regardless of DOM order (fixpoint)', () => {
        // `grand` (depends on line_total) is placed BEFORE `line_total` (depends on qty, price).
        document.body.innerHTML =
            '<form><div data-ac-repeater-row>'
            + '<input name="l[0][grand]" data-ac-compute="line_total * 2" data-ac-compute-decimals="0">'
            + '<input name="l[0][qty]" value="2">'
            + '<input name="l[0][price]" value="10">'
            + '<input name="l[0][line_total]" data-ac-compute="qty * price" data-ac-compute-decimals="0">'
            + '</div></form>';

        acComputeScope(document);
        expect(document.querySelector('[name="l[0][line_total]"]').value).toBe('20');
        expect(document.querySelector('[name="l[0][grand]"]').value).toBe('40'); // settled despite bad order
    });

    it('a form-level compute never reads a repeater row field (no upward scope leak)', () => {
        document.body.innerHTML =
            '<form>'
            + '<input name="header_qty" data-ac-compute="qty * 1">'                     // form-level, references `qty`
            + '<div data-ac-repeater-row><input name="l[0][qty]" value="99"></div>'     // a row also has `qty`
            + '</form>';

        acComputeScope(document);
        expect(document.querySelector('[name="header_qty"]').value).toBe('0'); // NOT 99 (row 0 not reached)
    });

    it('computes each repeater row independently and writes text for a non-input target', () => {
        document.body.innerHTML =
            '<form>'
            + '<div data-ac-repeater-row><input name="l[0][qty]" value="2"><input name="l[0][f]" value="5">'
            + '<span data-ac-compute="qty * f">0</span></div>'
            + '<div data-ac-repeater-row><input name="l[1][qty]" value="4"><input name="l[1][f]" value="5">'
            + '<span data-ac-compute="qty * f">0</span></div>'
            + '</form>';

        acComputeScope(document);
        const spans = document.querySelectorAll('[data-ac-compute]');
        expect(spans[0].textContent).toBe('10'); // row 0: 2 * 5
        expect(spans[1].textContent).toBe('20'); // row 1: 4 * 5 — independent
    });
});
