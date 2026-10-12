import { describe, it, expect } from 'vitest';
import { deliveryLabel } from '@/Pages/Tenant/Events/panels/NotificationsPanel';

// "Sent" only means the SMS provider accepted the text. Once the delivery
// sync has asked, the organiser is told what actually happened.
describe('what an organiser is told about one message', () => {
    const sms = (metadata) => ({ channel: 'sms', status: 'sent', metadata });

    it('says a text was delivered once the provider confirms it', () => {
        expect(deliveryLabel(sms({ delivery: 'delivered' }))).toBe('delivered');
    });

    it('says why a text was not delivered', () => {
        expect(deliveryLabel(sms({ delivery: 'undelivered', delivery_detail: 'rejected' }))).toBe(
            'not delivered (rejected)'
        );
        expect(deliveryLabel(sms({ delivery: 'undelivered' }))).toBe('not delivered');
    });

    it('does not claim delivery before the provider has said', () => {
        expect(deliveryLabel(sms({ provider_reference: 'camp-1' }))).toBe(
            'sent, awaiting delivery'
        );
        expect(deliveryLabel(sms(null))).toBe('sent, awaiting delivery');
    });

    it('leaves emails and other statuses as they were', () => {
        expect(deliveryLabel({ channel: 'email', status: 'sent' })).toBe('sent');
        expect(deliveryLabel({ channel: 'sms', status: 'suppressed_quota' })).toBe(
            'suppressed quota'
        );
        expect(deliveryLabel({ channel: 'whatsapp', status: 'staged_omnichannel' })).toBe(
            'Staged — not sent or billed'
        );
    });
});
