# Proje Calisma Kurallari

### Git & Otomatik Yukleme Kurallari

- Kullanici bir gelistirme, hata duzeltmesi veya yeni ozellik tamamlanmasini istediginde, degisiklikleri dogrula.
- Yapilan isi ozetleyen conventional commit formatinda (`feat:`, `fix:`, `refactor:`, `style:`) commit olustur.
- Her tamamlanan gorevden sonra kullanici ek bir komut vermeden `git push origin main` komutunu calistirarak kodu dogrudan GitHub'a aktar.
- `.env` ve gizli anahtarlari asla commit'e dahil etme.

### Bu Depoya Ozel Notlar

Bu depo bir **portfolyo surumudur**. Gercek musteri/sirket verisi, kimlik bilgileri ve
marka bilgileri kaldirilmis, yerlerine jenerik demo degerleri konmustur.

Asagidakiler **asla** commit edilmemelidir:

- `.env` dosyalari ve her turlu kimlik bilgisi (sifre, JWT secret, API anahtari, connection string)
- Veritabani dosyalari (`*.db`, `*.sqlite`) ve yedekler (`*.bak`, `*.backup`)
- Gercek kisi isimleri, TC kimlik, telefon, e-posta adresleri
- Gercek malzeme/urun fiyatlari ve tedarikci firma isimleri
- Sirket logolari, adresleri ve marka varliklari
- Sunucu IP adresleri ve ic ag bilgileri
- Log klasorleri ve calisma zamani ciktilari

Yeni bir ekran goruntusu eklenecekse once icindeki gercek veri anonimlestirilmelidir.
