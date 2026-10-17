import { beforeEach, it, expect } from 'vitest';
import {
    saveOfflineTicket,
    getOfflineTicket,
    listOfflineTickets,
    clearAllOfflineTickets,
} from '@/lib/offlineTicketStore';
import { reconcileAttendeeIdentity, clearAttendeeIdentity } from '@/lib/attendeeIdentityCache';
const context = (key = 'a') => ({
    state: 'verified',
    owner_key: key.repeat(64),
    proof_expires_at: Date.now() + 3600000,
});
const registration = () => ({
    id: 'one',
    full_name: 'Delegate',
    status: 'confirmed',
    ticket_code: 'CODE',
    qr_image: 'data:image/svg+xml;base64,PHN2Zy8+',
    email: 'private@example.test',
    agenda_session_ids: ['private'],
});
const event = () => ({
    name: 'Conference',
    starts_at: new Date().toISOString(),
    ends_at: new Date(Date.now() + 86400000).toISOString(),
    address: 'Accra',
    virtual_link: 'secret-meeting',
    sessions: [{ id: 'private', title: 'Secret session' }],
});
beforeEach(() => {
    localStorage.clear();
    clearAttendeeIdentity();
});
it('stores ticket passes only with explicit proof ownership and no agenda or private links', () => {
    const proof = context();
    reconcileAttendeeIdentity(proof);
    const pass = saveOfflineTicket(registration(), event(), proof);
    expect(pass.schema_version).toBe(3);
    expect(pass.owner_key).toBe(proof.owner_key);
    expect(pass).not.toHaveProperty('agenda');
    expect(JSON.stringify(pass)).not.toContain('secret');
    expect(JSON.stringify(pass)).not.toContain('private@example');
    expect(listOfflineTickets(proof)).toHaveLength(1);
});
it('rejects unconfirmed tickets, malformed timestamps and legacy unpartitioned snapshots', () => {
    const proof = context();
    reconcileAttendeeIdentity(proof);
    expect(
        saveOfflineTicket({ ...registration(), status: 'pending_payment' }, event(), proof)
    ).toBeNull();
    expect(
        saveOfflineTicket(registration(), { ...event(), ends_at: 'bad-date' }, proof)
    ).toBeNull();
    localStorage.setItem(
        'miconvener_offline_ticket_old',
        JSON.stringify({ registration_id: 'old', agenda: ['secret'] })
    );
    expect(getOfflineTicket('old', proof)).toBeNull();
    expect(localStorage.getItem('miconvener_offline_ticket_old')).toBeNull();
});
it('clears A on authoritative switch to B and rejects late A saves', () => {
    const a = context();
    const b = context('b');
    reconcileAttendeeIdentity(a);
    saveOfflineTicket(registration(), event(), a);
    reconcileAttendeeIdentity(b);
    expect(getOfflineTicket('one', a)).toBeNull();
    expect(saveOfflineTicket(registration(), event(), a)).toBeNull();
    expect(listOfflineTickets(b)).toEqual([]);
});
it('allows unknown offline boot only before saved proof expiry and clears on sign out', () => {
    const proof = context();
    reconcileAttendeeIdentity(proof);
    saveOfflineTicket(registration(), event(), proof);
    reconcileAttendeeIdentity({ state: 'unknown_offline' });
    expect(listOfflineTickets({ state: 'unknown_offline' })).toHaveLength(1);
    reconcileAttendeeIdentity({ state: 'signed_out' });
    expect(listOfflineTickets({ state: 'unknown_offline' })).toHaveLength(0);
    reconcileAttendeeIdentity(proof);
    saveOfflineTicket(registration(), event(), proof);
    clearAllOfflineTickets();
    expect(listOfflineTickets(proof)).toHaveLength(0);
});
it('fails closed on expired or corrupted ownership', () => {
    const proof = context();
    reconcileAttendeeIdentity(proof);
    saveOfflineTicket(registration(), event(), proof);
    expect(listOfflineTickets({ ...proof, proof_expires_at: Date.now() - 1 })).toEqual([]);
    expect(
        saveOfflineTicket(registration(), event(), { ...proof, proof_expires_at: NaN })
    ).toBeNull();
});
