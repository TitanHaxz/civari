-- =====================================================
--  NEARBY — OSM için ŞEMA GÜNCELLEMESİ  (1. adım)
--  phpMyAdmin > nearby_db > SQL > yapıştır > Git
--  Güvenle birden çok kez çalıştırılabilir (IF NOT EXISTS).
--  Mevcut veriye DOKUNMAZ, sadece yeni sütun/index ekler.
-- =====================================================

-- OSM kaynaklı alanlar
ALTER TABLE venues ADD COLUMN IF NOT EXISTS osm_type      VARCHAR(10)  NULL;   -- node / way / relation
ALTER TABLE venues ADD COLUMN IF NOT EXISTS osm_id        BIGINT       NULL;   -- OSM eleman id'si
ALTER TABLE venues ADD COLUMN IF NOT EXISTS district      VARCHAR(80)  NULL;   -- ilçe (addr:district / addr:city)
ALTER TABLE venues ADD COLUMN IF NOT EXISTS source        VARCHAR(20)  NOT NULL DEFAULT 'manual'; -- osm / manual
ALTER TABLE venues ADD COLUMN IF NOT EXISTS verified      TINYINT(1)   NOT NULL DEFAULT 0;         -- işletme doğrulandı mı
ALTER TABLE venues ADD COLUMN IF NOT EXISTS website       VARCHAR(255) NULL;
ALTER TABLE venues ADD COLUMN IF NOT EXISTS opening_hours VARCHAR(255) NULL;   -- OSM opening_hours ham metni
ALTER TABLE venues ADD COLUMN IF NOT EXISTS updated_at    TIMESTAMP    NULL DEFAULT NULL;

-- Tekrar-kayıt engeli: aynı OSM elemanı iki kez girmesin.
-- (manual satırlarda osm_type/osm_id NULL kalır; MySQL çoklu NULL'a izin verdiği için çakışmaz.)
ALTER TABLE venues ADD UNIQUE INDEX IF NOT EXISTS uniq_osm (osm_type, osm_id);

-- İsim + yakınlık bazlı mükerrer taraması için isim indexi (importer kullanır)
ALTER TABLE venues ADD INDEX IF NOT EXISTS idx_name (name);
ALTER TABLE venues ADD INDEX IF NOT EXISTS idx_city (city);
ALTER TABLE venues ADD INDEX IF NOT EXISTS idx_category (category);

-- Kontrol: yeni sütunlar geldi mi?
-- SHOW COLUMNS FROM venues;
