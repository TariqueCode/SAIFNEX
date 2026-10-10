<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('saifnex:create-operator', function () {
    $name = trim((string) $this->ask('Operator name'));
    $email = strtolower(trim((string) $this->ask('Operator email')));

    $validator = Validator::make(
        ['name' => $name, 'email' => $email],
        [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ],
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $password = (string) $this->secret('Operator password (minimum 12 characters)');
    $passwordValidator = Validator::make(
        ['password' => $password],
        ['password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()]],
    );

    if ($passwordValidator->fails()) {
        foreach ($passwordValidator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    if ($this->confirm('Create this operator account?', false) !== true) {
        $this->warn('No account was created.');

        return 0;
    }

    User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
    ]);

    $this->info('Operator account created. Sign in at /login and keep the password private.');

    return 0;
})->purpose('Create the first SAIFNEX operator account securely from the server CLI');

Artisan::command('saifnex:check-signing', function () {
    if (!function_exists('sodium_crypto_sign_detached')) {
        $this->error('FAIL: PHP Sodium is unavailable. Signed configuration publishing is disabled.');

        return 1;
    }

    $encodedSecret = (string) config('saifnex.configuration_signing_secret_key', '');
    $encodedPublic = (string) config('saifnex.configuration_signing_public_key', '');
    $secretKey = base64_decode($encodedSecret, true);
    $publicKey = base64_decode($encodedPublic, true);

    if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        $this->error('FAIL: SAIFNEX_CONFIG_SIGNING_SECRET_KEY is missing or is not a valid Ed25519 secret key.');

        return 1;
    }

    if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        $this->error('FAIL: SAIFNEX_CONFIG_SIGNING_PUBLIC_KEY is missing or is not a valid Ed25519 public key.');

        return 1;
    }

    $derivedPublicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

    if (!hash_equals($derivedPublicKey, $publicKey)) {
        $this->error('FAIL: The configured Ed25519 public key does not match the secret key.');

        return 1;
    }

    $this->info('PASS: PHP Sodium is available and the configured Ed25519 key pair matches.');
    $this->comment('No key material was displayed. Keep the secret key outside source control and logs.');

    return 0;
})->purpose('Check Sodium support and Ed25519 key-pair consistency without revealing key material');
