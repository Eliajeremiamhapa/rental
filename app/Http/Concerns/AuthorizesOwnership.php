<?php

namespace App\Http\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Provides a guard for route-model-bound records so a caller can never read
 * or mutate a record that does not belong to the authenticated user.
 *
 * Aborts with 404 so the existence of other users' records is not revealed.
 */
trait AuthorizesOwnership
{
    /**
     * Abort (404) unless the bound model belongs to the authenticated user.
     */
    protected function ensureOwned(?Model $model): void
    {
        abort_if(! $model, 404);

        $user = auth()->user();

        $owned = $model::query()
            ->ownedBy($user)
            ->whereKey($model->getKey())
            ->exists();

        abort_unless($owned, 404);
    }
}
