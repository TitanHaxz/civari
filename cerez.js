/* =====================================================
   CİVARİ — ÇEREZ ONAYI + ÖLÇÜM (KVKK / ePrivacy uyumlu)
   -----------------------------------------------------
   Yasal kural: Zorunlu olmayan çerezler (ölçüm/analitik dahil) kullanıcı
   AÇIKÇA ONAY VERMEDEN çalıştırılamaz. Bu dosya şunu garanti eder:

     • Onay verilmeden Google Analytics kodu SAYFAYA HİÇ YÜKLENMEZ.
     • Kullanıcı "Reddet" derse hiçbir ölçüm çerezi oluşmaz, site normal çalışır.
     • Onay kararı tarayıcıda 6 ay saklanır; kullanıcı istediği an değiştirebilir
       (her sayfanın altındaki "Çerez Ayarları" bağlantısı).

   Ölçüm kimliği index.html / mekan.php içinde window.CİVARİ_GA_ID olarak tanımlıdır.
   ===================================================== */
(function () {
  'use strict';

  var ANAHTAR   = 'civari_cerez_onay';   // localStorage anahtarı
  var GECERLILIK = 180 * 24 * 60 * 60 * 1000; // 6 ay (ms)

  /* ---------- Onay durumunu oku ---------- */
  function onayDurumu() {
    try {
      var ham = localStorage.getItem(ANAHTAR);
      if (!ham) return null;
      var k = JSON.parse(ham);
      if (!k || !k.t) return null;
      if (Date.now() - k.t > GECERLILIK) return null; // süresi doldu, tekrar sor
      return k.olcum === true ? 'kabul' : 'ret';
    } catch (e) { return null; }
  }

  function onayKaydet(kabul) {
    try { localStorage.setItem(ANAHTAR, JSON.stringify({ olcum: !!kabul, t: Date.now() })); } catch (e) {}
  }

  /* ---------- Google Analytics + Consent Mode v2 ----------
     Google'ın resmi "izin modu" kullanılır: etiket sayfaya yüklenir (böylece
     Google Analytics kurulumu doğrulayabilir) ancak izin verilene kadar
     TÜM depolama reddedilir — yani çerez yazılmaz, kişisel veri işlenmez.
     Kullanıcı "Kabul et" dediğinde izin 'granted' olarak güncellenir.
     Bu, KVKK'nın "önceden onay" ilkesiyle uyumlu Google-onaylı yöntemdir. */
  var gaYuklendi = false;

  function gtagHazirla() {
    window.dataLayer = window.dataLayer || [];
    if (!window.gtag) window.gtag = function () { window.dataLayer.push(arguments); };
  }

  function etiketiYukle() {
    if (gaYuklendi) return;
    var id = window.CİVARİ_GA_ID;
    if (!id) return;
    gaYuklendi = true;

    gtagHazirla();
    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id);
    document.head.appendChild(s);

    gtag('js', new Date());
    gtag('config', id, { anonymize_ip: true });  // IP adresi anonimleştirilir
  }

  /* İzin varsayılanı: her şey reddedilmiş. Etiket yüklenmeden ÖNCE ayarlanır. */
  function izinVarsayilani(kabulEdildiMi) {
    gtagHazirla();
    gtag('consent', 'default', {
      ad_storage: 'denied',
      ad_user_data: 'denied',
      ad_personalization: 'denied',
      analytics_storage: kabulEdildiMi ? 'granted' : 'denied',
      wait_for_update: 500
    });
  }

  function izniGuncelle(kabul) {
    gtagHazirla();
    gtag('consent', 'update', { analytics_storage: kabul ? 'granted' : 'denied' });
  }

  /* ---------- Ölçümü kapat (ret / vazgeçme) ---------- */
  function olcumuDurdur() {
    izniGuncelle(false);
    // Varsa daha önce oluşmuş ölçüm çerezlerini temizle
    try {
      document.cookie.split(';').forEach(function (c) {
        var ad = c.split('=')[0].trim();
        if (ad.indexOf('_ga') === 0 || ad.indexOf('_gid') === 0 || ad.indexOf('_gat') === 0) {
          var alanlar = ['', '; domain=' + location.hostname, '; domain=.' + location.hostname.replace(/^www\./, '')];
          alanlar.forEach(function (d) {
            document.cookie = ad + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/' + d;
          });
        }
      });
    } catch (e) {}
  }

  function olcumuBaslat() { izniGuncelle(true); }

  /* ---------- Banner ---------- */
  function stilEkle() {
    if (document.getElementById('civariCerezStil')) return;
    var st = document.createElement('style');
    st.id = 'civariCerezStil';
    st.textContent = [
      '.cz-banner{position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#fff;',
      'border-top:1px solid #d2d2d1;box-shadow:0 -12px 40px -18px rgba(0,0,0,.3);',
      'padding:20px 22px calc(20px + env(safe-area-inset-bottom,0px));',
      'font-family:"Outfit",system-ui,-apple-system,sans-serif;color:#1c1c1e;line-height:1.6;',
      'transform:translateY(110%);transition:transform .38s cubic-bezier(.2,.8,.3,1)}',
      '.cz-banner.acik{transform:translateY(0)}',
      '.cz-in{max-width:1000px;margin:0 auto;display:flex;gap:22px;align-items:center;flex-wrap:wrap}',
      '.cz-txt{flex:1 1 380px;min-width:260px;font-size:.93rem;color:#334155}',
      '.cz-txt b{display:block;font-size:1.02rem;color:#1c1c1e;margin-bottom:4px;font-weight:800}',
      '.cz-txt a{color:#e07f00;font-weight:700}',
      '.cz-btns{display:flex;gap:10px;flex-wrap:wrap}',
      '.cz-btn{border:none;cursor:pointer;font-family:inherit;font-weight:800;font-size:.93rem;',
      'padding:13px 26px;border-radius:13px;transition:.2s;white-space:nowrap}',
      '.cz-btn.kabul{background:#ff9900;color:#fff}',
      '.cz-btn.kabul:hover{background:#e07f00;transform:translateY(-2px)}',
      '.cz-btn.ret{background:#fff;color:#1c1c1e;border:2px solid #d2d2d1}',
      '.cz-btn.ret:hover{border-color:#ff9900;color:#e07f00;background:#fffaf2}',
      '.cz-btn:focus-visible{outline:3px solid #ff9900;outline-offset:2px}',
      '@media(max-width:640px){.cz-in{gap:14px}.cz-btns{width:100%}.cz-btn{flex:1 1 0;padding:13px 12px}',
      '.cz-banner{padding:18px 16px calc(18px + env(safe-area-inset-bottom,0px))}}',
      '@media(prefers-reduced-motion:reduce){.cz-banner{transition:none}}'
    ].join('');
    document.head.appendChild(st);
  }

  function bannerGoster() {
    stilEkle();
    var eski = document.getElementById('czBanner');
    if (eski) eski.remove();

    var el = document.createElement('div');
    el.className = 'cz-banner';
    el.id = 'czBanner';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-live', 'polite');
    el.setAttribute('aria-label', 'Çerez tercihi');
    el.innerHTML =
      '<div class="cz-in">' +
        '<div class="cz-txt">' +
          '<b>Çerezler hakkında</b>' +
          'Sitenin çalışması için gereken zorunlu çerezleri kullanıyoruz. Ayrıca siteyi nasıl geliştirebileceğimizi ' +
          'anlamak için <b style="display:inline;font-weight:700">isteğe bağlı ölçüm çerezleri</b> kullanmak istiyoruz. ' +
          'Bunlar yalnızca izin verirsen çalışır. Ayrıntılar: ' +
          '<a href="cerez-politikasi.html">Çerez Politikası</a> · <a href="gizlilik.html">Gizlilik</a>' +
        '</div>' +
        '<div class="cz-btns">' +
          '<button type="button" class="cz-btn ret" id="czRet">Sadece zorunlu</button>' +
          '<button type="button" class="cz-btn kabul" id="czKabul">Kabul et</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('acik'); });

    document.getElementById('czKabul').addEventListener('click', function () { karar(true); });
    document.getElementById('czRet').addEventListener('click', function () { karar(false); });
  }

  function bannerKapat() {
    var el = document.getElementById('czBanner');
    if (!el) return;
    el.classList.remove('acik');
    setTimeout(function () { el.remove(); }, 400);
  }

  function karar(kabul) {
    onayKaydet(kabul);
    bannerKapat();
    if (kabul) olcumuBaslat(); else olcumuDurdur();
  }

  /* ---------- Kullanıcı kararını sonradan değiştirebilsin ---------- */
  window.cerezAyarlari = function () {
    try { localStorage.removeItem(ANAHTAR); } catch (e) {}
    olcumuDurdur();
    bannerGoster();
  };

  /* ---------- Başlat ----------
     Sıra önemli: önce izin varsayılanı, sonra etiket. Böylece etiket
     yüklendiği anda hangi iznin geçerli olduğunu bilir. */
  var kayitliDurum = onayDurumu();
  izinVarsayilani(kayitliDurum === 'kabul');
  etiketiYukle();

  function basla() {
    if (kayitliDurum === 'kabul') olcumuBaslat();
    else if (kayitliDurum === 'ret') olcumuDurdur();
    else bannerGoster();   // karar verilene kadar izin 'denied' — çerez yazılmaz
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', basla);
  else basla();
})();
