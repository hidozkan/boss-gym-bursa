# Boss Gym Bursa

**WordPress tabanlı spor kompleksi yönetim sistemi**

Boss Gym Bursa; spor salonu üyeliklerini, bireysel ve grup antrenmanlarını, antrenör bilgilerini ve spor sahası rezervasyon taleplerini tek bir web arayüzünde birleştirmek amacıyla geliştirilmiş bir öğrenci projesidir.

> **Proje kapsamı:** Bu depo, projeye özel WordPress alt temasını (`bossgym-child`) ve çekirdek işlevleri sağlayan eklentiyi (`bossgym-core`) içerir. WordPress çekirdeği, Kadence üst teması ve kişisel/veritabanı bilgileri bu depoda yayımlanmaz. Sistem yerel XAMPP ortamında geliştirilmiştir; GitHub bağlantısı canlı site bağlantısı değildir.

## Özellikler

- **Üyelik işlemleri:** Kayıt, giriş, üyelik paketi seçimi, başvuru ve yönetici onayı.
- **Bireysel ve grup PT:** PT paketleri, antrenör seçimi, tarih ve müsait saat üzerinden randevu başvurusu.
- **Antrenör profilleri:** Fotoğraf, özgeçmiş, uzmanlık alanları, antrenman türleri ve çalışma saatleri.
- **Spor sahaları:** Basketbol ve voleybol saha kartları, görsel galerileri ve rezervasyon talepleri.
- **Hakem talepleri:** Saha rezervasyonuna bağlı isteğe bağlı hakem hizmeti talebi.
- **Üye paneli:** Üyelik durumu, PT randevuları, saha rezervasyon talepleri ve demo QR kartı.
- **QR ve doluluk yönetimi:** Yönetici ekranında demo giriş/çıkış kaydı ve salon doluluk göstergeleri.
- **Tesis ve iletişim:** Tesis olanaklarının görsel sunumu ve iletişim mesajlarının yönetimi.
- **Erişilebilirlik araçları:** Açık/koyu görünüm, yüksek kontrast, yazı boyutu ve tarayıcı destekli sesli okuma seçenekleri.
- **Yönetici paneli:** İlgili modüllerin WordPress yönetim alanında bir araya getirilmesi.

## Kullanılan teknolojiler

| Teknoloji | Amaç |
| --- | --- |
| WordPress | İçerik ve kullanıcı yönetimi |
| PHP | Sunucu tarafı işlevler ve özel eklenti |
| MySQL / MariaDB | Veri saklama |
| HTML, CSS, JavaScript | Ön yüz, arayüz ve etkileşimler |
| Kadence + `bossgym-child` | Üst tema ve projeye özel tasarım |
| XAMPP | Yerel geliştirme ortamı |

## Depo yapısı

```text
boss-gym-bursa/
├── README.md
└── wp-content/
    ├── themes/
    │   └── bossgym-child/
    └── plugins/
        └── bossgym-core/
```

## Yerel kurulum

1. Yerel sunucuda PHP, MySQL/MariaDB ve WordPress ortamını hazırlayın (ör. XAMPP).
2. WordPress'i kurun ve **Kadence** üst temasını yükleyin.
3. `wp-content/themes/bossgym-child` klasörünü WordPress kurulumunun aynı yoluna kopyalayın.
4. `wp-content/plugins/bossgym-core` klasörünü WordPress kurulumunun aynı yoluna kopyalayın.
5. WordPress yönetim panelinde **Boss Gym Core** eklentisini ve **bossgym-child** temasını etkinleştirin.
6. Projenin özgün sayfa, medya ve ayar verilerini yeniden oluşturmak için ayrıca yetkili bir WordPress içerik aktarımı ve/veya veritabanı yedeği gerekir. Bu depo tek başına eksiksiz site veritabanını içermez.

Yerel proje yolu: `C:\xampp\htdocs\spor_kompleksi`  
Yerel adres: `http://localhost/spor_kompleksi/`

## Kullanım

Ziyaretçiler siteden üyelik paketlerini ve antrenman/saha seçeneklerini inceleyebilir. Üyeler giriş yaptıktan sonra başvuru ve rezervasyon işlemlerini kullanabilir. Yetkili yöneticiler işlemleri `wp-admin` içindeki **Boss Gym Bursa** menüsünden yönetir.

Ayrıntılı kullanıcı ve yönetici adımları, ders teslimi için ayrıca hazırlanan **Boss Gym Bursa Sistem Kullanım Kitapçığı** içinde açıklanmıştır.

## Önemli notlar

- Görseller, profiller, ücretler ve tesis bilgileri proje/demonstrasyon amacıyla temsilî olabilir; gerçek işletme taahhüdü olarak değerlendirilmemelidir.
- QR turnike işlevi bir **demo yazılım akışıdır**; fiziksel turnike entegrasyonu olduğu iddia edilmez.
- Başvuru veya rezervasyon ekranlarında gösterilen tutarlar örnek olabilir; sistem doğrudan ödeme tahsilatı gerçekleştirmez.
- Erişilebilirlik seçeneklerinin bulunması, bağımsız bir WCAG uygunluk sertifikası anlamına gelmez.
- Güvenlik nedeniyle gerçek `wp-config.php`, şifreler, SMTP anahtarları ve kişisel kullanıcı verileri herkese açık depoya konulmamalıdır.

## Proje türü

Bilgisayar mühendisliği kapsamında geliştirilmiş eğitim ve portföy amaçlı WordPress projesi.
