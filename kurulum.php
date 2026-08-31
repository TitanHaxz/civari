<?php
/*
 * CİVARİ — VERİTABANI KURULUM SAYFASI
 * ===================================
 * Tarayıcıdan aç:  https://civari.com.tr/kurulum.php?token=KURULUM_ANAHTARI
 *
 * NE YAPAR?
 *   Veritabanındaki eksik tablo ve sütunları bulur, sadece eksik olanları ekler.
 *   Mevcut hiçbir veriye dokunmaz. Defalarca çalıştırılabilir.
 *
 * NEDEN VAR?
 *   "ALTER TABLE ... ADD COLUMN IF NOT EXISTS" yazımı yalnızca MariaDB'de çalışır;
 *   MySQL'de sözdizimi hatası verir ve dosyanın tamamı uygulanmaz. Bu sayfa her iki
 *   veritabanında da doğru çalışır — hangi sürümü kullandığını bilmene gerek kalmaz.
 *
 * GÜVENLİK: Aşağıdaki anahtarı bilmeyen bu sayfayı açamaz.
 *           Kurulum bittikten sonra bu dosyayı sunucudan SİLMENİ öneririz.
 */

$KURULUM_ANAHTARI = 'kurulum-145a63afd421176f';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
@set_time_limit(120);
require __DIR__ . '/db.php';

if (!hash_equals($KURULUM_ANAHTARI, (string)($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('403 — Geçersiz anahtar.');
}
if ($pdo === null) {
    exit('Veritabanına bağlanılamadı. db.php içindeki bilgileri kontrol et.');
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ---------- Yardımcılar (MySQL + MariaDB uyumlu) ---------- */
function tabloVar(PDO $pdo, string $tablo): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->execute([$tablo]);
    return (int)$s->fetchColumn() > 0;
}
function sutunVar(PDO $pdo, string $tablo, string $sutun): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->execute([$tablo, $sutun]);
    return (int)$s->fetchColumn() > 0;
}
function indeksVar(PDO $pdo, string $tablo, string $indeks): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->execute([$tablo, $indeks]);
    return (int)$s->fetchColumn() > 0;
}

$sonuclar = [];
function kaydet(string $durum, string $mesaj) { global $sonuclar; $sonuclar[] = [$durum, $mesaj]; }

function sutunEkle(PDO $pdo, string $tablo, string $sutun, string $tanim) {
    if (!tabloVar($pdo, $tablo)) { kaydet('atla', "$tablo tablosu yok — $sutun eklenmedi"); return; }
    if (sutunVar($pdo, $tablo, $sutun)) { kaydet('var', "$tablo.$sutun zaten var"); return; }
    try {
        $pdo->exec("ALTER TABLE `$tablo` ADD COLUMN `$sutun` $tanim");
        kaydet('ekle', "$tablo.$sutun eklendi");
    } catch (Throwable $ex) { kaydet('hata', "$tablo.$sutun eklenemedi: " . $ex->getMessage()); }
}
function indeksEkle(PDO $pdo, string $tablo, string $indeks, string $sutunlar) {
    if (!tabloVar($pdo, $tablo)) return;
    if (indeksVar($pdo, $tablo, $indeks)) { kaydet('var', "$tablo/$indeks indeksi zaten var"); return; }
    try {
        $pdo->exec("ALTER TABLE `$tablo` ADD INDEX `$indeks` ($sutunlar)");
        kaydet('ekle', "$tablo/$indeks indeksi eklendi");
    } catch (Throwable $ex) { kaydet('hata', "$tablo/$indeks eklenemedi: " . $ex->getMessage()); }
}

$calistir = isset($_GET['calistir']);

if ($calistir) {
    /* ---------- 1) OSM alanları (01_schema_osm.sql karşılığı) ---------- */
    sutunEkle($pdo, 'venues', 'osm_type',      'VARCHAR(10) NULL');
    sutunEkle($pdo, 'venues', 'osm_id',        'BIGINT NULL');
    sutunEkle($pdo, 'venues', 'district',      'VARCHAR(80) NULL');
    sutunEkle($pdo, 'venues', 'source',        "VARCHAR(20) NOT NULL DEFAULT 'manual'");
    sutunEkle($pdo, 'venues', 'verified',      'TINYINT(1) NOT NULL DEFAULT 0');
    sutunEkle($pdo, 'venues', 'website',       'VARCHAR(255) NULL');
    sutunEkle($pdo, 'venues', 'opening_hours', 'VARCHAR(255) NULL');
    sutunEkle($pdo, 'venues', 'updated_at',    'TIMESTAMP NULL DEFAULT NULL');
    indeksEkle($pdo, 'venues', 'idx_name',     'name');
    indeksEkle($pdo, 'venues', 'idx_city',     'city');
    indeksEkle($pdo, 'venues', 'idx_category', 'category');

    /* ---------- 2) Kullanıcı alanları (profil + KVKK + işletme) ---------- */
    sutunEkle($pdo, 'users', 'avatar',           'VARCHAR(500) NULL');   // profil fotoğrafı
    sutunEkle($pdo, 'users', 'phone',            'VARCHAR(30) NULL');
    sutunEkle($pdo, 'users', 'province',         'VARCHAR(80) NULL');
    sutunEkle($pdo, 'users', 'district',         'VARCHAR(80) NULL');
    sutunEkle($pdo, 'users', 'birth_year',       'SMALLINT NULL');
    sutunEkle($pdo, 'users', 'kvkk_onay_tarihi', 'DATETIME NULL');
    sutunEkle($pdo, 'users', 'kvkk_onay_surumu', 'VARCHAR(20) NULL');
    sutunEkle($pdo, 'users', 'hesap_turu',       "VARCHAR(20) NOT NULL DEFAULT 'bireysel'");
    sutunEkle($pdo, 'users', 'isletme_adi',      'VARCHAR(180) NULL');
    sutunEkle($pdo, 'users', 'yetkili_unvan',    'VARCHAR(120) NULL');

    /* ---------- 3) İşletme sahipliği ve onay akışı ---------- */
    sutunEkle($pdo, 'venues', 'owner_id',       'INT NULL');
    sutunEkle($pdo, 'venues', 'onay_durumu',    "VARCHAR(20) NOT NULL DEFAULT 'onaylandi'");
    sutunEkle($pdo, 'venues', 'red_nedeni',     'VARCHAR(500) NULL');
    sutunEkle($pdo, 'venues', 'basvuru_tarihi', 'DATETIME NULL');
    sutunEkle($pdo, 'venues', 'onay_tarihi',    'DATETIME NULL');
    indeksEkle($pdo, 'venues', 'idx_owner', 'owner_id');
    indeksEkle($pdo, 'venues', 'idx_onay',  'onay_durumu');

    /* ---------- 4) Fotoğraf galerisi tablosu ---------- */
    if (!tabloVar($pdo, 'venue_photos')) {
        try {
            $pdo->exec("CREATE TABLE venue_photos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                venue_id INT NOT NULL,
                url VARCHAR(500) NOT NULL,
                baslik VARCHAR(200) NULL,
                temsili TINYINT(1) NOT NULL DEFAULT 0,
                sira INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_venue (venue_id, sira)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            kaydet('ekle', 'venue_photos tablosu oluşturuldu');
        } catch (Throwable $ex) { kaydet('hata', 'venue_photos oluşturulamadı: ' . $ex->getMessage()); }
    } else {
        kaydet('var', 'venue_photos tablosu zaten var');
        sutunEkle($pdo, 'venue_photos', 'temsili', 'TINYINT(1) NOT NULL DEFAULT 0');
    }

    /* ---------- 5) Mevcut mekanlar yayında kalsın ---------- */
    try {
        $n = $pdo->exec("UPDATE venues SET onay_durumu='onaylandi'
                         WHERE owner_id IS NULL AND (onay_durumu IS NULL OR onay_durumu='')");
        if ($n > 0) kaydet('ekle', "$n mevcut mekan 'onaylandı' olarak işaretlendi");
    } catch (Throwable $ex) { kaydet('hata', 'Mevcut mekanlar güncellenemedi: ' . $ex->getMessage()); }
}

/* ---------- Mevcut durum özeti ---------- */
$ozet = [];
try {
    $ozet['Sürücü']  = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $ozet['Mekan (toplam)'] = $pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn();
    if (sutunVar($pdo, 'venues', 'onay_durumu')) {
        $ozet['Yayında mekan']   = $pdo->query("SELECT COUNT(*) FROM venues WHERE onay_durumu='onaylandi'")->fetchColumn();
        $ozet['Onay bekleyen']   = $pdo->query("SELECT COUNT(*) FROM venues WHERE onay_durumu='beklemede'")->fetchColumn();
    }
    $ozet['Kullanıcı'] = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if (sutunVar($pdo, 'users', 'hesap_turu')) {
        $ozet['İşletme hesabı'] = $pdo->query("SELECT COUNT(*) FROM users WHERE hesap_turu='isletme'")->fetchColumn();
    }
    if (tabloVar($pdo, 'venue_photos')) {
        $ozet['Galeri fotoğrafı'] = $pdo->query("SELECT COUNT(*) FROM venue_photos")->fetchColumn();
    }
} catch (Throwable $ex) { $ozet['Hata'] = $ex->getMessage(); }

/* Eksik kalan kritik alanlar */
$kritik = [
    'users.avatar'        => sutunVar($pdo, 'users', 'avatar'),
    'users.hesap_turu'    => sutunVar($pdo, 'users', 'hesap_turu'),
    'venues.owner_id'     => sutunVar($pdo, 'venues', 'owner_id'),
    'venues.onay_durumu'  => sutunVar($pdo, 'venues', 'onay_durumu'),
    'venue_photos tablosu'=> tabloVar($pdo, 'venue_photos'),
];
$hepsiTamam = !in_array(false, $kritik, true);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Veritabanı Kurulumu — Civari</title>
<style>
  :root{--primary:#ff9900;--primary-dark:#e07f00;--ink:#1c1c1e;--muted:#64748b;--gray:#d2d2d1;
        --ok:#15803d;--ok-bg:#dcfce7;--red:#b91c1c;--red-bg:#fee2e2;--sari:#b45309;--sari-bg:#fef3c7}
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:#f7f7f8;color:var(--ink);
       line-height:1.6;padding:32px 18px 70px}
  .sar{max-width:760px;margin:0 auto}
  h1{font-size:1.7rem;margin-bottom:6px;letter-spacing:-.5px}
  .alt{color:var(--muted);margin-bottom:26px;font-size:.95rem}
  .kart{background:#fff;border:1px solid var(--gray);border-radius:16px;padding:22px;margin-bottom:16px}
  .kart h2{font-size:1.1rem;margin-bottom:14px}
  table{width:100%;border-collapse:collapse;font-size:.92rem}
  td{padding:9px 0;border-bottom:1px solid #f1f5f9}
  td:last-child{text-align:right;font-weight:700;font-variant-numeric:tabular-nums}
  tr:last-child td{border-bottom:none}
  .satir{display:flex;align-items:flex-start;gap:10px;padding:7px 0;font-size:.9rem;border-bottom:1px solid #f8fafc}
  .satir:last-child{border-bottom:none}
  .rozet{font-size:.68rem;font-weight:800;padding:3px 9px;border-radius:20px;flex-shrink:0;letter-spacing:.4px;margin-top:2px}
  .r-ekle{background:var(--ok-bg);color:var(--ok)} .r-var{background:#f1f5f9;color:var(--muted)}
  .r-hata{background:var(--red-bg);color:var(--red)} .r-atla{background:var(--sari-bg);color:var(--sari)}
  .btn{display:inline-block;background:var(--primary);color:#fff;text-decoration:none;font-weight:800;
       padding:15px 32px;border-radius:13px;font-size:1rem;transition:.2s;border:none;cursor:pointer}
  .btn:hover{background:var(--primary-dark)}
  .durum{border-radius:14px;padding:16px 18px;margin-bottom:18px;font-size:.94rem}
  .d-ok{background:var(--ok-bg);border:1px solid #86efac;color:#14532d}
  .d-eksik{background:var(--sari-bg);border:1px solid #fcd34d;color:#78350f}
  .uyari{background:#fff7ed;border:1px solid rgba(255,153,0,.4);border-radius:12px;padding:14px 16px;
         font-size:.88rem;color:#7c5a1e;margin-top:18px}
  code{background:#f1f5f9;padding:2px 6px;border-radius:5px;font-size:.86em}
  ul{margin:8px 0 0 20px} li{margin-bottom:4px;font-size:.9rem}
</style>
</head>
<body>
<div class="sar">
  <h1>Civari — Veritabanı Kurulumu</h1>
  <p class="alt">Eksik tablo ve sütunları bulup ekler. Mevcut verilere dokunmaz, defalarca çalıştırılabilir.</p>

  <?php if ($hepsiTamam): ?>
    <div class="durum d-ok"><b>✓ Veritabanı hazır.</b> Tüm gerekli alanlar yerinde — site tüm özellikleriyle çalışabilir.</div>
  <?php else: ?>
    <div class="durum d-eksik">
      <b>Eksik alanlar var.</b> Aşağıdaki düğmeye basarak tamamlayabilirsin:
      <ul>
        <?php foreach ($kritik as $ad => $var) if (!$var): ?><li><?= e($ad) ?></li><?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($calistir && $sonuclar): ?>
    <div class="kart">
      <h2>Kurulum sonucu</h2>
      <?php foreach ($sonuclar as [$durum, $mesaj]):
        $sinif = ['ekle'=>'r-ekle','var'=>'r-var','hata'=>'r-hata','atla'=>'r-atla'][$durum] ?? 'r-var';
        $etiket = ['ekle'=>'EKLENDİ','var'=>'MEVCUT','hata'=>'HATA','atla'=>'ATLANDI'][$durum] ?? $durum; ?>
        <div class="satir"><span class="rozet <?= $sinif ?>"><?= $etiket ?></span><span><?= e($mesaj) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="kart">
    <h2>Şu anki durum</h2>
    <table>
      <?php foreach ($ozet as $ad => $deger): ?>
        <tr><td><?= e($ad) ?></td><td><?= e($deger) ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>

  <?php if (!$calistir): ?>
    <a class="btn" href="?token=<?= e($KURULUM_ANAHTARI) ?>&calistir=1">Eksikleri Tamamla</a>
  <?php else: ?>
    <a class="btn" href="?token=<?= e($KURULUM_ANAHTARI) ?>">Yeniden Kontrol Et</a>
  <?php endif; ?>

  <div class="uyari">
    <b>Sonraki adımlar</b>
    <ul>
      <li>Mekan verisi ve fotoğraflar için <code>03_ankara_vitrin.sql</code> ve <code>06_gorseller.sql</code> dosyalarını phpMyAdmin'den çalıştır. (Bunlar veri ekler; bu sayfa yalnızca yapıyı kurar.)</li>
      <li>Kurulum bittikten sonra <b>bu dosyayı sunucudan silmeni</b> öneririz.</li>
    </ul>
  </div>
</div>
</body>
</html>
