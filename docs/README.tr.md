# Paper to Quiz

**Türkçe** · [English](../README.md)

[![Son sürüm](https://img.shields.io/github/v/release/sawetco/paper-to-quiz?label=s%C3%BCr%C3%BCm)](https://github.com/sawetco/paper-to-quiz/releases/latest)
[![Kalite](https://github.com/sawetco/paper-to-quiz/actions/workflows/quality.yml/badge.svg)](https://github.com/sawetco/paper-to-quiz/actions/workflows/quality.yml)
[![Lisans: GPL v2 veya sonrası](https://img.shields.io/badge/lisans-GPL--2.0--or--later-blue.svg)](LICENSE)

Elinizde zaten PDF olarak hazırlanmış bir sınav veya çalışma kâğıdı varsa aynı
soruları WordPress'e tek tek girmek pek mantıklı değil. Paper to Quiz, PDF'teki
soruları doğrudan seçip çevrim içi test veya sınav olarak yayımlamanızı sağlar.

OCR kullanmaz. Bu sayede formüller, tablolar, şekiller ve cevap seçenekleri
bozulmadan, PDF'te nasıl görünüyorsa o şekilde kalır.

## Neler yapabilirsiniz?

- PDF sayfasında istediğiniz alanı seçerek soru oluşturabilirsiniz.
- Her soruya ders, doğru cevap ve puan ekleyebilirsiniz.
- Çalışmanızı test veya sınav olarak hazırlayabilirsiniz.
- Sınavlara başlangıç ve bitiş tarihi, üyelik şartı ve deneme sınırı
  koyabilirsiniz.
- Ders bazında puanları, sonuç belgelerini ve sıralamaları görüntüleyebilirsiniz.
- Sonuçları WordPress'in e-posta sistemi üzerinden gönderebilirsiniz.
- Oluşturulan kısa kodu herhangi bir sayfaya ekleyerek sınavı yayımlayabilirsiniz.

Kaynak PDF'ler ve soru görselleri özel alanda şifreli olarak saklanır. Öğrenci
ekranı da WordPress temasının stillerinden etkilenmeyecek şekilde yalıtılmıştır.
Eklenti geliştiriciye kullanım verisi veya telemetri göndermez.

Mevcut bir site güncellendiğinde eski şifreli katılımcı kayıtları ve dosyalar
küçük arka plan paketleriyle otomatik olarak yeni biçime geçirilir. Yeni
kayıtlar güncel PTQ2 biçimini kullanır; geçiş tamamlanana kadar eski biçim
okunabilir kalır. Ayarlar sayfası yalnızca güvenli geçiş durumunu gösterir;
anahtarlar veya katılımcı verileri gösterilmez.

## Nasıl kullanılır?

1. Sınav veya çalışma kâğıdı PDF'ini yükleyin.
2. Soruları PDF sayfası üzerinde tek tek seçin.
3. Doğru cevapları, dersleri ve puanları belirleyin.
4. Test veya sınav ayarlarını tamamlayın.
5. Oluşturulan kısa kodu bir WordPress sayfasına ekleyin.

## Test mi, sınav mı?

| Test                                 | Sınav                                   |
| ------------------------------------ | --------------------------------------- |
| Herkese açık ve süresizdir           | Belirli tarihler arasında açık olabilir |
| İstendiği kadar tekrar edilebilir    | Tek denemeyle sınırlandırılabilir       |
| Sıralama içermez                     | Üye sıralaması kullanılabilir           |
| Pratik ve konu tekrarı için uygundur | Kontrollü değerlendirme için uygundur   |

## Kurulum

Paper to Quiz'i WordPress üzerinden kurup güncellemek için **Eklentiler → Yeni
Eklenti Ekle** bölümünde **Paper to Quiz** araması yapın veya [resmî
WordPress.org eklenti dizinini](https://wordpress.org/plugins/paper-to-quiz/)
kullanın.

Manuel kurulum için [en güncel GitHub sürümündeki](https://github.com/sawetco/paper-to-quiz/releases/latest)
kurulabilir `paper-to-quiz.zip` dosyasını indirin. WordPress yönetim panelinde
**Eklentiler → Yeni Eklenti Ekle → Eklenti Yükle** yolunu izleyin, ZIP dosyasını
yükleyin ve Paper to Quiz'i etkinleştirin.

### Şifreleme anahtarı hakkında

Ekstra bir şifreleme anahtarı oluşturmanız gerekmez. Paper to Quiz ihtiyaç
duyduğu anahtarı güvenli şekilde otomatik oluşturur ve WordPress veritabanında
saklar. Çoğu kurulum için yapılması gereken başka bir işlem yoktur.

Anahtarı veritabanından ayrı yönetmek isteyen ileri düzey kullanıcılar,
**henüz herhangi bir PDF yüklemeden önce** `wp-config.php` dosyasına isteğe bağlı
olarak şu sabiti ekleyebilir:

```php
define('PAPER_TO_QUIZ_PRIVATE_STORAGE_KEY', 'en-az-32-baytlik-rastgele-ve-gizli-bir-deger');
```

Bu yöntem kullanılırsa değer güvenli bir yerde yedeklenmeli ve daha sonra
değiştirilmemelidir. Otomatik anahtarla daha önce dosya oluşturulmuş bir sitede
sonradan bu sabiti eklemek veya kullanılan anahtarı değiştirmek mevcut şifreli
dosyaları okunamaz hâle getirebilir.

WordPress salt değerlerinin değişmesi PTQ2 verilerini etkilemez. Güncelleme
sırasında PTQ1 kayıtları açıkça sürümlendirilmiş eski türetme yöntemiyle okunur
ve PTQ2 olarak yeniden yazılır. Geçiş arka planda çalışır ve kesinti sonrasında
güvenle kaldığı yerden devam eder. Geçiş Ayarlar sayfasında tamamlandı olarak
görünene kadar WordPress salt değerlerini değiştirmeyin; bekleyen PTQ1 kayıtları
ilk salt değerine ihtiyaç duyar.

Eklentiyi etkisizleştirmek verileri silmez. Kalıcı temizlik yalnızca yönetici,
eklentiyi silmeden önce Tehlikeli Bölge'den açıkça izin verirse çalışır.

## Gereksinimler

- WordPress 6.8 veya üzeri
- PHP 8.1 veya üzeri
- Yalnızca geliştirme için Node.js 22 veya üzeri

## Güvenlik ve gizlilik

Sınav ayarlarına göre katılımcı bilgileri, cevaplar, süre, puan, IP adresi ve
temel tarayıcı bilgileri kaydedilebilir. WordPress'in kişisel veri dışa aktarma
ve silme araçları desteklenir.

Bir güvenlik açığı bulursanız lütfen [SECURITY.md](SECURITY.md) dosyasındaki
yöntemi kullanarak özel olarak bildirin. Gerçek kullanıcı verilerini,
veritabanı yedeklerini, PDF'leri veya şifreleme anahtarlarını herkese açık sorun
kayıtlarına eklemeyin.

## Geliştirme

```sh
npm ci
npm run lint:js
npm run lint:css
npx tsc --noEmit
npm run test:unit -- --runInBand
npm run build
npm run check:build-portability
npm run plugin-zip
```

Yerel entegrasyon testlerini çalıştırmak için:

```sh
npm run test:integration
```

Bu testleri canlı bir sitede veya gerçek verilerle çalıştırmayın. Katkıda
bulunmak isterseniz [CONTRIBUTING.md](CONTRIBUTING.md) dosyasına göz atabilirsiniz.
Projenin teknik çalışma kuralları [AGENTS.md](AGENTS.md) içinde yer alır.

## Lisans

Paper to Quiz, GNU Genel Kamu Lisansı sürüm 2 veya sonrası koşullarıyla sunulan
özgür bir yazılımdır. Ayrıntılar için [LICENSE](LICENSE) dosyasına bakabilirsiniz.
