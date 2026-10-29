import Input from '@/Components/Console/Input';

const ZONES =
    typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];

/**
 * How a venue takes approval-first requests. There is no instant-booking
 * choice: every request waits for the host.
 */
export default function VenueRequestPolicyFields({ policy, errors, onChange }) {
    return (
        <div className="space-y-4 rounded-lg border border-border bg-surface p-6">
            <div>
                <h3 className="text-sm font-semibold text-ink">Booking requests</h3>
                <p className="mt-1 text-sm text-ink-secondary">
                    The host approves every request. A request does not reserve the dates; when you
                    approve it, the dates are held for the time you choose and then released.
                    Payment is not part of this step.
                </p>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Input
                    label="Default hold (hours)"
                    type="number"
                    min="1"
                    max="168"
                    value={policy.default_hold_hours}
                    onChange={(event) =>
                        onChange({ ...policy, default_hold_hours: Number(event.target.value) })
                    }
                    hint="Between 1 and 168 hours. You can choose a different length when you approve."
                    error={errors['public_booking_policy.default_hold_hours']}
                />
                <Input
                    label="Venue time zone"
                    list="venue-time-zones"
                    value={policy.timezone}
                    onChange={(event) => onChange({ ...policy, timezone: event.target.value })}
                    hint="Requesters pick times in this zone, for example Africa/Accra."
                    error={errors['public_booking_policy.timezone']}
                />
                <datalist id="venue-time-zones">
                    {ZONES.map((zone) => (
                        <option key={zone} value={zone} />
                    ))}
                </datalist>
            </div>
        </div>
    );
}
