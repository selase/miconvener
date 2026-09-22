import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import VerifyPrompt from './VerifyPrompt';

/**
 * Everything an attendee has with this organiser, once they prove an address.
 * Reached without a registration link, by co-authors and returning delegates.
 * The panels that show history arrive in Plan B.
 */
export default function MyPortal({ organiser, verifiedEmail: provenAtLoad }) {
    const [verifiedEmail, setVerifiedEmail] = useState(provenAtLoad);

    const signOut = async () => {
        await csrfFetch(route('public.my.verify.forget'), { method: 'POST' });
        setVerifiedEmail(null);
    };

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                <h1 className="text-2xl font-normal tracking-tight text-ink">
                    Your events with {organiser.name}
                </h1>
                {verifiedEmail ? (
                    <p className="mt-3 text-[13.5px] text-ink-secondary">
                        Signed in as {verifiedEmail}.{' '}
                        <button type="button" onClick={signOut} className="text-accent underline">
                            Sign out
                        </button>
                    </p>
                ) : (
                    <div className="mt-8">
                        <VerifyPrompt onVerified={setVerifiedEmail} />
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
