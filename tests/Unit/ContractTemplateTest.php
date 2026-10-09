<?php

namespace Tests\Unit;

use App\Services\ContractTemplate;
use PHPUnit\Framework\TestCase;

/**
 * Guards the "no hardcoded values in the contract" rule.
 *
 * Every number that belongs in a contract (rent, deposit, phone, account
 * number, dates) is rendered from the rental or from the owner's saved
 * settings. A literal typed straight into a clause is invisible to the owner,
 * survives a settings change, and produces a legally wrong document. These
 * tests fail the build the moment one is introduced.
 */
class ContractTemplateTest extends TestCase
{
    /**
     * Runs of 9+ digits: a full phone number (0754123456, 255754123456) or a
     * long account/payment reference.
     */
    private const LONG_DIGIT_RUN = '/\d{9,}/';

    /**
     * Tanzanian mobile shape, optionally separated: 0754123456, 0754 123 456,
     * 0754-123-456. Kept deliberately free of lookarounds — the long clause
     * text is enough to exhaust PCRE's backtrack budget on those.
     */
    private const LOCAL_PHONE = '/\d{3}[\s-]?\d{3}[\s-]?\d{3}/';

    public function test_no_clause_contains_a_long_digit_run(): void
    {
        foreach ($this->clauseTexts() as $label => $text) {
            $this->assertDoesNotMatchRegularExpression(
                self::LONG_DIGIT_RUN,
                $text,
                "Clause [{$label}] contains a hardcoded number. Render it from the rental or owner settings instead."
            );
        }
    }

    public function test_no_clause_contains_a_phone_shaped_literal(): void
    {
        foreach ($this->clauseTexts() as $label => $text) {
            $this->assertDoesNotMatchRegularExpression(
                self::LOCAL_PHONE,
                $text,
                "Clause [{$label}] contains what looks like a hardcoded phone number."
            );
        }
    }

    public function test_standard_text_has_a_stable_clause_count(): void
    {
        // Guards the count the owner UI and the test plan document rely on.
        $this->assertCount(13, ContractTemplate::standardText());
        $this->assertCount(
            count(ContractTemplate::clauses()),
            ContractTemplate::standardText(),
            'clause definitions and standard text are out of sync'
        );
    }

    public function test_payment_accounts_are_rendered_from_data_not_literals(): void
    {
        $accounts = [
            ['network' => 'M-Pesa', 'number' => '0754123456', 'account_name' => 'Uvimo'],
            ['network' => 'Tigopesa', 'number' => '0654123456', 'account_name' => 'Uvimo'],
        ];

        $rendered = ContractTemplate::renderPaymentAccounts($accounts);

        $this->assertStringContainsString('0754123456', $rendered);
        $this->assertStringContainsString('0654123456', $rendered);

        // Nothing beyond the two supplied numbers may appear.
        preg_match_all(self::LONG_DIGIT_RUN, $rendered, $found);
        sort($found[0]);
        $this->assertSame(['0654123456', '0754123456'], $found[0]);
    }

    public function test_rendered_payment_accounts_handle_an_empty_list(): void
    {
        $this->assertSame('', ContractTemplate::renderPaymentAccounts([]));
    }

    /**
     * @return array<string, string> label => rendered clause text
     */
    private function clauseTexts(): array
    {
        $clauses = ContractTemplate::clauses();
        $standard = ContractTemplate::standardText();

        $texts = [];

        foreach ($clauses as $key => $clause) {
            $texts["definition:{$key}"] = is_array($clause)
                ? json_encode($clause, JSON_UNESCAPED_UNICODE)
                : (string) $clause;
        }

        foreach ($standard as $key => $text) {
            $texts["standard:{$key}"] = (string) $text;
        }

        // Headings and fixed prose are rendered into the document as well.
        $texts['title'] = ContractTemplate::TITLE;
        $texts['conditions_heading'] = ContractTemplate::CONDITIONS_HEADING;
        $texts['additional_heading'] = ContractTemplate::ADDITIONAL_HEADING;

        return $texts;
    }
}
