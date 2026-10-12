<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected SmsService $sms,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been deactivated.',
            ], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => $user->load('tenant'),
                'token' => $token,
            ],
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'role' => ['required', Rule::in(['tenant', 'owner'])],
            'phone' => ['required', 'string', Rule::unique('users', 'phone')],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8|confirmed',
            'id_number' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:255',
        ], [
            'phone.unique' => 'This phone number is already registered.',
            'email.unique' => 'This email is already registered to another account.',
        ]);

        [$user, $tenantId] = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'role' => $validated['role'],
                'id_number' => $validated['id_number'] ?? null,
                'address' => $validated['address'] ?? null,
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $tenantId = null;

            // A tenant who signs up exists independently of any owner. The owner
            // relationship is established later, when an owner accepts their
            // first rental request ("one owner at a time").
            if ($validated['role'] === 'tenant') {
                Tenant::create([
                    'user_id' => $user->id,
                    'owner_id' => null,
                    'full_name' => $validated['name'],
                    'phone' => $validated['phone'],
                    'email' => $validated['email'] ?? null,
                    'id_number' => $validated['id_number'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'status' => 'active',
                ]);
                $tenantId = $user->tenant->id;
            }

            return [$user, $tenantId];
        });

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Account created successfully. Welcome!',
            'data' => [
                'user' => $user->fresh()->load('tenant'),
                'token' => $token,
            ],
        ], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user) {
            return response()->json([
                'success' => true,
                'message' => 'If an account with that phone exists, a reset code has been sent.',
            ]);
        }

        $token = Str::random(6);
        $expiresAt = Carbon::now()->addMinutes(15);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['phone' => $user->phone],
            [
                'token' => Hash::make($token),
                'created_at' => Carbon::now(),
            ]
        );

        DB::table('password_reset_tokens')->where('phone', $user->phone)->update([
            'expires_at' => $expiresAt,
            'created_at' => Carbon::now(),
        ]);

        $message = "Your Nipangishe password reset code is: {$token}. It expires in 15 minutes. If you did not request this, please ignore this message.";

        $delivered = $this->sms->send($user->phone, $message);

        $data = ['expires_at' => $expiresAt->toDateTimeString()];

        // Only ever expose the code outside production (local development), so a
        // misconfigured SMS provider can never leak a live reset token to the
        // public API response.
        if (! $delivered && ! app()->isProduction()) {
            $data['reset_token_dev'] = $token;
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account with that phone exists, a reset code has been sent.',
            'data' => $data,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|size:6',
            'phone' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token.',
            ], 422);
        }

        $record = DB::table('password_reset_tokens')
            ->where('phone', $validated['phone'])
            ->first();

        if (! $record || ! Hash::check($validated['token'], $record->token)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid reset token.',
            ], 422);
        }

        if (isset($record->expires_at) && Carbon::parse($record->expires_at)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'This reset token has expired. Please request a new one.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        DB::table('password_reset_tokens')->where('phone', $validated['phone'])->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password has been reset successfully.',
        ]);
    }
}
