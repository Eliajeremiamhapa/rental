<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes model queries so callers only ever see records they are authorised
 * to access, based on the authenticated user's role.
 *
 * Models using this trait must define two scopes:
 *   scopeOwnedByOwner(Builder $query, User $user)  - owner see their houses' data
 *   scopeOwnedByTenant(Builder $query, User $user) - tenant sees their own data
 */
trait OwnedByUser
{
    /**
     * Restrict a query to the records the given user is permitted to see.
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        if ($user->isOwner()) {
            return $this->scopeOwnedByOwner($query, $user);
        }

        return $this->scopeOwnedByTenant($query, $user);
    }
}
