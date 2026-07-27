<?php

namespace App\Services;

use App\Core\App;
use App\Core\Lang;

/**
 * Builds the sentence the phone speaks when the reminder call is answered, and
 * (optionally) renders it to MP3 server-side for devices whose on-device TTS
 * lacks a Gujarati/Hindi voice.
 */
class TtsService
{
    /**
     * "નમસ્તે અશોકભાઈ, સવારના 10 વાગી ગયા છે. તમારે ઓફિસના સ્ટાફને કોલ કરવાનો છે.
     *  કામ થઈ જાય એટલે 'થઈ ગયું' દબાવો."
     */
    public static function speech(array $reminder, array $user, ?array $occurrence = null): string
    {
        $lang = (string) ($user['language'] ?? 'gu');
        $tz = (string) ($user['timezone'] ?? 'Asia/Kolkata');
        $name = self::firstName((string) ($user['name'] ?? ''));

        $dueUtc = (string) ($occurrence['due_at'] ?? $reminder['start_at']);
        $timePhrase = self::timePhrase($dueUtc, $lang, $tz);

        $title = trim((string) $reminder['title']);
        $amount = $reminder['amount'] ?? null;
        $person = trim((string) ($reminder['person_name'] ?? ''));

        $parts = [];
        $parts[] = Lang::get('tts.greeting', ['name' => $name], $lang);
        $parts[] = Lang::get('tts.time_now', ['time' => $timePhrase], $lang);

        if (($reminder['type'] ?? '') === 'payment' && $amount !== null) {
            $parts[] = Lang::get('tts.payment_body', [
                'person' => $person !== '' ? $person : $title,
                'amount' => self::spokenAmount((float) $amount, $lang),
            ], $lang);
        } else {
            $parts[] = Lang::get('tts.task_body', ['title' => $title], $lang);
        }

        $parts[] = Lang::get('tts.instruction', [], $lang);

        return trim(preg_replace('/\s{2,}/u', ' ', implode(' ', array_filter($parts))) ?? '');
    }

    /**
     * Short text used for the notification subtitle / lock-screen line.
     */
    public static function shortSpeech(array $reminder, string $lang = 'gu'): string
    {
        return mb_substr(trim((string) $reminder['title']), 0, 120);
    }

    /**
     * Render speech to MP3 with Google Cloud Text-to-Speech when a key is set.
     * Returns a public URL under /uploads/tts, or null if unavailable.
     */
    public static function synthesise(string $text, string $lang = 'gu'): ?string
    {
        $apiKey = trim((string) App::i()->settings()->get('google_tts_api_key', ''));

        if ($apiKey === '' || $text === '') {
            return null;
        }

        $dir = App::i()->root() . '/uploads/tts';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $hash = sha1($text . '|' . $lang);
        $file = $dir . '/' . $hash . '.mp3';

        if (is_file($file) && filemtime($file) > time() - 604800) {
            return App::i()->url('/uploads/tts/' . $hash . '.mp3');
        }

        $voice = match ($lang) {
            'gu' => ['languageCode' => 'gu-IN', 'name' => 'gu-IN-Standard-A'],
            'hi' => ['languageCode' => 'hi-IN', 'name' => 'hi-IN-Standard-A'],
            default => ['languageCode' => 'en-IN', 'name' => 'en-IN-Standard-A'],
        };

        $response = HttpClient::postJson(
            'https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode($apiKey),
            [
                'input'       => ['text' => $text],
                'voice'       => $voice,
                'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => 0.95],
            ],
            [],
            20
        );

        $audio = $response['json']['audioContent'] ?? null;

        if (!is_string($audio) || $audio === '') {
            return null;
        }

        $binary = base64_decode($audio, true);

        if ($binary === false || @file_put_contents($file, $binary) === false) {
            return null;
        }

        return App::i()->url('/uploads/tts/' . $hash . '.mp3');
    }

    /* -------------------------------------------------------------- Helpers */

    private static function timePhrase(string $utc, string $lang, string $tz): string
    {
        try {
            $dt = new \DateTime($utc, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz));
        } catch (\Throwable) {
            return '';
        }

        $hour = (int) $dt->format('G');
        $minute = (int) $dt->format('i');

        $partKey = match (true) {
            $hour < 12 => 'morning',
            $hour < 16 => 'afternoon',
            $hour < 20 => 'evening',
            default    => 'night',
        };

        $part = Lang::get('tts.part_' . $partKey, [], $lang);
        $display = $dt->format('g');

        if ($minute > 0) {
            $display .= ':' . $dt->format('i');
        }

        return trim($part . ' ' . $display);
    }

    private static function spokenAmount(float $amount, string $lang): string
    {
        if ($amount >= 10000000) {
            return rtrim(rtrim(number_format($amount / 10000000, 2), '0'), '.') . ' ' . Lang::get('number.crore', [], $lang);
        }

        if ($amount >= 100000) {
            return rtrim(rtrim(number_format($amount / 100000, 2), '0'), '.') . ' ' . Lang::get('number.lakh', [], $lang);
        }

        if ($amount >= 1000 && fmod($amount, 1000.0) === 0.0) {
            return (string) (int) ($amount / 1000) . ' ' . Lang::get('number.thousand', [], $lang);
        }

        return number_format($amount, ($amount == (int) $amount) ? 0 : 2);
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];

        return (string) ($parts[0] ?? '');
    }
}
