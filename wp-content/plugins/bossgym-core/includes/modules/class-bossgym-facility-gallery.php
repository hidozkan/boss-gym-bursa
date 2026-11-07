<?php
if (!defined('ABSPATH')) { exit; }
add_shortcode('bossgym_facility_gallery', 'bossgym_child_facility_gallery_shortcode');
function bossgym_child_facility_gallery_shortcode() {
    $home = get_stylesheet_directory_uri() . '/assets/images/home/';
    $gallery = get_stylesheet_directory_uri() . '/assets/images/facilities/';
    $sets = [
        'changing-room' => ['title'=>'Soyunma odaları','description'=>'Antrenman öncesi hazırlık ve kişisel eşyalar için soyunma alanı konsepti. Görseller temsilîdir.','cover'=>$home.'tesis-soyunma-odasi.webp','images'=>[[$home.'tesis-soyunma-odasi.webp','Soyunma odası konsept görseli'],[$gallery.'changing-room-generated.webp','Dolap ve oturma alanı bulunan temsilî soyunma odası'],[$gallery.'changing-room-01.webp','Soyunma dolapları ve bank bulunan temsilî oda'],[$gallery.'changing-room-02.webp','Ahşap banklı temsilî soyunma alanı']]],
        'shower' => ['title'=>'Duş alanları','description'=>'Antrenman sonrası kullanım için duş alanı konsepti. Görseller gerçek tesisi göstermeyebilir.','cover'=>$home.'tesis-dus-alani.webp','images'=>[[$home.'tesis-dus-alani.webp','Duş alanı konsept görseli'],[$gallery.'shower-01.webp','Cam bölmeli temsilî duş alanı'],[$gallery.'shower-02.webp','Koyu taş yüzeyli temsilî duş kabinleri'],[$gallery.'shower-03.webp','Duş bölmelerinin temsilî iç görünümü']]],
        'parking' => ['title'=>'Otopark','description'=>'Tesis ziyareti için otopark konsepti. Mevcut kapasite ve giriş koşulları yerinde doğrulanmalıdır.','cover'=>$home.'tesis-otopark.webp','images'=>[[$home.'tesis-otopark.webp','Otopark konsept görseli'],[$gallery.'parking-01.webp','Aydınlatmalı temsilî açık otopark'],[$gallery.'parking-02.webp','Spor tesisi önündeki temsilî park alanı'],[$gallery.'parking-03.webp','Giriş saçağı bulunan temsilî otopark']]],
        'cafe' => ['title'=>'Kafe ve dinlenme','description'=>'Antrenman öncesi veya sonrasında mola alanı konsepti. Görseller temsilîdir.','cover'=>$home.'tesis-kafe.webp','images'=>[[$home.'tesis-kafe.webp','Kafe ve dinlenme alanı konsept görseli'],[$gallery.'cafe-01.webp','Temsilî kafe tezgâhı ve oturma alanı'],[$gallery.'cafe-02.webp','Pencereli temsilî dinlenme alanı'],[$gallery.'cafe-03.webp','Koltuklu temsilî dinlenme salonu']]],
    ];
    $html = '<div class="bg-fg-gallery"><div class="bg-fg-grid">';
    foreach ($sets as $key => $set) {
        $html .= '<button class="bg-fg-card" type="button" data-fg-open="' . esc_attr($key) . '" aria-haspopup="dialog"><img src="' . esc_url($set['cover']) . '" alt="' . esc_attr($set['title'] . ' alanının temsilî görseli') . '" loading="lazy"><span class="bg-fg-card__body"><strong>' . esc_html($set['title']) . '</strong><span>' . esc_html($set['description']) . '</span><em>4 görseli incele <span aria-hidden="true">→</span></em></span></button>';
    }
    $json = wp_json_encode($sets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $html . '</div><script type="application/json" class="bg-fg-data">' . $json . '</script><dialog class="bg-fg-dialog" aria-labelledby="bg-fg-title"><div class="bg-fg-dialog__panel"><header class="bg-fg-dialog__header"><div><span class="bg-fg-kicker">TEMSİLÎ GALERİ</span><h2 id="bg-fg-title"></h2></div><button class="bg-fg-close" type="button" aria-label="Galeriyi kapat" data-fg-close>×</button></header><p class="bg-fg-dialog__description"></p><figure class="bg-fg-view"><img src="" alt="" data-fg-image><figcaption><span class="bg-fg-count" aria-hidden="true">1/4</span></figcaption></figure><nav class="bg-fg-controls" aria-label="Galeri görselleri"><button type="button" data-fg-prev>Önceki</button><span data-fg-live role="status" aria-live="polite" aria-atomic="true">1 / 4</span><button type="button" data-fg-next>Sonraki</button></nav></div></dialog></div>';
}
