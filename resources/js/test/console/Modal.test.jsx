import { expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import Modal from '@/Components/Console/Modal';
it('labels the dialog, traps keyboard focus, and restores its trigger without resetting focus on rerender', () => {
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    const close = vi.fn();
    const { rerender, unmount } = render(
        <Modal open title="Design" onClose={close}>
            <input aria-label="Title" />
            <button>Save</button>
        </Modal>
    );
    expect(screen.getByRole('dialog', { name: 'Design' }).getAttribute('aria-modal')).toBe('true');
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close' }));
    screen.getByLabelText('Title').focus();
    rerender(
        <Modal open title="Design" onClose={() => close()}>
            <input aria-label="Title" />
            <button>Save</button>
        </Modal>
    );
    expect(document.activeElement).toBe(screen.getByLabelText('Title'));
    screen.getByRole('button', { name: 'Save' }).focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close' }));
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Save' }));
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(close).toHaveBeenCalledOnce();
    unmount();
    expect(document.activeElement).toBe(trigger);
    trigger.remove();
});

it('offers an opt-in mobile workspace and skips the hidden mobile pane when trapping focus', () => {
    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    const { unmount } = render(
        <Modal open fullScreenOnMobile title="Badge workspace" onClose={() => {}}>
            <button>Visible action</button>
            <div data-mobile-hidden="true">
                <button>Hidden action</button>
            </div>
        </Modal>
    );
    expect(screen.getByRole('dialog').className).toContain('h-dvh');
    screen.getByRole('button', { name: 'Visible action' }).focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Close' }));
    unmount();
    vi.unstubAllGlobals();
});

it('pins its footer outside the scrolling body and locks page scroll until every dialog closes', () => {
    const outer = render(
        <Modal open title="Register" onClose={() => {}} footer={<button>Submit</button>}>
            <input aria-label="Name" />
        </Modal>
    );
    const body = screen.getByLabelText('Name').parentElement;
    expect(body.className).toContain('overflow-y-auto');
    expect(body.contains(screen.getByRole('button', { name: 'Submit' }))).toBe(false);
    expect(document.body.style.overflow).toBe('hidden');

    const inner = render(
        <Modal open title="Confirm" onClose={() => {}}>
            <p>Sure?</p>
        </Modal>
    );
    inner.unmount();
    expect(document.body.style.overflow).toBe('hidden');
    outer.unmount();
    expect(document.body.style.overflow).toBe('');
});
