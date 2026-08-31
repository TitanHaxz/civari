<?php
/*
 * NEARBY — En İyi Kâşifler (Liderlik)
 * GET ?scope=district|province|country  (varsayılan country)
 * district/province => oturum açan kullanıcının kendi ilçe/iline göre filtreler.
 * Puan = yorum*10 + check-in*2
 */
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
ob_start();
@session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($d){ if(ob_get_length()!==false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if ($pdo === null) out(['status'=>'error','users'=>[]]);

$scope = preg_replace('/[^a-z]/', '', strtolower($_GET['scope'] ?? 'country'));
if (!in_array($scope, ['district','province','country'], true)) $scope = 'country';

$me = (int)($_SESSION['user_id'] ?? 0);

try {
    // Oturum sahibinin il/ilçesi (yerel kapsamlar için)
    $myProvince = ''; $myDistrict = '';
    if ($me) {
        try {
            $s = $pdo->prepare("SELECT province, district FROM users WHERE id=?");
            $s->execute([$me]);
            if ($row = $s->fetch()) { $myProvince = $row['province'] ?? ''; $myDistrict = $row['district'] ?? ''; }
        } catch (Exception $e) {}
    }

    // Yerel kapsam istendi ama bilgi yoksa: boş liste + bilgilendirme
    if ($scope === 'district' && $myDistrict === '') {
        out(['status'=>'success','scope'=>$scope,'scopeLabel'=>'İlçem','needLocation'=>1,'users'=>[],'myProvince'=>$myProvince,'myDistrict'=>$myDistrict]);
    }
    if ($scope === 'province' && $myProvince === '') {
        out(['status'=>'success','scope'=>$scope,'scopeLabel'=>'İlim','needLocation'=>1,'users'=>[],'myProvince'=>$myProvince,'myDistrict'=>$myDistrict]);
    }

    $hasCheckins = false;
    try { $pdo->query("SELECT 1 FROM checkins LIMIT 1"); $hasCheckins = true; } catch (Exception $e) { $hasCheckins = false; }

    $checkSel = $hasCheckins ? "(SELECT COUNT(*) FROM checkins c WHERE c.user_id=u.id)" : "0";
    $sql = "SELECT u.id, u.fullname, u.avatar, u.province, u.district,
               (SELECT COUNT(*) FROM reviews r WHERE r.user_id=u.id) AS reviews,
               $checkSel AS checkins
            FROM users u";
    $params = [];
    if ($scope === 'district') { $sql .= " WHERE u.district = ?"; $params[] = $myDistrict; }
    elseif ($scope === 'province') { $sql .= " WHERE u.province = ?"; $params[] = $myProvince; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['reviews']  = (int)$r['reviews'];
        $r['checkins'] = (int)$r['checkins'];
        $r['points']   = $r['reviews']*10 + $r['checkins']*2;
    }
    unset($r);
    usort($rows, fn($a,$b) => $b['points'] <=> $a['points']);
    $top = array_slice($rows, 0, 10);
    foreach ($top as &$r) { $r['mine'] = ($me && (int)$r['id'] === (int)$me) ? 1 : 0; }
    unset($r);

    $label = $scope === 'district' ? ($myDistrict ?: 'İlçem') : ($scope === 'province' ? ($myProvince ?: 'İlim') : 'Türkiye');
    out(['status'=>'success','scope'=>$scope,'scopeLabel'=>$label,'users'=>$top,'myProvince'=>$myProvince,'myDistrict'=>$myDistrict]);
} catch (Exception $e) {
    error_log('Liderlik: '.$e->getMessage());
    out(['status'=>'error','users'=>[]]);
}
