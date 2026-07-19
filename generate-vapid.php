<?php
declare(strict_types=1);
/**
 * Generate VAPID keys for Web Push notification (RFC 8292).
 * Uses ECDSA P-256 (compatible with Web Push protocol).
 * Run once: php generate-vapid.php
 */

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Generate EC P-256 key pair
$privKey = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if (!$privKey) {
    die("Failed to generate key pair. Ensure openssl extension is loaded.\n");
}

$details = openssl_pkey_get_details($privKey);
$publicKey = base64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);
$privateKey = base64url_encode($details['ec']['d']);

echo "VAPID Keys Generated (ECDSA P-256):\n";
echo "====================================\n";
echo "Public Key:\n  " . $publicKey . "\n\n";
echo "Private Key:\n  " . $privateKey . "\n\n";

// Save to file for reference
$keyFile = __DIR__ . '/vapid-keys.json';
file_put_contents($keyFile, json_encode([
    'public_key' => $publicKey,
    'private_key' => $privateKey,
    'subject' => 'mailto:admin@adammuiz.com',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Keys saved to: {$keyFile}\n\n";
echo "Add to CMS settings (database):\n";
echo "  push_vapid_public_key  = {$publicKey}\n";
echo "  push_vapid_private_key = {$privateKey}\n";
echo "  push_vapid_subject     = mailto:admin@adammuiz.com\n";
