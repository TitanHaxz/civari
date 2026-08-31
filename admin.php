<?php
/*
 * CİVARİ — Yönetim Paneli (Dashboard + Mekan + Kullanıcı + Yorum Yönetimi)
 * ŞİFREYİ DEĞİŞTİRİN: $ADMIN_PASSWORD
 */
session_start();
require 'db.php';

/* Şifre artık kodda düz metin DEĞİL — bcrypt karması (hash). Şifreyi değiştirmek için:
   php -r "echo password_hash('YeniSifren', PASSWORD_DEFAULT);"  komutunun çıktısını buraya yapıştır. */
$ADMIN_PASSWORD_HASH = 'CHANGE_ME';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* CSRF koruması: veri değiştiren her form bu gizli alanı taşımak zorunda */
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
function csrf_ok(): bool { return hash_equals($_SESSION['admin_csrf'] ?? '', (string)($_POST['csrf'] ?? '')); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['admin_csrf']) . '">'; }

if (isset($_GET['logout'])) { unset($_SESSION['admin_ok']); header('Location: admin.php'); exit; }
if (isset($_POST['admin_login'])) {
    /* Kaba kuvvet freni: her hatalı denemede bekleme, 5 hatada 60 sn kilit */
    $_SESSION['admin_try'] = (int)($_SESSION['admin_try'] ?? 0);
    if ($_SESSION['admin_try'] >= 5 && (time() - (int)($_SESSION['admin_try_t'] ?? 0)) < 60) {
        $login_error = 'Çok fazla deneme. 1 dakika bekleyip tekrar deneyin.';
    } elseif (password_verify((string)($_POST['admin_pass'] ?? ''), $ADMIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_try'] = 0;
    } else {
        usleep(400000);
        $_SESSION['admin_try']++; $_SESSION['admin_try_t'] = time();
        $login_error = 'Hatalı şifre!';
    }
}

if (empty($_SESSION['admin_ok'])):
?>
<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Giriş — Civari</title>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;800&family=Outfit:wght@400;600&display=swap" rel="stylesheet">
<style>
 *{margin:0;box-sizing:border-box;font-family:'Outfit',sans-serif}
 body{background:radial-gradient(ellipse at center,#1a0d04,#000);height:100vh;display:flex;align-items:center;justify-content:center;color:#fff}
 form{background:rgba(15,23,42,.9);border:1px solid rgba(249,115,22,.25);padding:44px;border-radius:22px;width:360px}
 h1{font-family:'Bricolage Grotesque';color:#ff9900;text-align:center;font-size:2rem}h1 span{color:#fff}
 p{text-align:center;color:#94a3b8;margin:6px 0 26px;font-size:.9rem}
 input{width:100%;padding:14px;border-radius:12px;border:none;margin-bottom:14px;font-size:1rem}
 button{width:100%;padding:14px;border:none;border-radius:12px;background:#ff9900;color:#fff;font-weight:800;cursor:pointer;font-size:1rem}
 button:hover{background:#e07f00}.err{background:#ef4444;padding:10px;border-radius:8px;text-align:center;margin-bottom:14px;font-size:.9rem}
</style></head><body>
 <form method="POST"><h1>Civari<span>.</span></h1><p>Yönetim Paneli</p>
   <?php if(!empty($login_error)): ?><div class="err"><?= e($login_error) ?></div><?php endif; ?>
   <input type="password" name="admin_pass" placeholder="Yönetici Şifresi" required autofocus>
   <button name="admin_login">Giriş Yap</button>
 </form></body></html>
<?php exit; endif;

if ($pdo === null) die("<h2 style='color:#ef4444;text-align:center;padding:60px;font-family:sans-serif'>Veritabanına bağlanılamadı.</h2>");

$cats = ['Kafe','Restoran','Bar','Tatlıcı'];
$msg = null;

/* UGC: bildirim tablosu garanti + onayda kullanılacak şehir koordinatları/görselleri */
try { $pdo->exec("CREATE TABLE IF NOT EXISTS submissions (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, name VARCHAR(150) NOT NULL, category VARCHAR(50) NOT NULL, city VARCHAR(120) NULL, address VARCHAR(255) NULL, note TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
/* Vitrin dönemi: site yalnızca Ankara. Onaylanan bildirimler Ankara merkeze düşer,
   sonra Mekanlar sekmesinden konum hassaslaştırılır. Sahte temsili görsel YOK —
   görsel boş kalır, önyüz fotoğrafsız şık kart gösterir. */
$CITY_COORDS = ['Ankara'=>[39.9208,32.8541]];

/* Galeri tablosu garanti (05_galeri.sql çalıştırılmamışsa da panel çalışsın) */
try { $pdo->exec("CREATE TABLE IF NOT EXISTS venue_photos (id INT AUTO_INCREMENT PRIMARY KEY, venue_id INT NOT NULL, url VARCHAR(500) NOT NULL, baslik VARCHAR(200) NULL, temsili TINYINT(1) NOT NULL DEFAULT 0, sira INT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_venue (venue_id, sira)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE venue_photos ADD COLUMN IF NOT EXISTS temsili TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

/* Yönetici fotoğraf yüklemesi: dosyayı uploads/ klasörüne kaydeder, yolunu döndürür.
   Sadece gerçek resim dosyaları kabul edilir (tür + içerik doğrulaması). */
function admin_foto_yukle(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['ok'=>false,'mesaj'=>''];
    if ($f['error'] !== UPLOAD_ERR_OK) return ['ok'=>false,'mesaj'=>'Yükleme hatası (kod '.$f['error'].').'];
    if ($f['size'] > 10 * 1024 * 1024) return ['ok'=>false,'mesaj'=>'Dosya en fazla 10 MB olmalı.'];

    $izinli = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $mime = @mime_content_type($f['tmp_name']);
    if (!isset($izinli[$mime])) return ['ok'=>false,'mesaj'=>'Sadece JPG, PNG veya WEBP yükleyebilirsin.'];
    $boyut = @getimagesize($f['tmp_name']);
    if ($boyut === false) return ['ok'=>false,'mesaj'=>'Geçerli bir resim dosyası değil.'];

    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) return ['ok'=>false,'mesaj'=>'uploads klasörü yazılabilir değil.'];

    $ad = 'm' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    // GD varsa: uzun kenarı 1600'e küçült ve WebP'ye çevir (sayfa hızlı açılsın)
    $webpVar = function_exists('imagewebp') && function_exists('imagecreatetruecolor') && (imagetypes() & IMG_WEBP);
    if ($webpVar) {
        $src = null;
        try {
            if ($mime === 'image/jpeg') $src = @imagecreatefromjpeg($f['tmp_name']);
            elseif ($mime === 'image/png') $src = @imagecreatefrompng($f['tmp_name']);
            elseif ($mime === 'image/webp') $src = @imagecreatefromwebp($f['tmp_name']);
        } catch (Throwable $e) { $src = null; }
        if ($src) {
            $w = imagesx($src); $h = imagesy($src);
            $olcek = min(1, 1600 / max($w, $h));
            $nw = max(1, (int)round($w * $olcek)); $nh = max(1, (int)round($h * $olcek));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $src, 0,0,0,0, $nw,$nh, $w,$h);
            $yol = $dir . '/' . $ad . '.webp';
            $tamam = @imagewebp($dst, $yol, 82);
            imagedestroy($src); imagedestroy($dst);
            if ($tamam) return ['ok'=>true,'url'=>'uploads/' . $ad . '.webp'];
        }
    }
    $yol = $dir . '/' . $ad . '.' . $izinli[$mime];
    if (!@move_uploaded_file($f['tmp_name'], $yol)) return ['ok'=>false,'mesaj'=>'Dosya kaydedilemedi.'];
    return ['ok'=>true,'url'=>'uploads/' . $ad . '.' . $izinli[$mime]];
}

try {
    /* Veri değiştiren TÜM işlemler POST + CSRF ister; linkle (GET) silme kaldırıldı. */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['admin_login'])) {
        if (!csrf_ok()) throw new Exception('Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');

        /* --- GALERİ: fotoğraf yükle --- */
        if (isset($_POST['foto_ekle'])) {
            $vid = (int)$_POST['foto_venue_id'];
            $eklenen = 0; $hatalar = [];
            if (!empty($_FILES['fotolar']['name'][0])) {
                $adet = count($_FILES['fotolar']['name']);
                for ($i = 0; $i < $adet; $i++) {
                    $tek = ['name'=>$_FILES['fotolar']['name'][$i], 'type'=>$_FILES['fotolar']['type'][$i],
                            'tmp_name'=>$_FILES['fotolar']['tmp_name'][$i], 'error'=>$_FILES['fotolar']['error'][$i],
                            'size'=>$_FILES['fotolar']['size'][$i]];
                    $sonuc = admin_foto_yukle($tek);
                    if ($sonuc['ok']) {
                        $sonSira = (int)$pdo->query("SELECT COALESCE(MAX(sira),0) FROM venue_photos WHERE venue_id=" . $vid)->fetchColumn();
                        $pdo->prepare("INSERT INTO venue_photos (venue_id,url,baslik,temsili,sira) VALUES (?,?,?,?,?)")
                            ->execute([$vid, $sonuc['url'], trim($_POST['foto_baslik'] ?? ''), (int)!empty($_POST['foto_temsili']), $sonSira + 1]);
                        // İlk fotoğraf aynı zamanda kartlarda görünen kapak olsun
                        $mevcut = $pdo->prepare("SELECT image_url FROM venues WHERE id=?"); $mevcut->execute([$vid]);
                        if (trim((string)$mevcut->fetchColumn()) === '') {
                            $pdo->prepare("UPDATE venues SET image_url=? WHERE id=?")->execute([$sonuc['url'], $vid]);
                        }
                        $eklenen++;
                    } elseif ($sonuc['mesaj'] !== '') $hatalar[] = $sonuc['mesaj'];
                }
            }
            $msg = $eklenen > 0
                ? ['#10b981', $eklenen . ' fotoğraf galeriye eklendi.' . ($hatalar ? ' (' . implode(' ', array_unique($hatalar)) . ')' : '')]
                : ['#ef4444', 'Fotoğraf eklenemedi. ' . implode(' ', array_unique($hatalar))];
        }
        /* --- GALERİ: fotoğraf sil --- */
        if (isset($_POST['foto_sil'])) {
            $fid = (int)$_POST['foto_sil'];
            $s = $pdo->prepare("SELECT venue_id, url FROM venue_photos WHERE id=?"); $s->execute([$fid]);
            if ($foto = $s->fetch()) {
                $pdo->prepare("DELETE FROM venue_photos WHERE id=?")->execute([$fid]);
                // Dosyayı da diskten sil (yalnızca uploads içindeyse)
                $dosya = __DIR__ . '/' . $foto['url'];
                if (strpos($foto['url'], 'uploads/') === 0 && is_file($dosya)) @unlink($dosya);
                // Kapak fotoğrafıysa, kalan ilk fotoğrafı kapak yap
                $kapak = $pdo->prepare("SELECT image_url FROM venues WHERE id=?"); $kapak->execute([$foto['venue_id']]);
                if (trim((string)$kapak->fetchColumn()) === $foto['url']) {
                    $y = $pdo->prepare("SELECT url FROM venue_photos WHERE venue_id=? ORDER BY sira, id LIMIT 1");
                    $y->execute([$foto['venue_id']]);
                    $pdo->prepare("UPDATE venues SET image_url=? WHERE id=?")->execute([(string)$y->fetchColumn(), $foto['venue_id']]);
                }
                $msg = ['#ef4444','Fotoğraf silindi.'];
            }
        }
        /* --- GALERİ: sırayı değiştir / kapak yap --- */
        if (isset($_POST['foto_tasi'])) {
            $fid = (int)$_POST['foto_tasi']; $yon = ($_POST['yon'] ?? '') === 'yukari' ? -1 : 1;
            $s = $pdo->prepare("SELECT venue_id, sira FROM venue_photos WHERE id=?"); $s->execute([$fid]);
            if ($f = $s->fetch()) {
                $k = $pdo->prepare($yon < 0
                    ? "SELECT id,sira FROM venue_photos WHERE venue_id=? AND sira < ? ORDER BY sira DESC LIMIT 1"
                    : "SELECT id,sira FROM venue_photos WHERE venue_id=? AND sira > ? ORDER BY sira ASC LIMIT 1");
                $k->execute([$f['venue_id'], $f['sira']]);
                if ($komsu = $k->fetch()) {
                    $pdo->prepare("UPDATE venue_photos SET sira=? WHERE id=?")->execute([$komsu['sira'], $fid]);
                    $pdo->prepare("UPDATE venue_photos SET sira=? WHERE id=?")->execute([$f['sira'], $komsu['id']]);
                    $msg = ['#3b82f6','Sıra güncellendi.'];
                }
            }
        }
        if (isset($_POST['foto_kapak'])) {
            $fid = (int)$_POST['foto_kapak'];
            $s = $pdo->prepare("SELECT venue_id, url FROM venue_photos WHERE id=?"); $s->execute([$fid]);
            if ($f = $s->fetch()) {
                $pdo->prepare("UPDATE venues SET image_url=? WHERE id=?")->execute([$f['url'], $f['venue_id']]);
                $msg = ['#10b981','Kapak fotoğrafı güncellendi.'];
            }
        }

        if (isset($_POST['sil']))       { $pdo->prepare("DELETE FROM venue_photos WHERE venue_id=?")->execute([(int)$_POST['sil']]); $pdo->prepare("DELETE FROM venues WHERE id=?")->execute([(int)$_POST['sil']]);  $msg=['#ef4444','Mekan silindi.']; }
        if (isset($_POST['sil_user']))  { $pdo->prepare("DELETE FROM users WHERE id=?")->execute([(int)$_POST['sil_user']]); $msg=['#ef4444','Kullanıcı silindi.']; }
        if (isset($_POST['sil_yorum'])) { $pdo->prepare("DELETE FROM reviews WHERE id=?")->execute([(int)$_POST['sil_yorum']]); $msg=['#ef4444','Yorum silindi.']; }
        if (isset($_POST['onayla_sub'])) {
            $s=$pdo->prepare("SELECT * FROM submissions WHERE id=?"); $s->execute([(int)$_POST['onayla_sub']]); $sub=$s->fetch();
            if ($sub) {
                $cc = $CITY_COORDS['Ankara'];
                $pdo->prepare("INSERT INTO venues (name,category,lat,lng,rating,image_url,city,is_active,address,source,verified) VALUES (?,?,?,?,NULL,'','Ankara',1,?,'user',0)")
                    ->execute([$sub['name'],$sub['category'],$cc[0],$cc[1],$sub['address']]);
                $pdo->prepare("UPDATE submissions SET status='approved' WHERE id=?")->execute([(int)$_POST['onayla_sub']]);
                $msg=['#10b981','Bildirim onaylandı ve mekan eklendi. (Konumu düzenleyip hassaslaştırabilirsin.)'];
            }
        }
        if (isset($_POST['sil_sub'])) { $pdo->prepare("UPDATE submissions SET status='rejected' WHERE id=?")->execute([(int)$_POST['sil_sub']]); $msg=['#ef4444','Bildirim reddedildi.']; }

        /* --- İŞLETME BAŞVURUSU: onayla / reddet --- */
        if (isset($_POST['isletme_onayla'])) {
            $vid = (int)$_POST['isletme_onayla'];
            /* verified (yerinde doğrulandı rozeti) ayrı bir karardır — onayla birlikte verilmez.
               Vitrine çıkarmak istediğinde Mekanlar sekmesinden işaretlersin. */
            $pdo->prepare("UPDATE venues SET onay_durumu='onaylandi', red_nedeni=NULL, onay_tarihi=NOW(), is_active=1 WHERE id=?")->execute([$vid]);
            $msg = ['#10b981','İşletme onaylandı — mekan artık sitede yayında.'];
        }
        if (isset($_POST['isletme_reddet'])) {
            $vid = (int)$_POST['isletme_reddet'];
            $neden = trim($_POST['red_nedeni'] ?? '');
            if ($neden === '') $neden = 'Bilgilerini gözden geçirip tekrar gönderebilirsin.';
            $pdo->prepare("UPDATE venues SET onay_durumu='reddedildi', red_nedeni=?, onay_tarihi=NOW() WHERE id=?")->execute([$neden, $vid]);
            $msg = ['#ef4444','Başvuru reddedildi, gerekçe işletmeye iletildi.'];
        }
        if (isset($_POST['isletme_yayindan_kaldir'])) {
            $vid = (int)$_POST['isletme_yayindan_kaldir'];
            $pdo->prepare("UPDATE venues SET onay_durumu='beklemede' WHERE id=?")->execute([$vid]);
            $msg = ['#f59e0b','Mekan yayından kaldırıldı, tekrar incelemeye alındı.'];
        }
        if (isset($_POST['ekle'])) {
            $pdo->prepare("INSERT INTO venues (name,lat,lng,category,rating,image_url,city,is_active,address,district,price_level,alcohol,tags,phone,website,opening_hours,description,source,verified)
                           VALUES (?,?,?,?,?,?,'Ankara',1,?,?,?,?,?,?,?,?,?,'manual',?)")
                ->execute([$_POST['name'],$_POST['lat'],$_POST['lng'],$_POST['category'],($_POST['rating']!=='' ? $_POST['rating'] : null),trim($_POST['image_url'] ?? ''),
                           trim($_POST['address'] ?? ''),trim($_POST['district'] ?? 'Çankaya'),(int)($_POST['price_level'] ?? 2),(int)!empty($_POST['alcohol']),trim($_POST['tags'] ?? ''),
                           trim($_POST['phone'] ?? ''),trim($_POST['website'] ?? ''),trim($_POST['opening_hours'] ?? ''),trim($_POST['description'] ?? ''),(int)!empty($_POST['verified'])]);
            $msg=['#10b981','Yeni mekan eklendi!'];
        }
        if (isset($_POST['guncelle'])) {
            $pdo->prepare("UPDATE venues SET name=?,lat=?,lng=?,category=?,rating=?,image_url=?,city='Ankara',address=?,district=?,price_level=?,alcohol=?,tags=?,phone=?,website=?,opening_hours=?,description=?,verified=? WHERE id=?")
                ->execute([$_POST['name'],$_POST['lat'],$_POST['lng'],$_POST['category'],($_POST['rating']!=='' ? $_POST['rating'] : null),trim($_POST['image_url'] ?? ''),
                           trim($_POST['address'] ?? ''),trim($_POST['district'] ?? 'Çankaya'),(int)($_POST['price_level'] ?? 2),(int)!empty($_POST['alcohol']),trim($_POST['tags'] ?? ''),
                           trim($_POST['phone'] ?? ''),trim($_POST['website'] ?? ''),trim($_POST['opening_hours'] ?? ''),trim($_POST['description'] ?? ''),(int)!empty($_POST['verified']),(int)$_POST['id']]);
            $msg=['#3b82f6','Mekan güncellendi!'];
        }
    }
} catch (Exception $ex) { $msg=['#ef4444','İşlem hatası: '.e($ex->getMessage())]; }

$duzenlenecek = null;
$galeri = [];
if (isset($_GET['duzenle'])) {
    $s=$pdo->prepare("SELECT * FROM venues WHERE id=?"); $s->execute([$_GET['duzenle']]); $duzenlenecek=$s->fetch();
    if ($duzenlenecek) {
        try {
            $g = $pdo->prepare("SELECT * FROM venue_photos WHERE venue_id=? ORDER BY sira, id");
            $g->execute([$duzenlenecek['id']]);
            $galeri = $g->fetchAll();
        } catch (Exception $e) {}
    }
}
/* Her mekanın galeri fotoğraf sayısı (listede rozet olarak gösterilir) */
$fotoSayilari = [];
try { foreach ($pdo->query("SELECT venue_id, COUNT(*) c FROM venue_photos GROUP BY venue_id") as $r) $fotoSayilari[(int)$r['venue_id']] = (int)$r['c']; } catch (Exception $e) {}

/* İşletme başvuruları (onay bekleyen + karara bağlanmış son kayıtlar) */
$bekleyenIsletmeler = []; $digerIsletmeler = [];
try {
    $q = $pdo->query("SELECT v.*, u.fullname AS sahip_ad, u.email AS sahip_mail, u.isletme_adi, u.yetkili_unvan,
                             (SELECT COUNT(*) FROM venue_photos p WHERE p.venue_id=v.id) AS foto_adet
                      FROM venues v JOIN users u ON u.id = v.owner_id
                      WHERE v.owner_id IS NOT NULL
                      ORDER BY FIELD(v.onay_durumu,'beklemede','reddedildi','taslak','onaylandi'), v.basvuru_tarihi DESC, v.id DESC");
    foreach ($q as $r) { if ($r['onay_durumu'] === 'beklemede') $bekleyenIsletmeler[] = $r; else $digerIsletmeler[] = $r; }
} catch (Exception $e) { /* 07_isletme.sql henüz çalıştırılmadıysa sessizce boş kalır */ }

$ara = trim($_GET['ara'] ?? '');
if ($ara !== '') { $vq=$pdo->prepare("SELECT * FROM venues WHERE name LIKE ? ORDER BY id DESC"); $vq->execute(['%'.$ara.'%']); $venues=$vq->fetchAll(); }
else $venues = $pdo->query("SELECT * FROM venues ORDER BY id DESC")->fetchAll();

$users = $pdo->query("SELECT id,fullname,email,created_at FROM users ORDER BY id DESC")->fetchAll();

$totalVenues = $pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn();
$totalUsers  = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$avgRating   = $pdo->query("SELECT ROUND(AVG(rating),1) FROM venues")->fetchColumn() ?: 0;
$catCounts   = $pdo->query("SELECT category, COUNT(*) c FROM venues GROUP BY category")->fetchAll();

// Yorumlar (tablo yoksa hata vermesin)
$totalReviews = 0; $reviews = [];
try {
    $totalReviews = $pdo->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
    $reviews = $pdo->query("SELECT r.id, r.user_name, r.rating, r.comment, r.created_at, v.name AS venue
                            FROM reviews r LEFT JOIN venues v ON v.id=r.venue_id
                            ORDER BY r.id DESC LIMIT 50")->fetchAll();
} catch (Exception $e) {}

// Yayındaki mekan sayısı (onay bekleyenler hariç)
$yayindaMekan = $totalVenues;
try { $yayindaMekan = $pdo->query("SELECT COUNT(*) FROM venues WHERE onay_durumu='onaylandi'")->fetchColumn(); } catch (Exception $e) {}
?>
<!DOCTYPE html><html lang="tr"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Yönetim Paneli — Civari</title>
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com https://cdn.jsdelivr.net https://cdn.tailwindcss.com https://www.googletagmanager.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob: https:; connect-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'">
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;800&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>body{font-family:'Outfit',sans-serif}h1,h2,.brand{font-family:'Bricolage Grotesque'}.tab.active{background:#ff9900;color:#fff}.card-stat{background:linear-gradient(135deg,#0f172a,#1e293b)}</style>
</head><body class="bg-gray-950 text-white min-h-screen">

<header class="bg-black border-b border-white/10 sticky top-0 z-50">
  <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
    <div class="brand text-2xl font-extrabold text-orange-500">Civari<span class="text-white">.</span> <span class="text-sm font-sans text-gray-400 ml-2">Yönetim</span></div>
    <div class="flex items-center gap-3">
      <a href="index.html" target="_blank" class="text-sm text-gray-300 hover:text-white">Siteyi Gör ↗</a>
      <a href="?logout=1" class="bg-gray-800 hover:bg-gray-700 px-4 py-2 rounded-lg text-sm">Çıkış</a>
    </div>
  </div>
</header>

<main class="max-w-7xl mx-auto px-6 py-8">
  <?php if($msg): ?><div style="background:<?= $msg[0] ?>" class="text-white px-4 py-3 rounded-lg mb-6 font-semibold"><?= e($msg[1]) ?></div><?php endif; ?>

  <h2 class="text-xl font-bold mb-4">Genel Bakış</h2>
  <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-10">
    <div class="card-stat p-5 rounded-2xl border border-white/5"><div class="text-gray-400 text-sm">Yayında Mekan</div><div class="text-3xl font-extrabold text-orange-500 mt-1"><?= $yayindaMekan ?></div></div>
    <?php $bekleyenSayi = count($bekleyenIsletmeler); ?>
    <div class="card-stat p-5 rounded-2xl border <?= $bekleyenSayi ? 'border-orange-500/60' : 'border-white/5' ?>">
      <div class="text-gray-400 text-sm">Onay Bekleyen</div>
      <div class="text-3xl font-extrabold mt-1 <?= $bekleyenSayi ? 'text-orange-400' : 'text-gray-600' ?>"><?= $bekleyenSayi ?></div>
    </div>
    <div class="card-stat p-5 rounded-2xl border border-white/5"><div class="text-gray-400 text-sm">Kullanıcı</div><div class="text-3xl font-extrabold text-orange-500 mt-1"><?= $totalUsers ?></div></div>
    <div class="card-stat p-5 rounded-2xl border border-white/5"><div class="text-gray-400 text-sm">Yorum</div><div class="text-3xl font-extrabold text-orange-500 mt-1"><?= $totalReviews ?></div></div>
    <div class="card-stat p-5 rounded-2xl border border-white/5"><div class="text-gray-400 text-sm">Ort. Puan</div><div class="text-3xl font-extrabold text-orange-500 mt-1">⭐ <?= $avgRating ?></div></div>
  </div>

  <div class="bg-gray-900 rounded-2xl p-6 mb-10 border border-white/5">
    <h3 class="font-bold mb-4">Kategori Dağılımı</h3>
    <div class="space-y-3">
      <?php foreach($catCounts as $cc): $pct=$totalVenues?round($cc['c']/$totalVenues*100):0; ?>
      <div><div class="flex justify-between text-sm mb-1"><span><?= e($cc['category']) ?></span><span class="text-gray-400"><?= $cc['c'] ?> mekan</span></div>
        <div class="h-2 bg-gray-800 rounded-full overflow-hidden"><div class="h-full bg-orange-500" style="width:<?= $pct ?>%"></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="flex gap-2 mb-6 flex-wrap">
    <button class="tab active px-5 py-2 rounded-full font-semibold bg-gray-800" onclick="showTab('mekanlar',this)">Mekanlar</button>
    <button class="tab px-5 py-2 rounded-full font-semibold bg-gray-800" onclick="showTab('kullanicilar',this)">Kullanıcılar (<?= $totalUsers ?>)</button>
    <button class="tab px-5 py-2 rounded-full font-semibold bg-gray-800" onclick="showTab('yorumlar',this)">Yorumlar (<?= $totalReviews ?>)</button>
    <button class="tab px-5 py-2 rounded-full font-semibold bg-gray-800" onclick="showTab('isletmeler',this)">İşletme Başvuruları <?php if(count($bekleyenIsletmeler)): ?><span class="ml-1 bg-orange-500 text-white text-xs px-2 py-0.5 rounded-full"><?= count($bekleyenIsletmeler) ?></span><?php endif; ?></button>
  </div>

  <!-- İŞLETME BAŞVURULARI -->
  <section id="tab-isletmeler" class="hidden">
    <div class="bg-gray-900 p-6 rounded-2xl border border-white/5 mb-6">
      <h3 class="font-bold text-lg mb-1">Onay Bekleyen İşletmeler</h3>
      <p class="text-gray-400 text-sm mb-5">Onaylanana kadar bu mekanlar sitenin hiçbir yerinde görünmez. İşletmeye 24 saat içinde dönüleceği bildirildi.</p>

      <?php if (!count($bekleyenIsletmeler)): ?>
        <div class="text-gray-500 py-8 text-center">Bekleyen başvuru yok. 🎉</div>
      <?php else: ?>
        <div class="space-y-4">
        <?php foreach ($bekleyenIsletmeler as $b): ?>
          <div class="bg-gray-800/60 rounded-2xl p-5 border border-white/5">
            <div class="flex flex-wrap items-start justify-between gap-4 mb-3">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <h4 class="font-bold text-lg"><?= e($b['name']) ?></h4>
                  <span class="text-xs bg-orange-500 text-white px-2 py-0.5 rounded-full font-bold"><?= e($b['category']) ?></span>
                  <span class="text-xs bg-gray-700 text-gray-300 px-2 py-0.5 rounded-full">📷 <?= (int)$b['foto_adet'] ?></span>
                </div>
                <p class="text-gray-400 text-sm mt-1"><?= e($b['address'] ?: '(adres yok)') ?> · <?= e($b['district']) ?></p>
                <p class="text-gray-500 text-xs mt-1">
                  Sahip: <?= e($b['sahip_ad']) ?><?= $b['yetkili_unvan'] ? ' (' . e($b['yetkili_unvan']) . ')' : '' ?> ·
                  <a href="mailto:<?= e($b['sahip_mail']) ?>" class="text-orange-400"><?= e($b['sahip_mail']) ?></a>
                  <?php if($b['phone']): ?> · <?= e($b['phone']) ?><?php endif; ?>
                </p>
                <?php if($b['basvuru_tarihi']): ?>
                  <p class="text-gray-600 text-xs mt-1">Başvuru: <?= e(date('j.m.Y H:i', strtotime((string)$b['basvuru_tarihi']))) ?></p>
                <?php endif; ?>
              </div>
              <a href="mekan.php?id=<?= (int)$b['id'] ?>" target="_blank" rel="noopener"
                 class="text-sm bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg whitespace-nowrap">Önizle ↗</a>
            </div>

            <?php if($b['description']): ?>
              <p class="text-gray-300 text-sm bg-black/25 rounded-xl p-3 mb-3"><?= e(mb_substr($b['description'], 0, 400)) ?></p>
            <?php endif; ?>

            <div class="text-xs text-gray-500 mb-4 flex flex-wrap gap-x-4 gap-y-1">
              <span>Konum: <?= e($b['lat']) ?>, <?= e($b['lng']) ?></span>
              <?php if($b['opening_hours']): ?><span>Saatler: <?= e($b['opening_hours']) ?></span><?php endif; ?>
              <?php if($b['website']): ?><span>Web: <?= e($b['website']) ?></span><?php endif; ?>
            </div>

            <div class="flex flex-wrap gap-2 items-center">
              <form method="POST" class="inline"><?= csrf_field() ?>
                <button name="isletme_onayla" value="<?= (int)$b['id'] ?>"
                        class="bg-green-600 hover:bg-green-500 text-white px-5 py-2.5 rounded-lg font-bold">✓ Onayla ve Yayınla</button>
              </form>
              <form method="POST" class="inline flex gap-2 items-center flex-wrap" onsubmit="return confirm('Başvuru reddedilsin mi?')">
                <?= csrf_field() ?>
                <input name="red_nedeni" placeholder="Düzeltme gerekçesi (işletme görecek)"
                       class="p-2.5 rounded-lg text-black text-sm w-72 max-w-full">
                <button name="isletme_reddet" value="<?= (int)$b['id'] ?>"
                        class="bg-red-600 hover:bg-red-500 text-white px-5 py-2.5 rounded-lg font-bold">✕ Düzeltme İste</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (count($digerIsletmeler)): ?>
    <div class="bg-gray-900 p-6 rounded-2xl border border-white/5">
      <h3 class="font-bold text-lg mb-4">Diğer İşletme Hesapları (<?= count($digerIsletmeler) ?>)</h3>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="text-gray-400 border-b border-white/10"><tr><th class="p-3">Mekan</th><th class="p-3">Sahip</th><th class="p-3">Durum</th><th class="p-3">İşlem</th></tr></thead>
        <tbody>
          <?php foreach ($digerIsletmeler as $d):
            $renk = ['onaylandi'=>'bg-green-600','reddedildi'=>'bg-red-600','taslak'=>'bg-gray-600'][$d['onay_durumu']] ?? 'bg-gray-600';
            $etiket = ['onaylandi'=>'Yayında','reddedildi'=>'Düzeltme bekliyor','taslak'=>'Taslak'][$d['onay_durumu']] ?? $d['onay_durumu']; ?>
            <tr class="border-b border-white/5">
              <td class="p-3 font-medium"><?= e($d['name']) ?><span class="text-gray-500 text-xs ml-2">📷 <?= (int)$d['foto_adet'] ?></span></td>
              <td class="p-3 text-gray-400"><?= e($d['sahip_ad']) ?><br><span class="text-xs"><?= e($d['sahip_mail']) ?></span></td>
              <td class="p-3"><span class="<?= $renk ?> text-white text-xs px-2.5 py-1 rounded-full font-bold"><?= e($etiket) ?></span></td>
              <td class="p-3 whitespace-nowrap">
                <a href="mekan.php?id=<?= (int)$d['id'] ?>" target="_blank" rel="noopener" class="bg-gray-700 hover:bg-gray-600 px-3 py-1.5 rounded text-white mr-1">Gör</a>
                <?php if($d['onay_durumu'] === 'onaylandi'): ?>
                  <form method="POST" class="inline" onsubmit="return confirm('Mekan yayından kaldırılsın mı?')"><?= csrf_field() ?>
                    <button name="isletme_yayindan_kaldir" value="<?= (int)$d['id'] ?>" class="bg-amber-600 hover:bg-amber-500 px-3 py-1.5 rounded text-white">Yayından Kaldır</button>
                  </form>
                <?php else: ?>
                  <form method="POST" class="inline"><?= csrf_field() ?>
                    <button name="isletme_onayla" value="<?= (int)$d['id'] ?>" class="bg-green-600 hover:bg-green-500 px-3 py-1.5 rounded text-white">Onayla</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>
  </section>

  <!-- MEKANLAR -->
  <section id="tab-mekanlar" class="grid lg:grid-cols-3 gap-6">
    <div class="bg-gray-900 p-6 rounded-2xl h-fit border border-white/5">
      <h3 class="font-bold mb-4 text-lg"><?= $duzenlenecek ? 'Mekanı Düzenle' : 'Yeni Mekan Ekle' ?></h3>
      <form method="POST" class="space-y-3 text-black">
        <?= csrf_field() ?>
        <?php if($duzenlenecek): ?><input type="hidden" name="id" value="<?= e($duzenlenecek['id']) ?>"><?php endif; ?>
        <input name="name" value="<?= e($duzenlenecek['name'] ?? '') ?>" placeholder="Mekan Adı" required class="w-full p-3 rounded-lg outline-none focus:ring-2 ring-orange-500">
        <div class="flex gap-2"><input name="lat" value="<?= e($duzenlenecek['lat'] ?? '39.9208') ?>" placeholder="Enlem" class="w-1/2 p-3 rounded-lg"><input name="lng" value="<?= e($duzenlenecek['lng'] ?? '32.8541') ?>" placeholder="Boylam" class="w-1/2 p-3 rounded-lg"></div>
        <div class="flex gap-2">
          <select name="category" class="w-1/2 p-3 rounded-lg"><?php foreach($cats as $c): ?><option value="<?= e($c) ?>" <?= (($duzenlenecek['category'] ?? '')===$c)?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?></select>
          <input name="district" value="<?= e($duzenlenecek['district'] ?? 'Çankaya') ?>" placeholder="İlçe" class="w-1/2 p-3 rounded-lg">
        </div>
        <input name="address" value="<?= e($duzenlenecek['address'] ?? '') ?>" placeholder="Açık adres (cadde, no)" class="w-full p-3 rounded-lg">
        <textarea name="description" rows="3" placeholder="Tanıtım metni (vitrinde ve mekan sayfasında görünür)" class="w-full p-3 rounded-lg"><?= e($duzenlenecek['description'] ?? '') ?></textarea>
        <div class="flex gap-2">
          <select name="price_level" class="w-1/2 p-3 rounded-lg">
            <?php foreach([1=>'₺ Uygun',2=>'₺₺ Orta',3=>'₺₺₺ Pahalı'] as $pl=>$pt): ?><option value="<?= $pl ?>" <?= ((int)($duzenlenecek['price_level'] ?? 2)===$pl)?'selected':'' ?>><?= $pt ?></option><?php endforeach; ?>
          </select>
          <input type="number" step="0.1" min="0" max="5" name="rating" value="<?= e($duzenlenecek['rating'] ?? '') ?>" placeholder="Puan (boş = Yeni)" class="w-1/2 p-3 rounded-lg">
        </div>
        <input name="opening_hours" value="<?= e($duzenlenecek['opening_hours'] ?? '') ?>" placeholder="Çalışma saati — örn: Mo-Sa 09:00-23:00; Su off" class="w-full p-3 rounded-lg">
        <div class="flex gap-2">
          <input name="phone" value="<?= e($duzenlenecek['phone'] ?? '') ?>" placeholder="Telefon" class="w-1/2 p-3 rounded-lg">
          <input name="website" value="<?= e($duzenlenecek['website'] ?? '') ?>" placeholder="Web sitesi" class="w-1/2 p-3 rounded-lg">
        </div>
        <input name="tags" value="<?= e($duzenlenecek['tags'] ?? '') ?>" placeholder="Etiketler (virgüllü: kahve,çalışma)" class="w-full p-3 rounded-lg">
        <input name="image_url" value="<?= e($duzenlenecek['image_url'] ?? '') ?>" placeholder="Fotoğraf URL (boş = şık fotoğrafsız kart)" class="w-full p-3 rounded-lg">
        <div class="flex gap-4 text-white text-sm px-1">
          <label class="flex items-center gap-2"><input type="checkbox" name="alcohol" value="1" <?= !empty($duzenlenecek['alcohol'])?'checked':'' ?>> Alkollü</label>
          <label class="flex items-center gap-2"><input type="checkbox" name="verified" value="1" <?= !empty($duzenlenecek['verified'])?'checked':'' ?>> Doğrulanmış (vitrin)</label>
        </div>
        <?php if($duzenlenecek): ?>
          <button name="guncelle" value="1" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold p-3 rounded-lg">GÜNCELLE</button>
          <a href="admin.php" class="block text-center text-gray-400 mt-2 hover:text-white">İptal</a>
        <?php else: ?><button name="ekle" value="1" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold p-3 rounded-lg">MEKAN EKLE</button><?php endif; ?>
      </form>

      <?php if($duzenlenecek): ?>
      <!-- FOTOĞRAF GALERİSİ -->
      <div class="mt-8 pt-6 border-t border-white/10">
        <h3 class="font-bold mb-1 text-lg">Fotoğraf Galerisi</h3>
        <p class="text-gray-400 text-xs mb-4">
          Birden çok fotoğraf seçebilirsin. Yüklenen fotoğraflar mekan sayfasında kaydırmalı galeri olarak görünür.
          <span class="text-orange-400">Yalnızca kendi çektiğin ya da izin aldığın fotoğrafları yükle.</span>
        </p>

        <form method="POST" enctype="multipart/form-data" class="space-y-3 mb-5">
          <?= csrf_field() ?>
          <input type="hidden" name="foto_venue_id" value="<?= (int)$duzenlenecek['id'] ?>">
          <input type="file" name="fotolar[]" accept="image/jpeg,image/png,image/webp" multiple required
                 class="w-full text-sm text-gray-300 file:mr-3 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:bg-orange-500 file:text-white file:font-bold file:cursor-pointer hover:file:bg-orange-600">
          <input name="foto_baslik" placeholder="Açıklama (isteğe bağlı, örn: İç mekan)" class="w-full p-3 rounded-lg text-black">
          <label class="flex items-start gap-2 text-xs text-gray-300 px-1">
            <input type="checkbox" name="foto_temsili" value="1" class="mt-0.5">
            <span>Bu bir <b>temsili görsel</b> (mekanın kendi fotoğrafı değil). İşaretlersen sitede "Temsili görsel" etiketiyle gösterilir.</span>
          </label>
          <button name="foto_ekle" value="1" class="w-full bg-orange-500 hover:bg-orange-600 text-white font-bold p-3 rounded-lg">FOTOĞRAF YÜKLE</button>
        </form>

        <?php if($galeri): ?>
          <div class="space-y-2">
            <?php foreach($galeri as $i => $foto):
              $kapakMi = trim((string)$duzenlenecek['image_url']) === $foto['url']; ?>
              <div class="flex items-center gap-3 bg-gray-800/60 rounded-xl p-2">
                <img src="<?= e($foto['url']) ?>" class="w-14 h-14 rounded-lg object-cover flex-shrink-0" onerror="this.style.opacity='.3'">
                <div class="flex-1 min-w-0">
                  <div class="text-sm font-semibold truncate"><?= e($foto['baslik'] ?: 'Fotoğraf ' . ($i+1)) ?></div>
                  <div class="text-xs text-gray-500 truncate"><?= e($foto['url']) ?></div>
                  <?php if($kapakMi): ?><span class="inline-block mt-1 text-[10px] bg-orange-500 text-white px-2 py-0.5 rounded-full font-bold">KAPAK</span><?php endif; ?>
                </div>
                <div class="flex items-center gap-1 flex-shrink-0">
                  <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="yon" value="yukari"><button name="foto_tasi" value="<?= (int)$foto['id'] ?>" title="Yukarı taşı" class="bg-gray-700 hover:bg-gray-600 w-8 h-8 rounded-lg text-white">↑</button></form>
                  <form method="POST" class="inline"><?= csrf_field() ?><input type="hidden" name="yon" value="asagi"><button name="foto_tasi" value="<?= (int)$foto['id'] ?>" title="Aşağı taşı" class="bg-gray-700 hover:bg-gray-600 w-8 h-8 rounded-lg text-white">↓</button></form>
                  <?php if(!$kapakMi): ?>
                    <form method="POST" class="inline"><?= csrf_field() ?><button name="foto_kapak" value="<?= (int)$foto['id'] ?>" title="Kapak yap" class="bg-blue-600 hover:bg-blue-500 w-8 h-8 rounded-lg text-white">★</button></form>
                  <?php endif; ?>
                  <form method="POST" class="inline" onsubmit="return confirm('Bu fotoğraf silinsin mi?')"><?= csrf_field() ?><button name="foto_sil" value="<?= (int)$foto['id'] ?>" title="Sil" class="bg-red-600 hover:bg-red-500 w-8 h-8 rounded-lg text-white">✕</button></form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <p class="text-gray-500 text-xs mt-3">↑↓ sırayı değiştirir · ★ kapak fotoğrafı yapar · ✕ siler</p>
        <?php else: ?>
          <div class="text-gray-500 text-sm text-center py-6 border border-dashed border-white/10 rounded-xl">
            Bu mekanda henüz fotoğraf yok.<br>Yukarıdan yükleyebilirsin.
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="lg:col-span-2 bg-gray-900 p-6 rounded-2xl border border-white/5">
      <div class="flex justify-between items-center mb-4"><h3 class="font-bold text-lg">Mekanlar (<?= count($venues) ?>)</h3>
        <form method="GET" class="flex gap-2"><input name="ara" value="<?= e($ara) ?>" placeholder="Ara..." class="p-2 rounded-lg text-black text-sm"><button class="bg-orange-500 hover:bg-orange-600 px-4 rounded-lg text-sm font-bold">Ara</button></form></div>
      <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="text-gray-400 border-b border-white/10"><tr><th class="p-3">Mekan</th><th class="p-3">Kategori</th><th class="p-3">Puan</th><th class="p-3">İşlem</th></tr></thead>
        <tbody>
          <?php foreach($venues as $v): ?>
          <tr class="border-b border-white/5 hover:bg-white/5">
            <td class="p-3 font-medium">
              <div class="flex items-center gap-3">
                <?php if(trim((string)$v['image_url']) !== ''): ?>
                  <img src="<?= e($v['image_url']) ?>" class="w-10 h-10 rounded-lg object-cover flex-shrink-0" onerror="this.style.display='none'">
                <?php else: ?>
                  <span class="w-10 h-10 rounded-lg bg-gray-700 flex items-center justify-center text-gray-400 text-xs flex-shrink-0">yok</span>
                <?php endif; ?>
                <span><?= e($v['name']) ?></span>
                <?php $fc = $fotoSayilari[(int)$v['id']] ?? 0; if($fc > 0): ?>
                  <span class="text-[10px] bg-gray-700 text-gray-300 px-2 py-0.5 rounded-full whitespace-nowrap">📷 <?= $fc ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="p-3 text-orange-400"><?= e($v['category']) ?></td><td class="p-3 font-bold">⭐ <?= e($v['rating']) ?></td>
            <td class="p-3 whitespace-nowrap"><a href="?duzenle=<?= e($v['id']) ?>" class="bg-blue-500 hover:bg-blue-400 px-3 py-1 rounded text-white mr-1">Düzenle</a><form method="POST" class="inline" onsubmit="return confirm('Silinsin mi?')"><?= csrf_field() ?><button name="sil" value="<?= e($v['id']) ?>" class="bg-red-500 hover:bg-red-400 px-3 py-1 rounded text-white">Sil</button></form></td>
          </tr>
          <?php endforeach; if(!$venues): ?><tr><td colspan="4" class="p-6 text-center text-gray-500">Kayıt yok.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
  </section>

  <!-- KULLANICILAR -->
  <section id="tab-kullanicilar" class="hidden bg-gray-900 p-6 rounded-2xl border border-white/5">
    <h3 class="font-bold text-lg mb-4">Kayıtlı Kullanıcılar</h3>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
      <thead class="text-gray-400 border-b border-white/10"><tr><th class="p-3">#</th><th class="p-3">Ad Soyad</th><th class="p-3">E-posta</th><th class="p-3">Kayıt</th><th class="p-3">İşlem</th></tr></thead>
      <tbody>
        <?php foreach($users as $u): ?>
        <tr class="border-b border-white/5 hover:bg-white/5"><td class="p-3 text-gray-500"><?= e($u['id']) ?></td><td class="p-3 font-medium"><?= e($u['fullname']) ?></td><td class="p-3 text-gray-300"><?= e($u['email']) ?></td><td class="p-3 text-gray-400"><?= e($u['created_at']) ?></td><td class="p-3"><form method="POST" class="inline" onsubmit="return confirm('Silinsin mi?')"><?= csrf_field() ?><button name="sil_user" value="<?= e($u['id']) ?>" class="bg-red-500 hover:bg-red-400 px-3 py-1 rounded text-white">Sil</button></form></td></tr>
        <?php endforeach; if(!$users): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">Henüz kullanıcı yok.</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>

  <!-- YORUMLAR -->
  <section id="tab-yorumlar" class="hidden bg-gray-900 p-6 rounded-2xl border border-white/5">
    <h3 class="font-bold text-lg mb-4">Son Yorumlar (Moderasyon)</h3>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
      <thead class="text-gray-400 border-b border-white/10"><tr><th class="p-3">Kullanıcı</th><th class="p-3">Mekan</th><th class="p-3">Puan</th><th class="p-3">Yorum</th><th class="p-3">İşlem</th></tr></thead>
      <tbody>
        <?php foreach($reviews as $r): ?>
        <tr class="border-b border-white/5 hover:bg-white/5"><td class="p-3 font-medium"><?= e($r['user_name']) ?></td><td class="p-3 text-orange-400"><?= e($r['venue']) ?></td><td class="p-3">⭐ <?= e($r['rating']) ?></td><td class="p-3 text-gray-300 max-w-md"><?= e($r['comment']) ?></td><td class="p-3"><form method="POST" class="inline" onsubmit="return confirm('Yorum silinsin mi?')"><?= csrf_field() ?><button name="sil_yorum" value="<?= e($r['id']) ?>" class="bg-red-500 hover:bg-red-400 px-3 py-1 rounded text-white">Sil</button></form></td></tr>
        <?php endforeach; if(!$reviews): ?><tr><td colspan="5" class="p-6 text-center text-gray-500">Henüz yorum yok. (Yorum tablosunu kurmak için upgrade.sql'i çalıştırın.)</td></tr><?php endif; ?>
      </tbody></table></div>
  </section>
</main>

<script>
function showTab(name, btn){
  ['mekanlar','kullanicilar','yorumlar','isletmeler'].forEach(function(n){
    var el = document.getElementById('tab-'+n);
    if (el) el.classList.toggle('hidden', n!==name);
  });
  document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
}
</script>
</body></html>
