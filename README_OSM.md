# Nearby — OpenStreetMap Veri Altyapısı (Kurulum Rehberi)

Bu paket, **uydurma mekanları temizleyip** 8 şehrin **gerçek** mekanlarını
OpenStreetMap'ten (Overpass API) çekip veritabanına aktarır. %100 ücretsiz ve
legal (ODbL lisansı — veriyi saklayıp işleyebilirsin).

## Dosyalar
- `osm_config.php` — Şehirler, kategori eşlemesi, placeholder görseller, **güvenlik token'ı**.
- `import_osm.php` — İçe aktarma paneli (tarayıcıda çalışır).
- `sql/01_schema_osm.sql` — Veritabanına OSM sütunları ekler.
- `sql/02_cleanup_fake.sql` — Uydurma mekanları siler.

---

## Adım adım

### 0) YEDEK AL (çok önemli)
phpMyAdmin → `nearby_db` → **Dışa Aktar (Export)** → Git → `.sql` dosyasını indir.
Bir şey ters giderse buradan geri dönersin.

### 1) Şemayı güncelle
phpMyAdmin → `nearby_db` → **SQL** sekmesi → `sql/01_schema_osm.sql` içeriğini
yapıştır → **Git**. (Mevcut veriye dokunmaz, sadece yeni sütun/index ekler.)

### 2) Uydurma veriyi temizle
Aynı yerde `sql/02_cleanup_fake.sql` içeriğini yapıştır → **Git**.
> Bu; tüm mekanları + onlara bağlı yorum/favori/check-in kayıtlarını siler.
> **Kullanıcı hesaplarına (users) dokunmaz.**

### 3) Güvenlik token'ını değiştir
`osm_config.php` içinde:
```php
$OSM_IMPORT_TOKEN = 'DEGISTIR_beni_uzun_rastgele_2f9c1a';
```
Buradaki değeri **kendi uzun, rastgele** anahtarınla değiştir (ör. `nearby_9x!Kq2...`).

### 4) Dosyaları sunucuya yükle
`osm_config.php`, `import_osm.php` ve `sql/` klasörünü nearby.com.tr kök dizinine
(diğer .php dosyalarının yanına) yükle.

### 5) İçe aktarmayı başlat
Tarayıcıda aç:
```
https://nearby.com.tr/import_osm.php?token=SENIN_TOKENIN
```
**▶ Başlat (otomatik)** butonuna bas. Panel şehirleri kutucuk kutucuk gezer,
her 3 saniyede bir sonrakine otomatik geçer. Sekmeyi açık bırak; bitince
"Tüm kutucuklar tamamlandı" yazar. (~72 kutu, birkaç dakika sürer.)

### 6) Kontrol et
```
https://nearby.com.tr/import_osm.php?token=SENIN_TOKENIN&action=stats
```
Kategori ve şehir bazında kaç mekan geldiğini gösterir.

---

## Sık sorulanlar

**Tekrar çalıştırınca mekan çoğalır mı?** Hayır. Her OSM elemanı `(osm_type, osm_id)`
ile tekildir; ayrıca aynı isim + ~11m yakınlıktaki mükerrerler elenir. Yeniden
çalıştırmak veriyi **günceller**, çoğaltmaz. Kullanıcının yüklediği fotoğraflar
(`image_url`) güncellemede **korunur**.

**Bir kutu "Overpass hatası" verirse?** Sorun değil, o kutu atlanır ve devam eder.
İstersen daha sonra sadece o adımı tekrar çalıştırabilirsin (panel otomatik ilerler).

**Yeni şehir / ilçe eklemek.** `osm_config.php` → `$OSM_CITIES` dizisine yeni satır
ekle (label + bbox + split). bbox'u https://bboxfinder.com adresinden kolayca
alabilirsin. Muğla için Bodrum/Marmaris/Fethiye'yi ayrı "şehir" olarak eklemen önerilir.

**Kategoriler.** OSM → Nearby eşlemesi `$OSM_CAT_MAP` içinde. Mevcut kategoriler:
Kafe, Restoran, Bar, Market, Ders & Kitap, Eğlence, Doğa, Kamp + **yeni**: Eczane, Konaklama.
> Not: Eczane ve Konaklama frontend'de henüz filtre "pill"i olarak yok. Genel aramada
> çıkarlar; bir sonraki (frontend) fazında bu iki kategoriye pill ekleyeceğiz.

**Puanlar.** OSM'de puan yok; içe aktarılan mekanların `rating` değeri boş (NULL) gelir.
Frontend fazında bunları "Yeni / puanlanmadı" olarak göstereceğiz; kullanıcı yorumları
biriktikçe gerçek puan oluşur.

**Sunucu gereksinimi.** Barındırmanın dışa `https://overpass-api.de` adresine bağlanabilmesi
gerekir (Plesk'te genelde açıktır). curl yoksa script otomatik `file_get_contents`e düşer.
