<?php

namespace App\Http\Concerns;

use App\Models\OwnerSetting;

/**
 * Exposes company branding to anything that renders a document.
 */
trait ResolvesBrandingAssets
{
    /**
     * The branding block attached to document payloads.
     *
     * @return array<string, mixed>
     */
    protected function brandingPayload(?OwnerSetting $settings = null): array
    {
        $hasOwnerBranding = (bool) ($settings?->company_name ?: $settings?->logo_path);
        $ownerLogoUrl = $this->assetUrl($settings?->logo_path);
        $ownerSignatureUrl = $this->assetUrl($settings?->signature_path);

        return [
            'company_name' => $hasOwnerBranding && $settings?->company_name
                ? $settings->company_name
                : config('services.receipt.company_name', 'Nipangishe'),
            'company_slogan' => $hasOwnerBranding
                ? null
                : config('services.receipt.company_slogan'),
            'company_phone' => $settings?->contact_phone
                ?: config('services.receipt.company_phone'),
            'company_address' => $settings?->address
                ?: config('services.receipt.company_address'),
            'logo_url' => $ownerLogoUrl ?: $this->assetUrl(config('services.receipt.logo_path')),
            // A compact mark for bars/headers. An owner's uploaded logo doubles
            // as its mark; when there is none we return null so the client can
            // fall back to its bundled symbol instead of the wide lockup.
            'logo_mark_url' => $ownerLogoUrl,
            'signature_url' => $ownerSignatureUrl ?: $this->assetUrl(config('services.receipt.signature_path')),
            'has_logo' => (bool) $settings?->logo_path,
            'has_signature' => (bool) $settings?->signature_path,
        ];
    }

    /**
     * The platform's own identity (the system owner's brand). This is what the
     * public site, auth screens and unscoped pages must always show, regardless
     * of who is logged in. It never borrows a landlord's logo.
     *
     * @return array<string, mixed>
     */
    protected function platformBrandingPayload(): array
    {
        $logoUrl = $this->assetUrl(config('services.receipt.logo_path'));
        $signatureUrl = $this->assetUrl(config('services.receipt.signature_path'));

        return [
            'company_name' => config('services.receipt.company_name', 'Nipangishe'),
            'company_slogan' => config('services.receipt.company_slogan'),
            'company_phone' => config('services.receipt.company_phone'),
            'company_address' => config('services.receipt.company_address'),
            'logo_url' => $logoUrl,
            // The platform's own mark is bundled with the client, so there is
            // no server-side mark URL to hand back.
            'logo_mark_url' => null,
            'signature_url' => $signatureUrl,
            'has_logo' => (bool) $logoUrl,
            'has_signature' => (bool) $signatureUrl,
        ];
    }

    /**
     * Logo-only branding for anonymous/public lookups: a visitor viewing a
     * specific owner's listings must never receive the owner's signature.
     *
     * @return array<string, mixed>
     */
    protected function publicBrandingPayload(?OwnerSetting $settings = null): array
    {
        $payload = $this->brandingPayload($settings);
        unset($payload['signature_url'], $payload['has_signature']);

        return $payload;
    }

    /**
     * Resolve a configured logo/signature reference to a browser-reachable URL.
     */
    protected function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return null;
        }

        $relative = ltrim((string) preg_replace('#^public/#', '', $path), '/');
        $publicPath = public_path($relative);
        if (is_file($publicPath)) {
            return '/'.$relative;
        }

        $storageRelative = ltrim((string) preg_replace('#^storage/#', '', $path), '/');
        if ($storageRelative !== $relative && is_file(public_path('storage/'.$storageRelative))) {
            return '/storage/'.$storageRelative;
        }

        if (is_file(storage_path('app/public/'.$relative))) {
            return '/storage/'.$relative;
        }

        return null;
    }
}
