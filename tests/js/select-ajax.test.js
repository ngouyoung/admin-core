import { beforeEach, describe, expect, it } from 'vitest';
import jQuery from 'jquery';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// The real shipped select-ajax.js — exercised end to end to prove the cascade wiring no longer leaks a
// document-level listener on every repeater row (the pre-fix code bound one per select, never unbound).
const here = dirname(fileURLToPath(import.meta.url));
const SRC = readFileSync(
    resolve(here, '../../stubs/frontend/resources/js/select-ajax.js.stub'),
    'utf8',
);

// Fresh module scope each load (resets the module-level acCascadeBound flag), exposing enhanceAjaxSelects.
function loadFresh() {
    // eslint-disable-next-line no-new-func
    return new Function(`${SRC}\n;return { enhanceAjaxSelects };`)();
}

function documentChangeHandlerCount() {
    const events = jQuery._data(document, 'events');
    return events && events.change ? events.change.length : 0;
}

beforeEach(() => {
    window.jQuery = jQuery;
    // Minimal select2 stub: mark the element enhanced (so the "already enhanced" guard works) and no-op.
    jQuery.fn.select2 = function select2() {
        return this.addClass('select2-hidden-accessible');
    };
    // Start each test with no lingering jQuery change handlers on document.
    jQuery(document).off('change');
    document.body.innerHTML = '<select id="province_id"><option value="1">A</option><option value="2">B</option></select>'
        + '<div id="rows"></div>';
});

function addRow(id) {
    const html = `<select class="admin-core-select-ajax" id="${id}" data-ajax-url="/x"`
        + " data-ac-depends='{&quot;province_id&quot;:&quot;#province_id&quot;}'></select>";
    jQuery('#rows').append(`<div class="row" id="wrap-${id}">${html}</div>`);
}

describe('select-ajax cascade listener', () => {
    it('installs exactly ONE document change listener regardless of how many rows are enhanced', () => {
        const { enhanceAjaxSelects } = loadFresh();

        for (let i = 0; i < 6; i++) {
            addRow(`child_${i}`);
            enhanceAjaxSelects(document); // the repeater re-runs this for each added row
            jQuery(`#wrap-child_${i}`).remove(); // …and rows come and go as the user edits
        }
        // Add a couple that stick around.
        addRow('keep_a');
        enhanceAjaxSelects(document);
        addRow('keep_b');
        enhanceAjaxSelects(document);

        // Pre-fix: one document 'change' handler per enhanced select → 8 here. Fixed: exactly one, ever.
        expect(documentChangeHandlerCount()).toBe(1);
    });

    it('still clears a dependent select when its parent changes (cascade works)', () => {
        const { enhanceAjaxSelects } = loadFresh();
        addRow('commune');
        enhanceAjaxSelects(document);

        // Give the child a value, then change the parent — the child must be cleared to re-query.
        const child = document.getElementById('commune');
        child.innerHTML = '<option value="9" selected>picked</option>';
        expect(jQuery(child).val()).toBe('9');

        jQuery('#province_id').val('2').trigger('change');

        expect(jQuery(child).val()).toBeFalsy(); // cleared (null/empty), ready to re-query for province 2
    });
});
