<?php

namespace App\Services\Configuration;

use App\Models\ConfigurationVersion;
use RuntimeException;

class ConfigurationSigningService
{
    public function sign(ConfigurationVersion $configuration): array
    {
        if (!function_exists('sodium_crypto_sign_detached')) {
            throw new RuntimeException('The PHP sodium extension is required for configuration signing.');
        }

        $encodedSecret = (string) config('saifnex.configuration_signing_secret_key', '');
        $secretKey = base64_decode($encodedSecret, true);

        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('SAIFNEX configuration signing key is missing or invalid.');
        }

        if (!is_array($configuration->snapshot)) {
            throw new RuntimeException('Cannot sign an invalid configuration snapshot.');
        }

        $canonical = app(CanonicalSnapshot::class)->encode($configuration->snapshot);
        $hash = hash('sha256', $canonical);

        if (!hash_equals((string) $configuration->snapshot_hash, $hash)) {
            throw new RuntimeException('Configuration snapshot hash mismatch; refusing to sign.');
        }

        $signature = sodium_crypto_sign_detached($canonical, $secretKey);

        return [
            'snapshot_hash' => $hash,
            'signature' => base64_encode($signature),
            'signature_algorithm' => 'Ed25519',
        ];
    }
}
