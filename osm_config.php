<?php
/*
 * CİVARİ — OSM / Overpass İçe Aktarma Yapılandırması
 * -----------------------------------------------------
 * import_osm.php bu dosyayı kullanır. Buradaki değerleri değiştirerek
 * yeni şehir ekleyeb, kategori eşlemesini genişletebilirsin.
 *
 * ÖNEMLİ: Aşağıdaki $OSM_IMPORT_TOKEN'ı MUTLAKA değiştir. Bu, içe
 * aktarma scriptini internetten rastgele kişilerin çalıştırmasını engeller.
 */

// ── İÇE AKTARMA KAPALI ─────────────────────────────────────────
// Vitrin dönemi kararı (2026-08): sitede yalnızca elle girilen 5 gerçek Ankara
// mekanı var; toplu OSM importu devre dışı. Tekrar açmak için true yap.
$OSM_IMPORT_ENABLED = false;

// ── GÜVENLİK: içe aktarma anahtarı ─────────────────────────────
// import_osm.php?token=BU_DEĞER şeklinde çağrılır. Kendi uzun/rastgele değerini yaz.
$OSM_IMPORT_TOKEN = '145a63afd421176f42d265f3912f7b88ed4c10820805b95a';

// ── Overpass API ayarları ──────────────────────────────────────
$OSM_OVERPASS_ENDPOINTS = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter', // yedek sunucu
];
$OSM_OVERPASS_TIMEOUT = 25; // Overpass sorgu zaman aşımı (saniye) — düşük tutmak zayıf sunucuda 500'ü önler

/*
 * ── ŞEHİRLER ───────────────────────────────────────────────────
 * bbox = [Güney(lat_min), Batı(lng_min), Kuzey(lat_max), Doğu(lng_max)]
 * split = büyük şehirleri küçük kutucuklara böler (timeout'a karşı).
 *         split=3 => 3x3 = 9 hücre. İstanbul gibi yoğun yerlerde artır.
 * Kutular şehir MERKEZLERİNİ kapsar; ilerde genişletebilir/ekleyebilirsin.
 */
// Sadece Ankara (vitrin dönemi kararı). Geniş bbox tüm merkez ilçeleri kapsar.
$OSM_CITIES = [
    'ankara'    => ['label' => 'Ankara',    'bbox' => [39.700, 32.450, 40.100, 33.150], 'split' => 4],
];

/*
 * ── KATEGORİ GRUPLARI ──────────────────────────────────────────
 * Her grup, tek bir Overpass sorgusunda çekilecek OSM etiketleridir.
 * Grupları küçük tutmak sorguyu hızlı ve güvenli yapar.
 * Her filtre: [osm_key, osm_value]
 */
// SADECE niş sosyal mekanlar: kafe, restoran, bar. (market/eczane/park/kamp vb. ARTIK YOK)
$OSM_GROUPS = [
    'food'      => [['amenity','cafe'], ['amenity','restaurant']],
    'nightlife' => [['amenity','bar'], ['amenity','pub'], ['amenity','nightclub']],
];

// "Az ama öz": yalnızca oturmuş/gerçek mekanları al (bu sinyallerden biri olmalı).
// Böylece isimsiz/gelişigüzel noktalar elenir, elit sosyal yerler kalır.
$OSM_REQUIRE_QUALITY = true;
function osm_is_quality(array $t): bool {
    foreach (['website','contact:website','cuisine','opening_hours','wikidata','image','wikimedia_commons','brand','stars'] as $k) {
        if (!empty($t[$k])) return true;
    }
    return false;
}

/*
 * ── KATEGORİ HARİTALAMA (Mapping Layer) ────────────────────────
 * OSM etiketi "key=value" => [Civari kategorisi, price_level(1-3), alcohol(0/1), temel etiketler]
 * Frontend'in mevcut kategorileri: Kafe, Restoran, Bar, Market, Ders & Kitap,
 * Eğlence, Doğa, Kamp. Ek olarak: Eczane, Konaklama (frontend'e sonra pill eklenecek).
 */
$OSM_CAT_MAP = [
    'amenity=cafe'          => ['Kafe',     1, 0, 'kahve,çalışma'],
    'amenity=restaurant'    => ['Restoran', 2, 0, 'yemek'],
    'amenity=bar'           => ['Bar',      3, 1, 'bar,gece,alkol'],
    'amenity=pub'           => ['Bar',      3, 1, 'bar,gece,alkol'],
    'amenity=nightclub'     => ['Bar',      3, 1, 'gece,alkol,müzik,eğlence'],
];

/*
 * ── PLACEHOLDER GÖRSELLER ──────────────────────────────────────
 * OSM'de mekanların çoğunda foto yok. Kategoriye göre şık bir varsayılan.
 * (İlerde kendi sunucundaki görsellerle değiştirebilirsin.)
 */
// Her kategoride birden çok görsel — hepsi aynı görünmesin (osm_id ile sabit seçilir).
$OSM_PLACEHOLDERS = [
    'Kafe'         => ['https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=600&q=80','https://images.unsplash.com/photo-1453614512568-c4024d13c247?w=600&q=80','https://images.unsplash.com/photo-1442512595331-e89e73853f31?w=600&q=80','https://images.unsplash.com/photo-1521017432531-fbd92d768814?w=600&q=80'],
    'Restoran'     => ['https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?w=600&q=80','https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=600&q=80','https://images.unsplash.com/photo-1552566626-52f8b828add9?w=600&q=80','https://images.unsplash.com/photo-1559339352-11d035aa65de?w=600&q=80'],
    'Bar'          => ['https://images.unsplash.com/photo-1514933651103-005eec06c04b?w=600&q=80','https://images.unsplash.com/photo-1543007630-9710e4a00a20?w=600&q=80','https://images.unsplash.com/photo-1470337458703-46ad1756a187?w=600&q=80'],
    'Market'       => ['https://images.unsplash.com/photo-1604719312566-8912e9227c6a?w=600&q=80','https://images.unsplash.com/photo-1578916171728-46686eac8d58?w=600&q=80','https://images.unsplash.com/photo-1542838132-92c53300491e?w=600&q=80'],
    'Ders & Kitap' => ['https://images.unsplash.com/photo-1544148103-0773bf10d330?w=600&q=80','https://images.unsplash.com/photo-1521587760476-6c12a4b040da?w=600&q=80','https://images.unsplash.com/photo-1507842217343-583bb7270b66?w=600&q=80'],
    'Eczane'       => ['https://images.unsplash.com/photo-1587854692152-cbe660dbde88?w=600&q=80','https://images.unsplash.com/photo-1576602976047-174e57a47881?w=600&q=80','https://images.unsplash.com/photo-1471864190281-a93a3070b6de?w=600&q=80'],
    'Konaklama'    => ['https://images.unsplash.com/photo-1566073771259-6a8506099945?w=600&q=80','https://images.unsplash.com/photo-1611892440504-42a792e24d32?w=600&q=80','https://images.unsplash.com/photo-1445019980597-93fa8acb246c?w=600&q=80'],
    'Doğa'         => ['https://images.unsplash.com/photo-1470770841072-f978cf4d019e?w=600&q=80','https://images.unsplash.com/photo-1501785888041-af3ef285b470?w=600&q=80','https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=600&q=80'],
    'Kamp'         => ['https://images.unsplash.com/photo-1537905569824-f89f14cceb68?w=600&q=80','https://images.unsplash.com/photo-1504280390367-361c6d9f38f4?w=600&q=80','https://images.unsplash.com/photo-1455496231601-e6195da1f841?w=600&q=80'],
    'Eğlence'      => ['https://images.unsplash.com/photo-1516450360452-9312f5e86fc7?w=600&q=80','https://images.unsplash.com/photo-1470229722913-7c0e2dbbafd3?w=600&q=80','https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?w=600&q=80'],
];

/*
 * Bir mekana görsel seçer:
 *  1) OSM'de gerçek foto varsa (image / wikimedia_commons) onu kullanır
 *  2) yoksa kategoriye göre çeşitli placeholder'lardan birini (osm_id ile sabit) seçer
 */
// Wikidata (Q...) -> gerçek mekan fotoğrafı (P18) — notable/elit mekanlar için
function osm_wikidata_image(string $qid): ?string {
    static $cache = [];
    if (array_key_exists($qid, $cache)) return $cache[$qid];
    $cache[$qid] = null;
    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://www.wikidata.org/wiki/Special:EntityData/' . rawurlencode($qid) . '.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_USERAGENT=>'CivariOSMImporter/1.0 (+https://civari.com.tr)']);
    $body = curl_exec($ch); curl_close($ch);
    if (!$body) return null;
    $j = json_decode($body, true);
    $file = $j['entities'][$qid]['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
    if (!$file) return null;
    $img = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode(str_replace(' ', '_', $file)) . '?width=600';
    $cache[$qid] = $img;
    return $img;
}
function osm_pick_image(array $tags, string $category, int $osmId): ?string {
    if (!empty($tags['image']) && preg_match('~^https?://~i', $tags['image'])) return $tags['image'];
    if (!empty($tags['wikimedia_commons'])) {
        $f = $tags['wikimedia_commons'];
        if (stripos($f, 'File:') === 0) $f = substr($f, 5);
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode(str_replace(' ', '_', $f)) . '?width=600';
    }
    if (!empty($tags['wikidata']) && preg_match('/^Q\d+$/', $tags['wikidata'])) {
        $wi = osm_wikidata_image($tags['wikidata']);
        if ($wi) return $wi;
    }
    global $OSM_PLACEHOLDERS;
    $imgs = $OSM_PLACEHOLDERS[$category] ?? null;
    if (!$imgs) return null;
    if (is_string($imgs)) return $imgs;
    return $imgs[abs($osmId) % count($imgs)];
}

// Bir OSM elemanının key=value'sını Civari kategorisine çevirir.
function osm_resolve_category(array $tags, array $map): ?array {
    foreach ($map as $kv => $info) {
        [$k, $v] = explode('=', $kv, 2);
        if (isset($tags[$k]) && $tags[$k] === $v) {
            return ['category' => $info[0], 'price' => $info[1], 'alcohol' => $info[2], 'tags' => $info[3]];
        }
    }
    return null;
}
