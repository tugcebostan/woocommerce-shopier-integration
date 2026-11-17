
<?php

$cookieFile = __DIR__ . '/shopier-cookies.txt';
$productUrl = 'https://www.shopier.com/zevrakdesign/52785177';

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $productUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR => $cookieFile,
    CURLOPT_COOKIEFILE => $cookieFile,
    CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7'
    ],
]);

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($html === false) {
    die('cURL hatası: ' . curl_error($ch));
}

curl_close($ch);

preg_match(
    '/<meta\s+name=["\']csrf-token["\']\s+content=["\']([^"\']+)["\']/i',
    $html,
    $matches
);

$csrfToken = $matches[1] ?? null;

echo "HTTP Status: {$httpCode}\n";
echo "CSRF token bulundu: " . ($csrfToken ? 'Evet' : 'Hayır') . "\n";
echo "Cookie dosyası oluşturuldu: " . (file_exists($cookieFile) ? 'Evet' : 'Hayır') . "\n";
