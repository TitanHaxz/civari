<?php
/*
 * CİVARİ — SİTE HARİTASI (Google'a "bende şu sayfalar var" listesi)
 * ------------------------------------------------------------------
 * Dinamiktir: veritabanına yeni mekan eklediğinde site haritası kendiliğinden
 * güncellenir, elle dosya düzenlemeye gerek kalmaz.
 *
 * Google Search Console'a şu adresi gönder:  https://civari.com.tr/sitemap.php
 */
ini_set('display_errors', '0');
require __DIR__ . '/db.php';

header('Content-Type: application/xml; charset=utf-8');

$SITE = 'https://civari.com.tr';
$bugun = date('Y-m-d');

/* Sabit sayfalar */
$sayfalar = [
    ['loc' => $SITE . '/',                        'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => $SITE . '/hakkimizda.html',         'priority' => '0.5', 'changefreq' => 'monthly'],
    ['loc' => $SITE . '/gizlilik.html',           'priority' => '0.3', 'changefreq' => 'yearly'],
    ['loc' => $SITE . '/kvkk.html',               'priority' => '0.3', 'changefreq' => 'yearly'],
    ['loc' => $SITE . '/cerez-politikasi.html',   'priority' => '0.3', 'changefreq' => 'yearly'],
    ['loc' => $SITE . '/kullanim-kosullari.html', 'priority' => '0.3', 'changefreq' => 'yearly'],
];

/* Mekan sayfaları */
$mekanlar = [];
if ($pdo !== null) {
    try {
        /* Yalnız onaylı mekanlar site haritasına girer. Sütun yoksa filtre kapanmaz —
           onaysız bir mekanın Google'a bildirilmesi geri alınması zor bir hatadır. */
        $onayKosul = " AND (source IS NULL OR source <> 'isletme')";
        try { $pdo->query("SELECT onay_durumu FROM venues LIMIT 1"); $onayKosul = " AND onay_durumu='onaylandi'"; } catch (Exception $oe) {}
        $q = $pdo->query("SELECT id, updated_at FROM venues WHERE is_active=1 AND city='Ankara'" . $onayKosul . " ORDER BY id");
        $mekanlar = $q->fetchAll();
    } catch (Exception $e) { error_log('sitemap: ' . $e->getMessage()); }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($sayfalar as $s) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($s['loc'], ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $bugun . "</lastmod>\n";
    echo '    <changefreq>' . $s['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $s['priority'] . "</priority>\n";
    echo "  </url>\n";
}

foreach ($mekanlar as $m) {
    $tarih = !empty($m['updated_at']) ? date('Y-m-d', strtotime((string)$m['updated_at'])) : $bugun;
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($SITE . '/mekan.php?id=' . (int)$m['id'], ENT_XML1) . "</loc>\n";
    echo '    <lastmod>' . $tarih . "</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.9</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
