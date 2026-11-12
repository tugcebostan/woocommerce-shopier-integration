# WooCommerce Shopier Sync

WooCommerce ürünlerini Shopier ürünleriyle eşleştirmek ve ürün bilgilerini Shopier API üzerinden yönetmek için WordPress eklentisi.

## Gereksinimler

- WordPress
- WooCommerce
- Shopier API erişimi için geçerli bir Personal Access Token (PAT)

## Kurulum

1. Eklenti klasörünü `wp-content/plugins/woo-shopier-sync` konumuna yükleyin.
2. Shopier API anahtarınızı WordPress kök dizinindeki `wp-config.php` dosyasına ekleyin:

   ```php
   define('SHOPIER_API_KEY', 'SHOPIER_PERSONAL_ACCESS_TOKEN');
   ```

   Alternatif olarak `SHOPIER_PAT` veya `SHOPIER_BEARER_TOKEN` sabitlerinden biri kullanılabilir. Değerin başında `Bearer` varsa eklenti bunu otomatik olarak temizler. API adresini özelleştirmek gerekiyorsa `SHOPIER_API_BASE_URL` tanımlanabilir; varsayılan adres `https://api.shopier.com/v1` şeklindedir.

3. WordPress yönetim panelinde **Eklentiler** sayfasından **WooCommerce Shopier Sync** eklentisini etkinleştirin.
4. WooCommerce menüsündeki **Shopier Entegrasyonu → Ayarlar** bölümünden salt okunur bağlantı testini çalıştırın.

> `SHOPIER_CLIENT_ID` ve `SHOPIER_CLIENT_SECRET` kodda okunabilse de mevcut API istemcisi bu bilgilerle token almıyor. API çağrıları için geçerli bir PAT veya eklentide önceden kaydedilmiş OAuth erişim tokeni gerekir.

## Kullanım

Eklenti sayfası WooCommerce menüsünde **Shopier Entegrasyonu** adıyla görünür. Yönetim için `manage_woocommerce` yetkisi gerekir.

- **Ürünler:** Shopier ürün kataloğunu yükleyin; WooCommerce ürünlerini mevcut Shopier ürünleriyle eşleştirin veya yeni ürün oluşturun. Eşleşmiş ürünleri güncelleyebilir, eşlemeleri kaldırabilir ve CSV içe/dışa aktarımı yapabilirsiniz. Ürün aktarımı işlemleri sıraya alınır ve WordPress zamanlanmış görevleriyle işlenir.
- **Varyasyonlar:** Shopier seçenek ve WooCommerce özellik/değer eşlemelerini yönetin. Değişken ürün aktarımından önce gerekli seçim eşlemelerini tamamlayın.
- **Genel Bakış ve Senkronizasyon Detayları:** İşlem durumlarını, geçmiş kayıtlarını ve günlükleri görüntüleyin.

Stok Yönetimi ve Siparişler sekmeleri mevcut arayüzde geliştirme aşaması yer tutucularıdır.

## Webhook

Eklenti aşağıdaki REST API adresinde `POST` webhook uç noktası kaydeder:

```text
/wp-json/woo-shopier-sync/v1/webhook
```

Shopier webhook imzası, isteğin ham gövdesi ve `SHOPIER_WEBHOOK_TOKEN` kullanılarak HMAC-SHA256 ile doğrulanır. Shopier tarafındaki webhook ayarlarında aynı gizli tokenı kullanın. Geçerli imza ve JSON doğrulanınca istek yanıtlanır ve yapısı kaydedilir; olayların sipariş veya ürün işlemesine dönüştürülmesi henüz uygulanmamıştır.

`wp-config.php` içine webhook tokenını şu şekilde ekleyin:

```php
define('SHOPIER_WEBHOOK_TOKEN', 'UZUN_RASTGELE_GIZLI_DEGER');
```

## Güvenlik notları

- API tokenlarını eklenti dosyalarına veya sürüm kontrolüne eklemeyin; `wp-config.php` içinde saklayın.
- Ürün oluşturma, güncelleme ve silme işlemleri Shopier hesabınızda değişiklik yapabilir. Toplu işlemlerden önce eşlemeleri kontrol edin.
- Bağlantı testi salt okunurdur ve Shopier verisini değiştirmez.
