<?php

return [
    // Base64-encoded 64-byte Ed25519 secret key. Keep it in the deployment environment,
    // never in source control or the database.
    'configuration_signing_secret_key' => env('SAIFNEX_CONFIG_SIGNING_SECRET_KEY', ''),

    // Base64-encoded 32-byte Ed25519 public key. Distribute to data-plane nodes out-of-band.
    'configuration_signing_public_key' => env('SAIFNEX_CONFIG_SIGNING_PUBLIC_KEY', ''),
];
