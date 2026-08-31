# Nearby — Mobil Uygulama (iOS + Android)

Bu klasör, **Capacitor** ile Nearby'yi native bir mobil uygulamaya dönüştürür.
Uygulama, canlı **https://nearby.com.tr** sitesini native bir kabuk içinde açar →
**iki platformda da birebir aynı özellikler**, tek kaynak, otomatik güncelleme
(sitede yaptığın değişiklik anında uygulamada da görünür, yeniden yayın gerekmez).

Native olarak eklenen: uygulama ikonu, açılış (splash) ekranı, durum çubuğu rengi,
**gerçek GPS konumu izinleri** (Yakınımdakiler için).

- Uygulama adı: **Nearby**  ·  Paket kimliği: **com.nearby.app**

---

## 📱 HEMEN TEST ET (Android)
Masaüstündeki **`Nearby.apk`** dosyasını Android telefonuna at, dokun, "bilinmeyen
kaynak"a izin ver, kur. Uygulama açılıp canlı Nearby'yi gösterir.
> Bu bir **debug** APK'sı — test için. Play Store için aşağıdaki "release" adımları.

---

## Gereksinimler
- **Android derleme:** Node.js (var), Android Studio + SDK (var), JDK 21.
  - Bu makinede JDK 21, Android Studio içinde: `C:\Program Files\Android\Android Studio\jbr`
  - Derlemeden önce: `set JAVA_HOME=C:\Program Files\Android\Android Studio\jbr`
- **iOS derleme:** **Mac + Xcode ZORUNLU** (Apple şartı, Windows'ta iOS derlenemez).
  Mac yoksa bulut derleme: Codemagic / Ionic Appflow (aşağıda).

## Kurulum (bir kez)
```bash
cd nearby-mobile
npm install
```

## Android — debug APK (test)
```bash
cd nearby-mobile/android
gradlew.bat assembleDebug
```
Çıktı: `android/app/build/outputs/apk/debug/app-debug.apk`

## Android — Play Store için release (AAB)
1. **İmza anahtarı oluştur** (bir kez, güvende sakla — kaybedersen güncelleme yapamazsın):
   ```bash
   keytool -genkey -v -keystore nearby.keystore -alias nearby -keyalg RSA -keysize 2048 -validity 10000
   ```
2. `android/keystore.properties` oluştur:
   ```
   storeFile=../../nearby.keystore
   storePassword=SENIN_SIFREN
   keyAlias=nearby
   keyPassword=SENIN_SIFREN
   ```
   ve `android/app/build.gradle` içine imza yapılandırmasını ekle (Capacitor dokümanı:
   https://capacitorjs.com/docs/android/deploying-to-google-play ).
3. Derle:
   ```bash
   cd android
   gradlew.bat bundleRelease
   ```
   Çıktı: `android/app/build/outputs/bundle/release/app-release.aab` → Google Play Console'a yükle.
- **Google Play Console:** tek seferlik 25 USD, kendi hesabınla.

## iOS — Mac'te
```bash
cd nearby-mobile
npx cap open ios        # Xcode açılır
```
Xcode'da: Signing & Capabilities → kendi Apple Developer hesabını seç → Product > Archive →
App Store Connect'e yükle.
- **Apple Developer Program:** yıllık 99 USD (zorunlu).

## iOS — Mac yoksa (bulut derleme)
- **Codemagic** (codemagic.io) veya **Ionic Appflow**: Capacitor projesini bağla,
  macOS sunucularında iOS derlemesi yapar. Yine de **Apple Developer hesabı** (99 USD/yıl)
  imzalama için gerekir.

---

## 🔄 Nasıl güncellenir?
- **İçerik/tasarım değişikliği (site):** Hiçbir şey yapma. Uygulama canlı siteyi
  gösterdiği için `nearby.com.tr`'yi güncellemen yeter — app anında güncel olur,
  mağazaya yeniden yükleme gerekmez.
- **Native değişiklik (ikon, izin, splash, eklenti):** `npx cap sync` → yeniden derle → mağazaya yükle.

## Dosya yapısı
- `capacitor.config.json` — uygulama kimliği, canlı URL, splash/status bar ayarı
- `www/index.html` — çevrimdışı/yükleme ekranı (marka temalı)
- `android/` — Android Studio projesi (Gradle ile derlenir)
- `ios/` — Xcode projesi (Mac'te derlenir)
- `assets/` — kaynak ikon + splash (1024 / 2732 px)

## Notlar
- Konum: hem web (`navigator.geolocation`) hem native izinler ayarlı
  (AndroidManifest + Info.plist). İlk "Yakınımdakiler"de sistem izni sorulur.
- Uygulama internet gerektirir (canlı site). Bağlantı yoksa marka temalı bir
  "bağlanınca açılacak" ekranı görünür.
- Apple, "sadece web sitesi sarmalayan" uygulamaları bazen reddeder (kural 4.2).
  Nearby gerçek bir servis + konum kullandığı için genelde sorun olmaz; istenirse
  ileride push bildirim / paylaşım gibi native özellikler eklenip güçlendirilebilir.
