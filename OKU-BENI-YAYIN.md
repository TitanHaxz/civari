# Nearby — Ankara Vitrini Yayın Kılavuzu

Bu klasördeki her şey hazır. Aşağıdaki adımları **sırayla** uygularsan site Ankara vitriniyle canlıya çıkar.

---

## ⚠️ ADIM 0 — ÖNCE YEDEK AL (atlanamaz)

1. **Veritabanı yedeği:** Plesk → Veritabanları → phpMyAdmin → `nearby_db` → **Dışa Aktar (Export)** → Git → dosyayı bilgisayarına indir.
2. **Dosya yedeği:** Plesk → Dosya Yöneticisi → `httpdocs` klasörünü seç → **Arşive Ekle (Add to Archive)** → oluşan .zip'i indir.

Bir şey ters giderse bu iki yedekle her şeyi geri alırsın. **Bu adımı yapmadan devam etme.**

---

## ADIM 1 — Dosyaları yükle

`NearbyEnSon` klasöründeki **tüm dosyaları** Plesk'te `httpdocs` klasörüne yükle (üzerine yaz).

Yüklenecekler arasında yeni dosyalar da var:
- `mekan.php` — mekan sayfaları (Google'ın indeksleyeceği sayfalar)
- `sitemap.php` — site haritası
- `robots.txt` — arama motoru yönergeleri
- `03_ankara_vitrin.sql` — 5 mekanı ekleyen veritabanı komutu
- `kurulum.php` — **veritabanı kurulum sayfası** (tablo yapısını tek tıkla kurar)
- `isletme.php` ve `isletme_api.php` — işletme paneli
- `06_gorseller.sql` — fotoğrafları mekanlara bağlar
- `uploads/` klasörü — **20 adet .webp görsel** (mekan fotoğrafları)
- `cerez.js` — çerez onayı sistemi
- `kvkk.html`, `cerez-politikasi.html`, `kullanim-kosullari.html` — yasal sayfalar
- `web.config` ve `.htaccess` — sunucu güvenlik ayarları
- `uploads/web.config` ve `uploads/.htaccess` — yükleme klasörü güvenliği

> **Önemli:** Nokta ile başlayan `.htaccess` dosyaları ve `uploads` klasörünün içindekiler gizli sayılabilir. Plesk Dosya Yöneticisi'nde **"Gizli dosyaları göster"** seçeneğini açmayı unutma, yoksa bu dosyalar yüklenmez.

---

## ADIM 2 — Veritabanını Ankara'ya çevir

phpMyAdmin → `nearby_db` → **SQL** sekmesi.

### 2a) Önce tablo yapısını kur (tarayıcıdan, tek tık)

Tarayıcıda şu adresi aç:

```
https://nearby.com.tr/kurulum.php?token=kurulum-145a63afd421176f
```

Açılan sayfada **"Eksikleri Tamamla"** düğmesine bas. Sayfa veritabanındaki eksik tablo ve sütunları bulup ekler; mevcut verilere dokunmaz, defalarca çalıştırılabilir.

> **Bu neden SQL dosyası değil?** `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` yazımı yalnızca MariaDB'de çalışır; sunucun MySQL ise sözdizimi hatası verip **hiçbir satırı uygulamaz** — ve bunu fark etmezsin. Bu sayfa her iki veritabanında da doğru çalışır. Eski `01/04/05/07` numaralı SQL dosyalarını çalıştırmana artık gerek yok.

Sonunda **"✓ Veritabanı hazır"** yazısını görmelisin.

### 2b) Sonra veriyi yükle (phpMyAdmin)

phpMyAdmin → `nearby_db` → **SQL** sekmesi. İki dosyayı sırayla yapıştır → Git:

1. `03_ankara_vitrin.sql` — tüm eski mekanları (8 şehirlik OSM verisi) siler, yerine 5 gerçek Ankara mekanını koyar. Kullanıcı hesapları silinmez. Sonunda 5 satır görmelisin: Dharma Coffee, Kakule Kahve, Göksu Lokantası, Flamingo Pastanesi, Kıtır.
2. `06_gorseller.sql` — `uploads` klasöründeki 20 görseli 5 mekana dağıtır. Sonunda her mekanın karşısında **4 fotoğraf** yazmalı.

> **Not:** 2. adımın çalışması için `uploads` klasöründeki 20 `.webp` dosyasının sunucuya yüklenmiş olması gerekir. Yüklenmediyse fotoğraflar kırık görünür.

### 2c) Kurulum bitince

`kurulum.php` dosyasını sunucudan **sil**. İşi bitti ve durmasına gerek yok.

---

## ADIM 3 — Yeni yönetici şifreni kaydet

Admin şifresi değişti. Yenisi:

```
EAE6u+mnzRWs6j4eaTk5
```

**Bunu şifre yöneticine ya da güvenli bir yere kaydet.** Kodda artık şifrenin kendisi değil, geri çevrilemez karşılığı (hash) duruyor — yani biri dosyaları görse bile şifreni öğrenemez.

İleride değiştirmek istersen: `admin.php` dosyasının başındaki `$ADMIN_PASSWORD_HASH` satırını değiştirmen gerekir. Yeni karşılığı üretmek için bana "şu şifrenin hash'ini al" de, sana hazır satırı vereyim.

---

## ADIM 4 — Siteyi kontrol et

Tarayıcıda sırayla aç:

| Adres | Görmen gereken |
|---|---|
| `nearby.com.tr` | Giriş yapmadan 5 mekan görünüyor, üstte "Ankara'da Öne Çıkanlar" vitrini, altta çerez bildirimi |
| `nearby.com.tr/mekan.php?id=1` | Dharma Coffee sayfası: kapak fotoğrafı, kaydırmalı 4'lü galeri, çalışma saatleri, harita |
| `nearby.com.tr/kvkk.html` | KVKK Aydınlatma Metni |
| `nearby.com.tr/cerez-politikasi.html` | Çerez Politikası |
| `nearby.com.tr/kullanim-kosullari.html` | Kullanım Koşulları |
| `nearby.com.tr/sitemap.php` | 11 adresli sayfa listesi (XML görünümlü) |
| `nearby.com.tr/robots.txt` | Metin dosyası |
| `nearby.com.tr/admin.php` | Yeni şifreyle giriş · "İşletme Başvuruları" sekmesi görünmeli |
| `nearby.com.tr/isletme.php` | Giriş yapmadan açılırsa ana sayfaya atmalı (yalnızca işletme hesapları girer) |
| `nearby.com.tr/db.php` | **404 hatası vermeli** (veritabanı şifresi korunuyor) |
| `http://nearby.com.tr` (baştaki s olmadan) | Otomatik olarak `https://`'ye dönmeli |

Son iki satır güvenlik ayarlarının çalıştığını gösterir. `db.php` içerik gösteriyorsa ya da http https'e dönmüyorsa, `web.config` dosyası yüklenmemiş demektir — bana haber ver.

Telefondan da bir kez bak — özellikle ana sayfa ve harita.

### Güvenlik başlıklarını doğrula (isteğe bağlı ama önerilir)

[securityheaders.com](https://securityheaders.com) adresine `nearby.com.tr` yazıp tara. Yükleme doğruysa **A** veya **A+** not almalısın. Düşük not alırsan bana ekran görüntüsü gönder.

---

## ADIM 5 — Google'a haber ver (SEO)

1. [Google Search Console](https://search.google.com/search-console) → **Mülk Ekle** → "URL öneki" → `https://nearby.com.tr` yaz.
2. Doğrulama yöntemi olarak **HTML etiketi**'ni seç. Sana `<meta name="google-site-verification" ...>` diye bir satır verecek — o satırı bana gönder, `index.html` içine ekleyeyim. (Ya da Plesk'in DNS doğrulama yöntemini kullan.)
3. Doğrulandıktan sonra sol menüden **Site Haritaları** → `sitemap.php` yaz → Gönder.
4. Üstteki arama kutusuna `https://nearby.com.tr/mekan.php?id=1` yapıştır → **Dizine ekleme iste**. Aynısını 2, 3, 4, 5 için tekrarla.

Google'ın sayfaları taraması genelde birkaç gün, bazen 2 haftayı bulur.

---

## ADIM 6 — Ziyaretçi ölçümü (analytics) — ✅ HAZIR

Ölçüm kimliğin (`G-7C18H8LLXX`) tüm sayfalara eklendi, yapman gereken bir şey yok.

**Nasıl çalışıyor:** Ziyaretçi siteye ilk girdiğinde alt kısımda bir çerez bildirimi çıkar. **"Kabul et" demeden Google Analytics kodu sayfaya hiç yüklenmez** — bu KVKK gereği zorunludur. "Sadece zorunlu" derse site normal çalışır ama o ziyaretçi sayılmaz.

Bu yüzden Analytics'teki ziyaretçi sayısı gerçek ziyaretçi sayısından bir miktar düşük görünecektir; bu normaldir ve yasal olarak doğru olan yoldur.

Veriler [analytics.google.com](https://analytics.google.com) adresinde görünür — ilk veriler siteye yükledikten ~24 saat sonra gelir. (Akış kimliğin `15436140513` sunucu taraflı ölçüm için saklanmıştır, şu an gerekmiyor.)

---

## ADIM 7 — Kendi fotoğraflarınla değiştir

Şu an her mekanda 4'er adet **telifsiz stok görsel** var (Unsplash lisansı, ticari kullanıma açık). Bunlar mekanların kendi fotoğrafları değil — site boş görünmesin diye kondu. Kendi çekimlerin hazır oldukça bunları değiştir.

**Çekim listesi** (telefon yeter, gündüz ışığı en iyisi):
- Dış cephe / tabela — 1 kare
- İç mekan genel — 1-2 kare
- İmza ürün(ler) — 2-3 kare
- Bir detay (fincan, masa, dekor) — 1 kare

**Fotoğrafı siteye ekleme (artık çok kolay):**
1. `admin.php` → Mekanlar → ilgili mekanda **Düzenle**
2. Aşağıdaki **Fotoğraf Galerisi** bölümünden dosyaları seç (birden fazla seçebilirsin) → **FOTOĞRAF YÜKLE**
3. Sistem fotoğrafı otomatik küçültüp WebP'ye çevirir — elle uğraşmana gerek yok
4. Listeden **↑↓** ile sıralar, **★** ile kapak fotoğrafı yapar, **✕** ile silersin

Stok görselleri silmek için aynı listede **✕** düğmesine bas. Hepsini silersen site kategori renginde şık bir kapak gösterir.

**Tanıtım metni:** Şu an her mekanda doğrulanmış bilgilere dayanan bir taslak metin var. Ziyaretten sonra kendi gözlemlerinle zenginleştirmen çok daha iyi olur — aynı yerden ("Düzenle" → Tanıtım metni) değiştirebilirsin.

> ⚖️ **Fotoğraf ve telif:** Siteye yalnızca kendi çektiğin, mekan sahibinin izin verdiği ya da telifsiz (Unsplash/Pexels gibi) fotoğrafları yükle. Google Haritalar yorumlarındaki, Instagram'daki veya başka sitelerdeki fotoğraflar onları çeken kişilerin telifi altındadır; izinsiz kullanmak telif ihlalidir ve tazminat talebine yol açabilir.

---

---

## ⚖️ Yasal durum — ne yapıldı, sende ne kaldı

### Siteye eklenen yasal koruma

| Konu | Durum |
|---|---|
| **KVKK Aydınlatma Metni** | ✅ `kvkk.html` — işlenen veriler, hukuki sebepler, saklama süreleri, m.11 hakları, başvuru yolu |
| **Çerez Politikası** | ✅ `cerez-politikasi.html` — çerez tablosu, süreler, tercih değiştirme |
| **Kullanım Koşulları** | ✅ `kullanim-kosullari.html` — üyelik, içerik kuralları, sorumluluk sınırları, 5651 yer sağlayıcı bildirimi |
| **Gizlilik Politikası** | ✅ `gizlilik.html` — güncellendi |
| **Çerez onayı** | ✅ Onay verilmeden Google Analytics **hiç yüklenmiyor** (KVKK gereği zorunlu) |
| **Açık rıza kaydı** | ✅ Kayıt formunda onay kutusu + onay tarihi veritabanına yazılıyor (ispat yükümlülüğü) |
| **HTTPS zorlaması** | ✅ `web.config` ile http → https yönlendirmesi + HSTS |
| **Güvenlik başlıkları** | ✅ CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy |
| **Hassas dosya koruması** | ✅ `db.php`, `.sql`, `.ini` dosyaları internetten indirilemez |

### Sende kalan yasal yükümlülükler

**1. VERBİS kaydı — muhtemelen gerekmiyor**
Yıllık çalışan sayın 50'den az ve yıllık mali bilanço toplamın 25 milyon TL'den azsa, ana faaliyetin özel nitelikli veri işlemek değilse VERBİS'e kayıt zorunluluğun yok. Nearby bu kapsamda. İleride şirketleşip büyürsen [verbis.kvkk.gov.tr](https://verbis.kvkk.gov.tr) üzerinden kaydolman gerekebilir.

**2. Ticari faaliyet başlarsa** (işletmelerden para almaya başladığında)
- Şirket kurman (şahıs şirketi yeterli) ve vergi mükellefiyeti gerekir.
- Yasal metinlere ticari unvan, vergi dairesi, vergi no ve açık adres eklenmesi zorunlu hale gelir — bana söyle, hepsini güncelleyeyim.
- Elektronik ticaret yapıyorsan **ETBİS** kaydı ([etbis.ticaret.gov.tr](https://etbis.ticaret.gov.tr)) gerekir.
- Mesafeli Satış Sözleşmesi ve İptal/İade Koşulları sayfaları eklenmelidir.

**3. E-posta gönderimi başlarsa** (bülten, duyuru)
Ticari elektronik ileti göndermek için **İYS (İleti Yönetim Sistemi)** kaydı ve alıcı onayı zorunludur ([iys.org.tr](https://iys.org.tr)).

**4. Sürekli sorumluluklar**
- KVKK başvurularını **30 gün içinde** yanıtla (mail@example.com adresini düzenli kontrol et).
- Hesap silme taleplerini 30 gün içinde yerine getir.
- Hukuka aykırı içerik bildirimi gelirse gecikmeksizin değerlendir ve gerekiyorsa kaldır (5651 s.K.).
- Bir veri ihlali (sızıntı) yaşanırsa, öğrendiğin andan itibaren **72 saat içinde** Kişisel Verileri Koruma Kurulu'na bildir.

> ⚠️ **Önemli not:** Bu metinler, Nearby'nin mevcut işleyişine göre hazırlanmış sağlam birer taslaktır ve benzer platformların standart uygulamalarını karşılar. Ancak ben avukat değilim ve bu bir hukuki danışmanlık değildir. Ticari gelir elde etmeye başlamadan önce metinleri bir avukata gözden geçirtmeni öneririm — özellikle sorumluluk sınırlaması ve mekanların ticari haklarıyla ilgili bölümleri.

---

---

## 🏪 İşletme Hesapları — nasıl çalışıyor

**İşletme sahibi ne yapıyor?**
1. Ana sayfada **Kaydol** → hesap türü olarak **İşletme**'yi seçer, işletme adını yazar.
2. Kayıt biter bitmez **İşletme Paneli**'ne (`isletme.php`) yönlendirilir — açılır pencere değil, tam sayfa.
3. Panelde 4 bölüm doldurur: mekan bilgileri, harita üzerinde konum (adres yazıp arayabilir ya da pini sürükler), çalışma saatleri (gün gün), fotoğraflar (çoklu yükleme, sıralama, kapak seçme).
4. **Onaya Gönder** der. "Ekibimiz 24 saat içinde inceleyip yayınlayacak" mesajını görür.

**Sen ne yapıyorsun?**
- `admin.php` → **İşletme Başvuruları** sekmesi. Bekleyen başvuru varsa sekmede turuncu sayı görünür.
- Her başvuruda mekanın tüm bilgileri, sahibin iletişimi ve **Önizle** bağlantısı var (yayınlanmadan sayfasını görebilirsin).
- **✓ Onayla ve Yayınla** → mekan siteye düşer. **✕ Düzeltme İste** → gerekçe yazarsın, işletme bunu kendi panelinde görür ve düzeltip tekrar gönderir.

**Onaylanmadan önce mekan nerede görünür?** Hiçbir yerde. Keşfet listesinde, haritada, aramada, vitrinde, site haritasında ve mekan sayfasında görünmez (sayfası 404 verir). Sadece işletme kendi panelinde ve sen admin panelinde görürsün. Bu iki katmanlı korumayla sağlanıyor: hem onay durumu filtresi, hem de `is_active=0`.

**Onaylanan mekan otomatik vitrine girer mi?** Hayır. Vitrin ("Ankara'da Öne Çıkanlar") senin seçkin. Bir mekanı vitrine almak istersen Mekanlar sekmesinden **"Doğrulanmış (vitrin)"** kutusunu işaretle.

**Yayındaki bir işletme bilgilerini değiştirirse?** Değişiklik tekrar incelemeye girer ve onaylanana kadar mekan yayından iner. Böylece denetimsiz içerik değişikliği olmaz.

**Not:** Haritadaki eski "Mekan Ekle / Bildir" formu kaldırıldı — yerini bu sistem aldı.

---

## Sık sorulanlar

**Mekan eklemek istersem?**
`admin.php` → Mekanlar → soldaki form. Şehir otomatik "Ankara" olur. Vitrinde görünmesi için **"Doğrulanmış (vitrin)"** kutusunu işaretle. Koordinatı Google Haritalar'da mekana sağ tıklayıp kopyalayabilirsin.

**Oyunlaştırma (liderlik, rozet, check-in) nereye gitti?**
Arayüzden gizlendi ama kodu duruyor. 5 mekanla boş bir liderlik tablosu siteyi ölü gösterirdi. Mekan sayısı artınca tek hamlede geri açarız.

**OSM içe aktarma neden kapalı?**
Vitrin dönemi kararı: sitede sadece elle girilen, doğrulanmış mekanlar var. `import_osm.php` artık çalışmıyor (kodu duruyor). İleride açmak istersen `osm_config.php` içinde `$OSM_IMPORT_ENABLED = false;` satırını `true` yap.

**Kullanıcılar eski şifreleriyle girebilir mi?**
Evet. Yeni 8 karakter kuralı sadece yeni kayıtlar için geçerli, mevcut hesaplar etkilenmez.

---

## Neler değişti (özet)

**Güvenlik:** Yönetici şifresi güçlendirildi ve kodda gizlendi; silme işlemleri artık sahte istekle tetiklenemiyor (CSRF koruması); giriş denemesine fren eklendi; kayıt şifresi en az 8 karakter; yükleme klasöründe kod çalıştırma kapatıldı; yüklenen dosyalarda gerçek resim kontrolü eklendi.

**Ankara dönüşümü:** Harita merkezi, arama, il/ilçe listesi, admin paneli ve tüm metinler Ankara'ya çevrildi; sunucu tarafında "sadece Ankara" sigortası eklendi; 8 şehirlik veri ve ölü şehir seçici kodu temizlendi; uydurma yedek mekanlar 5 gerçek mekanla değiştirildi.

**Vitrin ve içerik:** Ana sayfaya "Ankara'da Öne Çıkanlar" şeridi eklendi; "Tatlıcı" kategorisi eklendi; fotoğrafı olmayan mekanlar için şık kapak tasarımı yapıldı.

**Görünürlük:** Giriş duvarı kaldırıldı (içerik artık herkese açık); her mekana kendi sayfası verildi (`mekan.php`); site haritası, robots.txt, sosyal paylaşım etiketleri ve Google için yapılandırılmış veri (LocalBusiness) eklendi; analytics altyapısı hazırlandı.

**Temizlik:** "(1)" ekli kopya dosyalar düzeltildi (`index.html`, `upload.php`, `favicon.ico`), birebir kopyalar ve kullanılmayan `main.js` silindi.
