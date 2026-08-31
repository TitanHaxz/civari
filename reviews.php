<?php
/*
 * NEARBY — Yorum API (yaş kontrolü + fotoğraf + silme)
 * GET  ?venue_id=5        -> yorumlar + ortalama
 * POST action=add ...     -> yorum ekle (GİRİŞ + 18 yaş gerekli; foto Lv2+)
 * POST action=delete id=. -> kendi yorumunu sil
 */
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
ob_start();
@session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($d){ if(ob_get_length()!==false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if ($pdo === null) out(['status'=>'error','message'=>'Veritabanı bağlantısı yok.']);

$action = $_POST['action'] ?? '';

/* YORUM SİL (kendi yorumu) */
if ($action === 'delete') {
    if (empty($_SESSION['user_id'])) out(['status'=>'error','message'=>'Giriş gerekli.']);
    $rid = (int)($_POST['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM reviews WHERE id=? AND user_id=?");
        $stmt->execute([$rid, $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) out(['status'=>'success','message'=>'Yorum silindi.']);
        out(['status'=>'error','message'=>'Bu yorumu silemezsin.']);
    } catch (Exception $e) { out(['status'=>'error','message'=>'Silinemedi.']); }
}

/* YORUM EKLE */
if ($action === 'add') {
    if (empty($_SESSION['user_id'])) out(['status'=>'error','message'=>'Yorum yapmak için giriş yapmalısın.']);

    // Yaş kontrolü
    $birthYear = null; $reviewCount = 0;
    try {
        $q1 = $pdo->prepare("SELECT birth_year FROM users WHERE id=?");
        $q1->execute([$_SESSION['user_id']]);
        $birthYear = $q1->fetchColumn();
        $q2 = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE user_id=?");
        $q2->execute([$_SESSION['user_id']]);
        $reviewCount = (int)$q2->fetchColumn();
    } catch (Exception $e) {}

    if ($birthYear && (int)date('Y') - (int)$birthYear < 18) {
        out(['status'=>'error','message'=>'18 yaşından küçük kullanıcılar yorum yapamaz.']);
    }

    $venue_id = (int)($_POST['venue_id'] ?? 0);
    $rating   = (int)($_POST['rating'] ?? 5);
    $comment  = trim($_POST['comment'] ?? '');
    $image    = trim($_POST['image_url'] ?? '');
    if ($venue_id <= 0 || $comment === '') out(['status'=>'error','message'=>'Lütfen yorumunuzu yazın.']);
    if ($rating < 1 || $rating > 5) $rating = 5;

    // Fotoğraf sadece Seviye 2+ (5+ yorum) için
    if ($image !== '' && $reviewCount < 5) $image = '';

    try {
        $stmt = $pdo->prepare("INSERT INTO reviews (venue_id, user_id, user_name, rating, comment, image_url) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$venue_id, $_SESSION['user_id'], $_SESSION['fullname'] ?? 'Kullanıcı', $rating, $comment, $image !== '' ? $image : null]);
        out(['status'=>'success','message'=>'Yorumunuz eklendi!']);
    } catch (Exception $e) {
        error_log('Yorum ekleme: '.$e->getMessage());
        out(['status'=>'error','message'=>'Yorum eklenemedi.']);
    }
}

/* YORUMLARI LİSTELE */
$venue_id = (int)($_GET['venue_id'] ?? 0);
if ($venue_id <= 0) out(['status'=>'error','message'=>'Mekan belirtilmedi.']);

$myId = $_SESSION['user_id'] ?? 0;
try {
    $stmt = $pdo->prepare("SELECT id, user_id, user_name, rating, comment, image_url, created_at FROM reviews WHERE venue_id=? ORDER BY created_at DESC");
    $stmt->execute([$venue_id]);
    $reviews = $stmt->fetchAll();
    $count = count($reviews);
    $avg = 0;
    if ($count > 0) $avg = round(array_sum(array_column($reviews, 'rating')) / $count, 1);
    // kendi yorumu işaretle
    foreach ($reviews as &$r) { $r['mine'] = ($myId && (int)$r['user_id'] === (int)$myId) ? 1 : 0; }
    out(['status'=>'success','count'=>$count,'avg'=>$avg,'reviews'=>$reviews]);
} catch (Exception $e) {
    error_log('Yorum listeleme: '.$e->getMessage());
    out(['status'=>'success','count'=>0,'avg'=>0,'reviews'=>[]]);
}
