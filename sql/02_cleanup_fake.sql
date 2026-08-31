-- =====================================================
--  CİVARİ — SAHTE VERİ TEMİZLİĞİ  (2. adım)
--  phpMyAdmin > civari_db > SQL > yapıştır > Git
-- =====================================================
--
--  ⚠️  ÖNCE YEDEK AL!
--  phpMyAdmin > civari_db > "Dışa Aktar" (Export) > Git  ile
--  tüm veritabanının .sql yedeğini indir. Bu adım GERİ ALINAMAZ.
--
--  Bu script:
--    • TÜM mekanları (venues) siler  -> uydurma mekanların hepsi gider
--    • Mekanlara bağlı yorum/favori/check-in kayıtlarını siler
--    • users (kullanıcı hesapları) tablosuna DOKUNMAZ
--
--  Not: 03 adımdaki import_osm.php gerçek mekanları source='osm' olarak yeniden dolduracak.
-- =====================================================

-- Mekanlara bağlı türev kayıtları temizle
DELETE FROM reviews;
DELETE FROM favorites;
DELETE FROM checkins;

-- Mekanların kendisini temizle
DELETE FROM venues;

-- id sayaçlarını sıfırla (yeni veri 1'den başlasın — isteğe bağlı ama temiz durur)
ALTER TABLE venues    AUTO_INCREMENT = 1;
ALTER TABLE reviews   AUTO_INCREMENT = 1;
ALTER TABLE favorites AUTO_INCREMENT = 1;
ALTER TABLE checkins  AUTO_INCREMENT = 1;

-- Kontrol: 0 dönmeli
-- SELECT COUNT(*) AS kalan_mekan FROM venues;
