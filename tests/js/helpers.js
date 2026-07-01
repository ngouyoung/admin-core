import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Evaluate a shipped JS stub (a plain browser script — no import/export) and return whatever `returnExpr`
 * names from its scope. The stub runs against jsdom's global window/document, so its top-level
 * addEventListener calls are harmless. We test the REAL shipped text, not a copy.
 *
 * @param {string} relPath  stub path relative to the package root
 * @param {string} returnExpr  a JS expression listing what to expose, e.g. "{ acEsc, acRunAction }"
 */
export function loadStub(relPath, returnExpr) {
    const src = readFileSync(resolve(here, '../../', relPath), 'utf8');
    // eslint-disable-next-line no-new-func
    return new Function(`${src}\n;return (${returnExpr});`)();
}

export const DATATABLE_STUB = 'stubs/frontend/resources/js/datatable.js.stub';
export const MEDIA_PICKER_STUB = 'stubs/frontend/resources/js/media-picker.js.stub';
export const COMPUTE_STUB = 'stubs/frontend/resources/js/compute.js.stub';
