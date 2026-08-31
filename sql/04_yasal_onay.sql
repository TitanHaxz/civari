-- =====================================================
--  NEARBY — YASAL ONAY KAYDI  (4. adım)
--  phpMyAdmin > nearby_db > SQL sekmesi > yapıştır > Git
-- =====================================================
--
--  Bu script users tablosuna iki sütun ekler. Mevcut veriye DOKUNMAZ,
--  güvenle birden çok kez çalıştırılabilir.
--
--  NEDEN GEREKLİ?
--  KVKK'da açık rızanın alındığını İSPAT ETME yükümlülüğü site sahibindedir.
--  Yani "kullanıcı onay verdi" demek yetmez; ne zaman onay verdiğini kayıt
--  altında tutman gerekir. Bu sütunlar tam olarak bunu yapar.
-- =====================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS kvkk_onay_tarihi  DATETIME NULL;      -- onayın verildiği an
ALTER TABLE users ADD COLUMN IF NOT EXISTS kvkk_onay_surumu  VARCHAR(20) NULL;   -- onaylanan metin sürümü

-- Bu tarihten önce kayıt olmuş mevcut kullanıcılar için not:
-- Onay kayıtları boş kalır. Bu kullanıcılar siteye tekrar girdiğinde
-- güncellenmiş politikalar geçerli olur; istersen kendilerine
-- bilgilendirme e-postası gönderebilirsin.

-- Kontrol: yeni sütunlar geldi mi?
SELECT COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME LIKE 'kvkk%';
