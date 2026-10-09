<?php

namespace App\Services;

/**
 * The rental agreement letter supplied by the client, with every figure that
 * varies per tenancy replaced by a placeholder.
 *
 * Clause wording is reproduced from the client's sample. It has not been
 * drafted by a lawyer and must be reviewed before it is used to bind tenants.
 *
 * Owners may override any clause (see Contract::clause_overrides); the standard
 * text is always retained on the contract so a change stays visible.
 */
final class ContractTemplate
{
    /**
     * Bump this whenever the clause wording changes so issued contracts can be
     * identified by the template they were built from.
     */
    public const VERSION = '1.0';

    public const TITLE = 'HATI YA UTHIBITISHO WA MALIPO YA KODI YA PANGO';

    public const CONDITIONS_HEADING = 'MASHARTI YA UPANGISHAJI';

    public const ADDITIONAL_HEADING = 'MASHARTI YA MPANGISHAJI - NYONGEZA';

    public const WELCOME = 'Karibu sana.';

    /**
     * Swahili number words for the short "miezi minne (4)" style used in the
     * client's letter. Contract periods are small, so a map is enough.
     */
    private const NUMBER_WORDS = [
        1 => 'moja', 2 => 'mbili', 3 => 'tatu', 4 => 'nne', 5 => 'tano',
        6 => 'siti', 7 => 'sababu', 8 => 'nane', 9 => 'tisa', 10 => 'kumi',
        11 => 'kumi na moja', 12 => 'kumi na mbili',
    ];

    /**
     * @return array<int, array{key: string, text: string, editable: bool}>
     */
    public static function clauses(): array
    {
        return [
            [
                'key' => 'rent_commitment',
                'editable' => true,
                'text' => 'Kodi ilipwe kwa miezi {payment_commitment_months} au miezi {short_commitment_months} kwa muda mfupi.',
            ],
            [
                'key' => 'payment_day',
                'editable' => true,
                'text' => 'Kodi na gharama za huduma (usafi, takataka na umeme) zilipwe kila tarehe {due_day} kila mwezi au pamoja na kodi.',
            ],
            [
                'key' => 'no_security',
                'editable' => true,
                'text' => 'Mali za mpangaji si dhamana ya kodi.',
            ],
            [
                'key' => 'no_illegal_activity',
                'editable' => true,
                'text' => 'Hairuhusiwi kufanya biashara haramu au shughuli zinazokiuka sheria.',
            ],
            [
                'key' => 'peaceful_cohabitation',
                'editable' => true,
                'text' => 'Mpangaji atadumisha amani na kuepuka usumbufu kwa wakaazi wengine.',
            ],
            [
                'key' => 'gate_security',
                'editable' => true,
                'text' => 'Kufunga geti kila wakati ni wajibu wa mpangaji, kuhakikisha usalama wa mali zake.',
            ],
            [
                'key' => 'no_liability_for_property',
                'editable' => true,
                'text' => '{company_name} hatutawajibika kulinda mali za mpangaji.',
            ],
            [
                'key' => 'water_system',
                'editable' => true,
                'text' => 'Utaratibu wa matumizi na malipo ya maji kwa mfumo wa maji wa kujitegemea utatolewa mara mfumo utakapokuwa tayari.',
            ],
            [
                'key' => 'notice_and_overstay',
                'editable' => true,
                'text' => 'Iwapo utaamua kuhama utawajibika kwa siku utakazo kaa kwa gharama ya {currency} {notice_per_day} siku na utalipa deni lote kabla ya kuondoka.',
            ],
            [
                'key' => 'damage_liability',
                'editable' => true,
                'text' => 'Utagharamia uharibifu wa nyumba, kupoteza ufunguo au kuharibu kitasa wakati au baada ya mkatataba huu.',
            ],
            [
                'key' => 'no_liability_for_payment_numbers',
                'editable' => true,
                'text' => '{company_name} hatutowajibika kwa upotevu wa mali, utapeli au malipo yoyote yatakayofanyika kupitia namba nyingine tofauti na namba hapa chini:-',
            ],
            [
                'key' => 'official_contact',
                'editable' => true,
                'text' => 'Namba rasmi ya mawasiliano ya {company_name} ni {contact_phone}.',
            ],
            [
                'key' => 'official_payment_accounts',
                'editable' => true,
                'text' => 'Akaunti rasmi za malipo kupitia wakala ni {payment_accounts}. Jina la Akaunti zote: {account_name}.',
            ],
        ];
    }

    /**
     * @return array<string, string> clause key => standard wording
     */
    public static function standardText(): array
    {
        $text = [];
        foreach (self::clauses() as $clause) {
            $text[$clause['key']] = $clause['text'];
        }

        return $text;
    }

    public static function numberWord(int $number): string
    {
        return self::NUMBER_WORDS[$number] ?? (string) $number;
    }

    /**
     * Render the numbered payment-accounts list used in the final clause.
     *
     * @param  array<int, array{network?: string, number?: string, account_name?: string}>  $accounts
     */
    public static function renderPaymentAccounts(array $accounts, ?string $lipaNamba = null, ?string $lipaNambaName = null): string
    {
        $lines = [];

        if ($lipaNamba) {
            $label = $lipaNamba;
            $name = $lipaNambaName ? " ({$lipaNambaName})" : '';
            $lines[] = '1- Lipa Namba: '.$label.$name;
        }

        $i = count($lines);
        foreach ($accounts as $account) {
            $i++;
            $network = trim((string) ($account['network'] ?? ''));
            $number = trim((string) ($account['number'] ?? ''));
            if ($network === '' && $number === '') {
                continue;
            }
            $lines[] = $i.'-'.($network !== '' ? $network.' : ' : '').$number;
        }

        return $lines === [] ? '' : implode(', ', $lines);
    }

    public static function accountName(array $accounts, ?string $lipaNambaName = null): string
    {
        if ($lipaNambaName) {
            return trim($lipaNambaName);
        }

        foreach ($accounts as $account) {
            $name = trim((string) ($account['account_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }
}
