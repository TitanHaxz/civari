<?php
/*
 * CİVARİ — Merkezi Veritabanı Bağlantısı (Dayanıklı sürüm)
 * Hem 127.0.0.1 hem localhost dener; hangisi tutarsa onu kullanır.
 * Bağlantı kurulamazsa $pdo = null olur, betiği ÇÖKERTMEZ.
 */

$DB_NAME = 'civari_db';
$DB_USER = 'civari_user';
$DB_PASS = 'CHANGE_ME';   // Plesk'teki şifreyle BİREBİR aynı olmalı
$DB_PORT = '3306';

// Sırayla denenecek sunucu adresleri
$DB_HOSTS = ['127.0.0.1', 'localhost'];

$pdo = null;
$db_error = null;

foreach ($DB_HOSTS as $host) {
    try {
        $pdo = new PDO(
            "mysql:host=$host;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $db_error = null;
        break; // Bağlandık, döngüden çık
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
        $pdo = null;
    }
}

if ($pdo === null) {
    error_log('Civari DB Hatası: ' . $db_error);
}
