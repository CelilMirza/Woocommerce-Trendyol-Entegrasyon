# Trendyol Senkronizasyon

> **Geliştirici: [Digitalog](https://digitalog.com.tr)**
> WooCommerce ↔ Trendyol Pazaryeri entegrasyonu.
> İletişim, kurulum desteği ve özel yazılım çözümleri için: **[digitalog.com.tr](https://digitalog.com.tr)**

---


WooCommerce ↔ Trendyol Pazaryeri çift yönlü senkronizasyon. **FOX (WOOCS)** ve **WCMP Çoklu Fiyatlandırma** ile uyumlu.

## v0.5.0 Yenilikleri (Ürün Gönderim Tamiri)

Bu sürüm, **WP'den Trendyol'a ürün gönderme** problemini çözüyor. createProducts V2 API'ye tam uyumlu hâle getirildi.

### Düzeltilen kök sorunlar

| # | Sorun | Çözüm |
|---|---|---|
| 1 | V2 zorunlu `storeFrontCode` header'ı gönderilmiyordu → 400/HTML | API Client her istekte `storeFrontCode: TR` (ayarda değişebilir) yolluyor |
| 2 | `cargoCompanyId: 10` hardcoded → V2'de yok, reddediliyor | Sadece ayardan elle 0'dan farklı girersen ekleniyor |
| 3 | `dimensionalWeight` (desi) körü körüne `1` | Önce `(B×E×Y)/3000`, yoksa kg (g/lbs/oz çevirisi), son çare ayar default |
| 4 | Variable (varyantlı) ürünler hiç çalışmıyordu | Parent → tüm `get_children()` aynı `productMainId` ile tek `items[]`'de |
| 5 | Attribute auto-map V1 yapısındaydı | V2 ayrı endpoint'ten (`.../attributes/{attrId}/values`) değerler cache'leniyor; `attributeValueId` / `attributeValueIds` / `customAttributeValue` doğru gönderiliyor |
| 6 | `shipmentAddressId` / `returningAddressId` / `deliveryOption` yoktu | Yeni `WTS_Addresses` sınıfı `getSuppliersAddresses`'ı cache'liyor, ayardan default seçilince payload'a giriyor |
| 7 | Barkod sanitize yoktu, boşluk/Türkçe karakter rest geçiyordu | Sadece harf/rakam/`.`/`-`/`_` kalıyor, 40 char limit |
| 8 | `listPrice < salePrice` reddediliyordu | Otomatik `listPrice = max(listPrice, salePrice)` |
| 9 | Görseller HTTP olabiliyordu | Otomatik HTTPS'e çevriliyor; variation kendi görseli öne alınıyor; max 8 |
| 10 | `productMainId` her seferinde SKU'ydu — varyant gruplama bozuluyordu | Ayarlanabilir strateji: **parent_sku** (önerilen) / parent_id / sku |
| 11 | Description `wp_strip_all_tags` → düz metin | `wp_kses` ile basic HTML (`<p>`, `<ul>`, `<strong>`...) korunuyor |
| 12 | Hata raporu yok denecek kadar bilgisizdi | Push sonrası WP id → ilk gerçek hata mesajı kullanıcıya geliyor |

### Yeni admin sayfaları

- **Trendyol Adresleri** — `getSuppliersAddresses`'tan çekilen adresleri listeler, default seçimi Ayarlar'a yönlendirir
- **Trendyol'a Gönder** — Filtrelenebilir (Tümü / Henüz Gönderilmemiş / Gönderilmiş / Hatalı) ürün listesi; her satırda kategori-marka-stok yeşil/kırmızı durumu, payload önizleme (göz simgesi), tekli "Gönder" + toplu seç+gönder

### Yeni ayar alanları (Ayarlar → "Trendyol Ürün Gönderim Varsayılanları")

- Storefront Code (TR/AZ/DE/INT…)
- Default Sevkiyat Adres ID — adres dropdown
- Default İade Adres ID — adres dropdown
- Teslimat Süresi (gün) — default 3
- Varsayılan Desi (kg) — fallback
- Kargo Şirket ID — V1 davranışı isteniyorsa
- Origin (ülke kodu)
- productMainId Stratejisi (parent_sku / parent_id / sku)

### Yeni veritabanı tabloları

- `wts_category_attr_values` — V2 attribute değerleri cache
- `wts_supplier_addresses` — getSuppliersAddresses cache

## Akış (yeni kullanıcı için)

1. **Ayarlar** → API kimlik bilgilerini doldur, API Bağlantısını Test Et ✅
2. **Markaları Çek** + **Kategorileri Çek** + **Adresleri Çek** butonları
3. **Ayarlar** → "Trendyol Ürün Gönderim Varsayılanları" → adresleri seç, desi/delivery default'larını gözden geçir
4. **Marka Eşleştirme** → WP markaların Trendyol markalarına bağla (otomatik öneri var)
5. **Kategori Eşleştirme** → WP kategorilerini Trendyol leaf kategorilerine bağla (otomatik benzerlik önerisi)
6. **Trendyol'a Gönder** → Ürünleri seç → "Seçilenleri Trendyol'a Gönder" — varyantlı ürünler tek model altında grup gönderir
7. Onay sürecini **Senkron & Cron** → "Bekleyen Batch'leri Kontrol Et" ile takip et
8. Reddedilen ürünlerin nedeni **Loglar** sayfasında JSON detayında görünür

## Kurulum

1. ZIP'i `wp-content/plugins/` altına aç
2. Aktive et
3. Sol menüde **Trendyol Senkron**

## Gereksinimler

- WordPress 5.8+
- PHP 7.4+
- WooCommerce 6.0+
- FOX (WOOCS) veya WCMP — opsiyonel (çoklu para birimi için)

## API Notları

- V1 servisleri **10 Ağustos 2026'da deaktive olacak** — bu eklenti tamamen V2 üzerine kurulu
- `storeFrontCode` zorunlu header; default `TR`
- Rate limit: 100 istek/dk (eklenti sliding window ile kontrol ediyor)
- 429 / 5xx için otomatik retry (exponential backoff)


---

## İletişim & Destek

Bu eklenti **Digitalog** tarafından geliştirilmiştir.
Kurulum, özelleştirme, entegrasyon ve özel yazılım talepleriniz için:

🌐 **[https://digitalog.com.tr](https://digitalog.com.tr)**

## Lisans

Bu eklenti GPLv2 (veya üstü) lisansı altında dağıtılmaktadır — WordPress'in kendi lisansıyla aynıdır.
Detaylar için `LICENSE` dosyasına bakınız.
