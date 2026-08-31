<?php
/*
 * NEARBY — Kimlik Doğrulama API (Kurşun geçirmez sürüm)
 * Her durumda TEMİZ JSON döndürür. Doğrudan tarayıcıdan açılırsa da JSON verir (test için).
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length() !== false) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Sunucu hatası oluştu.']);
    }
});

@session_start();

/* ── 30 DAKİKA OTURUM AŞIMI ──────────────────────────────
 * Hareketsizlik 30 dk'yı geçerse oturum düşer. Böylece sayfa yenilense bile
 * eski oturum sonsuza dek açık kalmaz. Her istek zaman damgasını tazeler. */
$SESSION_TTL = 1800; // 30 dakika
if (!empty($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $SESSION_TTL) {
    $_SESSION = [];
    @session_destroy();
    @session_start();
}
$_SESSION['last_activity'] = time();

require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data): void {
    if (ob_get_length() !== false) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Basit IP bazlı hız sınırlama (API Gateway yerine hafif çözüm) */
function rateLimit(string $key, int $max, int $windowSec): bool {
    $dir = sys_get_temp_dir() . '/nearby_rl';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/' . md5($key) . '.json';
    $now = time();
    $data = ['count' => 0, 'start' => $now];
    if (is_file($file)) { $j = json_decode(@file_get_contents($file), true); if (is_array($j)) $data = $j; }
    if ($now - (int)($data['start'] ?? $now) > $windowSec) $data = ['count' => 0, 'start' => $now];
    $data['count'] = (int)($data['count'] ?? 0) + 1;
    @file_put_contents($file, json_encode($data));
    return $data['count'] <= $max;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* Giriş/kayıt için hız sınırı: aynı IP'den 60 sn'de en fazla 12 deneme */
if ($action === 'login' || $action === 'register') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!rateLimit('auth_' . $ip, 12, 60)) {
        jsonOut(['status' => 'error', 'message' => 'Çok fazla deneme yaptınız. Lütfen birazdan tekrar deneyin.']);
    }
}

/* ÇIKIŞ */
if ($action === 'logout') {
    $_SESSION = [];
    @session_destroy();
    jsonOut(['status' => 'success']);
}

/* OTURUM KONTROLÜ */
if ($action === 'session') {
    if (!empty($_SESSION['user_id'])) {
        jsonOut([
            'status'     => 'success',
            'fullname'   => $_SESSION['fullname'] ?? '',
            'hesap_turu' => $_SESSION['hesap_turu'] ?? 'bireysel'
        ]);
    }
    jsonOut(['status' => 'guest']);
}

if ($pdo === null) {
    jsonOut(['status' => 'error', 'message' => 'Sunucu veritabanına bağlanamadı.']);
}

/* KAYIT */
if ($action === 'register') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $birthYear = (int)($_POST['birth_year'] ?? 0);
    $province = trim($_POST['province'] ?? '');
    $district = trim($_POST['district'] ?? '');
    /* Hesap türü: bireysel (varsayılan) veya isletme */
    $hesapTuru   = (($_POST['hesap_turu'] ?? '') === 'isletme') ? 'isletme' : 'bireysel';
    $isletmeAdi  = trim($_POST['isletme_adi'] ?? '');
    $yetkiliUnvan= trim($_POST['yetkili_unvan'] ?? '');

    if ($fullname === '' || $email === '' || $password === '')
        jsonOut(['status' => 'error', 'message' => 'Lütfen tüm alanları doldurun.']);
    /* KVKK açık rıza + kullanım koşulları onayı zorunlu (tarayıcı kontrolü aşılırsa burada durur) */
    if (empty($_POST['onay']))
        jsonOut(['status' => 'error', 'message' => 'Kayıt için Kullanım Koşulları ve Gizlilik Politikası onayı gereklidir.']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        jsonOut(['status' => 'error', 'message' => 'Geçerli bir e-posta adresi girin.']);
    if (strlen($password) < 8)
        jsonOut(['status' => 'error', 'message' => 'Şifre en az 8 karakter olmalı.']);
    if ($hesapTuru === 'isletme') {
        if ($isletmeAdi === '') jsonOut(['status' => 'error', 'message' => 'İşletmenin adını yazmalısınız.']);
    } elseif ($birthYear < 1900 || $birthYear > (int)date('Y')) {
        jsonOut(['status' => 'error', 'message' => 'Lütfen geçerli bir doğum yılı girin.']);
    }

    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch())
            jsonOut(['status' => 'error', 'message' => 'Bu e-posta adresi zaten kayıtlı.']);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password, birth_year, province, district) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$fullname, $email, $hash, $birthYear, $province, $district]);
        } catch (Exception $inner) {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password, birth_year) VALUES (?, ?, ?, ?)");
                $stmt->execute([$fullname, $email, $hash, $birthYear]);
            } catch (Exception $inner2) {
                $stmt = $pdo->prepare("INSERT INTO users (fullname, email, password) VALUES (?, ?, ?)");
                $stmt->execute([$fullname, $email, $hash]);
            }
        }
        $uid = (int)$pdo->lastInsertId();

        /* KVKK: açık rızanın ne zaman alındığını kayıt altına al (ispat yükümlülüğü).
           Sütunlar yoksa (04_yasal_onay.sql çalıştırılmamışsa) kayıt yine de başarılı sayılır. */
        try {
            if ($uid > 0) {
                $pdo->prepare("UPDATE users SET kvkk_onay_tarihi = NOW(), kvkk_onay_surumu = ? WHERE id = ?")
                    ->execute(['2026-08-14', $uid]);
            }
        } catch (Throwable $onayHata) { error_log('KVKK onay kaydı: ' . $onayHata->getMessage()); }

        /* İşletme hesabı: hesap türünü ve işletme bilgisini kaydet.
           Sütunlar yoksa (07_isletme.sql çalıştırılmamışsa) açıkça uyar. */
        if ($hesapTuru === 'isletme') {
            try {
                $pdo->prepare("UPDATE users SET hesap_turu='isletme', isletme_adi=?, yetkili_unvan=? WHERE id=?")
                    ->execute([$isletmeAdi, $yetkiliUnvan, $uid]);
            } catch (Throwable $ih) {
                error_log('İşletme hesabı kaydı: ' . $ih->getMessage());
                jsonOut(['status' => 'error', 'message' => 'İşletme hesabı oluşturulamadı. (Sunucuda 07_isletme.sql çalıştırılmalı.)']);
            }
        }

        jsonOut([
            'status'  => 'success',
            'message' => $hesapTuru === 'isletme'
                ? 'İşletme hesabınız oluşturuldu.'
                : 'Kayıt başarılı! Şimdi giriş yapabilirsiniz.',
            'hesap_turu' => $hesapTuru
        ]);
    } catch (Exception $e) {
        error_log('Kayıt hatası: ' . $e->getMessage());
        jsonOut(['status' => 'error', 'message' => 'Kayıt sırasında bir hata oluştu.']);
    }
}

/* GİRİŞ */
if ($action === 'login') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true); // oturum sabitleme (session fixation) koruması
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['hesap_turu'] = $user['hesap_turu'] ?? 'bireysel';
            jsonOut([
                'status'     => 'success',
                'message'    => 'Giriş başarılı!',
                'fullname'   => $user['fullname'],
                'hesap_turu' => $_SESSION['hesap_turu']
            ]);
        }
        jsonOut(['status' => 'error', 'message' => 'E-posta veya şifre hatalı!']);
    } catch (Exception $e) {
        error_log('Giriş hatası: ' . $e->getMessage());
        jsonOut(['status' => 'error', 'message' => 'Giriş sırasında bir hata oluştu.']);
    }
}

/* Hiçbir eylem yoksa (örn. tarayıcıdan doğrudan açılınca) */
jsonOut(['status' => 'error', 'message' => 'Geçersiz işlem isteği. (auth.php çalışıyor)']);
