<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\ResolvesBrandingAssets;
use App\Http\Controllers\Controller;
use App\Models\OwnerSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

class BrandingController extends Controller
{
    use ResolvesBrandingAssets;

    /**
     * Public browser branding. With no `owner_id` this is always the platform's
     * own identity (the system owner's brand), so a logged-in landlord or tenant
     * never leaks their brand onto the public site. With `owner_id` it is that
     * landlord's public brand (logo + company name, never the signature).
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->filled('owner_id')) {
            $owner = User::query()
                ->where('role', 'owner')
                ->find($request->integer('owner_id'));

            return response()->json([
                'success' => true,
                'data' => $this->publicBrandingPayload($owner ? OwnerSetting::forOwner($owner) : null),
            ]);
        }

        return response()->json(['success' => true, 'data' => $this->platformBrandingPayload()]);
    }

    /**
     * The signed-in user's own brand: a landlord sees their company branding,
     * a tenant sees their landlord's. Used by the authenticated app shell.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $settings = null;
        if ($user->role === 'owner') {
            $settings = OwnerSetting::forOwner($user);
        } elseif ($user->role === 'tenant') {
            $tenant = $user->tenant()->with('owner.settings')->first();
            $settings = $tenant?->owner?->settings;
        }

        return response()->json(['success' => true, 'data' => $this->brandingPayload($settings)]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        $request->validate(['logo' => ['required', File::types(['png', 'jpg', 'jpeg', 'webp', 'svg'])->max(2048)]]);
        $settings = OwnerSetting::forOwner($user);
        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
        }
        $path = $request->file('logo')->store('branding/owners/'.$user->id, 'public');
        $settings->update(['logo_path' => $path]);

        return response()->json(['success' => true, 'message' => 'Logo imesasishwa.', 'data' => $this->brandingPayload($settings->fresh())]);
    }

    public function uploadSignature(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        $request->validate(['signature' => ['required', File::types(['png', 'jpg', 'jpeg', 'webp', 'svg'])->max(2048)]]);
        $settings = OwnerSetting::forOwner($user);
        if ($settings->signature_path) {
            Storage::disk('public')->delete($settings->signature_path);
        }
        $path = $request->file('signature')->store('branding/owners/'.$user->id, 'public');
        $settings->update(['signature_path' => $path]);

        return response()->json(['success' => true, 'message' => 'Saini imesasishwa.', 'data' => $this->brandingPayload($settings->fresh())]);
    }

    public function destroyLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        $settings = OwnerSetting::forOwner($user);
        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
            $settings->update(['logo_path' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Logo imeondolewa.', 'data' => $this->brandingPayload($settings->fresh())]);
    }

    public function destroySignature(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || $user->role !== 'owner') {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }
        $settings = OwnerSetting::forOwner($user);
        if ($settings->signature_path) {
            Storage::disk('public')->delete($settings->signature_path);
            $settings->update(['signature_path' => null]);
        }

        return response()->json(['success' => true, 'message' => 'Saini imeondolewa.', 'data' => $this->brandingPayload($settings->fresh())]);
    }
}
