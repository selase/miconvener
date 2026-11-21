import { beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

const blade = readFileSync(
    `${process.cwd()}/resources/views/auth/organisation-invitation.blade.php`,
    'utf8'
);
const script = blade.match(/<script>([\s\S]*?)<\/script>/)[1];
const token = 'a'.repeat(64);

function initialize() {
    window.eval(script);
    document.dispatchEvent(new Event('DOMContentLoaded'));
}

beforeEach(() => {
    document.body.innerHTML = `
        <input type="hidden" name="token" value="">
        <input type="hidden" name="token" value="">
        <button data-invitation-action>Accept</button>
        <p id="invitation-proof-status" hidden>Reopen the invitation email</p>`;
    window.history.replaceState(null, '', '/organisation-invitations/invitation-id');
    window.sessionStorage.clear();
});

describe('invitation mailbox proof fragment', () => {
    it('moves proof into all POST forms and clears browser history without client storage', () => {
        window.history.replaceState(null, '', `#${token}`);
        initialize();
        expect(window.location.hash).toBe('');
        expect(window.location.pathname).toBe('/organisation-invitations/invitation-id');
        expect(window.location.search).toBe('');
        document.querySelectorAll('input[name="token"]').forEach((input) => {
            expect(input.value).toBe(token);
        });
        expect(document.querySelector('button').disabled).toBe(false);
        expect(window.sessionStorage.length).toBe(0);
    });

    it('refuses malformed fragments and leaves scanner-only pages unable to submit', () => {
        window.history.replaceState(null, '', '#malformed');
        initialize();
        expect(window.location.hash).toBe('');
        expect(document.querySelector('input').value).toBe('');
        expect(document.querySelector('button').disabled).toBe(true);
        expect(document.getElementById('invitation-proof-status').hidden).toBe(false);
    });

    it('uses a server-restored hidden proof after sign-in without putting it back in the URL', () => {
        document.querySelectorAll('input').forEach((input) => {
            input.value = token;
        });
        initialize();
        expect(document.querySelector('button').disabled).toBe(false);
        expect(window.location.href).not.toContain(token);
        expect(window.location.hash).toBe('');
    });
});
