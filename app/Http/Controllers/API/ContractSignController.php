<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\ResolvesBrandingAssets;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\OwnerSetting;
use App\Models\User;
use App\Services\ContractContentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public tenant-facing signing endpoints.
 *
 * These are reachable without a login: a tenant is handed a single link and
 * should be able to read and sign without creating or remembering an account.
 * The token is the only credential, so it is treated as a bearer secret:
 * high entropy, rate limited, single use, and cleared the moment it is spent.
 */
class ContractSignController extends Controller
{
    use ResolvesBrandingAssets;

    /** Signature PNGs are posted as data URIs; 1 MB of base64 is generous. */
    private const MAX_SIGNATURE_BYTES = 1_048_576;

    /**
     * Bump this whenever the consent sentence below changes, so a stored
     * record can always be traced back to the exact wording accepted.
     */
    public const CONSENT_VERSION = '1.0';

    public function __construct(private readonly ContractContentBuilder $builder) {}

    /**
     * Read the contract. First view flips the status so the owner can see that
     * the tenant has actually opened it.
     */
    public function show(Request $request, string $token): JsonResponse
    {
        $contract = $this->resolve($token);

        if ($contract->isSigned()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'state' => 'signed',
                    'contract' => $this->signedPayload($contract),
                ],
            ]);
        }

        if ($contract->status === 'declined' || $contract->status === 'void') {
            return response()->json([
                'success' => true,
                'data' => [
                    'state' => $contract->status,
                    'message' => $contract->status === 'declined'
                        ? 'Mpangaji amekataa mkatataba huu.'
                        : 'Mkatataba huu umesitishwa na mwenyewe.',
                ],
            ]);
        }

        if (! $contract->hasValidToken()) {
            return response()->json([
                'success' => false,
                'message' => 'Kiungo hiki cha saini kimekwisha muda wake au hapatikani.',
            ], 410);
        }

        if ($contract->status === 'pending_signature') {
            $contract->update([
                'status' => 'viewed',
                'viewed_at' => now(),
                'view_count' => $contract->view_count + 1,
            ]);

            $this->recordEvent($contract, 'viewed', 'tenant', $contract->tenant?->full_name, $request);
        }

        $snapshot = $this->snapshotFor($contract);

        return response()->json([
            'success' => true,
            'data' => [
                'state' => 'awaiting_signature',
                'contract' => [
                    'contract_number' => $contract->contract_number,
                    'tenant_name' => $contract->tenant?->full_name,
                    'tenant_phone' => $contract->tenant?->phone,
                    'company_name' => $snapshot['company_name'] ?? config('services.receipt.company_name'),
                ],
                'snapshot' => $snapshot,
                // The tenant signs against this value. If the server later
                // renders something different, the signature is rejected.
                'fingerprint' => $this->builder->fingerprint($snapshot),
                // The exact sentence the tenant is about to accept.
                'consent_statement' => $this->consentStatement($contract, (string) $contract->tenant?->full_name),
                'consent_version' => self::CONSENT_VERSION,
                'branding' => $this->brandingPayload(),
            ],
        ]);
    }

    /**
     * Record consent and signature. The document is frozen here: the rendered
     * snapshot is stored, hashed, and the token destroyed so this contract can
     * never be signed a second time.
     */
    public function sign(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'signer_name' => 'required|string|max:120',
            'signature_image' => ['required', 'string', 'max:'.(self::MAX_SIGNATURE_BYTES * 4 / 3)],
            'fingerprint' => 'required|string|size:64',
            'consent' => 'required|accepted',
        ]);

        $contract = $this->resolve($token);

        if (! $contract->hasValidToken()) {
            return response()->json([
                'success' => false,
                'message' => 'Kiungo hiki cha saini hapatikani au kimekwisha muda wake.',
            ], 410);
        }

        if (! $this->isValidPng($validated['signature_image'])) {
            return response()->json([
                'success' => false,
                'message' => 'Saini imekosewa. Tafadhali tafuta upya.',
            ], 422);
        }

        // The signer must be who the contract says it is. Someone who opens a
        // leaked link on another tenant's phone must not be able to sign.
        $expected = trim((string) $contract->tenant?->full_name);
        if (mb_strtolower(trim($validated['signer_name'])) !== mb_strtolower($expected)) {
            return response()->json([
                'success' => false,
                'message' => 'Jina la msaini lazima liwe sawa na jina lililoandikwa kwenye mkatataba.',
            ], 422);
        }

        $snapshot = $this->snapshotFor($contract);

        if (! hash_equals($this->builder->fingerprint($snapshot), $validated['fingerprint'])) {
            $this->recordEvent($contract, 'signature_rejected', 'tenant', $validated['signer_name'], $request, [
                'reason' => 'fingerprint_mismatch',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Mkatataba umebadilika tangu ulipofunguliwa. Tafadhali fungua upya.',
            ], 409);
        }

        $contract = DB::transaction(function () use ($contract, $validated, $snapshot, $request) {
            $contract->update([
                'status' => 'signed',
                'content_snapshot' => $snapshot,
                'document_sha256' => $this->builder->fingerprint($snapshot),
                'signature_image' => $validated['signature_image'],
                'signer_name' => $validated['signer_name'],
                'signer_phone' => $contract->tenant?->phone,
                'signer_ip' => $request->ip(),
                'signer_user_agent' => substr((string) $request->userAgent(), 0, 512),
                'signer_consented_at' => now(),
                // Rendered server-side, never taken from the request, so the
                // record shows exactly what the tenant accepted.
                'signer_consent_text' => $this->consentStatement($contract, $validated['signer_name']),
                'signer_consent_version' => self::CONSENT_VERSION,
                'signed_at' => now(),
                'signature_token' => null,
            ]);

            $this->recordEvent($contract, 'signed', 'tenant', $validated['signer_name'], $request, [
                'sha256' => $contract->document_sha256,
            ]);

            return $contract;
        });

        return response()->json([
            'success' => true,
            'message' => 'Asante. Mkatataba umesainiwa.',
            'data' => [
                'state' => 'signed',
                'contract' => $this->signedPayload($contract),
            ],
        ]);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $contract = $this->resolve($token);

        if (! $contract->hasValidToken()) {
            return response()->json([
                'success' => false,
                'message' => 'Kiungo hiki cha saini hapatikani au kimekwisha muda wake.',
            ], 410);
        }

        $contract->update([
            'status' => 'declined',
            'declined_reason' => $validated['reason'] ?? null,
            'declined_at' => now(),
            'signature_token' => null,
        ]);

        $this->recordEvent($contract, 'declined', 'tenant', $contract->tenant?->full_name, $request, [
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Umerekodiwa kwamba hukukubali mkatataba huu.',
            'data' => ['state' => 'declined'],
        ]);
    }

    /**
     * The exact sentence the tenant accepts. Rendered server-side so the stored
     * text can never be supplied or altered by the client, and versioned so a
     * later rewording is distinguishable from what was actually agreed.
     */
    private function consentStatement(Contract $contract, string $signerName): string
    {
        return sprintf(
            'Mimi %s, nathibitisha kwamba nimeisoma mkatataba huu wote (%s), '
            .'nimeelewa masharti yake, na ninakubali kuisaini kwa hiari yangu mwenyewe bila shuruti yoyote.',
            $signerName,
            $contract->contract_number,
        );
    }

    /**
     * The signed copy the tenant keeps. Returns the frozen snapshot plus proof
     * of when and from where it was signed.
     */
    private function signedPayload(Contract $contract): array
    {
        $snapshot = is_array($contract->content_snapshot)
            ? $contract->content_snapshot
            : $this->snapshotFor($contract);

        return [
            'contract_number' => $contract->contract_number,
            'snapshot' => $snapshot,
            'signature_image' => $contract->signature_image,
            'signer_name' => $contract->signer_name,
            'consent_text' => $contract->signer_consent_text,
            'consent_version' => $contract->signer_consent_version,
            'consented_at' => $contract->signer_consented_at?->toIso8601String(),
            'signed_at' => $contract->signed_at?->toIso8601String(),
            'document_sha256' => $contract->document_sha256,
            'branding' => $this->brandingPayload(),
            'verification_note' => 'Mkatataba huu umesainiwa kwa umuhimu wa elektroniki. '
                .'Uthibitisho: SHA-256 '.substr((string) $contract->document_sha256, 0, 16).'.',
        ];
    }

    /**
     * Render from stored draft data. Once signed, the stored snapshot is used
     * verbatim so the document can never drift.
     */
    private function snapshotFor(Contract $contract): array
    {
        if (is_array($contract->content_snapshot)) {
            return $contract->content_snapshot;
        }

        $settings = OwnerSetting::forOwner(User::findOrFail($contract->owner_id));

        return $this->builder->build($contract, [
            'rent_amount' => (float) $contract->rent_amount,
            'period_months' => (int) $contract->period_months,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'due_day' => (int) $contract->due_day,
            'notice_months' => $contract->notice_months,
            'notice_per_day' => (float) $contract->notice_per_day,
            'service_fee' => (float) $contract->service_fee,
            'payment_commitment_months' => $contract->payment_commitment_months,
            'short_commitment_months' => $contract->short_commitment_months,
            'deposit_amount' => (float) $contract->deposit_amount,
            'currency' => $contract->currency,
            'company_name' => $settings->company_name,
            'contact_phone' => $settings->contact_phone,
            'payment_accounts' => $settings->payment_accounts ?? [],
        ]);
    }

    private function resolve(string $token): Contract
    {
        $contract = Contract::with(['tenant', 'house', 'room'])
            ->where('signature_token', $token)
            ->first();

        if (! $contract) {
            abort(response()->json([
                'success' => false,
                'message' => 'Kiungo hiki cha saini hapatikani.',
            ], 404));
        }

        return $contract;
    }

    /**
     * Only accept an inline PNG. Anything executable or oversized is rejected
     * before it is stored alongside the contract.
     */
    private function isValidPng(string $dataUri): bool
    {
        if (! str_starts_with($dataUri, 'data:image/png;base64,')) {
            return false;
        }

        $payload = substr($dataUri, strlen('data:image/png;base64,'));
        if ($payload === '' || strlen($payload) % 4 !== 0) {
            return false;
        }

        $binary = base64_decode($payload, true);
        if ($binary === false || strlen($binary) > self::MAX_SIGNATURE_BYTES) {
            return false;
        }

        return str_starts_with($binary, "\x89PNG\r\n\x1a\n");
    }

    private function recordEvent(Contract $contract, string $type, ?string $actorType, ?string $actorName, Request $request, array $meta = []): void
    {
        ContractEvent::create([
            'contract_id' => $contract->id,
            'event_type' => $type,
            'actor_type' => $actorType,
            'actor_name' => $actorName,
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'meta' => $meta ?: null,
        ]);
    }
}
