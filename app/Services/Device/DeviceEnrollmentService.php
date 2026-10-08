<?php

namespace App\Services\Device;

use App\Models\Device;
use App\Models\DeviceCredential;
use App\Models\DeviceEnrollment;
use App\Models\Network;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DeviceEnrollmentService
{
    public function create(Network $network, string $name, string $type, string $identifier, int $createdBy, int $ttlMinutes = 15): array
    {
        $device = $network->devices()->create([
            'name' => $name,
            'type' => $type,
            'identifier' => $identifier,
            'status' => 'PENDING',
        ]);

        $token = Str::random(64);

        $enrollment = $device->enrollments()->create([
            'network_id' => $network->id,
            'token_hash' => hash('sha256', $token),
            'status' => 'PENDING',
            'expires_at' => now()->addMinutes($ttlMinutes),
            'created_by' => $createdBy,
        ]);

        return [
            'device' => $device->fresh(),
            'enrollment' => $enrollment,
            'token' => $token,
        ];
    }

    public function consume(string $token, string $fingerprint, ?string $publicKey = null): DeviceCredential
    {
        return DB::transaction(function () use ($token, $fingerprint, $publicKey) {
            $enrollment = DeviceEnrollment::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (!$enrollment) {
                throw new RuntimeException('Invalid enrollment token.');
            }

            if ($enrollment->status !== 'PENDING') {
                throw new RuntimeException('Enrollment token has already been used or revoked.');
            }

            if ($enrollment->expires_at->isPast()) {
                $enrollment->update(['status' => 'EXPIRED']);
                throw new RuntimeException('Enrollment token has expired.');
            }

            $device = $enrollment->device()->lockForUpdate()->firstOrFail();

            if ($device->network_id !== $enrollment->network_id) {
                throw new RuntimeException('Enrollment network mismatch.');
            }

            if (DeviceCredential::query()->where('fingerprint', $fingerprint)->exists()) {
                throw new RuntimeException('Credential fingerprint is already registered.');
            }

            $credential = $device->credentials()->create([
                'type' => 'DEVICE_KEY',
                'fingerprint' => $fingerprint,
                'public_key' => $publicKey,
                'status' => 'ACTIVE',
                'issued_at' => now(),
            ]);

            $device->update([
                'status' => 'ACTIVE',
                'first_seen_at' => $device->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ]);

            $enrollment->update([
                'status' => 'USED',
                'used_at' => now(),
            ]);

            return $credential->fresh();
        });
    }
}
