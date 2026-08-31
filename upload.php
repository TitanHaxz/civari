<?php
/*
 * CİVARİ — Görsel Yükleme (WebP'ye çevirir + boyutlandırır)
 * POST file=<resim>, type=avatar|review  (GİRİŞ GEREKLİ)
 * GD varsa: yeniden boyutlandırıp WebP olarak kaydeder (küçük dosya).
 * GD/WebP yoksa: orijinali kaydeder (yedek).
 */
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
ob_start();
@session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($d){ if(ob_get_length()!==false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (empty($_SESSION['user_id'])) out(['status'=>'error','message'=>'Önce giriş yapmalısın.']);
if (empty($_FILES['file'])) out(['status'=>'error','message'=>'Dosya gelmedi.']);

$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) out(['status'=>'error','message'=>'Yükleme hatası.']);
if ($f['size'] > 8 * 1024 * 1024) out(['status'=>'error','message'=>'Dosya en fazla 8 MB olmalı.']);

$allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
$mime = @mime_content_type($f['tmp_name']);
if (!isset($allowed[$mime])) out(['status'=>'error','message'=>'Sadece resim yükleyebilirsin (jpg, png, webp).']);
/* İkinci kontrol: dosya gerçekten çözülebilir bir resim mi? (uzantısı/MIME'ı taklit edilmiş
   dosyalar burada elenir — getimagesize sahte resimlerde false döner) */
$boyut = @getimagesize($f['tmp_name']);
if ($boyut === false || empty($boyut[0]) || empty($boyut[1])) out(['status'=>'error','message'=>'Geçerli bir resim dosyası değil.']);

$dir = __DIR__ . '/uploads';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
if (!is_dir($dir) || !is_writable($dir)) out(['status'=>'error','message'=>'Sunucuda uploads klasörü yazılabilir değil.']);

$type = ($_POST['type'] ?? '') === 'avatar' ? 'avatar' : 'review';
$maxDim = $type === 'avatar' ? 400 : 1000;   // ekranda gereğinden büyük yükleme yok
$base = 'u' . $_SESSION['user_id'] . '_' . time() . '_' . bin2hex(random_bytes(4));

$webpOk = function_exists('imagewebp') && function_exists('imagecreatetruecolor') && (imagetypes() & IMG_WEBP);
$finalUrl = null;

if ($webpOk) {
    $src = null;
    try {
        if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($f['tmp_name']);
        elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($f['tmp_name']);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($f['tmp_name']);
        elseif ($mime === 'image/gif' && function_exists('imagecreatefromgif')) $src = @imagecreatefromgif($f['tmp_name']);
    } catch (Throwable $e) { $src = null; }

    if ($src) {
        $w = imagesx($src); $h = imagesy($src);
        $scale = min(1, $maxDim / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // saydamlığı koru
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0,0,0,0, $nw,$nh, $w,$h);
        $name = $base . '.webp';
        $path = $dir . '/' . $name;
        if (@imagewebp($dst, $path, 80)) $finalUrl = 'uploads/' . $name;
        imagedestroy($src); imagedestroy($dst);
    }
}

// Yedek: WebP üretilemediyse orijinali kaydet
if ($finalUrl === null) {
    $name = $base . '.' . $allowed[$mime];
    $path = $dir . '/' . $name;
    if (!move_uploaded_file($f['tmp_name'], $path)) out(['status'=>'error','message'=>'Dosya kaydedilemedi.']);
    $finalUrl = 'uploads/' . $name;
}

if ($type === 'avatar') {
    /* Avatar yolunu kullanıcıya kaydet. Eskiden bu adım sessizce yutuluyordu:
       users tablosunda avatar sütunu yoksa kayıt başarısız oluyor ama istemciye
       "başarılı" dönüyordu — bu yüzden yüklenen profil fotoğrafı sayfa
       yenilenince kayboluyordu. Artık hata açıkça bildiriliyor. */
    if ($pdo === null) out(['status'=>'error','message'=>'Veritabanına ulaşılamadı, fotoğraf kaydedilemedi.']);
    try {
        $pdo->prepare("UPDATE users SET avatar=? WHERE id=?")->execute([$finalUrl, $_SESSION['user_id']]);
    } catch (Throwable $e) {
        error_log('avatar update: ' . $e->getMessage());
        out(['status'=>'error','message'=>'Fotoğraf yüklendi ama profile kaydedilemedi. (07_isletme.sql çalıştırıldı mı?)']);
    }
}

out(['status'=>'success','url'=>$finalUrl]);
