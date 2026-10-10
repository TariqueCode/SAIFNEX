<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigurationSigningReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_readiness_command_accepts_a_matching_ed25519_key_pair(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP Sodium is not available in this test runtime.');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $publicKey = sodium_crypto_sign_publickey($keyPair);

        config([
            'saifnex.configuration_signing_secret_key' => base64_encode($secretKey),
            'saifnex.configuration_signing_public_key' => base64_encode($publicKey),
        ]);

        $this->artisan('saifnex:check-signing')
            ->expectsOutput('PASS: PHP Sodium is available and the configured Ed25519 key pair matches.')
            ->assertExitCode(0);
    }

    public function test_signing_readiness_command_rejects_a_mismatched_key_pair(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('PHP Sodium is not available in this test runtime.');
        }

        $firstPair = sodium_crypto_sign_keypair();
        $secondPair = sodium_crypto_sign_keypair();

        config([
            'saifnex.configuration_signing_secret_key' => base64_encode(sodium_crypto_sign_secretkey($firstPair)),
            'saifnex.configuration_signing_public_key' => base64_encode(sodium_crypto_sign_publickey($secondPair)),
        ]);

        $this->artisan('saifnex:check-signing')
            ->expectsOutput('FAIL: The configured Ed25519 public key does not match the secret key.')
            ->assertExitCode(1);
    }
}
