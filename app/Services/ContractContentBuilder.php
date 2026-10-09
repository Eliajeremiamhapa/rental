<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\OwnerSetting;
use App\Models\Rental;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * Builds the rental agreement letter from a tenancy plus the owner's settings.
 *
 * Money is always derived. An owner chooses the monthly rent and the number of
 * months; the total and the daily rate are computed here and can never be typed
 * in by hand, which is what allowed the client's sample to state a total that
 * did not match its own monthly rate.
 */
class ContractContentBuilder
{
    /**
     * Days used to convert a monthly rent into a daily rate. The client's letter
     * uses 40,000 / 30 = 1,333 (they wrote 1,334), i.e. a flat 30-day month
     * rather than the real length of the period.
     */
    public const DAYS_PER_MONTH = 30;

    private const MONTHS_SW = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mac', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Prefill a draft from an existing tenancy. Nothing is persisted here; the
     * controller decides what to store.
     *
     * @return array<string, mixed>
     */
    public function prefillFromRental(Rental $rental): array
    {
        $tenant = $rental->tenant;
        $settings = $this->settingsFor($rental->house->user_id ?? $tenant?->user_id);

        $months = (int) ($rental->period_months ?: $this->monthsBetween($rental->start_date, $rental->end_date) ?: 1);

        return [
            'rental_id' => $rental->id,
            'tenant_id' => $rental->tenant_id,
            'house_id' => $rental->house_id,
            'room_id' => $rental->room_id,
            'rent_amount' => (float) $rental->rent_amount,
            'period_months' => max(1, $months),
            'start_date' => $rental->start_date?->toDateString(),
            'end_date' => $rental->end_date?->toDateString(),
            'deposit_amount' => (float) ($rental->deposit_amount ?? 0),
            'due_day' => (int) ($rental->due_day ?: 25),
            'notice_per_day' => 2000,
            'service_fee' => 0,
            'payment_commitment_months' => 5,
            'short_commitment_months' => 3,
            'description' => null,
            'company_name' => $settings->company_name,
            'lipa_namba' => $settings->lipa_namba,
            'lipa_namba_name' => $settings->lipa_namba_name,
            'contact_phone' => $settings->contact_phone,
            'payment_accounts' => $settings->payment_accounts ?? [],
            'currency' => $settings->currency ?: config('app.currency', 'TZS'),
            // Preview-only figures so the owner can see the arithmetic before saving.
            'total_amount' => round((float) $rental->rent_amount * max(1, $months), 2),
            'daily_rate' => $this->dailyRate((float) $rental->rent_amount),
        ];
    }

    public function dailyRate(float $monthlyRent): float
    {
        return round($monthlyRent / self::DAYS_PER_MONTH, 2);
    }

    public function totalFor(float $monthlyRent, int $months): float
    {
        return round($monthlyRent * max(0, $months), 2);
    }

    /**
     * Structural checks. Errors block issuing; warnings are surfaced to the
     * owner but do not stop them.
     *
     * @param  array<string, mixed>  $input
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $warnings = [];

        $rent = (float) ($input['rent_amount'] ?? 0);
        $months = (int) ($input['period_months'] ?? 0);

        if ($rent <= 0) {
            $errors[] = 'Kiwango cha kodi kinawewa kuwa kikubwa kuliko sifuri.';
        }

        if ($months < 1) {
            $errors[] = 'Kipindi cha mkatataba lazima kiwe mieza mitatu au zaidi.';
        }

        $start = $input['start_date'] ?? null;
        $end = $input['end_date'] ?? null;

        if (! $start || ! $end) {
            $errors[] = 'Tafadhali weka tarehe ya kuanza na tarehe ya kumaliza.';
        } else {
            $span = $this->monthsBetween($start, $end);
            if ($span > 0 && $span !== $months) {
                $warnings[] = 'Kipindi kati ya '.static::formatDate($start).' na '.static::formatDate($end)
                    ." ni miezi {$span}, lakini umeweka miezi {$months}.";
            }
            if (static::toDate($end) < static::toDate($start)) {
                $errors[] = 'Tarehe ya kumaliza haipaswi kuwa ya awali kuliko tarehe ya kuanza.';
            }
        }

        $dueDay = (int) ($input['due_day'] ?? 25);
        if ($dueDay < 1 || $dueDay > 31) {
            $errors[] = 'Tarehe ya malipo lazima iwe kati ya 1 na 31.';
        } elseif ($dueDay > 28) {
            $warnings[] = 'Tarehe ya malipo ya zaidi ya 28 haipo kila mwezi.';
        }

        if ((float) ($input['notice_per_day'] ?? 0) < 0) {
            $errors[] = 'Gharama ya kila siku haipaswi kuwa hasi.';
        }

        $commitment = $input['payment_commitment_months'] ?? null;
        if ($commitment !== null && $months > 0 && (int) $commitment > $months) {
            $warnings[] = 'Mkatataba wa malipo kwa miezi '.(int) $commitment
                .' ni mrefu kuliko kipindi cha mkatataba cha miezi '.$months.'.';
        }

        $accounts = $input['payment_accounts'] ?? [];
        if (is_array($accounts) && $accounts !== []) {
            foreach ($accounts as $account) {
                $number = trim((string) Arr::get((array) $account, 'number', ''));
                if ($number === '') {
                    $warnings[] = 'Kuna akaunti ya malipo bila namba.';

                    continue;
                }
                if (! preg_match('/^[0-9]{5,15}$/', $number)) {
                    $warnings[] = "Namba ya akaunti ya malipo \"{$number}\" inaonekana kuwa si sahihi.";
                }
            }
        } else {
            $warnings[] = 'Hakuna akaunti za malipo zilizowekwa. Mpangaji atatuma malipo kwa namba nyingine ambayo hatutowajibika.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Render the immutable snapshot that will be hashed and signed.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function build(Contract $contract, array $input): array
    {
        $rent = (float) ($input['rent_amount'] ?? 0);
        $months = max(1, (int) ($input['period_months'] ?? 1));
        $total = $this->totalFor($rent, $months);
        $daily = $this->dailyRate($rent);
        $currency = (string) (($input['currency'] ?? null) ?: 'TZS');
        $company = (string) (($input['company_name'] ?? null) ?: config('services.receipt.company_name', 'UVIMO'));
        $accounts = (array) ($input['payment_accounts'] ?? []);
        $lipaNamba = trim((string) ($input['lipa_namba'] ?? '')) ?: null;
        $lipaNambaName = trim((string) ($input['lipa_namba_name'] ?? '')) ?: null;

        $tenant = Tenant::find($contract->tenant_id);
        $house = $contract->house;
        $room = $contract->room;

        $overrides = (array) ($contract->clause_overrides ?? []);
        $standard = array_merge(ContractTemplate::standardText(), (array) ($contract->clause_text ?? []));

        $vars = [
            'company_name' => $company,
            'contact_phone' => (string) ($input['contact_phone'] ?? ''),
            'currency' => $currency,
            'notice_per_day' => static::money((float) ($input['notice_per_day'] ?? 0)),
            'due_day' => (string) (int) ($input['due_day'] ?? 25),
            'payment_commitment_months' => (string) (int) ($input['payment_commitment_months'] ?? 5),
            'short_commitment_months' => (string) (int) ($input['short_commitment_months'] ?? 3),
            'payment_accounts' => ContractTemplate::renderPaymentAccounts($accounts, $lipaNamba, $lipaNambaName),
            'account_name' => ContractTemplate::accountName($accounts, $lipaNambaName),
        ];

        $clauses = [];
        foreach (ContractTemplate::clauses() as $index => $clause) {
            $key = $clause['key'];
            $base = $standard[$key] ?? $clause['text'];
            $override = trim((string) Arr::get($overrides, $key, ''));

            $text = $override !== '' ? $override : $base;
            $rendered = static::interpolate($text, $vars);

            $clauses[] = [
                'number' => $index + 1,
                'key' => $key,
                'text' => $rendered,
                'is_modified' => $override !== '' && $override !== $base,
                // The standard is stored already rendered: showing the raw
                // template would put "{currency}" in front of the owner
                // instead of the wording they are actually agreeing to.
                'standard_text' => $override !== '' && $override !== $base
                    ? static::interpolate($base, $vars)
                    : null,
            ];
        }

        $description = trim((string) ($contract->description ?? ''));

        return [
            'contract_number' => $contract->contract_number,
            'template_version' => $contract->template_version ?: ContractTemplate::VERSION,
            'title' => ContractTemplate::TITLE,
            'company_name' => $company,
            'currency' => $currency,
            'tenant' => [
                'full_name' => $tenant?->full_name,
                'phone' => $tenant?->phone,
                'id_number' => $tenant?->id_number,
            ],
            'property' => [
                'house' => $house?->name,
                'room' => $room?->name,
                'address' => $house?->address,
            ],
            'salutation' => 'Ndugu, '.($tenant?->full_name ?? '').',',
            'preamble' => sprintf(
                'Tunathibitisha kupokea malipo ya %s %s sawa na kodi ya pango ya miezi %s (%d), kwa kiwango cha %s %s kwa mwezi, sawa na %s %s kwa siku.',
                $currency,
                static::money($total),
                ContractTemplate::numberWord($months),
                $months,
                $currency,
                static::money($rent),
                $currency,
                static::money($daily),
            ),
            'period_line' => sprintf(
                'Muda wa kodi: %s - %s sawa na miezi %s.',
                static::formatDate($input['start_date'] ?? null),
                static::formatDate($input['end_date'] ?? null),
                ContractTemplate::numberWord($months),
            ),
            'figures' => [
                'rent_amount' => $rent,
                'total_amount' => $total,
                'daily_rate' => $daily,
                'period_months' => $months,
                'deposit_amount' => (float) ($input['deposit_amount'] ?? 0),
                'service_fee' => (float) ($input['service_fee'] ?? 0),
                'notice_per_day' => (float) ($input['notice_per_day'] ?? 0),
                'due_day' => (int) ($input['due_day'] ?? 25),
            ],
            'start_date' => static::formatDate($input['start_date'] ?? null),
            'end_date' => static::formatDate($input['end_date'] ?? null),
            'conditions_heading' => ContractTemplate::CONDITIONS_HEADING,
            'clauses' => $clauses,
            'additional_heading' => ContractTemplate::ADDITIONAL_HEADING,
            'description' => $description !== '' ? $description : null,
            'payment_accounts' => $accounts,
            'lipa_namba' => $lipaNamba,
            'lipa_namba_name' => $lipaNambaName,
            'welcome' => ContractTemplate::WELCOME,
        ];
    }

    /**
     * Canonical fingerprint of the document the signer agreed to. Only the
     * substance of the agreement is hashed: presentation changes must not alter
     * the signature, but any change to a figure or clause must.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function fingerprint(array $snapshot): string
    {
        $subjective = [
            'title' => $snapshot['title'] ?? null,
            'company_name' => $snapshot['company_name'] ?? null,
            'tenant' => $snapshot['tenant'] ?? null,
            'property' => $snapshot['property'] ?? null,
            'salutation' => $snapshot['salutation'] ?? null,
            'preamble' => $snapshot['preamble'] ?? null,
            'period_line' => $snapshot['period_line'] ?? null,
            'figures' => $snapshot['figures'] ?? null,
            'clauses' => array_map(
                fn (array $c) => ['number' => $c['number'], 'key' => $c['key'], 'text' => $c['text']],
                $snapshot['clauses'] ?? []
            ),
            'description' => $snapshot['description'] ?? null,
            'payment_accounts' => $snapshot['payment_accounts'] ?? null,
            'lipa_namba' => $snapshot['lipa_namba'] ?? null,
            'lipa_namba_name' => $snapshot['lipa_namba_name'] ?? null,
            'template_version' => $snapshot['template_version'] ?? null,
        ];

        ksort($subjective);

        return hash('sha256', json_encode($subjective, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function settingsFor(int $userId): OwnerSetting
    {
        return OwnerSetting::forOwner(User::findOrFail($userId));
    }

    private function monthsBetween(mixed $start, mixed $end): int
    {
        if (! $start || ! $end) {
            return 0;
        }

        $from = static::toDate($start);
        $to = static::toDate($end);
        if (! $from || ! $to || $to < $from) {
            return 0;
        }

        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month);
        if ($to->day >= $from->day - 1) {
            $months++;
        }

        return max(0, $months);
    }

    private static function toDate(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function interpolate(string $text, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        // An unresolved placeholder would print literally into a binding legal
        // document, so blank it out and let validation report the gap.
        $replacements['/\{[a-z_]+\}/'] = '';

        return trim(strtr($text, $replacements));
    }

    public static function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    public static function formatDate(mixed $date): string
    {
        $d = static::toDate($date);
        if (! $d) {
            return '';
        }

        return sprintf('%02d %s %d', $d->day, self::MONTHS_SW[$d->month], $d->year);
    }
}
