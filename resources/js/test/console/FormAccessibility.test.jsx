import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';
import Input from '@/Components/Console/Input';
import Select from '@/Components/Console/Select';

it('associates input labels and descriptions with unique controls', () => {
    render(
        <>
            <Input label="Name" hint="Full name" />
            <Input label="Email" error="Enter an email" />
        </>
    );
    const name = screen.getByRole('textbox', { name: 'Name' });
    const email = screen.getByRole('textbox', { name: 'Email' });
    expect(name.id).not.toBe(email.id);
    expect(document.getElementById(name.getAttribute('aria-describedby')).textContent).toBe(
        'Full name'
    );
    expect(document.getElementById(email.getAttribute('aria-describedby')).textContent).toBe(
        'Enter an email'
    );
    expect(email.getAttribute('aria-invalid')).toBe('true');
});

it('preserves an explicit input id and external description', () => {
    render(
        <>
            <p id="external">Outside help</p>
            <Input id="member-name" label="Name" aria-describedby="external" />
        </>
    );
    const input = screen.getByLabelText('Name');
    expect(input.id).toBe('member-name');
    expect(input.getAttribute('aria-describedby')).toBe('external');
});

it('associates select labels and errors, preserving explicit ids', () => {
    render(
        <Select id="membership" label="Membership type" error="Choose a type">
            <option>Monthly</option>
        </Select>
    );
    const select = screen.getByRole('combobox', { name: 'Membership type' });
    expect(select.id).toBe('membership');
    expect(select.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(select.getAttribute('aria-describedby')).textContent).toBe(
        'Choose a type'
    );
});
