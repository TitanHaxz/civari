<?php
/*
 * CİVARİ — Mekan Listeleme + Yapay Zeka Destekli Doğal Dil Araması
 * ?q=...  &  ?category=...
 * "öğrenciyim ucuz kafe", "alkolsüz lüks mekan eşimle" gibi cümleleri
 * otomatik olarak kategori / fiyat / alkol / etiket filtrelerine çevirir.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($data): void {
    if (ob_get_length() !== false) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
if ($pdo === null) out(['error' => 'Veritabanı bağlantısı yok.']);

$q        = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');

/* ---------- DOĞAL DİL ÇÖZÜCÜ ---------- */
function has(string $text, array $words): bool {
    foreach ($words as $w) if (mb_strpos($text, $w) !== false) return true;
    return false;
}
/* Yazım hatasına toleranslı eşleşme (Meilisearch yerine hafif yerel çözüm) */
function tokenize(string $t): array {
    return preg_split('/[^\p{L}0-9]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}
function fuzzy(string $text, array $words): bool {
    foreach ($words as $w) {
        if (mb_strpos($text, $w) !== false) return true;
        if (mb_strlen($w) >= 4) {
            foreach (tokenize($text) as $tok) {
                if (mb_strlen($tok) >= 4 && levenshtein($tok, $w) <= 1) return true; // "khave" -> "kahve"
            }
        }
    }
    return false;
}
function parseIntent(string $q): array {
    $t = mb_strtolower($q, 'UTF-8');
    // Sık yapılan yazım hatalarını normalize et
    $typos = ['khave'=>'kahve','kahwe'=>'kahve','kafee'=>'kafe','kaffe'=>'kafe','resturant'=>'restoran','restorant'=>'restoran','restaurant'=>'restoran','kamping'=>'kamp','bowilng'=>'bowling','kütüphana'=>'kütüphane'];
    foreach ($typos as $bad => $good) $t = str_replace($bad, $good, $t);

    $f = ['category' => null, 'price' => null, 'alcohol' => null, 'tags' => []];

    // Kategori ipuçları (genelden özele; sonraki eşleşme öncekini ezer)
    if (fuzzy($t, ['restoran','yemek','köfte','kofte','balık','balik','ocakbaşı','lokanta','akşam yemeği'])) $f['category'] = 'Restoran';
    if (fuzzy($t, ['kafe','kahve','cafe','çalış','calis','wifi']))                                           $f['category'] = 'Kafe';
    if (fuzzy($t, ['tatlı','tatli','pasta','pastane','baklava','fırın','firin','kurabiye','profiterol']))     $f['category'] = 'Tatlıcı';
    if (fuzzy($t, ['market','alışveriş','alisveris','bakkal','şarküteri']))                                  $f['category'] = 'Market';
    if (fuzzy($t, ['kitap','ders','kütüphane','kutuphane','etüt','etut','sınav','sinav','sahaf']))           $f['category'] = 'Ders & Kitap';
    if (fuzzy($t, ['eğlence','eglence','bowling','sinema','lunapark','oyun','aktivite']))                    $f['category'] = 'Eğlence';
    if (fuzzy($t, ['doğa','doga','tabiat','mesire','orman','piknik','yürüyüş','yuruyus','park','göl','gol'])) $f['category'] = 'Doğa';
    if (fuzzy($t, ['kamp','çadır','cadir','karavan','camping','bungalov']))                                  $f['category'] = 'Kamp';
    if (fuzzy($t, ['bar','pub','meyhane','taverna','kokteyl','birahane']))                                   $f['category'] = 'Bar';
    if (fuzzy($t, ['eczane','nöbetçi','nobetci','ecza','ilaç','ilac']))                                       $f['category'] = 'Eczane';
    if (fuzzy($t, ['otel','konaklama','hotel','pansiyon','motel','hostel','tatil köyü']))                     $f['category'] = 'Konaklama';

    // Fiyat ipuçları
    if (fuzzy($t, ['ucuz','öğrenci','ogrenci','bütçe','butce','uygun','hesaplı','hesapli','ekonomik'])) $f['price'] = 1;
    if (fuzzy($t, ['lüks','luks','pahalı','pahali','şık','premium','kaliteli','özel']))                 $f['price'] = 3;

    // Alkol ipuçları
    if (has($t, ['alkolsüz','alkolsuz','alkol olmasın','alkol istemiyorum','ailece','aile','çocuk','cocuk'])) $f['alcohol'] = 0;
    if (has($t, ['alkollü','alkollu','içkili','ickili']))                                                     $f['alcohol'] = 1;

    // Etiket ipuçları
    $tagWords = ['deniz'=>'deniz','manzara'=>'manzara','romantik'=>'romantik','sevgili'=>'romantik','eşim'=>'romantik','sakin'=>'sakin','sessiz'=>'sessiz','müzik'=>'müzik','muzik'=>'müzik','arkadaş'=>'arkadaş'];
    foreach ($tagWords as $word => $tag) { if (mb_strpos($t, $word) !== false && !in_array($tag, $f['tags'], true)) $f['tags'][] = $tag; }

    return $f;
}

/* Onay filtresi. Sütun henüz yoksa (kurulum.php çalıştırılmadıysa) filtre KAPANMAZ;
   bunun yerine işletme kaynaklı kayıtlar tamamen dışlanır. Böylece onaysız bir
   mekan hiçbir koşulda listeye sızamaz. */
function venues_onay_kosulu(PDO $pdo): string {
    static $kosul = null;
    if ($kosul === null) {
        try {
            $pdo->query("SELECT onay_durumu FROM venues LIMIT 1");
            $kosul = " AND v.onay_durumu='onaylandi'";
        } catch (Exception $e) {
            $kosul = " AND (v.source IS NULL OR v.source <> 'isletme')";
        }
    }
    return $kosul;
}

/* ---------- SAYFALI SORGU (LIMIT/OFFSET + toplam + konum) ---------- */
function runQuery(PDO $pdo, string $q, string $category, bool $rich, string $sort, int $limit, int $offset, array $geo = [], int $relax = 0): array {
    $intent = parseIntent($q);
    if ($category !== '' && $category !== 'all') $intent['category'] = $category;
    // Yapısal niyet var mı? (serbest metne düşmek için)
    $structured = ($intent['category'] || $intent['price'] !== null || $intent['alcohol'] !== null || !empty($intent['tags']));

    /* Görünürlük sigortası: yalnız Ankara + yayında + ONAYLI mekanlar.
       Onay bekleyen işletme başvuruları hiçbir listede/haritada/aramada çıkmaz. */
    $where = " WHERE v.is_active=1 AND v.city='Ankara'" . venues_onay_kosulu($pdo);
    $params = [];
    /* Kademeli gevşetme (relax): 0=tam, 1=etiketsiz, 2=fiyat/alkolsüz, 3=serbest metin.
       "sessiz çalışma yeri" gibi OSM'de olmayan etiketlerde boş dönmesin diye. */
    if ($relax < 3 && $intent['category']) { $where .= " AND v.category = ?"; $params[] = $intent['category']; }
    if ($rich) {
        if ($relax < 2 && $intent['price']   !== null) { $where .= " AND v.price_level = ?"; $params[] = $intent['price']; }
        if ($relax < 2 && $intent['alcohol'] !== null) { $where .= " AND v.alcohol = ?";     $params[] = $intent['alcohol']; }
        if ($relax < 1) foreach ($intent['tags'] as $tg) { $where .= " AND v.tags LIKE ?"; $params[] = '%' . $tg . '%'; }
    }
    // Serbest metin: yapısal niyet yoksa ya da en gevşek seviyede
    if ($q !== '' && (!$structured || $relax >= 3)) {
        if ($rich) { $where .= " AND (v.name LIKE ? OR v.tags LIKE ? OR v.description LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; $params[]="%$q%"; }
        else       { $where .= " AND v.name LIKE ?"; $params[]="%$q%"; }
    }

    /* Konum filtresi — güvenli (sadece doğrulanmış float değerler SQL'e gömülür) */
    $distSel = '';   // SELECT'e eklenecek mesafe ifadesi
    $geoActive = false;
    if (isset($geo['bbox']) && is_array($geo['bbox'])) {
        [$s, $w, $n, $e] = array_map('floatval', $geo['bbox']);
        $where .= sprintf(" AND v.lat BETWEEN %.7f AND %.7f AND v.lng BETWEEN %.7f AND %.7f", min($s,$n), max($s,$n), min($w,$e), max($w,$e));
        $geoActive = true;
    }
    if (isset($geo['lat'], $geo['lng'])) {
        $glat = (float)$geo['lat']; $glng = (float)$geo['lng'];
        $radius = isset($geo['radius']) ? max(0.5, min(2000.0, (float)$geo['radius'])) : 25.0;
        $latD = $radius / 111.045;
        $cos = cos(deg2rad($glat)); if (abs($cos) < 0.01) $cos = 0.01;
        $lngD = $radius / (111.045 * abs($cos));
        if (!isset($geo['bbox'])) {
            $where .= sprintf(" AND v.lat BETWEEN %.7f AND %.7f AND v.lng BETWEEN %.7f AND %.7f", $glat-$latD, $glat+$latD, $glng-$lngD, $glng+$lngD);
        }
        // Haversine (km) — sadece sıralama/etiket için
        $distSel = sprintf(", (6371 * acos(LEAST(1, cos(radians(%.7f))*cos(radians(v.lat))*cos(radians(v.lng)-radians(%.7f)) + sin(radians(%.7f))*sin(radians(v.lat))))) AS dist", $glat, $glng, $glat);
        $geoActive = true;
    }

    // Toplam (sayfalama için)
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM venues v" . $where);
    $cstmt->execute($params);
    $total = (int)$cstmt->fetchColumn();

    // Sıralama
    if ($distSel !== '' && ($sort === 'distance' || $sort === 'recommended')) $order = " ORDER BY dist ASC";
    elseif ($rich && $sort === 'reviews')  $order = " ORDER BY review_count DESC, v.rating DESC, v.id DESC";
    elseif ($sort === 'rating')            $order = " ORDER BY v.rating DESC, v.id DESC";
    elseif ($distSel !== '')               $order = " ORDER BY dist ASC";
    else                                   $order = " ORDER BY v.rating DESC, v.id DESC";

    $limit = max(1, min(1000, $limit)); $offset = max(0, $offset);
    if ($rich) {
        /* Galeri fotoğrafları: tek sorguda "|" ile birleşik gelir (tablo yoksa atlanır) */
        static $galeriVar = null;
        if ($galeriVar === null) {
            try { $pdo->query("SELECT 1 FROM venue_photos LIMIT 1"); $galeriVar = true; }
            catch (Exception $ge) { $galeriVar = false; }
        }
        $galeriSel = $galeriVar
            ? ", (SELECT GROUP_CONCAT(p.url ORDER BY p.sira, p.id SEPARATOR '|') FROM venue_photos p WHERE p.venue_id=v.id) AS gallery"
            : "";
        $sel = "SELECT v.*,
            (SELECT COUNT(*) FROM reviews r WHERE r.venue_id=v.id) AS review_count,
            (SELECT ROUND(AVG(r.rating),1) FROM reviews r WHERE r.venue_id=v.id) AS review_avg
            $galeriSel
            $distSel
            FROM venues v" . $where . $order . " LIMIT $limit OFFSET $offset";
    } else {
        $sel = "SELECT v.* $distSel FROM venues v" . $where . $order . " LIMIT $limit OFFSET $offset";
    }
    $stmt = $pdo->prepare($sel);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(), 'total' => $total, 'geo' => $geoActive];
}

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1) $limit = 20;
$sort  = preg_replace('/[^a-z]/', '', strtolower($_GET['sort'] ?? 'recommended'));
$offset = ($page - 1) * $limit;

/* Konum parametreleri: ?lat&lng[&radius]  veya  ?bbox=S,W,N,E */
$geo = [];
if (isset($_GET['lat'], $_GET['lng']) && is_numeric($_GET['lat']) && is_numeric($_GET['lng'])) {
    $geo['lat'] = (float)$_GET['lat'];
    $geo['lng'] = (float)$_GET['lng'];
    if (isset($_GET['radius']) && is_numeric($_GET['radius'])) $geo['radius'] = (float)$_GET['radius'];
}
if (isset($_GET['bbox'])) {
    $b = array_map('trim', explode(',', (string)$_GET['bbox']));
    if (count($b) === 4 && count(array_filter($b, 'is_numeric')) === 4) $geo['bbox'] = array_map('floatval', $b);
}

try {
    $res = runQuery($pdo, $q, $category, true, $sort, $limit, $offset, $geo, 0);
    // Doğal dil araması boş dönerse kademeli gevşet (etiket -> fiyat/alkol -> serbest metin)
    if ($q !== '' && (int)$res['total'] === 0) {
        for ($relax = 1; $relax <= 3 && (int)$res['total'] === 0; $relax++) {
            $res = runQuery($pdo, $q, $category, true, $sort, $limit, $offset, $geo, $relax);
        }
    }
} catch (Exception $e) {
    try {
        $res = runQuery($pdo, $q, $category, false, $sort, $limit, $offset, $geo, 3);
    } catch (Exception $e2) {
        error_log('Mekan listeleme hatası: ' . $e2->getMessage());
        out(['status' => 'error', 'venues' => [], 'page' => $page, 'hasMore' => false, 'error' => 'Mekanlar getirilemedi.']);
    }
}
$hasMore = ($offset + count($res['rows'])) < $res['total'];
out(['status' => 'success', 'venues' => $res['rows'], 'page' => $page, 'limit' => $limit, 'total' => $res['total'], 'hasMore' => $hasMore]);
