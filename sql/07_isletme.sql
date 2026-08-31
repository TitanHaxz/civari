-- =====================================================
--  NEARBY — İŞLETME HESAPLARI  (7. adım)
--  phpMyAdmin > nearby_db > SQL sekmesi > yapıştır > Git
-- =====================================================
--
--  Mevcut veriye ZARAR VERMEZ. Güvenle birden çok kez çalıştırılabilir.
--
--  NE EKLER?
--   • users tablosuna hesap türü (bireysel / işletme) ve eksikse avatar sütunu
--   • venues tablosuna sahiplik (owner_id) ve onay durumu
--   • Mevcut 5 mekan otomatik olarak "onaylandı" sayılır (görünmeye devam eder)
-- =====================================================

-- ---------- 1) KULLANICILAR ----------
-- Hesap türü: bireysel kullanıcı mı, işletme sahibi mi?
ALTER TABLE users ADD COLUMN IF NOT EXISTS hesap_turu VARCHAR(20) NOT NULL DEFAULT 'bireysel';

-- Profil fotoğrafı sütunu (bazı kurulumlarda eksikti — yüklenen avatarın
-- kaydedilememesinin ve profilde görünmemesinin sebebi buydu)
ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar VARCHAR(500) NULL;

-- İşletme sahibinin iletişim/yetkili bilgisi
ALTER TABLE users ADD COLUMN IF NOT EXISTS isletme_adi   VARCHAR(180) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS yetkili_unvan VARCHAR(120) NULL;

-- ---------- 2) MEKANLAR ----------
-- Bu mekanı hangi işletme hesabı yönetiyor? (NULL = editör tarafından eklenmiş)
ALTER TABLE venues ADD COLUMN IF NOT EXISTS owner_id INT NULL;

-- Onay durumu: beklemede | onaylandi | reddedildi
-- Yalnızca 'onaylandi' olanlar sitede (keşfet, harita, vitrin, arama) görünür.
ALTER TABLE venues ADD COLUMN IF NOT EXISTS onay_durumu VARCHAR(20) NOT NULL DEFAULT 'onaylandi';

-- Reddedilme gerekçesi (işletme kendi panelinde görür, düzeltip tekrar gönderir)
ALTER TABLE venues ADD COLUMN IF NOT EXISTS red_nedeni VARCHAR(500) NULL;

-- Başvuru ve karar zamanları
ALTER TABLE venues ADD COLUMN IF NOT EXISTS basvuru_tarihi DATETIME NULL;
ALTER TABLE venues ADD COLUMN IF NOT EXISTS onay_tarihi    DATETIME NULL;

ALTER TABLE venues ADD INDEX IF NOT EXISTS idx_owner (owner_id);
ALTER TABLE venues ADD INDEX IF NOT EXISTS idx_onay (onay_durumu);

-- ---------- 3) MEVCUT VERİYİ KORU ----------
-- Şu anki 5 vitrin mekanı editör eklemesidir; onaylı kalsınlar.
UPDATE venues SET onay_durumu = 'onaylandi' WHERE onay_durumu IS NULL OR onay_durumu = '';

-- ---------- 4) KONTROL ----------
SELECT
  (SELECT COUNT(*) FROM venues WHERE onay_durumu='onaylandi') AS onayli_mekan,
  (SELECT COUNT(*) FROM venues WHERE onay_durumu='beklemede') AS bekleyen_basvuru,
  (SELECT COUNT(*) FROM users WHERE hesap_turu='isletme')     AS isletme_hesabi;
