import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NOTIFY_STUB, loadStub } from './helpers.js';

// The toast helper must render the message as inert TEXT, never as HTML — a toast can carry untrusted data
// (a server payload, an error message), and SweetAlert2's `title` is an HTML sink.
describe('notify toast', () => {
    beforeEach(() => {
        delete window.toastr;
        window.Swal = { fire: vi.fn() };
    });

    it('passes the message as titleText (inert), never the HTML-rendering title', () => {
        loadStub(NOTIFY_STUB, '0'); // running the stub installs window.toastr
        window.toastr.info('<img src=x onerror=alert(1)>');

        expect(window.Swal.fire).toHaveBeenCalledTimes(1);
        const opts = window.Swal.fire.mock.calls[0][0];
        expect(opts.titleText).toBe('<img src=x onerror=alert(1)>'); // raw string → SweetAlert escapes it
        expect(opts.title).toBeUndefined();                          // NOT the HTML sink
        expect(opts.icon).toBe('info');
    });
});
