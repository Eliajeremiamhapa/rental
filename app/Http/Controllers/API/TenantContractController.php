<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\ResolvesBrandingAssets;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\OwnerSetting;
use App\Models\User;
use App\Services\ContractContentBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-facing view of their own contracts.
 *
 * This is what backs "My Contracts" on the tenant dashboard. It is a read-only
 * window: the tenant can open the letter and follow it to the signing page, but
 * nothing about the agreement itself is editable from this side. Signing itself
 * still happens through the single-use token endpoint so there is exactly one
 * signing path and one consent record.
 */
class TenantContractController extends Controller
{
    use ResolvesBrandingAssets;

    public function __construct(private readonly ContractContentBuilder $builder) {}

    public function index(Request $request): JsonResponse
    {
        $contracts = Contract::ownedByTenant($request->user())
            ->with(['house', 'room'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $c) => $this->summarise($c))
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Contracts retrieved successfully.',
            'data' => $contracts,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $contract = Contract::ownedByTenant($request->user())
            ->with(['house', 'room', 'events'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'Contract retrieved successfully.',
            'data' => array_merge($this->summarise($contract), [
                'description' => $contract->description,
                'snapshot' => $this->snapshotFor($contract),
                'branding' => $this->brandingPayload(),
                'signature_image' => $contract->signature_image,
                'document_sha256' => $contract->document_sha256,
                // Proof of what was accepted, shown next to the signed copy.
                'signer_name' => $contract->signer_name,
                'consent_text' => $contract->signer_consent_text,
                'consented_at' => $contract->signer_consented_at?->toIso8601String(),
                'verification_note' => $contract->signed_at
                    ? 'Mkatataba huu umesainiwa kwa umuhimu wa elektroniki. Uthibitisho: SHA-256 '
                        .substr((string) $contract->document_sha256, 0, 16).'.'
                    : null,
                'events' => $contract->events->map(fn ($e) => [
                    'type' => $e->event_type,
                    'at' => $e->created_at?->toIso8601String(),
                ])->values(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'status' => $contract->status,
            'house' => $contract->house?->name,
            'room' => $contract->room?->name,
            'rent_amount' => (float) $contract->rent_amount,
            'total_amount' => (float) $contract->total_amount,
            'daily_rate' => (float) $contract->daily_rate,
            'period_months' => (int) $contract->period_months,
            'currency' => $contract->currency,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'sent_at' => $contract->sent_at?->toIso8601String(),
            'signed_at' => $contract->signed_at?->toIso8601String(),
            'declined_reason' => $contract->declined_reason,
            'voided_reason' => $contract->voided_reason,
            'token_expires_at' => $contract->token_expires_at?->toIso8601String(),
            // Only surfaced while the link is actually usable. Handing over a
            // spent token would be pointless, and the tenant never needs it
            // for any other purpose.
            'signing_url' => $contract->hasValidToken()
                ? url("/contracts/sign/{$contract->signature_token}")
                : null,
        ];
    }

    /**
     * Render the letter. Once signed, the stored snapshot is used verbatim so
     * the document can never drift from what was actually agreed.
     *
     * @return array<string, mixed>
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
}
