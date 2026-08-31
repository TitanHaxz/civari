<?php
/*
 * NEARBY — Buradayım (Check-in)
 * POST venue_id=..  (GİRİŞ GEREKLİ)  -> günde 1 kez, +2 puan
 */
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
ob_start();
@session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($d){ if(ob_get_length()!==false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if ($pdo === null) out(['status'=>'error','message'=>'Veritabanı bağlantısı yok.']);
if (empty($_SESSION['user_id'])) out(['status'=>'error','message'=>'Giriş gerekli.']);

$uid = (int)$_SESSION['user_id'];
$venue_id = (int)($_POST['venue_id'] ?? 0);
if ($venue_id <= 0) out(['status'=>'error','message'=>'Mekan belirtilmedi.']);

try {
    // Aynı mekana bugün tekrar check-in engeli
    $chk = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE user_id=? AND venue_id=? AND DATE(created_at)=CURDATE()");
    $chk->execute([$uid, $venue_id]);
    if ((int)$chk->fetchColumn() > 0) {
        out(['status'=>'error','message'=>'Bugün bu mekana zaten check-in yaptın.']);
    }

    $pdo->prepare("INSERT INTO checkins (user_id, venue_id) VALUES (?, ?)")->execute([$uid, $venue_id]);

    // Güncel puan: yorum*10 + check-in*2
    $rc = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE user_id=?"); $rc->execute([$uid]); $reviews=(int)$rc->fetchColumn();
    $cc = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE user_id=?"); $cc->execute([$uid]); $checks=(int)$cc->fetchColumn();
    $points = $reviews*10 + $checks*2;

    out(['status'=>'success','message'=>'Buradasın! +2 Nearby Puan 🎉','points'=>$points,'checkins'=>$checks]);
} catch (Exception $e) {
    error_log('Check-in: '.$e->getMessage());
    out(['status'=>'error','message'=>'Check-in yapılamadı (checkins tablosu kurulu mu?).']);
}
