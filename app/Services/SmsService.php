<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends SMS messages through Africa's Talking (Bulk SMS API).
 *
 * The API key is read from config('services.africastalking.key'). When no key
 * or sender is configured (e.g. local development before the tenant supplies
 * one), sending is skipped gracefully rather than failing the request or
 * incurring charges on undelivered messages.
 */
class SmsService
{
    public function isConfigured(): bool
    {
        return filled(config('services.africastalking.key'))
            && filled(config('services.africastalking.sender'));
    }

    /**
     * Send an SMS. Returns true if the message was (or would have been) sent.
     * Accepts a local (E.164/TZ) or international phone number.
     */
    public function send(string $to, string $message): bool
    {
        $to = $this->normalizePhone($to);

        if (! $this->isConfigured()) {
            Log::info("SMS provider not configured; skipping SMS to {$to}: {$message}");

            return false;
        }

        try {
            $payload = [
                'username' => config('services.africastalking.username'),
                'to' => $to,
                'message' => $message,
            ];

            $sender = config('services.africastalking.sender');
            if ($sender) {
                $payload['from'] = $sender;
            }

            $response = Http::withHeaders([
                'apiKey' => config('services.africastalking.key'),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ])->asForm()->post(config('services.africastalking.base_url'), $payload);

            $body = $response->json('SMSMessageData');
            $message = $body['Message'] ?? '';
            $recipients = $body['Recipients'] ?? [];

            Log::info('Africa\'s Talking SMS send response', [
                'to' => $to,
                'status' => $response->status(),
                'message' => $message,
                'recipients' => $recipients,
            ]);

            if (! $response->successful() || str_contains($message, 'InvalidSenderId')) {
                Log::warning('Africa\'s Talking SMS send failed', [
                    'to' => $to,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $accepted = collect($recipients)->contains(fn ($r) => in_array($r['status'] ?? '', ['Success', 'Sent'], true));
            if ($recipients !== [] && ! $accepted) {
                Log::warning('Africa\'s Talking SMS recipient rejected', [
                    'to' => $to,
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Africa\'s Talking SMS send error', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Normalise a phone number to international E.164 (+CC...) format that
     * Africa's Talking expects. Local numbers starting with 0 are assumed to be
     * Tanzanian (+255) — adjust the country code to match the deployment region.
     */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]+/', '', $phone);

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        // Local number like 0711... -> +255711...
        if (str_starts_with($phone, '0')) {
            return '+'.config('app.phone_country_code', '255').substr($phone, 1);
        }

        // Otherwise assume it already carries a country code without a '+' prefix.
        return '+'.$phone;
    }
}
