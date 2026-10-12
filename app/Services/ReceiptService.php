<?php

namespace App\Services;

use App\Models\OwnerSetting;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReceiptService
{
    public function generateReceipt(Payment $payment): Receipt
    {
        $payment->load('rentCharge.room', 'rentCharge.rental');

        $rentCharge = $payment->rentCharge;
        $rentPeriod = $rentCharge->period_start->format('M Y');
        $balanceAfter = (float) $rentCharge->expected_amount - (float) $rentCharge->paid_amount;

        return Receipt::create([
            'receipt_number' => $this->generateReceiptNumber(),
            'payment_id' => $payment->id,
            'tenant_id' => $payment->tenant_id,
            'room_id' => $rentCharge->room_id,
            'house_id' => $rentCharge->rental->house_id,
            'amount_paid' => $payment->amount,
            'payment_date' => $payment->payment_date,
            'payment_method' => $payment->payment_method,
            'rent_period' => $rentPeriod,
            'balance_after' => $balanceAfter,
            'issued_at' => now(),
        ]);
    }

    public function generateReceiptNumber(): string
    {
        // UR sequence starts at UR0001. Legacy RCP-* receipts are ignored so the
        // new series begins cleanly; receipts are never deleted, so counting the
        // UR receipts yields a stable, collision-free next number.
        $lastReceipt = Receipt::where('receipt_number', 'like', 'UR%')
            ->orderByDesc('id')
            ->first();

        $nextNumber = 1;
        if ($lastReceipt) {
            $nextNumber = (int) substr($lastReceipt->receipt_number, 2) + 1;
        }

        return sprintf('UR%04d', $nextNumber);
    }

    /**
     * Render the printable receipt as a self-contained HTML document. Used as
     * the source for both the on-screen preview download and the PDF renderer.
     */
    public function getReceiptHtml(Receipt $receipt): string
    {
        $receipt->load('payment', 'tenant', 'room', 'house', 'house.owner');

        // Branding comes from the landlord who owns the house, so each owner's
        // receipts carry their own name/contact. Falls back to the platform
        // brand when the owner has not configured anything.
        $settings = $receipt->house?->user_id
            ? OwnerSetting::forOwner($receipt->house->user_id)
            : null;
        $hasOwnerBranding = (bool) $settings?->company_name;

        $companyName = $hasOwnerBranding
            ? $settings->company_name
            : config('services.receipt.company_name', 'Nipangishe');
        $slogan = $hasOwnerBranding ? null : config('services.receipt.company_slogan');
        $companyPhone = $settings?->contact_phone
            ?: config('services.receipt.company_phone');
        $companyAddress = $settings?->address
            ?: config('services.receipt.company_address');
        $currency = config('app.currency', 'TZS');

        // Everything below is user-supplied (house/room/tenant names, the
        // recorded-by user, plus admin-configured branding), so it must be
        // escaped before being embedded in the HTML document.
        $receiptNumber = e($receipt->receipt_number);
        $paymentDate = e($receipt->payment_date?->format('d/m/Y') ?? '-');
        $houseName = e($receipt->house?->name ?? '-');
        $roomName = e($receipt->room?->name ?? '-');
        $tenantName = e($receipt->tenant?->full_name ?? '-');
        $tenantPhone = e($receipt->tenant?->phone ?? '-');
        $period = e($receipt->rent_period ?? '-');
        $amount = number_format((float) $receipt->amount_paid, 2);
        $method = e(ucfirst(str_replace('_', ' ', $receipt->payment_method)));
        $balance = number_format((float) $receipt->balance_after, 2);
        $issuedAt = e($receipt->issued_at?->format('d/m/Y H:i') ?? '-');
        $companyName = e($companyName);
        $currency = e($currency);

        $signatoryName = $receipt->payment?->recorded_by
            ? User::find($receipt->payment->recorded_by)?->name
            : ($receipt->house?->owner?->name ?? $companyName);
        $signatory = e($signatoryName ?: '-');

        $logo = $this->embedImage(config('services.receipt.logo_path'));
        $signature = $this->embedImage(config('services.receipt.signature_path'));

        $logoHtml = $logo
            ? '<img src="'.$logo.'" alt="'.e($companyName).' logo" class="logo">'
            : '';

        $signatureHtml = $signature
            ? '<img src="'.$signature.'" alt="Signature" class="signature-img">'
            : '<div class="line"></div>';

        $sloganHtml = $slogan ? '<div class="sub">'.e($slogan).'</div>' : '';
        $contactLines = array_filter([$companyAddress, $companyPhone ? 'Tel: '.$companyPhone : null]);
        $contactHtml = $contactLines
            ? '<div class="sub">'.e(implode(' | ', $contactLines)).'</div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Receipt {$receiptNumber}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; color: #1a1a1a; padding: 32px; }
    .receipt { max-width: 640px; margin: 0 auto; border: 2px solid #1a1a1a; border-radius: 8px; padding: 28px; }
    .header { text-align: center; border-bottom: 2px solid #1a1a1a; padding-bottom: 16px; margin-bottom: 20px; }
    .header .logo { max-height: 72px; max-width: 160px; object-fit: contain; margin-bottom: 8px; }
    .header h1 { font-size: 22px; letter-spacing: 2px; text-transform: uppercase; }
    .header .title { font-size: 13px; letter-spacing: 3px; text-transform: uppercase; color: #555; margin-top: 6px; }
    .header .sub { font-size: 12px; color: #888; margin-top: 4px; }
    .receipt-no { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; font-size: 14px; }
    .receipt-no .block { display: flex; gap: 8px; align-items: baseline; }
    .receipt-no strong { letter-spacing: 1px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px; margin-bottom: 20px; }
    .grid .label { color: #888; font-size: 12px; text-transform: uppercase; }
    .grid .value { font-weight: 600; }
    .amount-box { background: #f5f5f5; border-radius: 6px; padding: 16px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .amount-box .label { font-size: 13px; text-transform: uppercase; color: #555; }
    .amount-box .value { font-size: 22px; font-weight: 800; }
    .footer { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: 36px; }
    .recipient { font-size: 13px; }
    .recipient .label { color: #888; font-size: 12px; text-transform: uppercase; }
    .recipient .value { font-weight: 600; margin-top: 2px; }
    .signature { text-align: center; font-size: 13px; min-width: 200px; }
    .signature .signature-img { height: 60px; max-width: 200px; object-fit: contain; display: block; margin: 0 auto 4px; }
    .signature .line { width: 180px; border-top: 1px solid #555; margin: 44px auto 8px; }
    .signature .name { font-weight: 600; }
    .signature .role { color: #888; font-size: 11px; }
    .muted { color: #888; font-size: 12px; }
</style>
</head>
<body>
<div class="receipt">
    <div class="header">
        {$logoHtml}
        <h1>{$companyName}</h1>
        <div class="title">Payment Receipt</div>
        {$sloganHtml}
        {$contactHtml}
    </div>

    <div class="receipt-no">
        <div class="block">
            <span class="muted">Receipt No.</span>
            <span><strong>{$receiptNumber}</strong></span>
        </div>
        <div class="block">
            <span class="muted">Date of Payment</span>
            <span><strong>{$paymentDate}</strong></span>
        </div>
    </div>

    <div class="grid">
        <div>
            <div class="label">Tenant Name</div>
            <div class="value">{$tenantName}</div>
        </div>
        <div>
            <div class="label">Tenant Number</div>
            <div class="value">{$tenantPhone}</div>
        </div>
        <div>
            <div class="label">House</div>
            <div class="value">{$houseName}</div>
        </div>
        <div>
            <div class="label">Room</div>
            <div class="value">{$roomName}</div>
        </div>
        <div>
            <div class="label">Rent Period</div>
            <div class="value">{$period}</div>
        </div>
        <div>
            <div class="label">Payment Method</div>
            <div class="value">{$method}</div>
        </div>
    </div>

    <div class="amount-box">
        <span class="label">Amount Paid</span>
        <span class="value">{$currency} {$amount}</span>
    </div>

    <div class="grid">
        <div>
            <div class="label">Balance After</div>
            <div class="value">{$currency} {$balance}</div>
        </div>
    </div>

    <div class="footer">
        <div class="recipient">
            <div class="label">Recipient</div>
            <div class="value">{$companyName}</div>
        </div>
        <div class="signature">
            {$signatureHtml}
            <div class="name">{$signatory}</div>
            <div class="role">Authorized Signature</div>
        </div>
    </div>

    <div class="muted" style="text-align:center; margin-top:24px;">Generated by {$companyName} on {$issuedAt}</div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Render the receipt HTML into a binary PDF document (A4 portrait).
     *
     * Remote assets are disabled: local logo/signature files are embedded as
     * data URIs by getReceiptHtml(), so the PDF stays self-contained and no
     * outbound request is ever made while generating a document.
     */
    public function renderPdf(Receipt $receipt): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('chroot', base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->getReceiptHtml($receipt), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Turn a configured logo/signature reference into a source usable in HTML.
     * Local files are embedded as data URIs so the downloaded receipt is
     * self-contained; URLs are returned untouched; missing assets return null.
     */
    private function embedImage(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $candidates = [$path, public_path($path), storage_path('app/'.$path)];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $mime = mime_content_type($candidate) ?: 'image/png';
                $contents = file_get_contents($candidate);

                return $contents === false
                    ? null
                    : 'data:'.$mime.';base64,'.base64_encode($contents);
            }
        }

        // Only http(s) URLs may be used as a remote asset reference; anything
        // else (javascript:, data:, ...) is dropped so it cannot be injected
        // into the img src attribute.
        if (filter_var($path, FILTER_VALIDATE_URL)
            && preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return null;
    }
}
