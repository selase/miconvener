<?php

declare(strict_types=1);

namespace App\Services\Secrets;

use App\Contracts\Secrets\SecretsProvider;
use Aws\Kms\KmsClient;
use Exception;
use RuntimeException;

final readonly class AwsKmsSecretsProvider implements SecretsProvider
{
    private KmsClient $client;

    public function __construct(array $config)
    {
        $this->client = new KmsClient([
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getSecret(string $ref): array
    {
        // For a true KMS provider, we usually store the mapping of ref -> KMS Key ID or Secret ARN.
        // In this implementation, we assume the reference IS the secret name in AWS Secrets Manager,
        // OR we use KMS to decrypt a locally stored blob.

        // Let's assume we use AWS Secrets Manager which uses KMS under the hood.
        // For pure KMS, we'd be decrypting data.

        try {
            // Simplified: Retrieve from Secrets Manager using the KMS-integrated client
            // Note: In a real production app, you might use 'Aws\SecretsManager\SecretsManagerClient'
            // But if we strictly want KMS for decryption of local config:

            // This is a placeholder for the actual KMS/SecretsManager logic
            // logic here...

            return [];
        } catch (Exception $e) {
            throw new RuntimeException("Failed to retrieve secret [{$ref}] from AWS: ".$e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Decrypt a value using AWS KMS.
     */
    public function decrypt(string $cipherText, string $keyId): string
    {
        $result = $this->client->decrypt([
            'CiphertextBlob' => base64_decode($cipherText),
            'KeyId' => $keyId,
        ]);

        return $result['Plaintext'];
    }
}
