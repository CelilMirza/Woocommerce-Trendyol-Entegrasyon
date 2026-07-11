# WooCommerce – Trendyol Entegrasyonu

<p align="center">
  <strong>WooCommerce mağazanızı Trendyol Pazaryeri'ne bağlayan WordPress eklentisi.</strong><br>
  Ürün gönderimi · Stok & fiyat senkronizasyonu · Sipariş yönetimi · Kategori & marka eşleştirme
</p>

<p align="center">
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-5.8%2B-blue">
  <img alt="WooCommerce" src="https://img.shields.io/badge/WooCommerce-6.0%2B-purple">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-7.4%2B-777bb4">
  <img alt="Trendyol API" src="https://img.shields.io/badge/Trendyol-V2%20API-orange">
  <img alt="License" src="https://img.shields.io/badge/Lisans-GPLv2-green">
</p>

<p align="center">
  Geliştirici: <a href="https://digitalog.com.tr"><strong>Digitalog</strong></a> ·
  İletişim & özel yazılım çözümleri için: <a href="https://digitalog.com.tr">digitalog.com.tr</a>
</p>

---

## İçindekiler

- [Genel Bakış](#genel-bakış)
- [Özellikler](#özellikler)
- [Ekran & Menü Yapısı](#ekran--menü-yapısı)
- [Kurulum](#kurulum)
- [Hızlı Başlangıç](#hızlı-başlangıç)
- [Fiyatlandırma Mantığı](#fiyatlandırma-mantığı)
- [Stok Senkronizasyonu](#stok-senkronizasyonu)
- [Sipariş Yönetimi](#sipariş-yönetimi)
- [Gereksinimler](#gereksinimler)
- [Sık Karşılaşılan Sorunlar (SSS)](#sık-karşılaşılan-sorunlar-sss)
- [Teknik Notlar](#teknik-notlar)
- [Katkı & Destek](#katkı--destek)
- [Lisans](#lisans)

---

## Genel Bakış

Bu eklenti, WooCommerce tabanlı bir e-ticaret sitesini **Trendyol Pazaryeri** ile entegre eder.
Ürünlerinizi Trendyol'a gönderir, stok ve fiyatları güncel tutar, gelen siparişleri WooCommerce
tarafına çeker. Tamamen **Trendyol V2 API** üzerine kuruludur.

Yönetim tarafı WordPress admin paneline entegre çalışır; ayrı bir servise veya harici bir
panele ihtiyaç duymaz. Ürün eşleştirme, kategori/marka bağlama ve gönderim süreçleri tek tek
takip edilebilir, hatalar loglanır ve kullanıcıya anlaşılır mesajlarla iletilir.

> **Not:** Bu eklenti bağımsız bir topluluk projesidir ve Trendyol'un resmi bir ürünü değildir.
> "Trendyol" adı yalnızca uyumluluğu belirtmek için kullanılmıştır.

---

## Özellikler

**Ürün Gönderimi**
- WooCommerce ürünlerini Trendyol'a tek tıkla veya toplu gönderme
- Varyantlı (variable) ürün desteği — tüm varyasyonlar tek model altında gruplanır
- Gönderim öncesi payload önizleme (Trendyol'a tam olarak ne gideceğini görün)
- Gönderim durumuna göre filtreleme: Tümü / Gönderilmemiş / Gönderilmiş / Hatalı
- Barkod otomatik temizleme (geçersiz karakter ve uzunluk kontrolü)
- Görsellerin otomatik HTTPS'e çevrilmesi, varyasyon görsellerinin önceliklendirilmesi

**Kategori & Marka**
- Trendyol kategori ağacının çekilip yerel olarak önbelleğe alınması
- WooCommerce kategorilerini Trendyol kategorilerine eşleştirme (otomatik benzerlik önerisiyle)
- Kategori bazında zorunlu özellik (attribute) varsayılanları tanımlama
- Marka listesini çekme ve WooCommerce markalarıyla eşleştirme

**Stok & Fiyat**
- WooCommerce stok adetlerinin Trendyol'a senkronizasyonu
- WooCommerce fiyatının olduğu gibi Trendyol'a aktarılması
- Zamanlanmış (cron) otomatik senkronizasyon
- Stok değişikliklerinde tetiklenen anlık güncelleme

**Sipariş**
- Trendyol siparişlerinin WooCommerce'e çekilmesi
- Sipariş durumlarının takibi

**Yönetim & İzleme**
- Genel durum panosu (dashboard) ile hızlı aksiyonlar
- Detaylı loglama — her API isteği ve yanıtı kaydedilir
- Reddedilen ürünlerin gerçek hata sebeplerinin görüntülenmesi
- Satış raporlama ekranı

**Altyapı**
- Trendyol V2 API uyumlu
- Otomatik yeniden deneme (retry) — 429 ve 5xx hatalarında exponential backoff
- Dahili rate limit koruması (sliding window)
- WooCommerce HPOS (High-Performance Order Storage) uyumlu

---

## Ekran & Menü Yapısı

Eklenti aktive edildikten sonra WordPress sol menüsünde **Trendyol Senkron** başlığı altında
şu sayfalar yer alır:

| Sayfa | Açıklama |
|---|---|
| **Panel** | Genel durum özeti, cache sayıları, hızlı aksiyon butonları |
| **Ayarlar** | API kimlik bilgileri, gönderim varsayılanları, stok/fiyat ayarları |
| **Trendyol Adresleri** | Satıcı sevkiyat/iade adreslerini çeker ve listeler |
| **Fiyat Önizleme** | Seçili ürünler için Trendyol'a gidecek fiyatı önizler |
| **Kategori Eşleştirme** | WooCommerce ↔ Trendyol kategori bağlama |
| **Kategori Özellikleri** | Kategori bazında zorunlu attribute varsayılanları |
| **Marka Eşleştirme** | WooCommerce ↔ Trendyol marka bağlama |
| **Ürün Eşleştirme** | Ürünlerin barkod bazlı eşleşme durumu |
| **Trendyol'a Gönder** | Ürün seçip toplu/tekli gönderim, payload önizleme |
| **Stok Yönetimi** | Stok senkronizasyon durumu ve manuel push |
| **Fiyat Yönetimi** | Fiyat karşılaştırma ve toplu fiyat gönderimi |
| **Senkron & Cron** | Zamanlanmış görevler, bekleyen batch kontrolü |
| **Satış Raporu** | Satış kayıtları özeti |
| **Loglar** | Tüm senkron işlemlerinin detaylı kaydı (debug + audit) |

---

## Kurulum

1. Bu depoyu ZIP olarak indirin (**Code → Download ZIP**) veya klonlayın.
2. `wp-trendyol-sync` klasörünü sitenizin `wp-content/plugins/` dizinine kopyalayın.
   - ZIP indirdiyseniz, arşiv içindeki `wp-trendyol-sync` klasörünün doğru şekilde
     `wp-content/plugins/wp-trendyol-sync/` olarak yerleştiğinden emin olun.
3. WordPress yönetim panelinde **Eklentiler** sayfasından **Trendyol Senkronizasyon**'u etkinleştirin.
4. Sol menüde **Trendyol Senkron** başlığı görünecektir.

> WooCommerce'in kurulu ve etkin olması zorunludur. Aksi halde eklenti bir uyarı gösterir
> ve çalışmaz.

---

## Hızlı Başlangıç

Aşağıdaki sırayı takip ederek ilk ürününüzü Trendyol'a gönderebilirsiniz:

1. **Ayarlar** → Trendyol Satıcı ID, API Key ve API Secret bilgilerinizi girin,
   ardından **API Bağlantısını Test Et** ile doğrulayın.
2. **Markaları Çek**, **Kategorileri Çek** ve **Adresleri Çek** butonlarıyla Trendyol
   verilerini yerel olarak önbelleğe alın.
3. **Ayarlar → Trendyol Ürün Gönderim Varsayılanları** bölümünden sevkiyat/iade adresini,
   teslimat süresini ve desi varsayılanını seçin.
4. **Marka Eşleştirme** → WooCommerce markalarınızı Trendyol markalarına bağlayın
   (otomatik öneri sunulur).
5. **Kategori Eşleştirme** → WooCommerce kategorilerinizi Trendyol kategorilerine bağlayın.
6. **Trendyol'a Gönder** → Ürünleri seçin ve **Seçilenleri Trendyol'a Gönder** ile gönderin.
   Varyantlı ürünler otomatik olarak tek model altında gruplanır.
7. **Senkron & Cron → Bekleyen Batch'leri Kontrol Et** ile Trendyol'un onay sürecini takip edin.
8. Bir ürün reddedildiyse sebebini **Loglar** sayfasındaki JSON detayında görebilirsiniz.

---

## Fiyatlandırma Mantığı

Eklenti, **WooCommerce'deki fiyatı olduğu gibi** Trendyol'a gönderir. Herhangi bir kur çevirimi,
KDV ekleme, yuvarlama veya fiyat manipülasyonu yapmaz:

- **listPrice** (piyasa/liste fiyatı) = ürünün normal (regular) fiyatı
- **salePrice** (satış fiyatı) = indirim tanımlıysa indirimli fiyat, yoksa normal fiyat

Trendyol kuralı gereği `listPrice ≥ salePrice` olmak zorundadır; eklenti bu tek koşulu otomatik
garanti eder. **Fiyat Önizleme** sayfasından, herhangi bir ürün için Trendyol'a tam olarak hangi
fiyatın gideceğini gönderim öncesi görebilirsiniz.

> WooCommerce'te fiyatlarınızı KDV hariç giriyorsanız, Trendyol'a da KDV hariç gider.
> Fiyatlarınızı Trendyol'a KDV dahil göndermek istiyorsanız, ürün fiyatlarını buna göre
> tanımlamanız gerekir.

---

## Stok Senkronizasyonu

- WooCommerce ürün stok adetleri Trendyol'a aktarılır.
- Stok değişikliği olduğunda (sipariş, manuel düzenleme vb.) güncelleme tetiklenebilir.
- **Senkron & Cron** sayfasından zamanlanmış otomatik senkronizasyon yönetilir.
- Manuel olarak **Stok Yönetimi** sayfasından da push yapılabilir.

Çakışma durumunda hangi tarafın (WooCommerce mi, Trendyol mu) esas alınacağı ayarlardan
belirlenebilir.

---

## Sipariş Yönetimi

Trendyol'da oluşan siparişler WooCommerce tarafına çekilerek tek bir yerden takip edilebilir.
Sipariş durumları senkronize edilir. Bu sayede pazaryeri siparişlerinizi mevcut WooCommerce
iş akışınızın içinde yönetebilirsiniz.

---

## Gereksinimler

| Bileşen | Minimum Sürüm |
|---|---|
| WordPress | 5.8+ |
| WooCommerce | 6.0+ |
| PHP | 7.4+ |
| Trendyol Satıcı Hesabı | API erişimi (Satıcı ID, API Key, API Secret) |

Ayrıca aktif bir **Trendyol Entegrasyon Bilgileri** (Satıcı Paneli → Hesap Bilgilerim →
Entegrasyon Bilgileri) setine ihtiyaç duyarsınız.

---

## Sık Karşılaşılan Sorunlar (SSS)

**API bağlantı testi başarısız oluyor.**
Satıcı ID, API Key ve API Secret bilgilerini Trendyol Satıcı Paneli'ndeki entegrasyon
bilgileriyle bire bir kontrol edin. Kopyalarken baştaki/sondaki boşluklara dikkat edin.

**Ürün gönderiliyor ama Trendyol'da görünmüyor.**
Trendyol'da ürünler bir onay sürecinden geçer. **Senkron & Cron → Bekleyen Batch'leri
Kontrol Et** ile durumu izleyin. Reddedildiyse sebep **Loglar** sayfasında görünür.

**Varyantlı ürünler tek tek gidiyor / gruplanmıyor.**
Varyasyonların aynı parent ürüne bağlı olduğundan ve gönderim stratejisinin ayarlardan
uygun seçildiğinden emin olun.

**Fiyat beklediğimden farklı gidiyor.**
Eklenti WooCommerce fiyatını olduğu gibi gönderir. **Fiyat Önizleme** sayfasından gidecek
değeri kontrol edin. KDV dahil/hariç ayrımını WooCommerce fiyat girişinizde yönetin.

**Kategori zorunlu özellikleri hatası alıyorum.**
Trendyol her kategori için zorunlu attribute'lar ister. **Kategori Özellikleri** sayfasından
ilgili kategori için varsayılan değerleri tanımlayın.

---

## Teknik Notlar

- Eklenti tamamen **Trendyol V2 API** üzerine kuruludur.
- Her istekte zorunlu `storeFrontCode` başlığı gönderilir (varsayılan: `TR`, ayarlanabilir).
- İstekler dahili bir **rate limit** koruması (sliding window) ile sınırlandırılır.
- `429` (rate limit) ve `5xx` (sunucu) hatalarında **otomatik yeniden deneme** (exponential
  backoff) yapılır.
- Tüm istek ve yanıtlar loglanır; hata ayıklama için **Loglar** sayfasından incelenebilir.
- WooCommerce **HPOS** (özel sipariş tabloları) ile uyumludur.

Eklenti; kategori ağacı, marka listesi, kategori özellik değerleri ve satıcı adreslerini
yerel veritabanı tablolarında önbelleğe alır. Bu sayede tekrar eden API çağrıları azaltılır
ve arayüz hızlı çalışır.

---

## Katkı & Destek

Bu proje açık kaynaktır. Hata bildirimi, öneri veya katkı için **Issues** ve **Pull Requests**
bölümlerini kullanabilirsiniz.

Kurulum, özelleştirme, entegrasyon veya kurumsal özel yazılım talepleriniz için:

**[digitalog.com.tr](https://digitalog.com.tr)**

---

## Lisans

Bu eklenti **GNU General Public License v2.0 (GPLv2) veya üzeri** lisansı altında dağıtılır —
WordPress'in kendi lisansıyla aynıdır. Kodu özgürce kullanabilir, değiştirebilir ve
dağıtabilirsiniz. Detaylar için depodaki [`LICENSE`](LICENSE) dosyasına bakınız.

---

<p align="center">
  <sub>© 2026 <a href="https://digitalog.com.tr">Digitalog</a> · WooCommerce – Trendyol Entegrasyonu</sub>
</p>
