<?php
/*
 * CİVARİ — Favori API
 * GET  (oturum açıksa) -> kullanıcının favori mekan id listesi
 * POST action=toggle venue_id -> favoriye ekle/çıkar
 */
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
ob_start();
@session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($d){ if(ob_get_length()!==false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if ($pdo === null) out(['status'=>'error','message'=>'DB yok','ids'=>[]]);

if (empty($_SESSION['user_id'])) out(['status'=>'guest','ids'=>[]]);
$uid = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';

if ($action === 'toggle') {
    $vid = (int)($_POST['venue_id'] ?? 0);
    if ($vid <= 0) out(['status'=>'error','message'=>'Geçersiz mekan.']);
    try {
        $chk = $pdo->prepare("SELECT id FROM favorites WHERE user_id=? AND venue_id=?");
        $chk->execute([$uid, $vid]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM favorites WHERE user_id=? AND venue_id=?")->execute([$uid, $vid]);
            out(['status'=>'success','favorited'=>false]);
        } else {
            $pdo->prepare("INSERT INTO favorites (user_id, venue_id) VALUES (?,?)")->execute([$uid, $vid]);
            out(['status'=>'success','favorited'=>true]);
        }
    } catch (Exception $e) {
        error_log('Favori hatası: '.$e->getMessage());
        out(['status'=>'error','message'=>'İşlem başarısız.']);
    }
}

/* Liste */
try {
    $stmt = $pdo->prepare("SELECT venue_id FROM favorites WHERE user_id=?");
    $stmt->execute([$uid]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'venue_id'));
    out(['status'=>'success','ids'=>$ids]);
} catch (Exception $e) {
    out(['status'=>'success','ids'=>[]]);
}
