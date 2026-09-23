import { useState } from 'react';
import PublicLayout from '@/Layouts/PublicLayout';
import csrfFetch from '@/lib/csrfFetch';
import VerifyPrompt from './VerifyPrompt';

/**
 * Platform attendee portal shell: proves identity across all organisers
 * and provides the entrance to the attendee's event lifecycle workspace.
 */
export default function MyPortal({ organiser = null, verifiedEmail: provenAtLoad = null }) {
    const [verifiedEmail, setVerifiedEmail] = useState(provenAtLoad);
    const [signOutError, setSignOutError] = useState(false);

    const signOutRoute = window.route ? route('attendee.my.verify.forget') : '/my/verify/forget';

    const signOut = async () => {
        try {
            const response = await csrfFetch(signOutRoute, { method: 'POST' });
            if (!response.ok) {
                setSignOutError(true);
                return;
            }
            setSignOutError(false);
            setVerifiedEmail(null);
        } catch {
            setSignOutError(true);
        }
    };

    return (
        <PublicLayout>
            <div className="mx-auto max-w-2xl px-6 py-16 sm:px-10">
                <h1 className="text-2xl font-normal tracking-tight text-ink">
                    {organiser?.name
                        ? `Your events with ${organiser.name}`
                        : 'Your MiConvener events'}
                </h1>

                {verifiedEmail ? (
                    <div className="mt-6 space-y-6">
                        <div className="flex items-center justify-between border-b border-border pb-4">
                            <p className="text-[13.5px] text-ink-secondary">
                                Signed in as <span className="font-medium text-ink">{verifiedEmail}</span>
                            </p>
                            <button
                                type="button"
                                onClick={signOut}
                                className="text-[13px] text-accent underline hover:opacity-80 cursor-pointer"
                            >
                                Sign out
                            </button>
                        </div>
                        {signOutError && (
                            <p className="text-[13px] text-red-600">
                                Couldn't sign you out. Please try again.
                            </p>
                        )}
                        <div className="rounded-xl border border-border bg-surface-subtle p-8 text-center">
                            <p className="text-[14px] text-ink-secondary">
                                No MiConvener events were found for this address yet.
                            </p>
                        </div>
                    </div>
                ) : (
                    <div className="mt-8">
                        <VerifyPrompt onVerified={setVerifiedEmail} />
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
