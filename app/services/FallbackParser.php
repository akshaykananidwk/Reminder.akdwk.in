<?php

namespace App\Services;

use App\Core\App;

/**
 * Pure-PHP natural language parser for Gujarati, Hindi and English.
 *
 * Used whenever Gemini is unavailable, over quota, or returns low confidence.
 * It must never throw: the worst outcome is a Note with no time, which the
 * caller then asks the user to complete.
 */
class FallbackParser
{
    /** Weekday keyword => ISO weekday number (1 = Monday). */
    private const WEEKDAYS = [
        'સોમવાર' => 1, 'મંગળવાર' => 2, 'બુધવાર' => 3, 'ગુરુવાર' => 4, 'ગુરૂવાર' => 4,
        'શુક્રવાર' => 5, 'શનિવાર' => 6, 'રવિવાર' => 7,
        'सोमवार' => 1, 'मंगलवार' => 2, 'बुधवार' => 3, 'गुरुवार' => 4, 'बृहस्पतिवार' => 4,
        'शुक्रवार' => 5, 'शनिवार' => 6, 'रविवार' => 7, 'इतवार' => 7,
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
        'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
    ];

    private const DAY_CODES = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    /** Part-of-day keyword => default hour. */
    private const DAY_PARTS = [
        'સવારે' => 9, 'સવાર' => 9, 'બપોરે' => 14, 'બપોર' => 14, 'સાંજે' => 18, 'સાંજ' => 18,
        'રાત્રે' => 21, 'રાતે' => 21, 'રાત' => 21, 'બપોરના' => 14, 'સવારના' => 9,
        'सुबह' => 9, 'दोपहर' => 14, 'शाम' => 18, 'रात' => 21, 'सवेरे' => 9,
        'morning' => 9, 'afternoon' => 14, 'evening' => 18, 'night' => 21, 'noon' => 12,
    ];

    private const MONTHS = [
        'જાન્યુઆરી' => 1, 'ફેબ્રુઆરી' => 2, 'માર્ચ' => 3, 'એપ્રિલ' => 4, 'મે' => 5, 'જૂન' => 6,
        'જુલાઈ' => 7, 'ઓગસ્ટ' => 8, 'સપ્ટેમ્બર' => 9, 'ઓક્ટોબર' => 10, 'નવેમ્બર' => 11, 'ડિસેમ્બર' => 12,
        'जनवरी' => 1, 'फरवरी' => 2, 'मार्च' => 3, 'अप्रैल' => 4, 'मई' => 5, 'जून' => 6,
        'जुलाई' => 7, 'अगस्त' => 8, 'सितंबर' => 9, 'अक्टूबर' => 10, 'नवंबर' => 11, 'दिसंबर' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /** Spelled-out numerals used for "two hours later" style phrases. */
    private const NUMBER_WORDS = [
        'એક' => 1, 'બે' => 2, 'ત્રણ' => 3, 'ચાર' => 4, 'પાંચ' => 5, 'છ' => 6, 'સાત' => 7,
        'આઠ' => 8, 'નવ' => 9, 'દસ' => 10, 'અગિયાર' => 11, 'બાર' => 12,
        'एक' => 1, 'दो' => 2, 'तीन' => 3, 'चार' => 4, 'पांच' => 5, 'पाँच' => 5, 'छह' => 6,
        'सात' => 7, 'आठ' => 8, 'नौ' => 9, 'दस' => 10, 'ग्यारह' => 11, 'बारह' => 12,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6,
        'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12,
    ];

    public static function parse(string $text, array $user): array
    {
        $envelope = GeminiService::emptyEnvelope();
        $original = trim($text);

        if ($original === '') {
            return $envelope;
        }

        $lang = detect_language($original, (string) ($user['language'] ?? 'gu'));
        $envelope['language'] = $lang;

        $tz = (string) ($user['timezone'] ?? App::i()->config('app.timezone', 'Asia/Kolkata'));

        try {
            $now = new \DateTime('now', new \DateTimeZone($tz));
        } catch (\Throwable) {
            $tz = 'Asia/Kolkata';
            $now = new \DateTime('now', new \DateTimeZone($tz));
        }

        $lower = self::normaliseDigits(mb_strtolower($original));

        // --- Intent -------------------------------------------------------
        $intent = self::detectIntent($lower);
        $envelope['intent'] = $intent;

        if (in_array($intent, ['list', 'summary', 'help'], true)) {
            return $envelope;
        }

        // --- Amount -------------------------------------------------------
        $amount = self::extractAmount($lower);

        // --- Type ---------------------------------------------------------
        $type = self::detectType($lower, $amount);

        // --- Recurrence ---------------------------------------------------
        $recurrence = self::extractRecurrence($lower);

        // --- Due time -----------------------------------------------------
        $defaultTime = (string) ($user['default_time'] ?? '09:00:00');
        $due = self::extractDateTime($lower, $now, $tz, $defaultTime, $recurrence);

        // --- Title --------------------------------------------------------
        $title = self::cleanTitle($original);

        if ($title === '') {
            $title = mb_substr($original, 0, 120);
        }

        $envelope['intent'] = $intent === 'unknown' ? 'create' : $intent;

        $envelope['items'][] = [
            'title'              => mb_substr($title, 0, 255),
            'description'        => '',
            'type'               => $type,
            'due_at'             => $due['iso'],
            'all_day'            => $due['all_day'],
            'recurrence'         => $recurrence,
            'priority'           => self::detectPriority($lower),
            'call_reminder'      => true,
            'advance_alerts_min' => [],
            'snooze_default_min' => 5,
            'person'             => ['name' => self::extractPerson($original, $lang), 'phone' => null],
            'amount'             => $amount,
            'currency'           => 'INR',
            'location'           => null,
            'tags'               => [],
            // Deliberately conservative — this parser is the safety net, and the
            // caller uses the score to decide whether to ask for confirmation.
            'confidence'         => $due['found'] ? 0.62 : 0.35,
        ];

        if (!$due['found']) {
            $envelope['needs_confirmation'] = true;
            $envelope['question'] = match ($lang) {
                'gu' => 'ક્યારે યાદ કરાવું? (તારીખ અને સમય લખો)',
                'hi' => 'कब याद दिलाऊं? (तारीख और समय लिखें)',
                default => 'When should I remind you? (please send date and time)',
            };
        }

        return $envelope;
    }

    /* --------------------------------------------------------------- Intent */

    private static function detectIntent(string $text): string
    {
        $map = [
            'list'    => ['યાદી', 'લિસ્ટ', 'सूची', 'लिस्ट', 'list', 'આજે શું', 'आज क्या'],
            'summary' => ['સારાંશ', 'રિપોર્ટ', 'सारांश', 'रिपोर्ट', 'summary', 'report'],
            'help'    => ['મદદ', 'મદદ કરો', 'मदद', 'help', 'kaise', 'કેવી રીતે'],
            'cancel'  => ['રદ કરો', 'કેન્સલ', 'रद्द', 'कैंसिल', 'cancel'],
            'complete'=> ['થઈ ગયું', 'પૂરું થયું', 'हो गया', 'पूरा हो गया', 'done', 'completed'],
            // "Keep a note", "write this down", a shopping list — the user is
            // asking us to remember *text*, not to ring them at a time. Without
            // this the message fell through to `create` and became a reminder
            // at the default time, which is not what was asked for and puts a
            // pointless alarm on the calendar.
            'note'    => [
                'નોટ', 'નોંધ', 'લખી રાખ', 'લખી લે', 'ટાંકી રાખ', 'યાદ રાખવા માટે લખ',
                'नोट', 'लिख लो', 'लिख लीजिए', 'लिखकर रखो',
                'note this', 'make a note', 'take a note', 'write this down', 'jot ',
                'shopping list', 'save this',
            ],
        ];

        foreach ($map as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $intent;
                }
            }
        }

        return 'create';
    }

    private static function detectType(string $text, ?float $amount): string
    {
        $signals = [
            'payment'  => ['ચૂકવ', 'પૈસા', 'રૂપિયા', 'ઉઘરાણી', 'આપવા', 'લેવાના', 'पैसे', 'रुपये', 'भुगतान', 'देना', 'payment', 'pay ', 'rupees', '₹'],
            'call'     => ['કોલ', 'ફોન', 'કૉલ', 'कॉल', 'फोन', 'call', 'phone'],
            'meeting'  => ['મીટિંગ', 'બેઠક', 'मीटिंग', 'बैठक', 'meeting'],
            'medicine' => ['દવા', 'ગોળી', 'दवा', 'गोली', 'medicine', 'tablet', 'dose'],
            'birthday' => ['જન્મદિવસ', 'બર્થડે', 'जन्मदिन', 'birthday', 'anniversary', 'લગ્નતિથિ'],
            'bill'     => ['બિલ', 'લાઇટ બિલ', 'बिल', 'bill', 'electricity', 'recharge'],
        ];

        foreach ($signals as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $type;
                }
            }
        }

        return $amount !== null ? 'payment' : 'task';
    }

    private static function detectPriority(string $text): string
    {
        foreach (['અર્જન્ટ', 'તાત્કાલિક', 'જરૂરી', 'अर्जेंट', 'तुरंत', 'ज़रूरी', 'urgent', 'asap', 'immediately'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'urgent';
            }
        }

        foreach (['મહત્વ', 'महत्वपूर्ण', 'important', 'high priority'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return 'high';
            }
        }

        return 'normal';
    }

    /* --------------------------------------------------------------- Amount */

    private static function extractAmount(string $text): ?float
    {
        $multipliers = [
            'હજાર' => 1000, 'હજા' => 1000, 'લાખ' => 100000, 'કરોડ' => 10000000,
            'हजार' => 1000, 'हज़ार' => 1000, 'लाख' => 100000, 'करोड़' => 10000000,
            'thousand' => 1000, 'lakh' => 100000, 'lac' => 100000, 'crore' => 10000000, 'k' => 1000,
        ];

        foreach ($multipliers as $word => $factor) {
            if (preg_match('/(\d+(?:\.\d+)?)\s*' . preg_quote($word, '/') . '/u', $text, $m)) {
                return (float) $m[1] * $factor;
            }

            foreach (self::NUMBER_WORDS as $numberWord => $value) {
                if (str_contains($text, $numberWord . ' ' . $word)) {
                    return (float) ($value * $factor);
                }
            }
        }

        // ₹5000 / rs 5000 / 5000 રૂપિયા / 5000 rupees
        $patterns = [
            '/₹\s*(\d[\d,]*(?:\.\d+)?)/u',
            '/\brs\.?\s*(\d[\d,]*(?:\.\d+)?)/iu',
            '/(\d[\d,]*(?:\.\d+)?)\s*(?:રૂપિયા|રૂ\.?|रुपये|रु\.?|rupees|rupee)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return (float) str_replace(',', '', $m[1]);
            }
        }

        // A bare 3+ digit number next to a payment verb.
        if (preg_match('/(?:ચૂકવ|આપવા|લેવા|भुगतान|देना|लेना|pay|paid|collect)\D{0,15}(\d{3,})/u', $text, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /* ----------------------------------------------------------- Recurrence */

    private static function extractRecurrence(string $text): array
    {
        $recurrence = ['freq' => 'none', 'interval' => 1, 'by_day' => [], 'by_month_day' => null, 'until' => null, 'count' => null];

        // Every N days: "દર 3 દિવસે", "हर 3 दिन", "every 3 days"
        if (preg_match('/(?:દર|हर|every)\s*(\d+)\s*(?:દિવસ|दिन|days?)/u', $text, $m)) {
            $recurrence['freq'] = 'daily';
            $recurrence['interval'] = max(1, (int) $m[1]);

            return $recurrence;
        }

        // Monthly on a day number: "દર મહિને 5 તારીખે", "हर महीने 5 तारीख", "5th of every month"
        if (preg_match('/(?:દર\s*મહિને|हर\s*महीने|हर\s*माह|every\s*month|monthly)\D{0,12}(\d{1,2})/u', $text, $m)) {
            $recurrence['freq'] = 'monthly';
            $recurrence['by_month_day'] = max(1, min(31, (int) $m[1]));

            return $recurrence;
        }

        if (preg_match('/(\d{1,2})\s*(?:તારીખે|तारीख|तारीख़)\D{0,12}(?:દર\s*મહિને|हर\s*महीने)/u', $text, $m)) {
            $recurrence['freq'] = 'monthly';
            $recurrence['by_month_day'] = max(1, min(31, (int) $m[1]));

            return $recurrence;
        }

        // Weekly on a weekday: "દર સોમવારે", "हर सोमवार", "every monday"
        foreach (self::WEEKDAYS as $word => $iso) {
            if (preg_match('/(?:દર|हर|every)\s*' . preg_quote($word, '/') . '/u', $text)) {
                $recurrence['freq'] = 'weekly';
                $recurrence['by_day'] = [self::DAY_CODES[$iso]];

                return $recurrence;
            }
        }

        foreach (['દરરોજ', 'રોજ', 'દરેક દિવસે', 'रोज', 'रोज़', 'हर दिन', 'प्रतिदिन', 'daily', 'every day', 'everyday'] as $word) {
            if (str_contains($text, $word)) {
                $recurrence['freq'] = 'daily';

                return $recurrence;
            }
        }

        foreach (['દર અઠવાડિયે', 'હર અઠવાડિયે', 'हर हफ्ते', 'हर सप्ताह', 'weekly', 'every week'] as $word) {
            if (str_contains($text, $word)) {
                $recurrence['freq'] = 'weekly';

                return $recurrence;
            }
        }

        foreach (['દર મહિને', 'हर महीने', 'monthly', 'every month'] as $word) {
            if (str_contains($text, $word)) {
                $recurrence['freq'] = 'monthly';

                return $recurrence;
            }
        }

        foreach (['દર વર્ષે', 'हर साल', 'yearly', 'every year', 'annually'] as $word) {
            if (str_contains($text, $word)) {
                $recurrence['freq'] = 'yearly';

                return $recurrence;
            }
        }

        return $recurrence;
    }

    /* ------------------------------------------------------------- Datetime */

    /**
     * @return array{iso: string|null, all_day: bool, found: bool}
     */
    private static function extractDateTime(string $text, \DateTime $now, string $tz, string $defaultTime, array $recurrence): array
    {
        $target = clone $now;
        $foundDate = false;
        $foundTime = false;

        // 1. Relative offsets: "15 મિનિટ પછી", "दो घंटे बाद", "in 2 hours"
        $relative = self::extractRelativeOffset($text);

        if ($relative !== null) {
            $target->modify('+' . $relative . ' seconds');

            return ['iso' => $target->format('c'), 'all_day' => false, 'found' => true];
        }

        // 2. Explicit date: 5/8/2026, 5-8, 5 ઓગસ્ટ
        if (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})(?:[\/\-.](\d{2,4}))?\b/u', $text, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = isset($m[3]) ? (int) $m[3] : (int) $now->format('Y');

            if ($year < 100) {
                $year += 2000;
            }

            if (checkdate($month, $day, $year)) {
                $target->setDate($year, $month, $day);
                $foundDate = true;
            }
        }

        if (!$foundDate) {
            foreach (self::MONTHS as $name => $month) {
                if (preg_match('/(\d{1,2})\s*' . preg_quote($name, '/') . '|' . preg_quote($name, '/') . '\s*(\d{1,2})/u', $text, $m)) {
                    $day = (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0));

                    if ($day >= 1 && $day <= 31) {
                        $year = (int) $now->format('Y');
                        $candidate = (clone $target)->setDate($year, $month, min($day, (int) date('t', mktime(0, 0, 0, $month, 1, $year))));

                        if ($candidate < $now) {
                            $candidate->modify('+1 year');
                        }

                        $target = $candidate;
                        $foundDate = true;
                    }
                    break;
                }
            }
        }

        // 3. Day-of-month only: "5 તારીખે", "5 तारीख को"
        if (!$foundDate && preg_match('/(\d{1,2})\s*(?:તારીખે|તારીખ|तारीख|तारीख़)/u', $text, $m)) {
            $day = max(1, min(31, (int) $m[1]));
            $candidate = clone $target;
            $daysInMonth = (int) $candidate->format('t');
            $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('n'), min($day, $daysInMonth));

            if ($candidate < $now) {
                $candidate->modify('first day of next month');
                $daysInMonth = (int) $candidate->format('t');
                $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('n'), min($day, $daysInMonth));
            }

            $target = $candidate;
            $foundDate = true;
        }

        // 4. Relative day words.
        if (!$foundDate) {
            $dayWords = [
                0 => ['આજે', 'આજ', 'आज', 'today', 'tonight', 'આજ રાત્રે'],
                1 => ['કાલે', 'આવતીકાલે', 'કાલ', 'कल', 'tomorrow'],
                2 => ['પરમ દિવસે', 'પરમદિવસે', 'પરમ', 'परसों', 'day after tomorrow'],
            ];

            foreach ($dayWords as $offset => $words) {
                foreach ($words as $word) {
                    if (str_contains($text, $word)) {
                        $target->modify('+' . $offset . ' days');
                        $foundDate = true;
                        break 2;
                    }
                }
            }
        }

        // 5. Weekday: "આવતા શુક્રવારે", "अगले शुक्रवार", "next friday", "શુક્રવારે"
        if (!$foundDate) {
            foreach (self::WEEKDAYS as $word => $iso) {
                if (!str_contains($text, $word)) {
                    continue;
                }

                $isNext = (bool) preg_match('/(?:આવતા|આવતી|अगले|अगली|next)\s*' . preg_quote($word, '/') . '/u', $text);
                $currentIso = (int) $target->format('N');
                $delta = ($iso - $currentIso + 7) % 7;

                if ($delta === 0) {
                    $delta = 7;
                }

                if ($isNext && $delta < 7) {
                    // "next Friday" means the Friday of the coming week.
                    $delta += ($delta <= (7 - $currentIso)) ? 0 : 0;
                }

                $target->modify('+' . $delta . ' days');
                $foundDate = true;
                break;
            }
        }

        // 6. "N દિવસ પછી" / "after 3 days"
        if (!$foundDate && preg_match('/(\d+)\s*(?:દિવસ|दिन|days?)\s*(?:પછી|બાદ|बाद|later|after)/u', $text, $m)) {
            $target->modify('+' . (int) $m[1] . ' days');
            $foundDate = true;
        }

        if (!$foundDate && preg_match('/(?:અઠવાડિયા|હફ્તા|हफ्ते|सप्ताह|week)\s*(?:પછી|બાદ|बाद|later)/u', $text)) {
            $target->modify('+7 days');
            $foundDate = true;
        }

        // 7. Explicit clock time.
        $time = self::extractClockTime($text);

        if ($time !== null) {
            $target->setTime($time[0], $time[1], 0);
            $foundTime = true;
        } else {
            // Part of day only: "સવારે", "शाम", "evening".
            foreach (self::DAY_PARTS as $word => $hour) {
                if (str_contains($text, $word)) {
                    $target->setTime($hour, 0, 0);
                    $foundTime = true;
                    break;
                }
            }
        }

        if (!$foundTime) {
            [$h, $i] = array_pad(array_map('intval', explode(':', $defaultTime)), 2, 0);
            $target->setTime($h, $i, 0);
        }

        // Monthly-by-day recurrence overrides the date component.
        if ($recurrence['freq'] === 'monthly' && $recurrence['by_month_day'] !== null) {
            $day = $recurrence['by_month_day'];
            $daysInMonth = (int) $target->format('t');
            $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($day, $daysInMonth));

            if ($target <= $now) {
                $target->modify('first day of next month');
                $daysInMonth = (int) $target->format('t');
                $target->setDate((int) $target->format('Y'), (int) $target->format('n'), min($day, $daysInMonth));
                [$h, $i] = [(int) $target->format('G'), (int) $target->format('i')];
                $target->setTime($h, $i, 0);
            }

            $foundDate = true;
        }

        if ($recurrence['freq'] === 'weekly' && $recurrence['by_day'] !== []) {
            $iso = array_search($recurrence['by_day'][0], self::DAY_CODES, true);

            if ($iso !== false) {
                $currentIso = (int) $target->format('N');
                $delta = ($iso - $currentIso + 7) % 7;

                if ($delta === 0 && $target <= $now) {
                    $delta = 7;
                }

                $target->modify('+' . $delta . ' days');
                $foundDate = true;
            }
        }

        // Never schedule in the past: roll forward sensibly.
        if ($target <= $now) {
            if (!$foundDate) {
                $target->modify('+1 day');
            } else {
                while ($target <= $now) {
                    $target->modify('+1 day');
                }
            }
        }

        return [
            'iso'     => $target->format('c'),
            'all_day' => !$foundTime,
            'found'   => $foundDate || $foundTime,
        ];
    }

    /** "15 મિનિટ પછી" / "2 घंटे बाद" / "in 30 minutes" -> seconds. */
    private static function extractRelativeOffset(string $text): ?int
    {
        $units = [
            'minute' => ['મિનિટ', 'मिनट', 'minutes', 'minute', 'min', 'mins'],
            'hour'   => ['કલાક', 'घंटे', 'घंटा', 'hours', 'hour', 'hrs', 'hr'],
        ];

        $laterWords = 'પછી|બાદ|बाद|later|after|in';

        foreach ($units as $unit => $words) {
            $multiplier = $unit === 'minute' ? 60 : 3600;

            foreach ($words as $word) {
                $quoted = preg_quote($word, '/');

                if (preg_match('/(\d+)\s*' . $quoted . '\s*(?:' . $laterWords . ')?/u', $text, $m)) {
                    // Guard against matching a clock time like "10 વાગ્યે".
                    if (preg_match('/(?:' . $laterWords . ')/u', $text)) {
                        return (int) $m[1] * $multiplier;
                    }
                }

                if (preg_match('/(?:in|after)\s*(\d+)\s*' . $quoted . '/iu', $text, $m)) {
                    return (int) $m[1] * $multiplier;
                }

                foreach (self::NUMBER_WORDS as $numberWord => $value) {
                    if (preg_match('/' . preg_quote($numberWord, '/') . '\s*' . $quoted . '\s*(?:' . $laterWords . ')/u', $text)) {
                        return $value * $multiplier;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int}|null [hour, minute] in 24h
     */
    private static function extractClockTime(string $text): ?array
    {
        // 10:30 / 10.30 with optional am/pm
        if (preg_match('/\b(\d{1,2})[:.](\d{2})\s*(am|pm|સવારે|બપોરે|સાંજે|રાત્રે|सुबह|दोपहर|शाम|रात)?/u', $text, $m)) {
            $hour = (int) $m[1];
            $minute = (int) $m[2];

            return [self::applyMeridiem($hour, $m[3] ?? '', $text), min(59, $minute)];
        }

        // 10 વાગ્યે / 10 बजे / at 10 / 10 am
        if (preg_match('/\b(\d{1,2})\s*(?:વાગ્યે|વાગે|बजे|o\'?clock|am|pm)/u', $text, $m)) {
            return [self::applyMeridiem((int) $m[1], '', $text), 0];
        }

        if (preg_match('/\bat\s+(\d{1,2})\b/iu', $text, $m)) {
            return [self::applyMeridiem((int) $m[1], '', $text), 0];
        }

        return null;
    }

    /**
     * Decide AM/PM from an explicit marker, an Indian part-of-day word, or the
     * common-sense rule that 1–6 without a marker means afternoon/evening.
     */
    private static function applyMeridiem(int $hour, string $marker, string $text): int
    {
        if ($hour > 23) {
            $hour %= 24;
        }

        $isPm = str_contains($text, 'pm')
            || str_contains($text, 'બપોરે') || str_contains($text, 'સાંજે') || str_contains($text, 'રાત્રે') || str_contains($text, 'રાતે')
            || str_contains($text, 'दोपहर') || str_contains($text, 'शाम') || str_contains($text, 'रात');

        $isAm = str_contains($text, 'am') || str_contains($text, 'સવારે') || str_contains($text, 'सुबह') || str_contains($text, 'morning');

        if ($marker !== '') {
            $isPm = $isPm || in_array($marker, ['pm', 'બપોરે', 'સાંજે', 'રાત્રે', 'दोपहर', 'शाम', 'रात'], true);
            $isAm = $isAm || in_array($marker, ['am', 'સવારે', 'सुबह'], true);
        }

        if ($isPm && $hour < 12) {
            return $hour + 12;
        }

        if ($isAm && $hour === 12) {
            return 0;
        }

        if (!$isAm && !$isPm && $hour >= 1 && $hour <= 6) {
            return $hour + 12; // "મળવાનું છે 4 વાગ્યે" almost always means 4 PM.
        }

        return $hour;
    }

    /* ---------------------------------------------------------------- Title */

    /** Strip the date/time scaffolding so the title reads like a task. */
    private static function cleanTitle(string $text): string
    {
        $patterns = [
            '/\b\d{1,2}[:.]\d{2}\s*(am|pm)?/iu',
            '/\b\d{1,2}\s*(?:વાગ્યે|વાગે|बजे|am|pm|o\'?clock)/iu',
            '/\b\d{1,2}[\/\-.]\d{1,2}(?:[\/\-.]\d{2,4})?\b/u',
            '/(?:આજે|કાલે|આવતીકાલે|પરમ દિવસે|आज|कल|परसों|today|tomorrow|day after tomorrow)/u',
            '/(?:સવારે|બપોરે|સાંજે|રાત્રે|રાતે|सुबह|दोपहर|शाम|रात|morning|afternoon|evening|tonight)/u',
            '/(?:દર|हर|every)\s*\S+/u',
            '/(?:દરરોજ|रोज़?|daily|everyday)/u',
            '/\d+\s*(?:મિનિટ|કલાક|दिन|मिनट|घंटे|minutes?|hours?|days?)\s*(?:પછી|બાદ|बाद|later|after)?/u',
            '/\b(?:at|on|in)\s+\d+\b/iu',
            '/\d{1,2}\s*(?:તારીખે|तारीख)/u',
        ];

        $title = $text;

        foreach ($patterns as $pattern) {
            $title = preg_replace($pattern, ' ', $title) ?? $title;
        }

        $title = preg_replace('/\s{2,}/u', ' ', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B-,.·");

        return $title;
    }

    private static function extractPerson(string $text, string $lang): ?string
    {
        // Gujarati/Hindi honorifics are a reliable person marker.
        if (preg_match('/([\x{0A80}-\x{0AFF}\x{0900}-\x{097F}A-Za-z]+(?:ભાઈ|બેન|भाई|बहन|जी|साहब))/u', $text, $m)) {
            return mb_substr($m[1], 0, 120);
        }

        return null;
    }

    /* -------------------------------------------------------------- Digits */

    /** Convert Gujarati/Devanagari digits to ASCII so the regexes above work. */
    public static function normaliseDigits(string $text): string
    {
        $map = [
            '૦' => '0', '૧' => '1', '૨' => '2', '૩' => '3', '૪' => '4',
            '૫' => '5', '૬' => '6', '૭' => '7', '૮' => '8', '૯' => '9',
            '०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
            '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9',
        ];

        return strtr($text, $map);
    }
}
