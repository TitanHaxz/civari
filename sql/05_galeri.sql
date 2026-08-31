-- =====================================================
--  CİVARİ — MEKAN FOTOĞRAF GALERİSİ  (5. adım)
--  phpMyAdmin > civari_db > SQL sekmesi > yapıştır > Git
-- =====================================================
--
--  Bu script yeni bir tablo oluşturur. Mevcut veriye DOKUNMAZ,
--  güvenle birden çok kez çalıştırılabilir.
--
--  NE İŞE YARAR?
--  Şimdiye kadar her mekanın tek bir fotoğrafı olabiliyordu (venues.image_url).
--  Bu tablo sayesinde bir mekana istediğin kadar fotoğraf ekleyebilirsin;
--  mekan sayfasında ve detay penceresinde kaydırmalı bir galeri olarak görünürler.
--
--  ÖNEMLİ: Buraya yalnızca KENDİ ÇEKTİĞİN ya da mekan sahibinin izin verdiği
--  fotoğrafları ekle. Başkasının fotoğrafını (Google yorumları, Instagram vb.)
--  izinsiz yüklemek telif ihlalidir.
-- =====================================================

CREATE TABLE IF NOT EXISTS venue_photos (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  venue_id   INT NOT NULL,
  url        VARCHAR(500) NOT NULL,               -- örn: uploads/dharma-1.webp
  baslik     VARCHAR(200) NULL,                   -- fotoğrafın altında görünecek kısa açıklama
  temsili    TINYINT(1) NOT NULL DEFAULT 0,       -- 1 = mekanın kendi fotoğrafı değil, temsili stok görsel
  sira       INT NOT NULL DEFAULT 0,              -- küçükten büyüğe sıralanır
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_venue (venue_id, sira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tablo daha önce oluşturulmuşsa eksik sütunu ekle (zararsız, tekrar çalıştırılabilir)
ALTER TABLE venue_photos ADD COLUMN IF NOT EXISTS temsili TINYINT(1) NOT NULL DEFAULT 0;

-- Kontrol: tablo oluştu mu?
SELECT COUNT(*) AS galeri_foto_sayisi FROM venue_photos;
