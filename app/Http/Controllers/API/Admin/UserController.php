<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * System administrator user management: browse accounts, suspend/reactivate
 * them, grant or revoke the admin role, and reset a user's password.
 *
 * Owner/tenant role changes are intentionally out of scope — a bad actor is
 * contained by suspension, not by silently rewriting their account type.
 */
class UserController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected NotificationService $notifications,
        protected SmsService $sms,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string',
            'role' => ['nullable', Rule::in(['owner', 'tenant', 'admin'])],
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $users = User::query()
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(function ($wq) use ($search) {
                    $wq->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when(($validated['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'suspended', fn ($q) => $q->where('is_active', false))
            ->withCount('houses')
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully.',
            'data' => $users,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount('houses');
        $user->load(['tenant']);

        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => $user,
        ]);
    }

    public function suspend(User $user): JsonResponse
    {
        if ($reason = $this->cannotManage($user)) {
            return $reason;
        }

        if (! $user->is_active) {
            return $this->error('This account is already suspended.', 422);
        }

        $old = ['is_active' => (bool) $user->is_active];

        $user->update(['is_active' => false]);

        // Kill every live session so the suspension takes effect immediately.
        $user->tokens()->delete();

        $this->audit->log($this->admin(), 'user_suspended', $user, $old, ['is_active' => false]);

        $this->notifications->create(
            $user->id,
            'system',
            'Account Suspended',
            'Your account has been suspended. Please contact support for more information.',
        );

        return response()->json([
            'success' => true,
            'message' => 'User account suspended.',
            'data' => $user->fresh(),
        ]);
    }

    public function activate(User $user): JsonResponse
    {
        if ($user->is_active) {
            return $this->error('This account is already active.', 422);
        }

        $old = ['is_active' => (bool) $user->is_active];

        $user->update(['is_active' => true]);

        $this->audit->log($this->admin(), 'user_activated', $user, $old, ['is_active' => true]);

        $this->notifications->create(
            $user->id,
            'system',
            'Account Reactivated',
            'Your account has been reactivated. You can sign in again.',
        );

        return response()->json([
            'success' => true,
            'message' => 'User account activated.',
            'data' => $user->fresh(),
        ]);
    }

    public function grantAdmin(User $user): JsonResponse
    {
        if ($user->isAdmin()) {
            return $this->error('This user is already an administrator.', 422);
        }

        $old = ['role' => $user->role, 'previous_role' => $user->previous_role];

        $user->update([
            'previous_role' => $user->role,
            'role' => 'admin',
        ]);

        $this->audit->log($this->admin(), 'admin_granted', $user, $old, ['role' => 'admin']);

        return response()->json([
            'success' => true,
            'message' => 'Administrator role granted.',
            'data' => $user->fresh(),
        ]);
    }

    public function revokeAdmin(User $user): JsonResponse
    {
        if ($reason = $this->cannotManage($user)) {
            return $reason;
        }

        if (! $user->isAdmin()) {
            return $this->error('This user is not an administrator.', 422);
        }

        $restored = in_array($user->previous_role, ['owner', 'tenant'], true)
            ? $user->previous_role
            : 'tenant';

        $old = ['role' => $user->role, 'previous_role' => $user->previous_role];

        $user->update([
            'role' => $restored,
            'previous_role' => null,
        ]);

        $this->audit->log($this->admin(), 'admin_revoked', $user, $old, ['role' => $restored]);

        return response()->json([
            'success' => true,
            'message' => 'Administrator role revoked.',
            'data' => $user->fresh(),
        ]);
    }

    public function resetPassword(User $user): JsonResponse
    {
        if ($this->admin()->id === $user->id) {
            return $this->error('Use your profile settings to change your own password.', 422);
        }

        $temporaryPassword = Str::upper(Str::random(3))
            .'-'.random_int(1000, 9999)
            .'-'.Str::upper(Str::random(3));

        // Deliberately do not touch is_active: reactivation is a separate,
        // explicit decision and must not ride along with a password reset.
        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ]);

        $user->tokens()->delete();

        $this->audit->log($this->admin(), 'user_password_reset', $user);

        $smsSent = $this->sendTempPasswordSms($user, $temporaryPassword);

        $data = $user->fresh();
        $data->sms_sent = $smsSent;

        if (! $smsSent && ! $this->sms->isConfigured()) {
            $data->temporary_password = $temporaryPassword;
        }

        return response()->json([
            'success' => true,
            'message' => $smsSent
                ? 'Password reset. The temporary password has been sent via SMS.'
                : 'Password reset. Share the temporary password with the user.',
            'data' => $data,
        ]);
    }

    /**
     * Guard destructive account actions: an admin must not lock themselves out
     * and the platform must always retain at least one active administrator.
     */
    protected function cannotManage(User $user): ?JsonResponse
    {
        if ($this->admin()->id === $user->id) {
            return $this->error('You cannot perform this action on your own account.', 422);
        }

        if ($user->isAdmin() && $this->activeAdminCount() <= 1) {
            return $this->error('You cannot act on the last remaining administrator.', 422);
        }

        return null;
    }

    protected function activeAdminCount(): int
    {
        return User::where('role', 'admin')->where('is_active', true)->count();
    }

    protected function admin(): User
    {
        return request()->user();
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    protected function sendTempPasswordSms(User $user, string $temporaryPassword): bool
    {
        if (! $user->phone) {
            return false;
        }

        try {
            $brand = config('services.receipt.company_name', 'Nipangishe');

            $message = "Dear {$user->name}, your {$brand} password has been reset. Your temporary login password is: {$temporaryPassword}. Sign in with your phone number and change it after your next login.";

            return $this->sms->send($user->phone, $message);
        } catch (\Throwable $e) {
            Log::error('Failed to send admin password reset SMS', [
                'phone' => $user->phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
