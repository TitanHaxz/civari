<?php
/*
 * NEARBY — İŞLETME PANELİ API
 * ---------------------------
 * isletme.php sayfasının arka ucu. Bir işletme sahibi YALNIZCA kendi mekanını
 * görebilir ve düzenleyebilir; her istekte sahiplik kontrolü yapılır.
 *
 * Eylemler (POST action=...):
 *   kaydet        → mekan bilgilerini kaydeder (taslak olarak)
 *   onaya_gonder  → başvuruyu incelemeye gönderir
 *   foto_yukle    → galeriye fotoğraf ekler (çoklu)
 *   foto_sil      → fotoğraf siler
 *   foto_sirala   → fotoğrafı yukarı/aşağı taşır
 *   foto_kapak    → kapak fotoğrafını değiştirir
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();
@session_start();
require __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

function cikti(array $d): void { if (ob_get_length() !== false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($pdo === null)                cikti(['status'=>'error','message'=>'Veritabanına ulaşılamadı.']);
if (empty($_SESSION['user_id']))  cikti(['status'=>'error','message'=>'Oturumun sona ermiş. Lütfen tekrar giriş yap.']);

$uid = (int)$_SESSION['user_id'];

/* Bu kullanıcı işletme hesabı mı? */
try {
    $k = $pdo->prepare("SELECT hesap_turu, isletme_adi FROM users WHERE id=?");
    $k->execute([$uid]);
    $kullanici = $k->fetch();
} catch (Throwable $e) { cikti(['status'=>'error','message'=>'Hesap bilgisi okunamadı. (07_isletme.sql çalıştırıldı mı?)']); }

if (!$kullanici || ($kullanici['hesap_turu'] ?? '') !== 'isletme') {
    cikti(['status'=>'error','message'=>'Bu alan yalnızca işletme hesapları içindir.']);
}

/* CSRF: panel formundaki gizli anahtarla eşleşmeli */
if (empty($_SESSION['isletme_csrf'])) $_SESSION['isletme_csrf'] = bin2hex(random_bytes(32));
if (!hash_equals($_SESSION['isletme_csrf'], (string)($_POST['csrf'] ?? ''))) {
    cikti(['status'=>'error','message'=>'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar dene.']);
}

$action = $_POST['action'] ?? '';

/* Kullanıcının mekanını getir (yoksa null) */
function mekanimiGetir(PDO $pdo, int $uid) {
    $s = $pdo->prepare("SELECT * FROM venues WHERE owner_id=? ORDER BY id LIMIT 1");
    $s->execute([$uid]);
    return $s->fetch() ?: null;
}

/* Ankara sınırları — panel dışına konum konmasın */
function ankaraIcinde(float $lat, float $lng): bool {
    return $lat >= 39.60 && $lat <= 40.20 && $lng >= 32.30 && $lng <= 33.30;
}

/* ---------------- MEKAN BİLGİLERİNİ KAYDET ---------------- */
if ($action === 'kaydet' || $action === 'onaya_gonder') {
    $ad       = trim($_POST['name'] ?? '');
    $kategori = trim($_POST['category'] ?? '');
    $aciklama = trim($_POST['description'] ?? '');
    $adres    = trim($_POST['address'] ?? '');
    $ilce     = trim($_POST['district'] ?? 'Çankaya');
    $telefon  = trim($_POST['phone'] ?? '');
    $web      = trim($_POST['website'] ?? '');
    $saatler  = trim($_POST['opening_hours'] ?? '');
    $fiyat    = (int)($_POST['price_level'] ?? 2);
    $alkol    = !empty($_POST['alcohol']) ? 1 : 0;
    $etiket   = trim($_POST['tags'] ?? '');
    $lat      = isset($_POST['lat']) ? (float)$_POST['lat'] : 0;
    $lng      = isset($_POST['lng']) ? (float)$_POST['lng'] : 0;

    $izinliKategoriler = ['Kafe','Restoran','Bar','Tatlıcı'];
    if (!in_array($kategori, $izinliKategoriler, true)) $kategori = 'Kafe';
    if ($fiyat < 1 || $fiyat > 3) $fiyat = 2;
    if ($web !== '' && !preg_match('~^https?://~i', $web)) $web = 'https://' . $web;

    if ($ad === '')                     cikti(['status'=>'error','message'=>'Mekanının adını yazmalısın.']);
    if (!$lat || !$lng)                 cikti(['status'=>'error','message'=>'Haritadan mekanının konumunu seçmelisin.']);
    if (!ankaraIcinde($lat, $lng))      cikti(['status'=>'error','message'=>'Şu an yalnızca Ankara içindeki mekanları kabul ediyoruz.']);

    $mekan = mekanimiGetir($pdo, $uid);

    /* Onaya gönderirken eksik alan kontrolü — yayına hazır olsun */
    if ($action === 'onaya_gonder') {
        $eksikler = [];
        if (mb_strlen($aciklama) < 40) $eksikler[] = 'en az 40 karakterlik bir tanıtım yazısı';
        if ($adres === '')             $eksikler[] = 'açık adres';
        if ($telefon === '')           $eksikler[] = 'telefon numarası';
        if ($saatler === '')           $eksikler[] = 'çalışma saatleri';
        if ($mekan) {
            $fs = $pdo->prepare("SELECT COUNT(*) FROM venue_photos WHERE venue_id=?");
            $fs->execute([$mekan['id']]);
            if ((int)$fs->fetchColumn() < 1) $eksikler[] = 'en az 1 fotoğraf';
        } else {
            $eksikler[] = 'en az 1 fotoğraf';
        }
        if ($eksikler) {
            cikti(['status'=>'error','message'=>'Onaya göndermeden önce şunlar gerekli: ' . implode(', ', $eksikler) . '.']);
        }
    }

    try {
        if ($mekan) {
            /* Onaylanmış bir mekan düzenlenirse tekrar incelemeye girer
               (yayındaki bilgilerin denetimsiz değişmesini önler) */
            $yeniDurum = ($action === 'onaya_gonder') ? 'beklemede'
                       : (($mekan['onay_durumu'] === 'onaylandi') ? 'beklemede' : 'taslak');

            /* İkinci savunma katmanı: onaylı değilse is_active de 0 olur —
               onay filtresi bir şekilde atlansa bile mekan listelenmez. */
            $sql = "UPDATE venues SET name=?, category=?, description=?, address=?, district=?, phone=?, website=?,
                    opening_hours=?, price_level=?, alcohol=?, tags=?, lat=?, lng=?, city='Ankara',
                    onay_durumu=?, is_active=(CASE WHEN ?='onaylandi' THEN 1 ELSE 0 END), red_nedeni=NULL"
                 . ($action === 'onaya_gonder' ? ", basvuru_tarihi=NOW()" : "")
                 . " WHERE id=? AND owner_id=?";
            $pdo->prepare($sql)->execute([
                $ad, $kategori, $aciklama, $adres, $ilce, $telefon, $web,
                $saatler, $fiyat, $alkol, $etiket, $lat, $lng,
                $yeniDurum, $yeniDurum, $mekan['id'], $uid
            ]);
            $mekanId = (int)$mekan['id'];
        } else {
            $yeniDurum = ($action === 'onaya_gonder') ? 'beklemede' : 'taslak';
            /* Yeni başvuru is_active=0 ile açılır; onaylanınca admin 1 yapar. */
            $pdo->prepare("INSERT INTO venues
                (name, category, description, address, district, phone, website, opening_hours,
                 price_level, alcohol, tags, lat, lng, city, is_active, rating, image_url,
                 source, verified, owner_id, onay_durumu, basvuru_tarihi)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'Ankara', 0, NULL, '', 'isletme', 0, ?, ?, NOW())")
                ->execute([$ad,$kategori,$aciklama,$adres,$ilce,$telefon,$web,$saatler,
                           $fiyat,$alkol,$etiket,$lat,$lng,$uid,$yeniDurum]);
            $mekanId = (int)$pdo->lastInsertId();
        }

        cikti([
            'status'  => 'success',
            'mekan_id'=> $mekanId,
            'durum'   => $yeniDurum,
            'message' => $action === 'onaya_gonder'
                ? 'Başvurun alındı! Ekibimiz 24 saat içinde inceleyip yayınlayacak.'
                : 'Bilgilerin kaydedildi.'
        ]);
    } catch (Throwable $e) {
        error_log('İşletme kaydet: ' . $e->getMessage());
        cikti(['status'=>'error','message'=>'Kaydedilemedi. Lütfen tekrar dene.']);
    }
}

/* ---------------- FOTOĞRAF YÜKLEME ---------------- */
function isletme_foto_kaydet(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok'=>false,'mesaj'=>''];
    if ($f['error'] !== UPLOAD_ERR_OK)      return ['ok'=>false,'mesaj'=>'Yükleme sırasında bir sorun oldu.'];
    if ($f['size'] > 10 * 1024 * 1024)      return ['ok'=>false,'mesaj'=>'Her fotoğraf en fazla 10 MB olabilir.'];

    $izinli = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $mime = @mime_content_type($f['tmp_name']);
    if (!isset($izinli[$mime]))             return ['ok'=>false,'mesaj'=>'Sadece JPG, PNG veya WEBP yükleyebilirsin.'];
    if (@getimagesize($f['tmp_name']) === false) return ['ok'=>false,'mesaj'=>'Geçerli bir fotoğraf değil.'];

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) return ['ok'=>false,'mesaj'=>'Sunucuda yükleme klasörü yazılabilir değil.'];

    $ad = 'i' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $webpVar = function_exists('imagewebp') && (imagetypes() & IMG_WEBP);
    if ($webpVar) {
        $src = null;
        try {
            if ($mime === 'image/jpeg')      $src = @imagecreatefromjpeg($f['tmp_name']);
            elseif ($mime === 'image/png')   $src = @imagecreatefrompng($f['tmp_name']);
            elseif ($mime === 'image/webp')  $src = @imagecreatefromwebp($f['tmp_name']);
        } catch (Throwable $e) { $src = null; }
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $o = min(1, 1600 / max($w, $h));
            $nw = max(1,(int)round($w*$o)); $nh = max(1,(int)round($h*$o));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst,false); imagesavealpha($dst,true);
            imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h);
            $yol = $dir . '/' . $ad . '.webp';
            $tamam = @imagewebp($dst, $yol, 82);
            imagedestroy($src); imagedestroy($dst);
            if ($tamam) return ['ok'=>true,'url'=>'uploads/' . $ad . '.webp'];
        }
    }
    $yol = $dir . '/' . $ad . '.' . $izinli[$mime];
    if (!@move_uploaded_file($f['tmp_name'], $yol)) return ['ok'=>false,'mesaj'=>'Fotoğraf kaydedilemedi.'];
    return ['ok'=>true,'url'=>'uploads/' . $ad . '.' . $izinli[$mime]];
}

if ($action === 'foto_yukle') {
    $mekan = mekanimiGetir($pdo, $uid);
    if (!$mekan) cikti(['status'=>'error','message'=>'Önce mekan bilgilerini kaydetmelisin.']);

    $eklenen = 0; $hatalar = [];
    if (!empty($_FILES['fotolar']['name'][0])) {
        $adet = min(12, count($_FILES['fotolar']['name']));
        for ($i = 0; $i < $adet; $i++) {
            $tek = ['name'=>$_FILES['fotolar']['name'][$i], 'type'=>$_FILES['fotolar']['type'][$i],
                    'tmp_name'=>$_FILES['fotolar']['tmp_name'][$i], 'error'=>$_FILES['fotolar']['error'][$i],
                    'size'=>$_FILES['fotolar']['size'][$i]];
            $s = isletme_foto_kaydet($tek);
            if ($s['ok']) {
                $ss = $pdo->prepare("SELECT COALESCE(MAX(sira),0) FROM venue_photos WHERE venue_id=?");
                $ss->execute([$mekan['id']]);
                $pdo->prepare("INSERT INTO venue_photos (venue_id,url,baslik,temsili,sira) VALUES (?,?,'',0,?)")
                    ->execute([$mekan['id'], $s['url'], (int)$ss->fetchColumn() + 1]);
                if (trim((string)$mekan['image_url']) === '') {
                    $pdo->prepare("UPDATE venues SET image_url=? WHERE id=? AND owner_id=?")
                        ->execute([$s['url'], $mekan['id'], $uid]);
                    $mekan['image_url'] = $s['url'];
                }
                $eklenen++;
            } elseif ($s['mesaj'] !== '') $hatalar[] = $s['mesaj'];
        }
    }
    if ($eklenen > 0) cikti(['status'=>'success','message'=>$eklenen . ' fotoğraf eklendi.' . ($hatalar ? ' (' . implode(' ', array_unique($hatalar)) . ')' : '')]);
    cikti(['status'=>'error','message'=>$hatalar ? implode(' ', array_unique($hatalar)) : 'Fotoğraf seçilmedi.']);
}

/* ---------------- FOTOĞRAF SİL / SIRALA / KAPAK ---------------- */
if ($action === 'foto_sil' || $action === 'foto_sirala' || $action === 'foto_kapak') {
    $mekan = mekanimiGetir($pdo, $uid);
    if (!$mekan) cikti(['status'=>'error','message'=>'Mekan bulunamadı.']);
    $fid = (int)($_POST['foto_id'] ?? 0);

    /* Sahiplik: fotoğraf gerçekten bu işletmenin mekanına mı ait? */
    $fs = $pdo->prepare("SELECT * FROM venue_photos WHERE id=? AND venue_id=?");
    $fs->execute([$fid, $mekan['id']]);
    $foto = $fs->fetch();
    if (!$foto) cikti(['status'=>'error','message'=>'Fotoğraf bulunamadı.']);

    try {
        if ($action === 'foto_sil') {
            $pdo->prepare("DELETE FROM venue_photos WHERE id=? AND venue_id=?")->execute([$fid, $mekan['id']]);
            $dosya = __DIR__ . '/' . $foto['url'];
            if (strpos($foto['url'], 'uploads/') === 0 && is_file($dosya)) @unlink($dosya);
            if (trim((string)$mekan['image_url']) === $foto['url']) {
                $y = $pdo->prepare("SELECT url FROM venue_photos WHERE venue_id=? ORDER BY sira, id LIMIT 1");
                $y->execute([$mekan['id']]);
                $pdo->prepare("UPDATE venues SET image_url=? WHERE id=? AND owner_id=?")
                    ->execute([(string)$y->fetchColumn(), $mekan['id'], $uid]);
            }
            cikti(['status'=>'success','message'=>'Fotoğraf silindi.']);
        }
        if ($action === 'foto_kapak') {
            $pdo->prepare("UPDATE venues SET image_url=? WHERE id=? AND owner_id=?")
                ->execute([$foto['url'], $mekan['id'], $uid]);
            cikti(['status'=>'success','message'=>'Kapak fotoğrafı değişti.']);
        }
        /* foto_sirala */
        $yon = ($_POST['yon'] ?? '') === 'yukari' ? -1 : 1;
        $k = $pdo->prepare($yon < 0
            ? "SELECT id,sira FROM venue_photos WHERE venue_id=? AND sira < ? ORDER BY sira DESC LIMIT 1"
            : "SELECT id,sira FROM venue_photos WHERE venue_id=? AND sira > ? ORDER BY sira ASC LIMIT 1");
        $k->execute([$mekan['id'], $foto['sira']]);
        if ($komsu = $k->fetch()) {
            $pdo->prepare("UPDATE venue_photos SET sira=? WHERE id=?")->execute([$komsu['sira'], $fid]);
            $pdo->prepare("UPDATE venue_photos SET sira=? WHERE id=?")->execute([$foto['sira'], $komsu['id']]);
        }
        cikti(['status'=>'success','message'=>'Sıra güncellendi.']);
    } catch (Throwable $e) {
        error_log('İşletme foto: ' . $e->getMessage());
        cikti(['status'=>'error','message'=>'İşlem tamamlanamadı.']);
    }
}

cikti(['status'=>'error','message'=>'Geçersiz istek.']);
