<?php
/*
 * CİVARİ — OSM / Overpass İçe Aktarma Paneli  (3. adım)
 * ------------------------------------------------------
 * Kullanım (tarayıcıda):
 *   1) Önce sql/01_schema_osm.sql çalıştırılmış olmalı.
 *   2) osm_config.php içindeki $OSM_IMPORT_TOKEN'ı değiştir.
 *   3) https://civari.com.tr/import_osm.php?token=SENIN_TOKENIN
 *      -> "Başlat" de. Panel şehirleri kutu kutu otomatik gezer.
 *
 * Her istek TEK bir coğrafi kutucuğu işler; böylece hosting zaman aşımına
 * takılmaz. Sayfa 3 sn'de bir kendini bir sonraki kutuya yönlendirir.
 * İşlem güvenle tekrar çalıştırılabilir (aynı mekan iki kez eklenmez).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
@ini_set('memory_limit', '512M');   // yoğun kutularda (İstanbul) bellek yetsin
@ini_set('max_execution_time', '120');
@set_time_limit(120);

require __DIR__ . '/db.php';
require __DIR__ . '/osm_config.php';

/* ───────── Güvenlik ───────── */
// Vitrin dönemi: toplu OSM importu kapalı (osm_config.php -> $OSM_IMPORT_ENABLED).
if (empty($OSM_IMPORT_ENABLED)) {
    http_response_code(403);
    exit('403 — İçe aktarma şu an devre dışı. (Vitrin dönemi: sitede yalnızca elle girilen mekanlar var. Açmak için osm_config.php içinde $OSM_IMPORT_ENABLED = true yapın.)');
}
$token = $_GET['token'] ?? '';
if (!hash_equals((string)$OSM_IMPORT_TOKEN, (string)$token)) {
    http_response_code(403);
    exit('403 — Geçersiz token. Erişim yok.');
}
$tokenQS = 'token=' . rawurlencode((string)$OSM_IMPORT_TOKEN);

/* ───────── Yardımcılar ───────── */

// Şehirleri kutucuklara bölerek sıralı iş listesi üretir. (city, bbox, label)
function osm_build_jobs(array $cities): array {
    $jobs = [];
    foreach ($cities as $key => $c) {
        [$S, $W, $N, $E] = $c['bbox'];
        $s = max(1, (int)($c['split'] ?? 1));
        $latStep = ($N - $S) / $s;
        $lngStep = ($E - $W) / $s;
        for ($r = 0; $r < $s; $r++) {
            for ($col = 0; $col < $s; $col++) {
                $cs = $S + $r * $latStep;
                $cw = $W + $col * $lngStep;
                $jobs[] = [
                    'city'  => $key,
                    'label' => $c['label'],
                    'bbox'  => [$cs, $cw, $cs + $latStep, $cw + $lngStep],
                    'cell'  => ($r + 1) . '/' . $s . ' , ' . ($col + 1) . '/' . $s,
                ];
            }
        }
    }
    return $jobs;
}

// Tüm gruplardaki filtreleri tek bir düz listeye indir.
function osm_all_filters(array $groups): array {
    $out = [];
    foreach ($groups as $g) foreach ($g as $f) $out[] = $f;
    return $out;
}

// Bir kutu için Overpass QL sorgusu üretir.
function osm_build_query(array $filters, array $bbox, int $timeout): string {
    [$s, $w, $n, $e] = $bbox;
    $b = sprintf('(%.6f,%.6f,%.6f,%.6f)', $s, $w, $n, $e);
    $parts = '';
    foreach ($filters as [$k, $v]) {
        $parts .= "node[\"$k\"=\"$v\"]$b;way[\"$k\"=\"$v\"]$b;relation[\"$k\"=\"$v\"]$b;";
    }
    return "[out:json][timeout:$timeout];($parts);out center tags;";
}

// Overpass'a POST atar; sunucular arasında yedekli dener.
function osm_fetch(string $query, array $endpoints, int $timeout): array {
    $lastErr = 'bilinmeyen hata';
    foreach ($endpoints as $url) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['data' => $query]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout + 40,
                CURLOPT_CONNECTTIMEOUT => 25,
                CURLOPT_USERAGENT      => 'CivariOSMImporter/1.0 (+https://civari.com.tr)',
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);
            if ($body !== false && $code === 200) {
                $j = json_decode($body, true);
                if (is_array($j) && isset($j['elements'])) return ['ok' => true, 'data' => $j, 'endpoint' => $url];
                $lastErr = "Beklenmeyen yanıt (HTTP $code) — $url";
            } else {
                $lastErr = "HTTP $code " . ($cerr ?: '') . " — $url";
            }
        } else {
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: CivariOSMImporter/1.0\r\n",
                'content' => http_build_query(['data' => $query]),
                'timeout' => $timeout + 40,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false) {
                $j = json_decode($body, true);
                if (is_array($j) && isset($j['elements'])) return ['ok' => true, 'data' => $j, 'endpoint' => $url];
            }
            $lastErr = "İstek başarısız — $url";
        }
    }
    return ['ok' => false, 'error' => $lastErr];
}

// OSM adresini derli toplu tek satıra çevirir.
function osm_addr(array $t): ?string {
    $line = trim(($t['addr:street'] ?? '') . ' ' . ($t['addr:housenumber'] ?? ''));
    $parts = array_filter([
        $line,
        $t['addr:neighbourhood'] ?? $t['addr:suburb'] ?? '',
        $t['addr:city'] ?? $t['addr:district'] ?? '',
    ]);
    return $parts ? implode(', ', $parts) : null;
}

/* ───────── Sabit veri ───────── */
$JOBS    = osm_build_jobs($OSM_CITIES);
$FILTERS = osm_all_filters($OSM_GROUPS);
$TOTAL   = count($JOBS);
$action  = $_GET['action'] ?? 'plan';
$noauto  = isset($_GET['noauto']);

/* ───────── DB özet sayacı ───────── */
function osm_counts(?PDO $pdo): array {
    if (!$pdo) return ['total' => 0, 'osm' => 0, 'byCat' => []];
    $total = (int)$pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn();
    $osm   = (int)$pdo->query("SELECT COUNT(*) FROM venues WHERE source='osm'")->fetchColumn();
    $byCat = $pdo->query("SELECT category, COUNT(*) c FROM venues GROUP BY category ORDER BY c DESC")->fetchAll();
    return ['total' => $total, 'osm' => $osm, 'byCat' => $byCat];
}

/* ═══════════ TEK KUTU (JSON) ═══════════
 * JS sürücüsü her kutuyu bununla çeker. Kendi hatasını yakalayıp JSON döndürür,
 * böylece bir kutu patlasa bile (500) tarayıcıdaki döngü durmaz, sıradakine geçer. */
if ($action === 'cell') {
    header('Content-Type: application/json; charset=utf-8');
    $i = (int)($_GET['i'] ?? -1);
    if (!$pdo) { echo json_encode(['error' => 'DB bağlantısı yok']); exit; }
    if ($i < 0 || $i >= $TOTAL) { echo json_encode(['done' => true]); exit; }
    $job = $JOBS[$i];
    try {
        $query = osm_build_query($FILTERS, $job['bbox'], $OSM_OVERPASS_TIMEOUT);
        $res = osm_fetch($query, $OSM_OVERPASS_ENDPOINTS, $OSM_OVERPASS_TIMEOUT);
        if (!$res['ok']) { echo json_encode(['i'=>$i,'label'=>$job['label'],'cell'=>$job['cell'],'ins'=>0,'error'=>$res['error']]); exit; }
        $elements = $res['data']['elements'] ?? [];
        $insStmt = $pdo->prepare(
            "INSERT INTO venues
                (name, category, lat, lng, rating, image_url, city, is_active, address,
                 price_level, alcohol, tags, phone, osm_type, osm_id, district, source,
                 website, opening_hours, updated_at)
             VALUES
                (:name,:cat,:lat,:lng,NULL,:img,:city,1,:addr,
                 :price,:alc,:tags,:phone,:otype,:oid,:district,'osm',:web,:oh,NOW())
             ON DUPLICATE KEY UPDATE
                name=VALUES(name), category=VALUES(category), lat=VALUES(lat), lng=VALUES(lng),
                address=VALUES(address), price_level=VALUES(price_level), alcohol=VALUES(alcohol),
                tags=VALUES(tags), phone=VALUES(phone), district=VALUES(district),
                website=VALUES(website), opening_hours=VALUES(opening_hours), updated_at=NOW()"
        );
        $ins = $upd = 0;
        $pdo->beginTransaction();
        foreach ($elements as $el) {
            $tags = $el['tags'] ?? [];
            $name = trim((string)($tags['name'] ?? ''));
            if ($name === '') continue;
            $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $lon = $el['lon'] ?? ($el['center']['lon'] ?? null);
            if ($lat === null || $lon === null) continue;
            $cat = osm_resolve_category($tags, $OSM_CAT_MAP);
            if ($cat === null) continue;
            if (!empty($OSM_REQUIRE_QUALITY) && !osm_is_quality($tags)) continue; // az ama öz
            $extra = [];
            $ia = $tags['internet_access'] ?? '';
            if ($ia !== '' && $ia !== 'no') $extra[] = 'wifi';
            if (($tags['outdoor_seating'] ?? '') === 'yes') $extra[] = 'bahçe';
            if (($tags['takeaway'] ?? '') === 'yes') $extra[] = 'paket';
            $tagStr = trim($cat['tags'] . ($extra ? ',' . implode(',', $extra) : ''), ',');
            $insStmt->execute([
                ':name'  => mb_substr($name, 0, 150),
                ':cat'   => $cat['category'],
                ':lat'   => round((float)$lat, 7),
                ':lng'   => round((float)$lon, 7),
                ':img'   => osm_pick_image($tags, $cat['category'], (int)$el['id']),
                ':city'  => $job['label'],
                ':addr'  => osm_addr($tags),
                ':price' => $cat['price'],
                ':alc'   => $cat['alcohol'],
                ':tags'  => mb_substr($tagStr, 0, 255),
                ':phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                ':otype' => (string)$el['type'],
                ':oid'   => (int)$el['id'],
                ':district' => $tags['addr:district'] ?? $tags['addr:suburb'] ?? $tags['addr:city'] ?? null,
                ':web'   => $tags['website'] ?? $tags['contact:website'] ?? null,
                ':oh'    => isset($tags['opening_hours']) ? mb_substr($tags['opening_hours'], 0, 255) : null,
            ]);
            $rc = $insStmt->rowCount();
            if ($rc === 1) $ins++; elseif ($rc >= 2) $upd++;
        }
        $pdo->commit();
        echo json_encode(['i'=>$i,'label'=>$job['label'],'cell'=>$job['cell'],'el'=>count($elements),'ins'=>$ins,'upd'=>$upd]); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $x) {} }
        echo json_encode(['i'=>$i,'label'=>$job['label'] ?? '','cell'=>$job['cell'] ?? '','ins'=>0,'error'=>$e->getMessage()]); exit;
    }
}
if ($action === 'count') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['total' => $pdo ? (int)$pdo->query("SELECT COUNT(*) FROM venues")->fetchColumn() : 0]); exit;
}

?><!doctype html>
<html lang="tr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Civari — OSM İçe Aktarma</title>
<?php if ($action === 'run' && !$noauto) {
    $next = (int)($_GET['i'] ?? 0) + 1;
    if ($next < $TOTAL) echo '<meta http-equiv="refresh" content="3;url=?' . $tokenQS . '&action=run&i=' . $next . '">';
} ?>
<style>
  :root{--o:#ff9900;--g:#d2d2d1;--ink:#1c1c1e;}
  *{box-sizing:border-box}
  body{font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#fafafa;color:var(--ink);margin:0;padding:24px;line-height:1.5}
  .wrap{max-width:820px;margin:0 auto}
  h1{font-size:20px;margin:0 0 4px}
  .sub{color:#666;font-size:13px;margin-bottom:20px}
  .card{background:#fff;border:1px solid #eee;border-radius:14px;padding:18px 20px;margin-bottom:16px;box-shadow:0 6px 20px rgba(0,0,0,.04)}
  .bar{height:12px;background:var(--g);border-radius:999px;overflow:hidden;margin:10px 0}
  .bar > i{display:block;height:100%;background:var(--o);transition:width .3s}
  .btn{display:inline-block;background:var(--o);color:#fff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:10px;border:0;cursor:pointer;font-size:14px}
  .btn.ghost{background:#fff;color:var(--ink);border:1px solid var(--g)}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  table{width:100%;border-collapse:collapse;font-size:13px}
  td,th{text-align:left;padding:6px 8px;border-bottom:1px solid #f0f0f0}
  .k{font-weight:700;font-size:22px;color:var(--o)}
  .muted{color:#888;font-size:12px}
  code{background:#f3f3f3;padding:2px 6px;border-radius:6px;font-size:12px}
  .warn{background:#fff6e8;border:1px solid #ffd591;color:#8a5a00;padding:10px 14px;border-radius:10px;font-size:13px}
  .ok{color:#137333}.err{color:#c5221f}
</style></head><body><div class="wrap">
<h1>🧭 Civari — OpenStreetMap İçe Aktarma</h1>
<div class="sub">Kaynak: Overpass API · Lisans: ODbL · <?= $TOTAL ?> kutucuk · <?= count($OSM_CITIES) ?> şehir</div>

<?php
if ($OSM_IMPORT_TOKEN === 'DEGISTIR_beni_uzun_rastgele_2f9c1a') {
    echo '<div class="warn">⚠️ <b>Güvenlik:</b> <code>osm_config.php</code> içindeki <code>$OSM_IMPORT_TOKEN</code> hâlâ varsayılan. Devam edebilirsin ama yayına almadan önce mutlaka değiştir.</div><br>';
}
if (!$pdo) {
    echo '<div class="card err">Veritabanı bağlantısı yok. db.php ayarlarını kontrol et.</div></div></body></html>';
    exit;
}

/* ═══════════ ÇALIŞTIR ═══════════ */
if ($action === 'run') {
    $i = (int)($_GET['i'] ?? 0);
    if ($i < 0 || $i >= $TOTAL) {
        $c = osm_counts($pdo);
        echo '<div class="card"><h2>✅ Tüm kutucuklar tamamlandı</h2>';
        echo '<p>Veritabanında toplam <span class="k">' . $c['total'] . '</span> mekan (' . $c['osm'] . ' tanesi OSM kaynaklı).</p>';
        echo '<div class="row"><a class="btn" href="?' . $tokenQS . '&action=stats">📊 İstatistikleri gör</a> <a class="btn ghost" href="?' . $tokenQS . '">← Panele dön</a></div></div>';
        echo '</div></body></html>'; exit;
    }
    $job = $JOBS[$i];
    $query = osm_build_query($FILTERS, $job['bbox'], $OSM_OVERPASS_TIMEOUT);

    $t0 = microtime(true);
    $res = osm_fetch($query, $OSM_OVERPASS_ENDPOINTS, $OSM_OVERPASS_TIMEOUT);
    $fetchMs = round((microtime(true) - $t0) * 1000);

    $done = $i + 1;
    $pct  = round($done / $TOTAL * 100);
    echo '<div class="card"><div class="row" style="justify-content:space-between">';
    echo '<b>' . htmlspecialchars($job['label']) . '</b> <span class="muted">kutu ' . htmlspecialchars($job['cell']) . ' · adım ' . $done . '/' . $TOTAL . '</span></div>';
    echo '<div class="bar"><i style="width:' . $pct . '%"></i></div>';

    if (!$res['ok']) {
        echo '<p class="err">Overpass hatası: ' . htmlspecialchars($res['error']) . '</p>';
        echo '<p class="muted">Bu kutu atlanacak. Bir sonraki adıma geçiliyor…</p>';
        $nextUrl = '?' . $tokenQS . '&action=run&i=' . ($i + 1);
        echo '<div class="row"><a class="btn" href="' . $nextUrl . '">Devam →</a> <a class="btn ghost" href="?' . $tokenQS . '">Durdur</a></div>';
        echo '</div></div></body></html>'; exit;
    }

    $elements = $res['data']['elements'] ?? [];
    $ins = $upd = $noName = $noCoord = $noCat = $dup = 0;

    // Hazır ifadeler
    $dupStmt = $pdo->prepare(
        "SELECT id FROM venues WHERE name = :name
           AND lat BETWEEN :latlo AND :lathi
           AND lng BETWEEN :lnglo AND :lnghi
           AND (osm_id IS NULL OR osm_id <> :oid OR osm_type <> :otype)
         LIMIT 1"
    );
    $insStmt = $pdo->prepare(
        "INSERT INTO venues
            (name, category, lat, lng, rating, image_url, city, is_active, address,
             price_level, alcohol, tags, phone, osm_type, osm_id, district, source,
             website, opening_hours, updated_at)
         VALUES
            (:name,:cat,:lat,:lng,NULL,:img,:city,1,:addr,
             :price,:alc,:tags,:phone,:otype,:oid,:district,'osm',
             :web,:oh,NOW())
         ON DUPLICATE KEY UPDATE
            name=VALUES(name), category=VALUES(category), lat=VALUES(lat), lng=VALUES(lng),
            address=VALUES(address), price_level=VALUES(price_level), alcohol=VALUES(alcohol),
            tags=VALUES(tags), phone=VALUES(phone), district=VALUES(district),
            website=VALUES(website), opening_hours=VALUES(opening_hours), updated_at=NOW()"
    );

    foreach ($elements as $el) {
        $tags = $el['tags'] ?? [];
        $name = trim((string)($tags['name'] ?? ''));
        if ($name === '') { $noName++; continue; }

        $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $lon = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if ($lat === null || $lon === null) { $noCoord++; continue; }

        $cat = osm_resolve_category($tags, $OSM_CAT_MAP);
        if ($cat === null) { $noCat++; continue; }

        // Etiket zenginleştirme
        $extra = [];
        $ia = $tags['internet_access'] ?? '';
        if ($ia !== '' && $ia !== 'no') $extra[] = 'wifi';
        if (($tags['outdoor_seating'] ?? '') === 'yes') $extra[] = 'bahçe';
        if (($tags['takeaway'] ?? '') === 'yes') $extra[] = 'paket';
        $tagStr = trim($cat['tags'] . ($extra ? ',' . implode(',', $extra) : ''), ',');

        $osm_type = (string)$el['type'];
        $osm_id   = (int)$el['id'];

        // İsim + ~11m yakınlık mükerrer taraması (farklı OSM elemanı, aynı yer)
        $dupStmt->execute([
            ':name' => $name,
            ':latlo' => $lat - 0.0001, ':lathi' => $lat + 0.0001,
            ':lnglo' => $lon - 0.0001, ':lnghi' => $lon + 0.0001,
            ':oid' => $osm_id, ':otype' => $osm_type,
        ]);
        if ($dupStmt->fetchColumn()) { $dup++; continue; }

        $insStmt->execute([
            ':name'  => mb_substr($name, 0, 150),
            ':cat'   => $cat['category'],
            ':lat'   => round((float)$lat, 7),
            ':lng'   => round((float)$lon, 7),
            ':img'   => osm_pick_image($tags, $cat['category'], $osm_id),
            ':city'  => $job['label'],
            ':addr'  => osm_addr($tags),
            ':price' => $cat['price'],
            ':alc'   => $cat['alcohol'],
            ':tags'  => mb_substr($tagStr, 0, 255),
            ':phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
            ':otype' => $osm_type,
            ':oid'   => $osm_id,
            ':district' => $tags['addr:district'] ?? $tags['addr:suburb'] ?? $tags['addr:city'] ?? null,
            ':web'   => $tags['website'] ?? $tags['contact:website'] ?? null,
            ':oh'    => isset($tags['opening_hours']) ? mb_substr($tags['opening_hours'], 0, 255) : null,
        ]);
        $rc = $insStmt->rowCount();
        if ($rc === 1) $ins++; elseif ($rc >= 2) $upd++;
    }

    echo '<table>';
    echo '<tr><td>Overpass yanıtı</td><td>' . count($elements) . ' eleman · ' . $fetchMs . ' ms · ' . htmlspecialchars(parse_url($res['endpoint'], PHP_URL_HOST) ?: '') . '</td></tr>';
    echo '<tr><td>✅ Yeni eklenen</td><td class="ok"><b>' . $ins . '</b></td></tr>';
    echo '<tr><td>♻️ Güncellenen</td><td>' . $upd . '</td></tr>';
    echo '<tr><td>⏭️ Mükerrer (atlandı)</td><td>' . $dup . '</td></tr>';
    echo '<tr><td class="muted">İsimsiz / koordinatsız / kategori dışı</td><td class="muted">' . $noName . ' / ' . $noCoord . ' / ' . $noCat . '</td></tr>';
    echo '</table>';

    $nextUrl = '?' . $tokenQS . '&action=run&i=' . ($i + 1);
    if ($noauto) {
        echo '<div class="row" style="margin-top:12px"><a class="btn" href="' . $nextUrl . '">Sonraki kutu →</a> <a class="btn ghost" href="?' . $tokenQS . '">Durdur</a></div>';
    } else {
        echo '<p class="muted" style="margin-top:12px">3 saniye içinde otomatik olarak bir sonraki kutuya geçilecek… <a href="?' . $tokenQS . '">durdurmak için tıkla</a></p>';
    }
    echo '</div></div></body></html>'; exit;
}

/* ═══════════ İSTATİSTİK ═══════════ */
if ($action === 'stats') {
    $c = osm_counts($pdo);
    echo '<div class="card"><h2>📊 İstatistikler</h2>';
    echo '<p>Toplam mekan: <span class="k">' . $c['total'] . '</span> · OSM kaynaklı: <b>' . $c['osm'] . '</b></p><table>';
    foreach ($c['byCat'] as $row) echo '<tr><td>' . htmlspecialchars((string)$row['category']) . '</td><td><b>' . $row['c'] . '</b></td></tr>';
    echo '</table></div>';
    echo '<div class="card"><b>Şehir bazında</b><table>';
    foreach ($pdo->query("SELECT city, COUNT(*) c FROM venues GROUP BY city ORDER BY c DESC")->fetchAll() as $row)
        echo '<tr><td>' . htmlspecialchars((string)$row['city']) . '</td><td><b>' . $row['c'] . '</b></td></tr>';
    echo '</table><div class="row" style="margin-top:12px"><a class="btn ghost" href="?' . $tokenQS . '">← Panele dön</a></div></div>';
    echo '</div></body></html>'; exit;
}

/* ═══════════ PANEL (varsayılan) — JS sürücülü, 500'e dayanıklı ═══════════ */
$c = osm_counts($pdo);
?>
<div class="card"><h2>Kontrol Paneli</h2>
<p>Şu an veritabanında: <span class="k" id="dbCount"><?= $c['total'] ?></span> mekan (<?= $c['osm'] ?> OSM).</p>
<p class="muted">Aşağıdaki <?= $TOTAL ?> kutucuğu sırayla gezerek 8 şehrin gerçek mekanlarını çekeceğim. Bir kutu hata verse bile <b>durmadan sıradakine geçer</b>. Güvenle tekrar başlatılabilir — aynı mekan iki kez eklenmez.</p>

<div id="progWrap" style="display:none;margin:14px 0">
  <div class="bar"><i id="progBar" style="width:0%"></i></div>
  <div class="row" style="justify-content:space-between;margin-top:8px">
    <span class="muted" id="progText">Hazırlanıyor…</span>
    <span class="muted"><b id="progIns">0</b> yeni · <b id="progErr">0</b> hata</span>
  </div>
  <div id="progLog" style="max-height:180px;overflow:auto;margin-top:10px;font-size:12px;color:#555;font-family:monospace;background:#fafafa;border:1px solid #eee;border-radius:8px;padding:8px"></div>
</div>

<div class="row" style="margin-top:16px">
  <button class="btn" id="startBtn" onclick="startImport()">▶ Başlat</button>
  <button class="btn ghost" id="stopBtn" onclick="stopImport()" style="display:none">■ Durdur</button>
  <a class="btn ghost" href="?<?= $tokenQS ?>&action=stats">📊 İstatistik</a>
</div>
</div>

<script>
const TOTAL = <?= $TOTAL ?>;
const TOKEN = <?= json_encode((string)$OSM_IMPORT_TOKEN) ?>;
let _stop = false;
const $ = id => document.getElementById(id);
function log(msg){ const l=$('progLog'); l.innerHTML += msg + '<br>'; l.scrollTop = l.scrollHeight; }
function stopImport(){ _stop = true; $('stopBtn').textContent='Durduruluyor…'; }
async function startImport(){
  _stop = false;
  $('startBtn').style.display='none'; $('stopBtn').style.display='inline-flex';
  $('progWrap').style.display='block';
  let ins=0, err=0;
  for (let i=0; i<TOTAL && !_stop; i++){
    $('progText').textContent = 'Kutu ' + (i+1) + ' / ' + TOTAL + ' çekiliyor…';
    $('progBar').style.width = Math.round((i)/TOTAL*100) + '%';
    try {
      const r = await fetch('?token=' + encodeURIComponent(TOKEN) + '&action=cell&i=' + i, {cache:'no-store'});
      if (r.ok){
        const d = await r.json();
        if (d.error){ err++; log('⚠️ ' + (d.label||'') + ' [' + (d.cell||'') + '] — ' + d.error); }
        else { ins += (d.ins||0); if ((d.ins||0)>0 || (d.upd||0)>0) log('✅ ' + d.label + ' [' + d.cell + '] — +' + d.ins + ' yeni, ' + d.upd + ' güncel (' + d.el + ' eleman)'); }
      } else { err++; log('⚠️ Kutu ' + (i+1) + ' — sunucu hatası (HTTP ' + r.status + '), atlandı'); }
    } catch(e){ err++; log('⚠️ Kutu ' + (i+1) + ' — bağlantı hatası, atlandı'); }
    $('progIns').textContent = ins; $('progErr').textContent = err;
    await new Promise(res => setTimeout(res, 500)); // Overpass'ı yormamak için kısa bekleme
  }
  $('progBar').style.width = '100%';
  $('progText').innerHTML = _stop ? 'Durduruldu.' : '✅ Tamamlandı! Toplam <b>' + ins + '</b> yeni mekan eklendi.';
  $('stopBtn').style.display='none'; $('startBtn').style.display='inline-flex'; $('startBtn').textContent='▶ Tekrar çalıştır';
  // güncel sayacı çek
  try { const s = await fetch('?token=' + encodeURIComponent(TOKEN) + '&action=count', {cache:'no-store'}); if(s.ok){ const sd = await s.json(); if(sd.total!=null) $('dbCount').textContent = sd.total; } } catch(e){}
  log('Bitti. Siteyi açıp mekanları görebilirsin.');
}
</script>
</div></body></html>
<?php
