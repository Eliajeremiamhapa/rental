<?php

namespace App\Http\Concerns;

use App\Models\OwnerSetting;

/**
 * Exposes company branding to anything that renders a document.
 *
 * Receipts and tenancy contracts must both look like the same company, so they
 * read the same settings and resolve them the same way instead of each keeping
 * its own copy of the path handling. When a landlord has configured their own
 * details, those win; otherwise the platform brand is used.
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
        $hasOwnerBranding = (bool) $settings?->company_name;

        return [
            'company_name' => $hasOwnerBranding
                ? $settings->company_name
                : config('services.receipt.company_name', 'Nipangishe'),
            // The platform tagline only belongs on platform-branded documents;
            // a landlord's own letterhead should not carry it.
            'company_slogan' => $hasOwnerBranding
                ? null
                : config('services.receipt.company_slogan'),
            'company_phone' => $settings?->contact_phone
                ?: config('services.receipt.company_phone'),
            'company_address' => $settings?->address
                ?: config('services.receipt.company_address'),
            'logo_url' => $this->assetUrl(config('services.receipt.logo_path')),
            'signature_url' => $this->assetUrl(config('services.receipt.signature_path')),
        ];
    }

    /**
     * Resolve a configured logo/signature reference to a browser-reachable URL.
     *
     * Files inside public/ are exposed as static URLs (nginx serves public/branding
     * directly). Absolute paths are not reachable by the browser, so they are only
     * usable for the downloaded HTML receipt, not for the on-screen preview.
     */
    protected function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        // An absolute filesystem path exists but is not reachable by a browser.
        // It still works for the downloaded HTML receipt, which embeds the file
        // inline, but there is no URL to hand to the browser for the preview.
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return null;
        }

        // A path relative to public/, e.g. "branding/logo.png".
        $relative = ltrim((string) preg_replace('#^public/#', '', $path), '/');

        // Root-relative rather than asset(), so the URL works on any host, port
        // or domain the app happens to be served from (localhost during
        // development, the real domain in production).
        return is_file(public_path($relative)) ? '/'.$relative : null;
    }
}
