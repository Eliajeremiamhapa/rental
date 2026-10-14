<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BalanceService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        protected BalanceService $balanceService,
        protected SmsService $sms,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::query()
            ->ownedBy($request->user())
            ->with(['user', 'rentals' => function ($q) {
                $q->live()->with('room');
            }])
            ->when($request->search, function ($q, $search) {
                $q->where(function ($wq) use ($search) {
                    $wq->where('full_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('id_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('updated_at')
            ->get();

        $tenants->each(function ($tenant) {
            $balance = $this->balanceService->calculateTenantBalance($tenant->id);
            $tenant->current_balance = $balance['total_balance'];
            $tenant->current_room = $tenant->rentals->first()?->room;
        });

        return response()->json([
            'success' => true,
            'message' => 'Tenants retrieved successfully.',
            'data' => $tenants,
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $tenant->load([
            'user',
            'rentals' => function ($q) {
                $q->with(['room.house', 'rentCharges' => function ($cq) {
                    $cq->orderByDesc('period_start');
                }])->orderByDesc('start_date');
            },
            'payments' => function ($q) {
                $q->with('rentCharge.room')->orderByDesc('payment_date')->limit(20);
            },
        ]);

        $balance = $this->balanceService->calculateTenantBalance($tenant->id);
        $tenant->balance = $balance;

        $tenant->current_rental = $tenant->rentals
            ->first(fn ($rental) => in_array($rental->status, Rental::LIVE_STATUSES, true));

        return response()->json([
            'success' => true,
            'message' => 'Tenant retrieved successfully.',
            'data' => $tenant,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => [
                'required',
                'string',
                'unique:tenants,phone',
                Rule::unique('users', 'phone'),
            ],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')],
            'id_number' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string',
            'emergency_contact_phone' => 'nullable|string',
            'notes' => 'nullable|string',
        ], [
            'phone.unique' => 'This phone number is already registered to another account.',
            'email.unique' => 'This email is already registered to another account.',
        ]);

        $owner = $request->user();

        $temporaryPassword = Str::upper(Str::random(3))
            .'-'.random_int(1000, 9999)
            .'-'.Str::upper(Str::random(3));

        [$user, $tenant] = DB::transaction(function () use ($validated, $owner, $temporaryPassword) {
            $user = User::create([
                'name' => $validated['full_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'password' => Hash::make($temporaryPassword),
                'role' => 'tenant',
                'id_number' => $validated['id_number'] ?? null,
                'address' => $validated['address'] ?? null,
                'is_active' => true,
                'must_change_password' => true,
            ]);

            $tenant = Tenant::create([
                ...$validated,
                'user_id' => $user->id,
                'owner_id' => $owner->id,
                'status' => 'active',
            ]);

            return [$user, $tenant];
        });

        $smsSent = $this->sendTempPasswordSms(
            $validated['phone'],
            $validated['full_name'],
            $temporaryPassword,
        );

        $data = $tenant->load('user');
        $data->sms_sent = $smsSent;

        // Only expose the temporary password in the response when SMS is not
        // available (local development). In production it travels via SMS only.
        if (! $smsSent && ! $this->sms->isConfigured()) {
            $data->temporary_password = $temporaryPassword;
        }

        return response()->json([
            'success' => true,
            'message' => $smsSent
                ? 'Tenant created successfully. The temporary password has been sent to the tenant via SMS.'
                : 'Tenant created successfully. Share the temporary password with the tenant.',
            'data' => $data,
        ], 201);
    }

    public function resetPassword(Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $user = $tenant->user
            ?? User::where('phone', $tenant->phone)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'This tenant has no login account yet.',
            ], 422);
        }

        $temporaryPassword = Str::upper(Str::random(3))
            .'-'.random_int(1000, 9999)
            .'-'.Str::upper(Str::random(3));

        $user->update([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
            'is_active' => true,
        ]);

        $tenant->refresh();

        $smsSent = $this->sendTempPasswordSms(
            $user->phone,
            $tenant->full_name ?: $user->name,
            $temporaryPassword,
        );

        $data = $tenant->fresh()->load('user');
        $data->sms_sent = $smsSent;

        if (! $smsSent && ! $this->sms->isConfigured()) {
            $data->temporary_password = $temporaryPassword;
        }

        return response()->json([
            'success' => true,
            'message' => $smsSent
                ? 'Password reset successfully. The temporary password has been sent to the tenant via SMS.'
                : 'Password reset successfully. Share the temporary password with the tenant.',
            'data' => $data,
        ]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => [
                'sometimes',
                'string',
                'unique:tenants,phone,'.$tenant->id,
                Rule::unique('users', 'phone')->ignore($tenant->user_id),
            ],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($tenant->user_id)],
            'id_number' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string',
            'emergency_contact_phone' => 'nullable|string',
            'notes' => 'nullable|string',
        ], [
            'phone.unique' => 'This phone number is already registered to another account.',
            'email.unique' => 'This email is already registered to another account.',
        ]);

        $tenant->update($validated);

        if (isset($validated['full_name']) || isset($validated['phone'])) {
            $tenant->user->update([
                'name' => $validated['full_name'] ?? $tenant->user->name,
                'phone' => $validated['phone'] ?? $tenant->user->phone,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant updated successfully.',
            'data' => $tenant->fresh()->load('user'),
        ]);
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $hasActiveRental = $tenant->rentals()
            ->live()
            ->exists();

        if ($hasActiveRental) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate tenant with active rental. End tenancy first.',
            ], 422);
        }

        $tenant->update(['status' => 'inactive']);
        $tenant->user->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Tenant deactivated successfully.',
        ]);
    }

    public function rentals(Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $rentals = $tenant->rentals()
            ->with(['room.house', 'rentCharges' => function ($q) {
                $q->orderByDesc('period_start');
            }])
            ->orderByDesc('start_date')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Tenant rentals retrieved successfully.',
            'data' => $rentals,
        ]);
    }

    public function payments(Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $payments = $tenant->payments()
            ->with('rentCharge.room')
            ->orderByDesc('payment_date')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Tenant payments retrieved successfully.',
            'data' => $payments,
        ]);
    }

    public function balance(Tenant $tenant): JsonResponse
    {
        $this->ensureOwned($tenant);

        $balance = $this->balanceService->calculateTenantBalance($tenant->id);

        return response()->json([
            'success' => true,
            'message' => 'Tenant balance retrieved successfully.',
            'data' => $balance,
        ]);
    }

    protected function sendTempPasswordSms(?string $phone, string $fullName, string $temporaryPassword): bool
    {
        if (! $phone) {
            return false;
        }

        try {
            $brand = config('services.receipt.company_name', 'Nipangishe');

            $message = "Dear {$fullName}, welcome to {$brand}. Your temporary login password is: {$temporaryPassword}. Sign in with your phone number and change it after your first login.";

            return $this->sms->send($phone, $message);
        } catch (\Throwable $e) {
            Log::error('Failed to send tenant temporary password SMS', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
