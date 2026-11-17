# WooCommerce Shopier Sync

WooCommerce ürünlerini Shopier ürünleriyle eşleştirmek ve ürün bilgilerini Shopier API üzerinden yönetmek için WordPress eklentisi.

## Gereksinimler

- WordPress
- WooCommerce
- Shopier API erişimi için geçerli bir Personal Access Token (PAT)

## Kurulum

1. Eklenti klasörünü `wp-content/plugins/woo-shopier-sync` konumuna yükleyin.
2. WordPress yönetim panelinde **Eklentiler** sayfasından **WooCommerce Shopier Sync** eklentisini etkinleştirin.
3. WooCommerce menüsündeki **Shopier Entegrasyonu → Ayarlar** bölümünden PAT/API tokenınızı girip kaydedin. Aynı ekrandan OAuth Client ID, Client Secret, webhook tokenı ve API adreslerini de ekleyebilir veya güncelleyebilirsiniz.

Kimlik bilgilerini isterseniz WordPress kök dizinindeki `wp-config.php` dosyasında da tanımlayabilirsiniz:

   ```php
   define('SHOPIER_API_KEY', 'SHOPIER_PERSONAL_ACCESS_TOKEN');
   ```

   Alternatif olarak `SHOPIER_PAT` veya `SHOPIER_BEARER_TOKEN` sabitlerinden biri kullanılabilir. Değerin başında `Bearer` varsa eklenti bunu otomatik olarak temizler. API adresini özelleştirmek gerekiyorsa `SHOPIER_API_BASE_URL` tanımlanabilir; varsayılan adres `https://api.shopier.com/v1` şeklindedir.

4. Ayarlar sayfasındaki salt okunur bağlantı testini çalıştırın.

PAT, API çağrılarında doğrudan kullanılır. Client ID ve Client Secret ayarlardan saklanabilir; OAuth yönlendirme, yetki onayı ve token alışverişi henüz uygulanmadığı için bu bilgiler tek başına API bağlantısı kurmaz. Ayarlar sayfası mevcut kaynak değerleri alanlara yükler; gizli alanlar parola biçiminde maskelenir. `SHOPIER_CLIENT_SECRET`, `SHOPIER_API_SECRET` ve `SHOPIER_SECRET_KEY` sabitleri Client Secret için desteklenir. “Temizle” seçeneği eklentinin veritabanındaki değeri siler; `wp-config.php` sabitleri yedek değer olarak kullanılmaya devam eder.

## Kullanım

Eklenti sayfası WooCommerce menüsünde **Shopier Entegrasyonu** adıyla görünür. Yönetim için `manage_woocommerce` yetkisi gerekir.

- **Ürünler:** Shopier ürün kataloğunu yükleyin; WooCommerce ürünlerini mevcut Shopier ürünleriyle eşleştirin veya yeni ürün oluşturun. Değişken bir WooCommerce ürünü, Shopier’de tek bir varyasyonlu ürün olarak oluşturulur ve güncellenir; her varyasyon için ayrı Shopier ürünü oluşturulmaz. Tekli ve toplu ekleme/güncelleme işlemleri bu ürün eşlemesini kullanır. Eşleşmiş ürünleri güncelleyebilir, eşlemeleri kaldırabilir ve ürün başına tek satır içeren CSV içe/dışa aktarımı yapabilirsiniz. Ürün aktarımı işlemleri sıraya alınır ve WordPress zamanlanmış görevleriyle işlenir.
- **Varyasyonlar:** Shopier seçenekleri ile WooCommerce özellik/değerlerini eşleştirin. Değişken ürünü Shopier’e eklemeden veya güncellemeden önce, WooCommerce varyasyon kombinasyonlarının Shopier seçenek ID’leriyle eşleştiğini doğrulayın.
- **Genel Bakış ve Senkronizasyon Detayları:** İşlem durumlarını, geçmiş kayıtlarını ve günlükleri görüntüleyin.

Shopier stoklarını Ürünler sekmesindeki **Shopier stoklarını güncelle** düğmesiyle eşleştirilmiş ürünler için WooCommerce’e aktarabilirsiniz. Güncellemeler zamanlanmış görevlerle işlenir; son istek ve stok hareketleri Ürünler sekmesinde, ayrıntılı kayıtlar Senkronizasyon Detayları bölümünde görünür. Siparişler sekmesinde Shopier siparişleri, Kargolar sekmesinde Shopier kargo kayıtları sayfalı ve filtrelenebilir şekilde listelenir.

### Kargo ödeyeni

İstenen kural: WooCommerce ürününde gönderim tipi seçilmemişse `buyerPays` (alıcı öder), seçilmişse `sellerPays` (satıcı öder) kullanılmasıdır.

Kargo ödeyeni, kargo firmasını değil, ücreti kimin ödeyeceğini belirtir.

## Webhook

Eklenti aşağıdaki REST API adresinde `POST` webhook uç noktası kaydeder:

```text
/wp-json/woo-shopier-sync/v1/webhook
```

Webhook imzası, isteğin ham gövdesi ve ilgili olayın tokenı kullanılarak HMAC-SHA256 ile doğrulanır. Şu anda `product.updated` olayı için verilen token Ayarlar sekmesindeki **Webhook token — product.updated** alanına kaydedilir; bu olay stok eşlemesini güncellemek için işlenir. Eski `SHOPIER_WEBHOOK_TOKEN` sabiti ve genel token seçeneği geriye dönük uyumluluk için yedek olarak desteklenir.

Webhook tokenını Ayarlar sekmesinden girebilir veya `wp-config.php` içine ekleyebilirsiniz:

```php
define('SHOPIER_WEBHOOK_PRODUCT_UPDATED_TOKEN', 'SHOPIERIN_PRODUCT_UPDATED_ICIN_VERDIGI_TOKEN');
```

Shopier webhook adresi ortama göre WordPress tarafından oluşturulur. Test ortamında `https://test.zevrak.com/wp-json/woo-shopier-sync/v1/webhook`, canlı ortamda ise canlı alan adının aynı `/wp-json/woo-shopier-sync/v1/webhook` yolu kullanılır. Canlı mağaza webhook kaydına canlı adresi girin.

## Geliştirici rehberi

Eklenti giriş noktası [`woo-shopier-sync.php`](woo-shopier-sync.php) modülleri yükler ve WordPress kancalarını başlatır. Yönetim ekranları `manage_woocommerce` yetkisiyle erişilir. Depoda bağımsız bir test paketi veya derleme yapılandırması bulunmaz.

| Dosya | Sorumluluk |
| --- | --- |
| [`class-wss-admin.php`](includes/class-wss-admin.php) | Yönetim sekmeleri, formlar, yetki/nonce kontrolleri, ürün ve varyasyon eşleme işlemleri, CSV ve iş başlatma. |
| [`class-wss-shopier-api.php`](includes/class-wss-shopier-api.php) | Kimlik bilgilerini okuma, Shopier HTTP istekleri ve ürün, varyasyon, sipariş ve kargo API yardımcıları. |
| [`class-wss-product-sync.php`](includes/class-wss-product-sync.php) | Ürün eşlemeleri; basit/değişken ürün oluşturma ve güncelleme verilerinin hazırlanması, uzak ürünün okunması ve sonuç doğrulaması. |
| [`class-wss-attribute-map.php`](includes/class-wss-attribute-map.php) | WooCommerce özellik/değerleri ile Shopier varyasyon grubu/seçimi arasındaki eşlemelerin çözülmesi ve yönetimi. |
| [`class-wss-variation-map.php`](includes/class-wss-variation-map.php) | Ürün varyasyonlarının Shopier seçim kombinasyonlarıyla eşlenmesi, çakışma kontrolleri ve atomik kayıt. |
| [`class-wss-queue.php`](includes/class-wss-queue.php) | Ürün işlerinin ve öğelerinin kaydı, WP-Cron ile işlenmesi, yazma kilitleri ve sonuçların takibi. |
| [`class-wss-webhook.php`](includes/class-wss-webhook.php) | Webhook rotası ve imza doğrulaması; `product.updated` sonrasında stokların zamanlanmış olarak WooCommerce’e aktarılması. |
| [`class-wss-database.php`](includes/class-wss-database.php) | Tablo tanımları ve sürümlü şema geçişleri. |
| [`class-wss-logger.php`](includes/class-wss-logger.php) | Dosya tabanlı JSON günlükleri, son kayıtların okunması ve saklama/boyut temizliği. |
| [`class-wss-shopier-purchase.php`](includes/class-wss-shopier-purchase.php) | Ürün düzenleme ekranındaki Shopier bağlantısı ve mağaza sayfalarındaki satın alma bağlantıları. |
| [`class-wss-shopier-cart.php`](includes/class-wss-shopier-cart.php) | Eski sepet aktarımı kodu; `init()` devre dışıdır ve mevcut satın alma akışı tarafından kullanılmaz. |

### Veri akışları

- **Ürün aktarımı:** Yönetim ekranı seçilen ürünler için kuyruğa iş ekler. Kuyruk ürün senkronizasyonunu çağırır; API katmanı Shopier’e istek gönderir. Değişken ürünlerde seçim kombinasyonları doğrulanır; güncellemelerde uzak ürün tekrar okunur ve sonuç kontrol edilir. Başarısız veya sonucu belirsiz işlemleri tekrar başlatmadan önce Shopier’deki ürünü kontrol edin.
- **Stok:** Ürünler sekmesinden başlatılan stok yenilemesi veya doğrulanmış `product.updated` webhook’u Shopier stoklarını eşlenmiş WooCommerce ürünlerine/varyasyonlarına aktarır.
- **Satın alma:** Ürün düzenleme ekranına kaydedilen Shopier ürün bağlantısı kullanılır; değişken ürünün varyasyonları ana ürün bağlantısını paylaşır. Eski sepet aktarımı etkin değildir.

Veritabanında WordPress tablo önekinden sonra `wss_` gelir: `product_map` ürün ID’lerini, `attribute_map` özellik/seçim eşlemelerini, `variation_map` varyasyon kombinasyonlarını, `sync_jobs` ve `sync_job_items` iş durumlarını, `stock_sync_log` stok hareketlerini, `order_map` ise sipariş eşleme tablosunu tutar. Tablo tanımı tek başına otomatik sipariş senkronizasyonu yapıldığı anlamına gelmez.

Günlükler web kökü dışında, PHP geçici dizini altında siteye özel ve erişimi sınırlandırılmış bir klasörde tutulur. `WSS_LOG_DIR` sabitiyle kalıcı ve web dışında bir günlük dizini belirtilebilir. Kayıtlar yönetim panelindeki **Senkronizasyon Detayları** ekranından incelenebilir. Ham API yanıt gövdeleri ve ürün payload'ları günlüklerde saklanmaz.

Değişikliklerde en azından ilgili PHP dosyasını `php -l includes/dosya-adi.php` ile kontrol edin; davranış değişikliklerini WooCommerce ve Shopier test ortamında uygun tekli/toplu işlemlerle doğrulayın. Tanılama betiklerini production eklenti klasöründe bulundurmayın.

## Güvenlik notları

- API tokenlarını eklenti dosyalarına veya sürüm kontrolüne eklemeyin; Ayarlar sekmesinde ya da `wp-config.php` içinde saklayın.
- Ürün oluşturma, güncelleme ve silme işlemleri Shopier hesabınızda değişiklik yapabilir. Toplu işlemlerden önce eşlemeleri kontrol edin.
- Bağlantı testi salt okunurdur ve Shopier verisini değiştirmez.
