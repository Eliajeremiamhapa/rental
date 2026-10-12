<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\ResolvesBrandingAssets;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\OwnerSetting;
use App\Models\Rental;
use App\Models\User;
use App\Services\ContractContentBuilder;
use App\Services\ContractTemplate;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractController extends Controller
{
    use ResolvesBrandingAssets;

    public function __construct(
        private readonly ContractContentBuilder $builder,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Owner settings feed clause 12 (contact number) and clause 13 (payment
     * accounts), so these live outside the contract to stop the same numbers
     * being retyped on every letter.
     */
    public function settings(Request $request): JsonResponse
    {
        $settings = OwnerSetting::forOwner($request->user());

        return response()->json([
            'success' => true,
            'data' => $this->settingsPayload($settings),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'contact_email' => 'nullable|email|max:190',
            'address' => 'nullable|string|max:255',
            'currency' => 'nullable|string|size:3',
            'payment_accounts' => 'nullable|array|max:12',
            'payment_accounts.*.network' => 'nullable|string|max:40',
            'payment_accounts.*.number' => 'nullable|string|max:30',
            'payment_accounts.*.account_name' => 'nullable|string|max:120',
            'lipa_namba' => 'nullable|string|max:30',
            'lipa_namba_name' => 'nullable|string|max:120',
        ]);

        $settings = OwnerSetting::forOwner($request->user());
        $settings->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Mipangilio imesasishwa.',
            'data' => $this->settingsPayload($settings->fresh()),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Contract::ownedByOwner($request->user())
            ->with(['tenant', 'room', 'house'])
            ->orderByDesc('id');

        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->tenant_id) {
            $query->where('tenant_id', $request->tenant_id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(fn (Contract $c) => $this->summary($c)),
        ]);
    }

    /**
     * Everything the builder screen needs for one tenancy: the derived figures
     * and the standard clause text it will offer for editing.
     */
    public function prefill(Request $request, Rental $rental): JsonResponse
    {
        $rental = Rental::ownedByOwner($request->user())
            ->with(['tenant', 'house', 'room'])
            ->findOrFail($rental->id);

        $settings = OwnerSetting::forOwner($rental->house->user_id);

        $input = array_merge($this->builder->prefillFromRental($rental), [
            'company_name' => $settings->company_name,
            'contact_phone' => $settings->contact_phone,
            'currency' => $settings->currency ?: config('app.currency', 'TZS'),
            'payment_accounts' => $settings->payment_accounts ?? [],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'prefill' => $input,
                'template' => [
                    'version' => ContractTemplate::VERSION,
                    'clauses' => ContractTemplate::clauses(),
                ],
                'validation' => $this->builder->validate($input),
            ],
        ]);
    }

    /**
     * Live preview of the rendered letter. Never persisted, so the owner can
     * iterate freely before committing to an issued document.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $this->withOwnerDefaults(
            $this->validateInput($request),
            $request->user(),
        );

        $contract = new Contract([
            'description' => $validated['description'] ?? null,
            'clause_overrides' => $validated['clause_overrides'] ?? [],
            'template_version' => ContractTemplate::VERSION,
        ]);

        $validation = $this->builder->validate($validated);
        if ($validation['errors'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba haujaweza kutengenezwa.',
                'errors' => $validation['errors'],
            ], 422);
        }

        $snapshot = $this->builder->build($contract, $validated);

        return response()->json([
            'success' => true,
            'data' => [
                'snapshot' => $snapshot,
                'fingerprint' => $this->builder->fingerprint($snapshot),
                'branding' => $this->brandingPayload(OwnerSetting::forOwner($request->user())),
                'warnings' => $validation['warnings'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->withOwnerDefaults(
            $this->validateInput($request),
            $request->user(),
        );

        $rental = Rental::ownedByOwner($request->user())->findOrFail($validated['rental_id']);

        // Block issuing a second live contract for the same tenancy: the tenant
        // must never be able to sign two different agreements for one room.
        $clash = Contract::where('rental_id', $rental->id)
            ->whereIn('status', ['draft', 'pending_signature', 'viewed', 'signed'])
            ->exists();
        if ($clash) {
            return response()->json([
                'success' => false,
                'message' => 'Kuna mkatataba wa huu unaoendelea kwa mpangaji huyu. Malisha au futa kilichopo kwanza.',
            ], 422);
        }

        $validation = $this->builder->validate($validated);
        if ($validation['errors'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba haujaweza kutengenezwa.',
                'errors' => $validation['errors'],
            ], 422);
        }

        $contract = DB::transaction(function () use ($validated, $rental, $request, $validation) {
            $contract = Contract::create([
                'contract_number' => (new Contract)->generateContractNumber(),
                'rental_id' => $rental->id,
                'tenant_id' => $rental->tenant_id,
                'house_id' => $rental->house_id,
                'room_id' => $rental->room_id,
                'owner_id' => $request->user()->id,
                'status' => 'draft',
                'template_version' => ContractTemplate::VERSION,
                'description' => $validated['description'] ?? null,
                'clause_overrides' => $validated['clause_overrides'] ?? [],
                'clause_text' => ContractTemplate::standardText(),
                'extra_clauses' => $validated['extra_clauses'] ?? [],
                'rent_amount' => $validated['rent_amount'],
                'total_amount' => $this->builder->totalFor($validated['rent_amount'], $validated['period_months']),
                'daily_rate' => $this->builder->dailyRate($validated['rent_amount']),
                'period_months' => $validated['period_months'],
                'notice_months' => $validated['notice_months'] ?? null,
                'notice_per_day' => $this->builder->dailyRate((float) $validated['rent_amount']),
                'service_fee' => $validated['service_fee'] ?? 0,
                'due_day' => $validated['due_day'] ?? 25,
                'payment_commitment_months' => $validated['payment_commitment_months'] ?? null,
                'short_commitment_months' => $validated['short_commitment_months'] ?? null,
                'currency' => $validated['currency'] ?? 'TZS',
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'deposit_amount' => $validated['deposit_amount'] ?? 0,
            ]);

            $this->recordEvent($contract, 'created', 'owner', $request->user()->name, $request, [
                'warnings' => $validation['warnings'],
            ]);

            return $contract;
        });

        $contract->load(['tenant', 'room', 'house']);

        return response()->json([
            'success' => true,
            'message' => 'Mkatataba umetengenezwa.',
            'data' => $this->detail($contract),
            'warnings' => $validation['warnings'],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $contract = Contract::ownedByOwner($request->user())
            ->with(['tenant', 'room', 'house', 'events'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->detail($contract),
        ]);
    }

    /**
     * Drafts are editable; anything that has been sent, signed, declined or
     * voided is not. The model enforces this too, this is the friendly error.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $contract = Contract::ownedByOwner($request->user())->findOrFail($id);

        if ($contract->isFinal()) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba huu umesitishwa au umekamilika na hauwezi kubadilishwa.',
            ], 422);
        }

        $validated = $this->validateInput($request, partial: true, contract: $contract);

        $validation = $this->builder->validate(array_merge($this->currentInput($contract), $validated));
        if ($validation['errors'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba haujaweza kuhifadhiwa.',
                'errors' => $validation['errors'],
            ], 422);
        }

        $months = (int) ($validated['period_months'] ?? $contract->period_months);
        $rent = (float) ($validated['rent_amount'] ?? $contract->rent_amount);

        $contract->fill(array_filter([
            'description' => $validated['description'] ?? null,
            'clause_overrides' => $validated['clause_overrides'] ?? $contract->clause_overrides,
            'extra_clauses' => $validated['extra_clauses'] ?? null,
            'rent_amount' => $validated['rent_amount'] ?? null,
            'period_months' => $validated['period_months'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'due_day' => $validated['due_day'] ?? null,
            'notice_months' => $validated['notice_months'] ?? null,
            'service_fee' => $validated['service_fee'] ?? null,
            'payment_commitment_months' => $validated['payment_commitment_months'] ?? null,
            'short_commitment_months' => $validated['short_commitment_months'] ?? null,
            'deposit_amount' => $validated['deposit_amount'] ?? null,
        ], fn ($v) => $v !== null));

        // Figures are always recalculated from the inputs, never accepted.
        $contract->total_amount = $this->builder->totalFor($rent, $months);
        $contract->daily_rate = $this->builder->dailyRate($rent);
        $contract->notice_per_day = $this->builder->dailyRate($rent);
        $contract->save();

        $this->recordEvent($contract, 'updated', 'owner', $request->user()->name, $request);

        return response()->json([
            'success' => true,
            'message' => 'Mkatataba umehifadhiwa.',
            'data' => $this->detail($contract->fresh()->load(['tenant', 'room', 'house'])),
            'warnings' => $validation['warnings'],
        ]);
    }

    /**
     * Issue the single signing invitation. One token per contract; signing
     * clears it, so a link cannot be replayed.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $contract = Contract::ownedByOwner($request->user())->with(['tenant', 'house', 'room'])->findOrFail($id);

        if ($contract->isFinal()) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba huu umesitishwa au umekamilika.',
            ], 422);
        }

        if ($contract->signature_token && $contract->isAwaitingSignature()) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba huu tayari ameshawekwa kwa mpangaji.',
            ], 422);
        }

        $contract->update([
            'signature_token' => $contract->generateSignatureToken(),
            'token_expires_at' => now()->addDays(14),
            'sent_at' => now(),
            'status' => 'pending_signature',
        ]);

        $signingUrl = url("/contracts/sign/{$contract->signature_token}");

        // The tenant is reached through the app, not SMS: the notice lands in
        // their dashboard notifications and on My Contracts, where they read
        // the letter and sign it. Delivery outcome is recorded rather than
        // assumed, so the owner can see it was actually raised.
        $notified = $this->notifyTenantOfContract($contract);

        $this->recordEvent($contract, 'sent', 'owner', $request->user()->name, $request, [
            'to' => $contract->tenant?->phone,
            'notified' => $notified,
        ]);

        return response()->json([
            'success' => true,
            'message' => $notified
                ? 'Mkatataba umetumwa kwa mpangaji kwa saini.'
                : 'Mkatataba umetengenezwa, lakini arifa haijaweza kuundwa kwa mpangaji. Thibitisha kama mpangaji hana akaunti.',
            'data' => [
                'contract' => $this->detail($contract->fresh()->load(['tenant', 'room', 'house'])),
                'signing_url' => $signingUrl,
                'notified' => $notified,
            ],
        ]);
    }

    /**
     * Raise an in-app notice on the tenant's dashboard.
     *
     * Notifications are keyed to the tenant's login, so the contract only
     * becomes visible to the person the tenancy actually belongs to.
     */
    private function notifyTenantOfContract(Contract $contract): bool
    {
        $userId = (int) $contract->tenant?->user_id;

        if ($userId <= 0) {
            Log::warning('Contract notice skipped: tenant has no linked login', [
                'contract_id' => $contract->id,
                'tenant_id' => $contract->tenant_id,
            ]);

            return false;
        }

        $this->notifications->create(
            $userId,
            'contract_signature',
            'Mkatataba wa pango unahitaji saini yako',
            "{$contract->contract_number} kwa chumba {$contract->house?->name} - {$contract->room?->name} "
                .'umeanda kwa kusaini. Ingia kwenye "Mikatataba" kwenye dashibodi yako na ukubali mashartiano '
                .'ndani ya siku 14.',
            $contract,
        );

        return true;
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $contract = Contract::ownedByOwner($request->user())->findOrFail($id);

        if ($contract->isFinal()) {
            return response()->json([
                'success' => false,
                'message' => 'Mkatataba huu umekamilika na hauwezi kusitishwa.',
            ], 422);
        }

        $validated = $request->validate([
            'voided_reason' => 'nullable|string|max:500',
        ]);

        $contract->update([
            'status' => 'void',
            'voided_reason' => $validated['voided_reason'] ?? null,
            'voided_at' => now(),
            'signature_token' => null,
        ]);

        // A tenancy that never started must give the room back to the market.
        $contract->rental?->releaseIfUnstarted($validated['voided_reason'] ?? 'Contract voided.');

        $this->recordEvent($contract, 'voided', 'owner', $request->user()->name, $request);

        return response()->json([
            'success' => true,
            'message' => 'Mkatataba umesitishwa.',
            'data' => $this->detail($contract->fresh()->load(['tenant', 'room', 'house'])),
        ]);
    }

    /**
     * Fill in the owner-level details (company, contact number, payment
     * accounts) whenever the request did not carry them. Without this the
     * letter would render clause 13 empty on every save that only sends the
     * figures, and the owner would be warned about missing accounts they have
     * already configured.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function withOwnerDefaults(array $input, User $owner): array
    {
        $settings = OwnerSetting::forOwner($owner);

        return array_merge([
            'company_name' => $settings->company_name,
            'contact_phone' => $settings->contact_phone,
            'currency' => $settings->currency ?: config('app.currency', 'TZS'),
            'payment_accounts' => $settings->payment_accounts ?? [],
            'lipa_namba' => $settings->lipa_namba,
            'lipa_namba_name' => $settings->lipa_namba_name,
        ], array_filter($input, fn ($v) => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInput(Request $request, bool $partial = false, ?Contract $contract = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'rental_id' => $partial.'|'.($partial ? 'nullable' : 'required').'|integer|exists:rentals,id',
            'rent_amount' => $required.'|numeric|min:0',
            'period_months' => $required.'|integer|min:1|max:120',
            'start_date' => $required.'|date',
            'end_date' => $required.'|date',
            'due_day' => 'nullable|integer|min:1|max:31',
            'notice_months' => 'nullable|integer|min:0|max:12',
            'service_fee' => 'nullable|numeric|min:0',
            'payment_commitment_months' => 'nullable|integer|min:0|max:120',
            'short_commitment_months' => 'nullable|integer|min:0|max:120',
            'deposit_amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'company_name' => 'nullable|string|max:120',
            'contact_phone' => 'nullable|string|max:40',
            'payment_accounts' => 'nullable|array|max:12',
            'payment_accounts.*.network' => 'nullable|string|max:40',
            'payment_accounts.*.number' => 'nullable|string|max:30',
            'payment_accounts.*.account_name' => 'nullable|string|max:120',
            'lipa_namba' => 'nullable|string|max:30',
            'lipa_namba_name' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:8000',
            'extra_clauses' => 'nullable|array|max:30',
            'extra_clauses.*' => 'string|max:1000',
            'clause_overrides' => 'nullable|array',
            'clause_overrides.*' => 'nullable|string|max:2000',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentInput(Contract $contract): array
    {
        $settings = OwnerSetting::forOwner(User::findOrFail($contract->owner_id));

        return [
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
            'lipa_namba' => $settings->lipa_namba,
            'lipa_namba_name' => $settings->lipa_namba_name,
        ];
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

    private function settingsPayload(OwnerSetting $settings): array
    {
        return [
            'company_name' => $settings->company_name,
            'contact_phone' => $settings->contact_phone,
            'contact_email' => $settings->contact_email,
            'address' => $settings->address,
            'currency' => $settings->currency ?: config('app.currency', 'TZS'),
            'payment_accounts' => $settings->payment_accounts ?? [],
            'lipa_namba' => $settings->lipa_namba,
            'lipa_namba_name' => $settings->lipa_namba_name,
        ];
    }

    private function summary(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'rental_id' => $contract->rental_id,
            'status' => $contract->status,
            'tenant' => $contract->tenant?->full_name,
            'room' => $contract->room?->name,
            'house' => $contract->house?->name,
            'rent_amount' => (float) $contract->rent_amount,
            'total_amount' => (float) $contract->total_amount,
            'daily_rate' => (float) $contract->daily_rate,
            'period_months' => (int) $contract->period_months,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'currency' => $contract->currency,
            'has_description' => filled($contract->description),
            'signed_at' => $contract->signed_at?->toIso8601String(),
            'created_at' => $contract->created_at?->toIso8601String(),
        ];
    }

    private function detail(Contract $contract): array
    {
        $snapshot = $contract->content_snapshot;
        if (! is_array($snapshot)) {
            $snapshot = $this->builder->build($contract, $this->currentInput($contract));
        }

        return array_merge($this->summary($contract), [
            'description' => $contract->description,
            // The editable figure set, so reopening a draft cannot silently
            // reset terms that are not shown in the summary.
            'input' => $this->currentInput($contract),
            'clause_overrides' => $contract->clause_overrides ?? [],
            'extra_clauses' => $contract->extra_clauses ?? [],
            'standard_clauses' => ContractTemplate::standardText(),
            'snapshot' => $snapshot,
            'branding' => $this->brandingPayload(OwnerSetting::forOwner($contract->owner_id)),
            'fingerprint' => $contract->document_sha256 ?: $this->builder->fingerprint($snapshot),
            'signing_url' => $contract->signature_token
                ? url("/contracts/sign/{$contract->signature_token}")
                : null,
            'token_expires_at' => $contract->token_expires_at?->toIso8601String(),
            'view_count' => (int) $contract->view_count,
            'signer_name' => $contract->signer_name,
            'signer_phone' => $contract->signer_phone,
            'signature_image' => $contract->signature_image,
            // The exact words accepted, kept for the owner's own records.
            'consent_text' => $contract->signer_consent_text,
            'consented_at' => $contract->signer_consented_at?->toIso8601String(),
            'signed_at' => $contract->signed_at?->toIso8601String(),
            'declined_reason' => $contract->declined_reason,
            'voided_reason' => $contract->voided_reason,
            'events' => $contract->relationLoaded('events')
                ? $contract->events->map(fn (ContractEvent $e) => [
                    'type' => $e->event_type,
                    'actor' => $e->actor_name,
                    'ip' => $e->ip,
                    'at' => $e->created_at?->toIso8601String(),
                ])
                : [],
        ]);
    }
}
