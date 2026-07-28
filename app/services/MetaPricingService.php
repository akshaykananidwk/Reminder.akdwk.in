<?php

namespace App\Services;

use App\Core\App;

/**
 * What each conversation costs.
 *
 * One thing to be clear about, because it shapes everything here: Meta does not
 * send the amount. The status webhook carries the pricing *category*
 * (marketing / utility / authentication / service) and the conversation id, and
 * the money is left to the published rate card. Anyone claiming to show you
 * "Meta's real-time price" is multiplying a category by a number they typed in.
 *
 * So this does the same thing, but says so: rates live in `wa_price_rates`, the
 * operator maintains them, and every figure is labelled as a computed estimate
 * against Meta's invoice rather than as the invoice itself.
 */
class MetaPricingService
{
    /** Rate cards change rarely; a request-lifetime cache is enough. */
    private static ?array $rates = null;

    /**
     * Price one conversation.
     *
     * @return array{price: float, currency: string, rate: float, markup: float, known: bool}
     */
    public static function priceFor(string $category, ?string $countryCode = null): array
    {
        $category = strtolower(trim($category));
        $country = strtoupper(trim((string) $countryCode));

        $rate = self::rate($category, $country);
        $markup = self::markupPercent();

        $price = round($rate['price'] * (1 + $markup / 100), 6);

        return [
            'price'    => $price,
            'currency' => $rate['currency'],
            'rate'     => $rate['price'],
            'markup'   => $markup,
            // A zero rate is almost always "nobody filled the rate card in", not
            // "this is free". Saying which is which stops a dashboard from
            // reporting a month of messaging as costing nothing.
            'known'    => $rate['price'] > 0 || $category === 'referral_conversion',
        ];
    }

    /** @return array{price: float, currency: string} */
    private static function rate(string $category, string $country): array
    {
        $rates = self::load();

        foreach ([$country, 'DEFAULT'] as $key) {
            if ($key !== '' && isset($rates[$key][$category])) {
                return $rates[$key][$category];
            }
        }

        return ['price' => 0.0, 'currency' => self::defaultCurrency()];
    }

    /** @return array<string, array<string, array{price: float, currency: string}>> */
    private static function load(): array
    {
        if (self::$rates !== null) {
            return self::$rates;
        }

        self::$rates = [];

        try {
            $rows = App::i()->db()->all('SELECT country_code, category, price, currency FROM wa_price_rates');
        } catch (\Throwable) {
            return self::$rates;
        }

        foreach ($rows as $row) {
            self::$rates[strtoupper((string) $row['country_code'])][strtolower((string) $row['category'])] = [
                'price'    => (float) $row['price'],
                'currency' => (string) $row['currency'],
            ];
        }

        return self::$rates;
    }

    public static function clearCache(): void
    {
        self::$rates = null;
    }

    public static function markupPercent(): float
    {
        try {
            return max(0.0, App::i()->settings()->float('meta_price_markup_percent', 0.0));
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private static function defaultCurrency(): string
    {
        try {
            return (string) (App::i()->settings()->get('currency', 'INR') ?: 'INR');
        } catch (\Throwable) {
            return 'INR';
        }
    }

    /* ------------------------------------------------------------- Applying */

    /**
     * Stamp the computed price onto a conversation and the messages inside it.
     *
     * Called when a status receipt creates or updates a conversation. Meta bills
     * once per conversation, so the price goes on the conversation; each message
     * carries the same category so a breakdown by message type is possible, but
     * only the first message of a conversation carries the charge.
     */
    public static function applyToConversation(int $conversationRowId): void
    {
        try {
            $db = App::i()->db();

            $conversation = $db->one('SELECT * FROM wa_conversations WHERE id = ?', [$conversationRowId]);

            if ($conversation === null || !(int) $conversation['is_billable']) {
                return;
            }

            $country = (string) ($conversation['country'] ?? '');

            if ($country === '') {
                $country = self::countryFromNumber((string) $conversation['contact_wa_id']);
            }

            $priced = self::priceFor((string) $conversation['category'], $country);

            $db->update('wa_conversations', [
                'price'    => $priced['price'],
                'currency' => $priced['currency'],
                'country'  => $country !== '' ? mb_substr($country, 0, 8) : null,
            ], 'id = :id', ['id' => $conversationRowId]);

            // Charge the first message of the conversation, so summing the
            // message table and summing the conversation table agree.
            $first = $db->one(
                'SELECT id FROM wa_messages WHERE conversation_row_id = ? ORDER BY id LIMIT 1',
                [$conversationRowId]
            );

            if ($first !== null) {
                $db->update('wa_messages', [
                    'price'            => $priced['price'],
                    'currency'         => $priced['currency'],
                    'country'          => $country !== '' ? mb_substr($country, 0, 8) : null,
                    'billing_category' => (string) $conversation['category'],
                ], 'id = :id', ['id' => (int) $first['id']]);
            }
        } catch (\Throwable) {
            // Pricing is reporting, never delivery. It must not throw into the
            // webhook path.
        }
    }

    /**
     * Country from the dialling code — enough for a rate card, and it is all we
     * have: Meta gives the recipient's wa_id, not their country.
     */
    public static function countryFromNumber(string $waId): string
    {
        $digits = preg_replace('/\D+/', '', $waId) ?? '';

        if ($digits === '') {
            return '';
        }

        // Longest prefix first, so 1 does not swallow every NANP country.
        static $codes = [
            '971' => 'AE', '966' => 'SA', '974' => 'QA', '965' => 'KW', '968' => 'OM',
            '973' => 'BH', '880' => 'BD', '977' => 'NP', '975' => 'BT', '960' => 'MV',
            '852' => 'HK', '886' => 'TW', '351' => 'PT', '353' => 'IE', '234' => 'NG',
            '254' => 'KE', '256' => 'UG', '255' => 'TZ', '212' => 'MA', '233' => 'GH',
            '92'  => 'PK', '94' => 'LK', '95' => 'MM', '91' => 'IN', '86' => 'CN',
            '81'  => 'JP', '82' => 'KR', '84' => 'VN', '66' => 'TH', '65' => 'SG',
            '63'  => 'PH', '62' => 'ID', '61' => 'AU', '64' => 'NZ', '60' => 'MY',
            '55'  => 'BR', '54' => 'AR', '57' => 'CO', '56' => 'CL', '52' => 'MX',
            '51'  => 'PE', '58' => 'VE', '49' => 'DE', '44' => 'GB', '43' => 'AT',
            '41'  => 'CH', '39' => 'IT', '34' => 'ES', '33' => 'FR', '31' => 'NL',
            '32'  => 'BE', '30' => 'GR', '48' => 'PL', '46' => 'SE', '47' => 'NO',
            '45'  => 'DK', '40' => 'RO', '90' => 'TR', '20' => 'EG', '27' => 'ZA',
            '7'   => 'RU', '1' => 'US',
        ];

        foreach ($codes as $prefix => $country) {
            if (str_starts_with($digits, (string) $prefix)) {
                return $country;
            }
        }

        return '';
    }

    /* ------------------------------------------------------------ Reporting */

    /**
     * The billing dashboard's numbers.
     *
     * @return array{
     *   total: float, currency: string, conversations: int, messages: int,
     *   by_category: array<int, array>, incomplete_rates: bool
     * }
     */
    public static function summary(int $accountId, string $from, string $to): array
    {
        $empty = [
            'total'            => 0.0,
            'currency'         => self::defaultCurrency(),
            'conversations'    => 0,
            'messages'         => 0,
            'by_category'      => [],
            'incomplete_rates' => self::ratesIncomplete(),
        ];

        try {
            $db = App::i()->db();

            $rows = $db->all(
                'SELECT category, COUNT(*) AS conversations, SUM(price) AS total, currency
                   FROM wa_conversations
                  WHERE waba_account_id = ? AND started_at >= ? AND started_at < ?
                  GROUP BY category, currency
                  ORDER BY total DESC',
                [$accountId, $from, $to]
            );

            $messages = (int) $db->value(
                'SELECT COUNT(*) FROM wa_messages
                  WHERE waba_account_id = ? AND created_at >= ? AND created_at < ?',
                [$accountId, $from, $to],
                0
            );

            $total = 0.0;
            $currency = $empty['currency'];

            foreach ($rows as $row) {
                $total += (float) $row['total'];
                $currency = (string) ($row['currency'] ?: $currency);
            }

            return [
                'total'            => round($total, 4),
                'currency'         => $currency,
                'conversations'    => array_sum(array_map(static fn ($r) => (int) $r['conversations'], $rows)),
                'messages'         => $messages,
                'by_category'      => $rows,
                'incomplete_rates' => self::ratesIncomplete(),
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /** Has anyone actually filled the rate card in? The dashboard must say so. */
    public static function ratesIncomplete(): bool
    {
        try {
            return (int) App::i()->db()->value(
                'SELECT COUNT(*) FROM wa_price_rates WHERE price > 0',
                [],
                0
            ) === 0;
        } catch (\Throwable) {
            return true;
        }
    }
}
