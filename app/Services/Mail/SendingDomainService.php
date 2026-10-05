<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Tenant;
use App\Models\TenantSendingDomain;
use Aws\SesV2\Exception\SesV2Exception;
use Aws\SesV2\SesV2Client;
use Illuminate\Support\Facades\Log;

/**
 * Registers an organiser's domain with SES and tracks whether SES will send
 * from it.
 *
 * SES answers with three DKIM tokens; the organiser publishes them as CNAMEs
 * and SES notices on its own. We only ask SES what it currently thinks, so a
 * domain whose records are later removed drops back to failed and mail falls
 * back to our own address without anyone having to notice first.
 */
final class SendingDomainService
{
    public function __construct(private readonly SesV2Client $ses) {}

    /**
     * Register the domain with SES, or pick up an identity that already exists
     * there (a retry after a timeout, or a domain another product registered).
     * Only an identity created here is ours to delete later.
     */
    public function start(Tenant $tenant, string $domain, string $fromAddress): TenantSendingDomain
    {
        $domain = mb_strtolower(mb_trim($domain));
        $existing = $tenant->sendingDomain;
        $created = true;

        try {
            $identity = $this->ses->createEmailIdentity(['EmailIdentity' => $domain])->toArray();
        } catch (SesV2Exception $e) {
            if ($e->getAwsErrorCode() !== 'AlreadyExistsException') {
                throw $e;
            }

            $identity = $this->ses->getEmailIdentity(['EmailIdentity' => $domain])->toArray();
            $created = false;
        }

        $sendingDomain = TenantSendingDomain::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'domain' => $domain,
                'from_address' => mb_strtolower(mb_trim($fromAddress)),
                'status' => TenantSendingDomain::STATUS_PENDING,
                'owns_ses_identity' => $created || ($existing?->domain === $domain && $existing->owns_ses_identity),
                'verified_at' => null,
            ],
        );

        return $this->apply($sendingDomain, $identity);
    }

    /**
     * Ask SES for the domain's current state and record it.
     */
    public function refresh(TenantSendingDomain $sendingDomain): TenantSendingDomain
    {
        try {
            $identity = $this->ses->getEmailIdentity(['EmailIdentity' => $sendingDomain->domain])->toArray();
        } catch (SesV2Exception $e) {
            if ($e->getAwsErrorCode() !== 'NotFoundException') {
                throw $e;
            }

            $identity = [];
        }

        return $this->apply($sendingDomain, $identity);
    }

    /**
     * Stop sending from the domain, and remove it from SES if we created it
     * there. An identity that already existed belongs to someone else.
     */
    public function remove(TenantSendingDomain $sendingDomain): void
    {
        if ($sendingDomain->owns_ses_identity) {
            try {
                $this->ses->deleteEmailIdentity(['EmailIdentity' => $sendingDomain->domain]);
            } catch (SesV2Exception $e) {
                if ($e->getAwsErrorCode() !== 'NotFoundException') {
                    throw $e;
                }
            }
        }

        $sendingDomain->delete();
    }

    /**
     * @param  array<string, mixed>  $identity  a CreateEmailIdentity or GetEmailIdentity result
     */
    private function apply(TenantSendingDomain $sendingDomain, array $identity): TenantSendingDomain
    {
        /** @var array{Status?: string, Tokens?: list<string>} $dkim */
        $dkim = $identity['DkimAttributes'] ?? [];
        $tokens = $dkim['Tokens'] ?? [];

        $status = match (true) {
            ($identity['VerifiedForSendingStatus'] ?? false) === true => TenantSendingDomain::STATUS_VERIFIED,
            $identity === [], ($dkim['Status'] ?? '') === 'FAILED' => TenantSendingDomain::STATUS_FAILED,
            default => TenantSendingDomain::STATUS_PENDING,
        };

        if ($sendingDomain->isVerified() && $status !== TenantSendingDomain::STATUS_VERIFIED) {
            Log::error('A tenant sending domain stopped verifying; their mail has fallen back to the platform address.', [
                'tenant_id' => $sendingDomain->tenant_id,
                'domain' => $sendingDomain->domain,
                'ses_dkim_status' => $dkim['Status'] ?? null,
            ]);
        }

        $sendingDomain->fill([
            'status' => $status,
            'last_checked_at' => now(),
            'verified_at' => $status === TenantSendingDomain::STATUS_VERIFIED ? ($sendingDomain->verified_at ?? now()) : null,
        ]);

        if ($tokens !== []) {
            $sendingDomain->dkim_records = array_map(fn (string $token): array => [
                'name' => "{$token}._domainkey.{$sendingDomain->domain}",
                'type' => 'CNAME',
                'value' => "{$token}.dkim.amazonses.com",
            ], $tokens);
        }

        $sendingDomain->save();

        return $sendingDomain;
    }
}
