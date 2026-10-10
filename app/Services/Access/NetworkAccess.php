<?php

namespace App\Services\Access;

use App\Models\Network;
use App\Models\NetworkMember;
use App\Models\User;

class NetworkAccess
{
    /**
     * Resolve a network only when the user owns it or has an active membership
     * whose role grants the requested permission. Missing access deliberately
     * returns 404 to avoid disclosing another network's existence.
     */
    public function require(User $user, int $networkId, string $permission): Network
    {
        $ownedNetwork = Network::query()
            ->where('owner_id', $user->id)
            ->find($networkId);

        if ($ownedNetwork) {
            return $ownedNetwork;
        }

        $hasPermission = NetworkMember::query()
            ->where('network_id', $networkId)
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->whereHas('role.permissions', fn ($query) => $query->where('permissions.key', $permission))
            ->exists();

        abort_unless($hasPermission, 404);

        return Network::query()->findOrFail($networkId);
    }
}
