<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Concerns\ResolvesBrandingAssets;
use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReceiptController extends Controller
{
    use AuthorizesOwnership;
    use ResolvesBrandingAssets;

    public function __construct(
        protected ReceiptService $receiptService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $receipts = Receipt::query()
            ->ownedBy($request->user())
            ->with(['tenant', 'room.house', 'payment'])
            ->when($request->tenant_id, function ($q, $tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->when($request->house_id, function ($q, $houseId) {
                $q->where('house_id', $houseId);
            })
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Receipts retrieved successfully.',
            'data' => $receipts->map(fn (Receipt $receipt) => $this->withBranding($receipt)),
        ]);
    }

    public function show(Receipt $receipt): JsonResponse
    {
        $this->ensureOwned($receipt);

        $receipt->load(['tenant', 'room.house', 'payment']);

        return response()->json([
            'success' => true,
            'message' => 'Receipt retrieved successfully.',
            'data' => $this->withBranding($receipt),
        ]);
    }

    /**
     * Attach the configured receipt branding so the client renders the same
     * company name, slogan and contact details as the downloaded receipt
     * instead of hardcoding them in the UI.
     */
    protected function withBranding(Receipt $receipt): array
    {
        return array_merge($receipt->toArray(), [
            'branding' => array_merge($this->brandingPayload(), [
                'currency' => config('app.currency', 'TZS'),
            ]),
        ]);
    }

    public function download(Receipt $receipt)
    {
        $this->ensureOwned($receipt);

        $html = $this->receiptService->getReceiptPdf($receipt);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', "attachment; filename=\"{$receipt->receipt_number}.html\"");
    }
}
