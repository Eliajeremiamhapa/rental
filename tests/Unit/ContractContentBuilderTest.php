<?php

namespace Tests\Unit;

use App\Services\ContractContentBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Guards the payment-instruction warning in ContractContentBuilder::validate().
 *
 * An owner may advertise payment details either as a list of agent accounts or
 * as a single Lipa Namba. The "no payment details" warning must fire only when
 * both are missing, otherwise owners who rely on a Lipa Namba only would be
 * wrongly nudged on every contract.
 */
class ContractContentBuilderTest extends TestCase
{
    private const NO_ACCOUNTS_WARNING = 'Hakuna akaunti za malipo zilizowekwa.';

    public function test_warns_when_neither_accounts_nor_lipa_namba_are_set(): void
    {
        $result = $this->validate();

        $this->assertStringContainsString(self::NO_ACCOUNTS_WARNING, $this->joined($result['warnings']));
    }

    public function test_does_not_warn_when_payment_accounts_are_set(): void
    {
        $result = $this->validate([
            'payment_accounts' => [
                ['network' => 'Vodacom', 'number' => '702882', 'account_name' => 'Uvimo Civil Group'],
            ],
        ]);

        $this->assertStringNotContainsString(self::NO_ACCOUNTS_WARNING, $this->joined($result['warnings']));
    }

    public function test_does_not_warn_when_only_a_lipa_namba_is_set(): void
    {
        $result = $this->validate([
            'payment_accounts' => [],
            'lipa_namba' => '702882',
        ]);

        $this->assertStringNotContainsString(self::NO_ACCOUNTS_WARNING, $this->joined($result['warnings']));
    }

    public function test_warns_about_an_account_without_a_number(): void
    {
        $result = $this->validate([
            'payment_accounts' => [
                ['network' => 'Airtel', 'number' => '', 'account_name' => 'Uvimo Civil Group'],
            ],
        ]);

        $this->assertStringContainsString('bila namba', $this->joined($result['warnings']));
        $this->assertStringNotContainsString(self::NO_ACCOUNTS_WARNING, $this->joined($result['warnings']));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    private function validate(array $overrides = []): array
    {
        $input = array_merge([
            'rent_amount' => 100000,
            'period_months' => 12,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'due_day' => 25,
            'notice_per_day' => 3333.33,
        ], $overrides);

        return (new ContractContentBuilder)->validate($input);
    }

    /**
     * @param  array<int, string>  $warnings
     */
    private function joined(array $warnings): string
    {
        return implode(' | ', $warnings);
    }
}
