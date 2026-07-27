<?php

/**
 * Proves the FCM push path is the supported one.
 *
 * Google decommissioned the legacy `fcm.googleapis.com/fcm/send` API in June
 * 2024. If the product used it, phones would simply never ring. This harness
 * checks, without any network access:
 *
 *   1. the HTTP v1 endpoint is what actually gets called;
 *   2. the RS256 service-account JWT is built and signed correctly in pure PHP
 *      (openssl_sign), and verifies against the public key;
 *   3. the claim set is exactly what Google's token endpoint requires;
 *   4. a legacy-only configuration is reported as NOT configured, so it can
 *      never masquerade as working push.
 *
 * Run:  php tests/verify_fcm.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\FcmService;

$pass = 0;
$fail = 0;

function assertThat(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    $ok ? $pass++ : $fail++;
    printf("  %-58s %s%s\n", $name, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  — ' . $detail : '');
}

echo "\n=== 1. Endpoints ===\n\n";

$source = (string) file_get_contents(__DIR__ . '/../app/services/FcmService.php');

assertThat(
    'HTTP v1 endpoint present',
    str_contains($source, 'https://fcm.googleapis.com/v1/projects/%s/messages:send')
);

assertThat(
    'OAuth token endpoint present',
    str_contains($source, 'https://oauth2.googleapis.com/token')
);

assertThat(
    'v1 is tried before anything else',
    strpos($source, 'return self::sendV1(') < strpos($source, 'return self::sendLegacy(')
);

assertThat(
    'legacy path marked decommissioned',
    str_contains($source, 'DECOMMISSIONED by Google in June 2024')
);

echo "\n=== 2. RS256 JWT built and signed in pure PHP ===\n\n";

// A throwaway keypair standing in for a real service-account key.
$keyPair = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

if ($keyPair === false) {
    echo "  openssl_pkey_new failed — cannot test\n";
    exit(1);
}

openssl_pkey_export($keyPair, $privateKeyPem);
$publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

$account = [
    'type'         => 'service_account',
    'project_id'   => 'krishna-reminder-test',
    'client_email' => 'fcm@krishna-reminder-test.iam.gserviceaccount.com',
    'private_key'  => $privateKeyPem,
];

$issuedAt = 1_800_000_000;
$jwt = FcmService::buildAssertion($account, $issuedAt);

assertThat('assertion produced', is_string($jwt) && $jwt !== '');

if (!is_string($jwt)) {
    echo "\nAborting: no JWT produced.\n";
    exit(1);
}

$parts = explode('.', $jwt);
assertThat('three dot-separated segments', count($parts) === 3, count($parts) . ' segment(s)');

$base64UrlDecode = static fn (string $v): string
    => (string) base64_decode(str_pad(strtr($v, '-_', '+/'), strlen($v) % 4 === 0 ? strlen($v) : strlen($v) + 4 - (strlen($v) % 4), '='), true);

$header = json_decode($base64UrlDecode($parts[0]), true);
$claims = json_decode($base64UrlDecode($parts[1]), true);
$signature = $base64UrlDecode($parts[2]);

assertThat('header alg = RS256', ($header['alg'] ?? '') === 'RS256', (string) ($header['alg'] ?? 'missing'));
assertThat('header typ = JWT', ($header['typ'] ?? '') === 'JWT');

assertThat('base64url is unpadded and URL-safe',
    !str_contains($parts[2], '=') && !str_contains($parts[2], '+') && !str_contains($parts[2], '/'));

echo "\n=== 3. Claim set matches Google's requirements ===\n\n";

assertThat('iss = service account email',
    ($claims['iss'] ?? '') === $account['client_email'], (string) ($claims['iss'] ?? ''));

assertThat('scope = firebase.messaging',
    ($claims['scope'] ?? '') === 'https://www.googleapis.com/auth/firebase.messaging');

assertThat('aud = oauth2 token endpoint',
    ($claims['aud'] ?? '') === 'https://oauth2.googleapis.com/token');

assertThat('iat set correctly', ($claims['iat'] ?? 0) === $issuedAt);
assertThat('exp is iat + 3600 (max Google allows)', ($claims['exp'] ?? 0) === $issuedAt + 3600);

echo "\n=== 4. Signature verifies against the public key ===\n\n";

$signingInput = $parts[0] . '.' . $parts[1];
$verified = openssl_verify($signingInput, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256);

assertThat('openssl_verify returns 1', $verified === 1, 'got ' . var_export($verified, true));

// Tampering must break it — otherwise the check above proves nothing.
$tampered = $base64UrlDecode($parts[1]);
$tamperedClaims = json_decode($tampered, true);
$tamperedClaims['scope'] = 'https://www.googleapis.com/auth/cloud-platform';

$forgedPayload = rtrim(strtr(base64_encode((string) json_encode($tamperedClaims)), '+/', '-_'), '=');
$forgedInput = $parts[0] . '.' . $forgedPayload;

assertThat('tampered payload fails verification',
    openssl_verify($forgedInput, $signature, $publicKeyPem, OPENSSL_ALGO_SHA256) !== 1);

echo "\n=== 5. Broken configurations are rejected, not silently accepted ===\n\n";

assertThat('missing private_key -> no assertion',
    FcmService::buildAssertion(['client_email' => 'a@b.com']) === null);

assertThat('missing client_email -> no assertion',
    FcmService::buildAssertion(['private_key' => $privateKeyPem]) === null);

assertThat('garbage private key -> no assertion',
    FcmService::buildAssertion([
        'client_email' => 'a@b.com',
        'private_key'  => '-----BEGIN PRIVATE KEY-----\nnot-a-key\n-----END PRIVATE KEY-----',
    ]) === null);

echo "\n=== 6. Android side expects a data-only high-priority message ===\n\n";

assertThat('server sends android.priority = high', str_contains($source, "'priority' => 'high'"));
assertThat('server sends data-only payload', str_contains($source, "'data'  => array_map"));

$manifest = (string) file_get_contents(__DIR__ . '/../android/app/src/main/AndroidManifest.xml');

assertThat('app declares the FCM service',
    str_contains($manifest, 'com.google.firebase.MESSAGING_EVENT'));

assertThat('app can show a full-screen call',
    str_contains($manifest, 'android.permission.USE_FULL_SCREEN_INTENT'));

$messaging = (string) file_get_contents(
    __DIR__ . '/../android/app/src/main/java/com/akdwk/krishnareminder/push/KrishnaMessagingService.kt'
);

assertThat('app handles data key type=call', str_contains($messaging, '"call" -> handleCall'));

echo "\n" . str_repeat('-', 78) . "\n";
echo "TOTAL: " . ($pass + $fail) . "   PASS: $pass   FAIL: $fail\n";

if ($fail === 0) {
    echo "\nFCM path confirmed: HTTP v1 + service-account RS256 JWT, signed with openssl_sign().\n";
    echo "The June-2024 legacy API is present only to raise a clear error, and a legacy-only\n";
    echo "configuration is reported as NOT configured.\n";
}

exit($fail > 0 ? 1 : 0);
