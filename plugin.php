<?php
declare(strict_types=1);
/**
 * Browser Push Notifications Plugin v1.0.5
 * Browser push notifications via Web Push API (VAPID).
 */

if (!defined('BACKEND_PATH')) return;

// ── Schema ──────────────────────────────────────────

function jyavani_push_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        endpoint VARCHAR(500) NOT NULL,
        p256dh_key VARCHAR(255) NOT NULL,
        auth_key VARCHAR(255) NOT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_endpoint (endpoint(200))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS push_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        url VARCHAR(500) DEFAULT NULL,
        icon VARCHAR(255) DEFAULT NULL,
        sent_count INT DEFAULT 0,
        fail_count INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Settings ────────────────────────────────────────

function jyavani_push_settings(PDO $pdo): array {
    $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` LIKE 'push_%'");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    return $settings;
}

function jyavani_push_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? $row['value'] : $default;
}

function jyavani_push_save_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([$key, $value]);
}

// ── Helpers ─────────────────────────────────────────

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function pem_encode_ec_public_key(string $rawPoint): string {
    // Build SPKI DER for EC P-256 public key
    $oidEcPublicKey = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    $oidPrime256v1  = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $algorithmSeq = chr(0x30) . chr(strlen($oidEcPublicKey) + strlen($oidPrime256v1))
                    . $oidEcPublicKey . $oidPrime256v1;
    $bitString = "\x03" . chr(strlen($rawPoint) + 1) . "\x00" . $rawPoint;
    $der = chr(0x30) . chr(strlen($algorithmSeq) + strlen($bitString))
           . $algorithmSeq . $bitString;
    return "-----BEGIN PUBLIC KEY-----\n"
           . chunk_split(base64_encode($der), 64, "\n")
           . "-----END PUBLIC KEY-----\n";
}

function hkdf_extract(string $salt, string $ikm): string {
    return hash_hmac('sha256', $ikm, $salt, true);
}

function hkdf_expand(string $prk, string $info, int $length): string {
    $t = '';
    $last = '';
    $blocks = ceil($length / 32);
    for ($i = 1; $i <= $blocks; $i++) {
        $last = hash_hmac('sha256', $last . $info . chr($i), $prk, true);
        $t .= $last;
    }
    return substr($t, 0, $length);
}

function jyavani_push_encrypt(string $payload, string $userPublicKey, string $userAuth): string {
    // Generate ephemeral key pair
    $ephKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ephDetails = openssl_pkey_get_details($ephKey);
    $ephPublicRaw = "\x04" . $ephDetails['ec']['x'] . $ephDetails['ec']['y'];
    $salt = random_bytes(16);

    // ECDH shared secret
    $userPubPem = pem_encode_ec_public_key($userPublicKey);
    $userPubKeyObj = openssl_pkey_get_public($userPubPem);
    if (!$userPubKeyObj) {
        openssl_pkey_free($ephKey);
        return '';
    }
    $sharedSecret = openssl_pkey_derive($userPubKeyObj, $ephKey);
    openssl_pkey_free($ephKey);
    openssl_pkey_free($userPubKeyObj);

    // PRK = HMAC-SHA256(auth, shared_secret)
    $prk = hkdf_extract($userAuth, $sharedSecret);

    // Build context
    $context = chr(strlen($userPublicKey)) . $userPublicKey
              . chr(strlen($ephPublicRaw)) . $ephPublicRaw;

    // Derive CEK and Nonce
    $cekInfo = "Content-Encoding: aes128gcm\0" . $context;
    $nonceInfo = "Content-Encoding: nonce\0" . $context;
    $cek = hkdf_expand($prk, $cekInfo, 16);
    $nonce = hkdf_expand($prk, $nonceInfo, 12);

    // Build header for AAD
    $recordSize = pack('N', 4096);
    $header = $salt . $recordSize . chr(65) . $ephPublicRaw;

    // Encrypt with AES-128-GCM, AAD = header (RFC 8188)
    $tag = '';
    $ciphertext = openssl_encrypt($payload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, $header, 16);

    // Build output: header || ciphertext || tag
    return $header . $ciphertext . $tag;
}

// ── Web Push Send ───────────────────────────────────

function jyavani_push_send(array $subscription, string $title, string $body, string $url = '', string $icon = '', ?PDO $pdo = null): bool {
    $settings = $pdo ? jyavani_push_settings($pdo) : [];
    $vapidPublicKey = $settings['push_vapid_public_key'] ?? '';
    $vapidPrivateKey = $settings['push_vapid_private_key'] ?? '';
    $vapidSubject = $settings['push_vapid_subject'] ?? 'mailto:admin@adammuiz.com';

    if (empty($vapidPublicKey) || empty($vapidPrivateKey)) {
        error_log('[jyavani-push] VAPID keys not configured');
        return false;
    }

    $payload = json_encode([
        'title' => $title,
        'body' => $body,
        'url' => $url,
        'icon' => $icon ?: ($settings['push_default_icon'] ?? '/static/icons/lucide/bell.svg'),
        'badge' => '/static/icons/lucide/bell.svg',
        'timestamp' => time() * 1000,
    ]);

    // Decrypt the subscription keys
    $endpoint = $subscription['endpoint'];
    $p256dh = $subscription['p256dh_key'];
    $auth = $subscription['auth_key'];

    // Build the JWT for VAPID
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $now = time();
    $claims = base64url_encode(json_encode([
        'aud' => parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST),
        'exp' => $now + 43200,
        'sub' => $vapidSubject,
    ]));

    // Sign with ECDSA P-256
    $signingInput = $header . '.' . $claims;
    $pem = base64_decode($vapidPrivateKey);
    $key = openssl_pkey_get_private($pem);
    if (!$key) {
        error_log('[jyavani-push] Failed to load VAPID private key');
        return false;
    }

    $signature = '';
    $signed = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);
    openssl_pkey_free($key);

    if (!$signed) {
        error_log('[jyavani-push] Failed to sign VAPID token');
        return false;
    }

    // Extract r and s from the signature
    $sigDer = $signature;
    $seq = [];
    // Parse DER-encoded ECDSA signature
    if (ord($sigDer[0]) === 0x30) {
        $offset = 2;
        // Read r
        $rLen = ord($sigDer[$offset + 1]);
        $r = substr($sigDer, $offset + 2, $rLen);
        $offset += 2 + $rLen;
        // Read s
        $sLen = ord($sigDer[$offset + 1]);
        $s = substr($sigDer, $offset + 2, $sLen);
        // Pad/trim to 32 bytes each
        $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        $signature = base64url_encode($r . $s);
    }

    $jwt = $signingInput . '.' . $signature;

    // Encrypt the payload using ECDH
    $userPublicKeyRaw = base64url_decode($p256dh);
    $userAuthRaw = base64url_decode($auth);
    $encryptedPayload = jyavani_push_encrypt($payload, $userPublicKeyRaw, $userAuthRaw);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encryptedPayload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($encryptedPayload),
            'TTL: 86400',
            'Urgency: normal',
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublicKey,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // 201 = created (success), 410 = subscription expired
    if ($httpCode === 201) {
        return true;
    } elseif ($httpCode === 410 && $pdo) {
        // Subscription expired, deactivate
        $stmt = $pdo->prepare("UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = ?");
        $stmt->execute([$endpoint]);
    }

    error_log("[jyavani-push] Send failed: HTTP {$httpCode} for endpoint: " . substr($endpoint, 0, 50) . "...");
    return false;
}

function jyavani_push_broadcast(PDO $pdo, string $title, string $body, string $url = '', string $icon = ''): array {
    $stmt = $pdo->query("SELECT * FROM push_subscriptions WHERE is_active = 1");
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0;
    $failed = 0;

    foreach ($subscriptions as $sub) {
        $result = jyavani_push_send($sub, $title, $body, $url, $icon, $pdo);
        if ($result) {
            $sent++;
        } else {
            $failed++;
        }
    }

    // Log the notification
    $stmt = $pdo->prepare("INSERT INTO push_notifications (title, body, url, icon, sent_count, fail_count, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$title, $body, $url, $icon, $sent, $failed]);

    return ['sent' => $sent, 'failed' => $failed, 'total' => count($subscriptions)];
}

// ── Admin Routes (AJAX) ─────────────────────────────

function jyavani_push_api_routes(): array {
    return [
        'admin/tools/push-notifications/api/subscribe' => __DIR__ . '/admin/api/subscribe.php',
        'admin/tools/push-notifications/api/unsubscribe' => __DIR__ . '/admin/api/unsubscribe.php',
        'admin/tools/push-notifications/api/send' => __DIR__ . '/admin/api/send.php',
        'admin/tools/push-notifications/api/test' => __DIR__ . '/admin/api/test.php',
    ];
}

// ── Initialize ──────────────────────────────────────

if (php_sapi_name() !== 'cli') {
    global $pdo;
    if (isset($pdo)) {
        jyavani_push_ensure_schema($pdo);
    }
}

// ── Sidebar Widget ──────────────────────────────────

add_filter('sidebar_widget_types', function (array $types): array {
    $types['push_subscribe'] = [
        'label'          => 'Push Notifications',
        'desc'           => 'Subscribe button for browser push notifications.',
        'default_config' => ['title' => 'Notifikasi'],
    ];
    return $types;
});

add_filter('render_sidebar_widget', function ($html, string $type, array $config, PDO $pdo): string {
    if ($type !== 'push_subscribe') return $html;
    return jyavani_push_render_subscribe_widget($pdo, $config);
}, 10, 4);

function jyavani_push_render_subscribe_widget(PDO $pdo, array $config): string {
    $settings = jyavani_push_settings($pdo);
    $vapidKey = $settings['push_vapid_public_key'] ?? '';
    if ($vapidKey === '') return '';

    $title = h($config['title'] ?? 'Notifikasi');
    $subscribeUrl = '/api/push/subscribe.php';
    $unsubscribeUrl = '/api/push/unsubscribe.php';

    $html = '<div class="w-box w-push-subscribe">';
    $html .= '<h3 class="w-title">' . $title . '</h3>';
    $html .= '<p style="font-size:.85rem;color:var(--muted);margin:0 0 .75rem">Dapatkan notifikasi saat artikel baru terbit.</p>';
    $html .= '<div id="push-status" style="font-size:.8rem;color:var(--muted);margin-bottom:.5rem"></div>';
    $html .= '<button id="push-subscribe-btn" onclick="jyavaniPushToggle()" style="';
    $html .= 'display:inline-flex;align-items:center;gap:.4rem;';
    $html .= 'padding:.45rem .9rem;border-radius:6px;border:1px solid var(--border);';
    $html .= 'background:var(--surface);color:var(--text);cursor:pointer;';
    $html .= 'font-size:.85rem;font-family:inherit;transition:background .15s,border-color .15s">';
    $html .= '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>';
    $html .= '<span id="push-btn-text">Subscribe</span>';
    $html .= '</button>';
    $html .= '</div>';
    $html .= '<script>';
    $html .= '(function(){';
    $html .= 'var VAPID_KEY=' . json_encode($vapidKey) . ';';
    $html .= 'var SUB_URL=' . json_encode($subscribeUrl) . ';';
    $html .= 'var UNSUB_URL=' . json_encode($unsubscribeUrl) . ';';
    $html .= 'function base64urlToUint8Array(s){var p="=".repeat((4-s.length%4)%4);var b=(s+p).replace(/-/g,"+").replace(/_/g,"/");var d=atob(b);var a=new Uint8Array(d.length);for(var i=0;i<d.length;i++)a[i]=d.charCodeAt(i);return a}';
    $html .= 'function isIOS(){return/iPad|iPhone|iPod/.test(navigator.userAgent)||navigator.platform==="MacIntel"&&navigator.maxTouchPoints>1}';
    $html .= 'function isStandalone(){return window.navigator.standalone===true||window.matchMedia("(display-mode:standalone)").matches}';
    $html .= 'if("serviceWorker" in navigator){navigator.serviceWorker.register("/sw.js",{scope:"/"}).then(function(r){console.log("[push] SW registered, scope:",r.scope)}).catch(function(e){console.error("[push] SW register failed:",e)})}';
    $html .= 'function updateUI(){';
    $html .= 'var st=document.getElementById("push-status");';
    $html .= 'var btn=document.getElementById("push-subscribe-btn");';
    $html .= 'if(!("serviceWorker" in navigator)||!("PushManager" in window)||!("Notification" in window)){';
    $html .= 'if(isIOS()){st.textContent="iOS membutuhkan iOS 16.4+ dan situs harus ditambahkan ke Home Screen (Add to Home Screen).";}';
    $html .= 'else{st.textContent="Browser tidak mendukung push notification."}';
    $html .= 'btn.style.display="none";return}';
    $html .= 'if(isIOS()&&!isStandalone()){st.textContent="Di iOS, push notification hanya works setelah ditambahkan ke Home Screen. Tap icon Share → Add to Home Screen.";btn.style.display="none";return}';
    $html .= 'navigator.serviceWorker.ready.then(function(reg){';
    $html .= 'reg.pushManager.getSubscription().then(function(sub){';
    $html .= 'var btnTxt=document.getElementById("push-btn-text");';
    $html .= 'if(sub){btnTxt.textContent="Unsubscribe";st.textContent="Anda sudah berlangganan."}';
    $html .= 'else{btnTxt.textContent="Subscribe";st.textContent=""}';
    $html .= '}).catch(function(e){console.error("[push] getSubscription error:",e);st.textContent="Gagal memeriksa status: "+e.message})})}';
    $html .= 'window.jyavaniPushToggle=function(){';
    $html .= 'if(!("serviceWorker" in navigator)||!("PushManager" in window)||!("Notification" in window))return;';
    $html .= 'if(isIOS()&&!isStandalone())return;';
    $html .= 'var st=document.getElementById("push-status");';
    $html .= 'var btn=document.getElementById("push-subscribe-btn");';
    $html .= 'btn.disabled=true;st.textContent="Memproses...";';
    $html .= 'navigator.serviceWorker.ready.then(function(reg){';
    $html .= 'reg.pushManager.getSubscription().then(function(sub){';
    $html .= 'if(sub){doUnsubscribe(reg,sub)}else{doSubscribe(reg)}';
    $html .= '}).catch(function(e){console.error("[push] getSubscription error:",e);st.textContent="Gagal: "+e.message;btn.disabled=false})';
    $html .= '}).catch(function(e){console.error("[push] SW ready error:",e);st.textContent="Service Worker error: "+e.message;btn.disabled=false})';
    $html .= '};';
    $html .= 'function doSubscribe(reg){';
    $html .= 'var st=document.getElementById("push-status");';
    $html .= 'var btn=document.getElementById("push-subscribe-btn");';
    $html .= 'Notification.requestPermission().then(function(perm){';
    $html .= 'console.log("[push] Permission:",perm);';
    $html .= 'if(perm!=="granted"){st.textContent="Izin notifikasi ditolak. Ubah di Settings → Safari → Notifications.";btn.disabled=false;return}';
    $html .= 'st.textContent="Subscribe ke push...";';
    $html .= 'reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:base64urlToUint8Array(VAPID_KEY)}).then(function(sub){';
    $html .= 'console.log("[push] Subscribed:",sub.endpoint.substring(0,50));';
    $html .= 'var j=sub.toJSON();';
    $html .= 'fetch(SUB_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({endpoint:j.endpoint,keys:j.keys})}).then(function(r){return r.json()}).then(function(d){';
    $html .= 'console.log("[push] Server response:",d);';
    $html .= 'st.textContent="Berhasil subscribe!";btn.disabled=false;updateUI()';
    $html .= '}).catch(function(e){console.error("[push] Fetch error:",e);st.textContent="Gagal kirim ke server: "+e.message;btn.disabled=false})';
    $html .= '}).catch(function(e){console.error("[push] Subscribe error:",e);st.textContent="Gagal subscribe: "+e.message;btn.disabled=false})';
    $html .= '}).catch(function(e){console.error("[push] Permission error:",e);st.textContent="Gagal meminta izin: "+e.message;btn.disabled=false})';
    $html .= '}';
    $html .= 'function doUnsubscribe(reg,sub){';
    $html .= 'var st=document.getElementById("push-status");';
    $html .= 'var btn=document.getElementById("push-subscribe-btn");';
    $html .= 'st.textContent="Unsubscribe...";';
    $html .= 'sub.unsubscribe().then(function(){';
    $html .= 'var j=sub.toJSON();';
    $html .= 'fetch(UNSUB_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({endpoint:j.endpoint})}).then(function(){st.textContent="Berhasil unsubscribe.";btn.disabled=false;updateUI()}).catch(function(e){st.textContent="Gagal: "+e.message;btn.disabled=false})';
    $html .= '}).catch(function(e){st.textContent="Gagal unsubscribe: "+e.message;btn.disabled=false})';
    $html .= '}';
    $html .= 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",updateUI)}else{updateUI()}';
    $html .= '})();';
    $html .= '</script>';

    return $html;
}
