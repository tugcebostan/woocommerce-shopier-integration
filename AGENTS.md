# Geliştirici yönergeleri

Bu WordPress eklentisi WooCommerce ve Shopier arasında ürün eşleme, aktarım ve stok güncellemesi sağlar. Dosya sorumlulukları ve veri akışları için [README geliştirici rehberine](README.md#geliştirici-rehberi) bakın.

## Modül haritası

- `woo-shopier-sync.php`: modül yükleme ve kancaların başlangıcı.
- `includes/class-wss-admin.php`: yönetim ekranları, formlar ve iş başlatma.
- `includes/class-wss-shopier-api.php`: Shopier kimlik bilgileri ve HTTP API.
- `includes/class-wss-product-sync.php`: ürün eşleme, oluşturma, güncelleme ve doğrulama.
- `includes/class-wss-attribute-map.php` ve `includes/class-wss-variation-map.php`: özellik/seçim ve ürün varyasyonu eşlemeleri.
- `includes/class-wss-queue.php`: WP-Cron ürün işleri, kilitler ve durumlar.
- `includes/class-wss-webhook.php`: imzalı webhook ve Shopier'den WooCommerce'e stok akışı.
- `includes/class-wss-database.php` ve `includes/class-wss-logger.php`: şema geçişleri ve JSON günlükleri.
- `includes/class-wss-shopier-purchase.php`: ürünün Shopier satın alma bağlantısı; `class-wss-shopier-cart.php` eski ve devre dışı sepet akışıdır.

## Kargo ödeyeni

- Ürün oluşturma ve güncellemede WooCommerce ürününün gönderim sınıfı yoksa Shopier'e `buyerPays`, varsa `sellerPays` gönderin. Bu kararı ürün bazında `WSS_Product_Sync::get_shipping_payer()` verir; kargo firmasını belirlemez.
- Kuralı değiştirirken iki durumu da ve oluşturma/güncelleme akışlarını doğrulayın. Shopier'e gönderilen değer ile Shopier'de kaydedilen değerin aynı olduğunu varsaymayın.

## Çalışma ve doğrulama

- Yönetim işlemlerinde mevcut yetki, nonce ve veri doğrulama düzenini koruyun. Gizli anahtarları kaynak koduna, test çıktısına veya günlüklere eklemeyin.
- Kimlik bilgisi yönetimini `manage_options` ile, ürün/stok işlemlerini `manage_woocommerce` ile sınırlandırın. Secret değerlerini form HTML'sine geri yazmayın; boş giriş mevcut değeri korur.
- Shopier API adresini yalnızca HTTPS `api.shopier.com` adresiyle sınırlayın ve `wp_safe_remote_request()` kullanın.
- Günlükleri public web kökü dışında tutun; tam payload ve API yanıt gövdelerini kaydetmeyin.
- Webhook olay adı olarak noktalı tam değeri koruyun (`product.updated`); imza, desteklenen olay ve tekrar teslim kontrollerini uygulayın.
- Shopier'de yazma işlemleri gerçek veriyi değiştirebilir. Test ederken güvenli bir test ürünü kullanın; belirsiz sonuçlarda tekrar göndermeden önce uzak ürünü kontrol edin.
- Ürün ve varyasyon eşlemelerini, kuyruk kilitlerini ve güncelleme sonrası doğrulamayı değiştirdiğinizde ilgili akışların tamamını dikkate alın.
- Depoda otomatik test paketi yapılandırması yoktur. Değişen PHP dosyalarında `php -l` çalıştırın; davranış değişikliklerini uygun WordPress/WooCommerce test ortamında doğrulayın.
