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

        return self::FAILURE;
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

        return self::FAILURE;
    }

    if ($this->confirm('Create this operator account?', false) !== true) {
        $this->warn('No account was created.');

        return self::SUCCESS;
    }

    User::query()->create([
        'name' => $name,
        'email' => $email,
        'password' => $password,
    ]);

    $this->info('Operator account created. Sign in at /login and keep the password private.');

    return self::SUCCESS;
})->purpose('Create the first SAIFNEX operator account securely from the server CLI');
