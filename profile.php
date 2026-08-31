<?php
/*
 * CİVARİ — Profil API
 * GET  (oturum açıksa)      -> kullanıcı bilgisi + yorum sayısı + yorumlarım + favorilerim
 * POST action=update_contact phone=... -> iletişim güncelle
 */
ini_set('display_errors', '0'); ini_set('log_errors', '1'); error_reporting(E_ALL);
ob_start();
@session_start();
require 'db.php';
header('Content-Type: application/json; charset=utf-8');

function out($d){ if(ob_get_length()!==false) ob_clean(); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
if ($pdo === null) out(['status'=>'error','message'=>'DB yok']);
if (empty($_SESSION['user_id'])) out(['status'=>'guest']);
$uid = $_SESSION['user_id'];

$action = $_POST['action'] ?? '';

/* İletişim güncelle */
if ($action === 'update_contact') {
    $phone = trim($_POST['phone'] ?? '');
    try {
        $pdo->prepare("UPDATE users SET phone=? WHERE id=?")->execute([$phone, $uid]);
        out(['status'=>'success','message'=>'Bilgiler güncellendi.']);
    } catch (Exception $e) {
        error_log('Profil güncelleme: '.$e->getMessage());
        out(['status'=>'error','message'=>'Güncellenemedi (users tablosunda phone sütunu var mı?).']);
    }
}

/* Profil verisi */
try {
    /* hesap_turu / isletme_adi sütunları yoksa (07_isletme.sql çalıştırılmadıysa) eski sorguya düş */
    try {
        $u = $pdo->prepare("SELECT fullname, email, phone, birth_year, avatar, province, district, created_at, hesap_turu, isletme_adi FROM users WHERE id=?");
        $u->execute([$uid]);
    } catch (Exception $eu) {
        $u = $pdo->prepare("SELECT fullname, email, phone, birth_year, avatar, province, district, created_at FROM users WHERE id=?");
        $u->execute([$uid]);
    }
    $user = $u->fetch() ?: [];

    $rc = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE user_id=?");
    $rc->execute([$uid]);
    $reviewCount = (int)$rc->fetchColumn();

    $myr = $pdo->prepare("SELECT r.id, r.rating, r.comment, r.image_url, r.created_at, v.name AS venue, r.venue_id
                          FROM reviews r LEFT JOIN venues v ON v.id=r.venue_id
                          WHERE r.user_id=? ORDER BY r.id DESC");
    $myr->execute([$uid]);
    $reviews = $myr->fetchAll();

    $fav = [];
    try {
        /* Favorilerde yalnız yayındaki mekanlar görünür — onay bekleyen bir mekan
           favorilere eklenmişse profilde de sızmamalı */
        $fq = $pdo->prepare("SELECT v.id, v.name, v.category, v.image_url, v.rating
                             FROM favorites f JOIN venues v ON v.id=f.venue_id
                             WHERE f.user_id=? AND v.is_active=1
                               AND (v.onay_durumu IS NULL OR v.onay_durumu='onaylandi')
                             ORDER BY f.id DESC");
        $fq->execute([$uid]);
        $fav = $fq->fetchAll();
    } catch (Exception $e) {}

    $by = $user['birth_year'] ?? null;
    $age = $by ? ((int)date('Y') - (int)$by) : null;

    $checkins = 0;
    try { $cc = $pdo->prepare("SELECT COUNT(*) FROM checkins WHERE user_id=?"); $cc->execute([$uid]); $checkins = (int)$cc->fetchColumn(); } catch (Exception $e) {}

    out([
        'status' => 'success',
        'fullname' => $user['fullname'] ?? '',
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'birth_year' => $by,
        'age' => $age,
        'avatar' => $user['avatar'] ?? '',
        'hesap_turu' => $user['hesap_turu'] ?? 'bireysel',
        'isletme_adi' => $user['isletme_adi'] ?? '',
        'province' => $user['province'] ?? '',
        'district' => $user['district'] ?? '',
        'member_since' => $user['created_at'] ?? '',
        'review_count' => $reviewCount,
        'checkins' => $checkins,
        'points' => $reviewCount * 10 + $checkins * 2,
        'reviews' => $reviews,
        'favorites' => $fav
    ]);
} catch (Exception $e) {
    error_log('Profil hatası: '.$e->getMessage());
    out(['status'=>'error','message'=>'Profil yüklenemedi.']);
}
