# Cron Sistemi — Dökümantasyon (Taşınabilir)

DB tabanlı, çok görevli, kendi cron ifadesini yorumlayan zamanlayıcı sistemi.
Plesk Cron'un tek satırı ile dakikada bir `runner.php` tetiklenir; hangi görevin
çalışacağına DB'deki cron ifadeleri karar verir. Görevler PHP fonksiyonu,
zamanlamalar ve loglar tamamen MSSQL tablolarında tutulur.

## Mimari Özet

```
Plesk Cron (dakikada 1)
        │  GET .../cron/runner.php?key=SECRET
        ▼
   runner.php ──► cronEslesiyor() (DB'deki cron ifadelerini yorumlar)
        │              │
        │              ▼
        │        gorevCalistir() ──► gorevXxx() fonksiyonu (tasks.php)
        │              │
        ▼              ▼
  CronZamanlamalar   CronCalismaLog (her çalışma loglanır)
  CronGorevler
```

3 katman:

| Dosya | Görev |
|-------|-------|
| `cron/runner.php` | Zamanlayıcı. Plesk dakikada 1 çağırır, eşleşen görevleri çalıştırır. |
| `cron/worker.php` | Manuel/tekil tetikleme. Tek görevi elle veya kayıtlı zamanlama ID ile çalıştırır. |
| `cron/tasks.php` | Paylaşılan kütüphane: cron parser, log yardımcıları, tüm görev fonksiyonları, `gorevCalistir()` dağıtıcısı. |

## Dosya Yapısı

```
proje/
├── cron/
│   ├── runner.php     # zamanlayıcı (Plesk buraya bağlanır)
│   ├── worker.php     # manuel tetikleme
│   ├── tasks.php      # görev fonksiyonları + yardımcılar + dispatcher
│   └── .htaccess      # (opsiyonel) doğrudan erişim koruması
├── admin/
│   └── db.php         # Database sınıfı (getInstance, fetchAll, insert, update...)
└── config/
    └── database.php   # DB bağlantı bilgileri
```

## Veritabanı Şeması

Üç tablo. Tüm tablolarda standart alanlar zorunlu: `OlusturanKullanici`,
`OlusturmaTarihi`, `GuncelleyenKullanici`, `GuncellemeTarihi`, `Durum`.

```sql
-- ── Görev tanımları ───────────────────────────────────────────────
CREATE TABLE dbo.CronGorevler (
    CronGorevler_Id            INT IDENTITY(1,1) PRIMARY KEY,
    CronGorevler_Ad            NVARCHAR(200)  NOT NULL,
    CronGorevler_Aciklama      NVARCHAR(MAX)  NULL,
    CronGorevler_GorevKodu     VARCHAR(100)   NOT NULL UNIQUE,   -- dispatcher anahtarı
    CronGorevler_Parametreler  NVARCHAR(MAX)  NULL,              -- JSON parametre şeması
    OlusturanKullanici         INT            NULL,
    OlusturmaTarihi            DATETIME       NULL,
    GuncelleyenKullanici       INT            NULL,
    GuncellemeTarihi           DATETIME       NULL,
    Durum                      BIT            NOT NULL DEFAULT 1
);

-- ── Zamanlamalar ──────────────────────────────────────────────────
CREATE TABLE dbo.CronZamanlamalar (
    CronZamanlamalar_Id                INT IDENTITY(1,1) PRIMARY KEY,
    CronZamanlamalar_GorevId           INT           NOT NULL,   -- FK → CronGorevler_Id
    CronZamanlamalar_Ad                NVARCHAR(200) NOT NULL,
    CronZamanlamalar_CronIfadesi       VARCHAR(100)  NOT NULL,   -- "*/30 * * * *"
    CronZamanlamalar_SabitParametreler NVARCHAR(MAX) NULL,       -- JSON (dinamik tarih destekli)
    CronZamanlamalar_SonCalisma        DATETIME      NULL,       -- çift çalışma koruması
    CronZamanlamalar_BaslangicTarihi   DATETIME      NOT NULL,
    CronZamanlamalar_BitisTarihi       DATETIME      NULL,
    OlusturanKullanici                 INT           NULL,
    OlusturmaTarihi                    DATETIME      NULL,
    GuncelleyenKullanici               INT           NULL,
    GuncellemeTarihi                   DATETIME      NULL,
    Durum                              BIT           NOT NULL DEFAULT 1
);

-- ── Çalışma logları ───────────────────────────────────────────────
CREATE TABLE dbo.CronCalismaLog (
    CronCalismaLog_Id                  INT IDENTITY(1,1) PRIMARY KEY,
    CronCalismaLog_GorevId             INT           NOT NULL,
    CronCalismaLog_ZamanlamaId         INT           NULL,       -- manuelde NULL
    CronCalismaLog_BaslangicTarihi     DATETIME      NOT NULL,
    CronCalismaLog_BitisTarihi         DATETIME      NULL,
    CronCalismaLog_SureSaniye          INT           NULL,
    CronCalismaLog_Parametreler        NVARCHAR(MAX) NULL,
    CronCalismaLog_CalismaDurum        TINYINT       NOT NULL,   -- 0=çalışıyor 1=başarılı 2=hata
    CronCalismaLog_Sonuc               NVARCHAR(2000) NULL,
    CronCalismaLog_TetikleyenTur       TINYINT       NULL,       -- 0=web/manuel 1=CLI/otomatik
    CronCalismaLog_TetikleyenKullanici INT           NULL,
    OlusturanKullanici                 INT           NULL,
    OlusturmaTarihi                    DATETIME      NULL,
    GuncelleyenKullanici               INT           NULL,
    GuncellemeTarihi                   DATETIME      NULL,
    Durum                              BIT           NOT NULL DEFAULT 1
);
```

**Durum kodları (`CronCalismaLog_CalismaDurum`):**
- `0` → başladı / çalışıyor
- `1` → başarılı
- `2` → hata (exception veya görev `durum=2` döndü)

## Cron İfade Yorumlayıcısı

Sistem cron ifadesini **kendisi** yorumlar (5 alan: dakika saat gün ay haftagünü).
Desteklenen sözdizimi:

| Sözdizimi | Örnek | Anlam |
|-----------|-------|-------|
| `*` | `* * * * *` | her değer |
| Tek değer | `30 9 * * *` | saat 09:30 |
| Liste | `0,15,30,45 * * * *` | çeyrek saatlerde |
| Aralık | `0 9-18 * * *` | 09–18 arası her saat başı |
| Adım | `*/30 * * * *` | 30 dakikada bir |
| Aralık+adım | `0-30/10 * * * *` | 0,10,20,30. dakikalar |

> Karmaşık `L`, `W`, `#` gibi ifadeler **desteklenmez** — sadece yukarıdakiler.

**Çift çalışma koruması:** `CronZamanlamalar_SonCalisma` aynı dakikaya denk
gelirse görev atlanır. Runner, göreve başlamadan önce `SonCalisma`'yı hemen
günceller (uzun görevler bir sonraki dakikaya taşarsa tekrar tetiklenmez).

**Kesilen görev koruması:** `try/catch` yalnız `Throwable`'ı yakalar; zaman aşımı
veya fatal error'da `cronLogBitir()` hiç çağrılmaz ve log `CalismaDurum=0`
("çalışıyor") durumunda sonsuza kadar asılı kalır. Runner bunu üç önlemle çözer:

| Önlem | Etkisi |
|-------|--------|
| `register_shutdown_function` | Process ölürken açık kalan log `durum=2` + sebep ile kapatılır (`error_get_last()` fatal ise mesajı da yazılır). Görev normal bitince işaretçi sıfırlanır, başarılı çalışma yanlışlıkla "kesildi" damgası yemez. |
| Görev başına `set_time_limit(600)` | Süre sayacı her görevde sıfırlanır; önceki görevin harcadığı saniyeler sonrakini zaman aşımına düşürmez. |
| `ignore_user_abort(true)` | Plesk'in HTTP isteği koparsa (IIS/FastCGI timeout) görev yarıda kalmaz. |

> **Sıralı çalışma uyarısı:** Runner eşleşen görevleri **tek process içinde ardışık**
> çalıştırır. Bir görev timeout'la ölürse aynı turdaki *sonraki* görevler hiç
> sıraya gelmez. Aynı dakikaya (`0 * * * *`) birden fazla uzun görev denk
> getirmeyin — birini birkaç dakika kaydırın (`5 * * * *`).

## Parametre Sistemi

### 1. Görev parametre şeması (`CronGorevler_Parametreler`)
Görevin hangi parametreleri aldığını tanımlayan JSON. Arayüzde form üretmek için kullanılır:

```json
[
  {"ad":"baslangic","etiket":"Başlangıç Tarihi","tip":"text","zorunlu":true},
  {"ad":"bitis","etiket":"Bitiş Tarihi","tip":"text","zorunlu":true},
  {"ad":"dry","etiket":"Dry Run (1=test)","tip":"text","zorunlu":false}
]
```

### 2. Sabit parametreler (`CronZamanlamalar_SabitParametreler`)
Zamanlamaya gömülü değerler. JSON obje:

```json
{"tarih":"{dun}","kanal":"1"}
```

### 3. Dinamik tarih yer tutucuları
`SabitParametreler` içindeki string değerler çalışma anında çözülür
(`dinamikParamCoz` → `dinamikTarih`):

| Yer tutucu | Karşılık |
|------------|----------|
| `{bugun}` | bugün `d.m.Y` |
| `{dun}` | dün |
| `{7gun_once}` / `{30gun_once}` | N gün önce |
| `{ay_basi}` / `{ay_sonu}` | içinde bulunulan ay ilk/son günü |
| `{gecen_ay_basi}` / `{gecen_ay_sonu}` | geçen ay ilk/son günü |
| `{3ay_once}` / `{6ay_once}` | N ay önce |

## Görev Yazma (Yeni Cron Görevi Ekleme)

Her görev şu imzayı taşıyan bir PHP fonksiyonudur ve **daima aynı dizi yapısını döndürür**:

```php
function gorevOrnek(array $params, $db): array
{
    ob_start();               // canlı çıktıyı log/ekran için topla
    set_time_limit(300);

    try {
        // ... iş mantığı ...
        echo "  ✓ İşlem tamam.\n";
        $sonuc = "5 kayıt işlendi.";
        $durum = 1;           // 1=başarılı, 2=hata
    } catch (Throwable $e) {
        $sonuc = get_class($e) . ': ' . $e->getMessage();
        $durum = 2;
    }

    return [
        'durum' => $durum,            // 1|2  → log CalismaDurum
        'sonuc' => $sonuc,            // kısa özet (max 2000 char loglanır)
        'cikti' => ob_get_clean(),    // ayrıntılı çıktı (ekrana basılır)
    ];
}
```

**Dış API'den liste çeken görevlerde dikkat:** `$r['DataList'] ?? $r['Data'] ?? []`
gibi fallback zincirleri, servis yanıt yapısını değiştirdiğinde sessizce boş dizi
döner — görev `durum=1` ile "başarılı" loglanır ama 0 kayıt yazar. Zinciri `?? null`
ile bitirip yapıyı doğrulayın:

```php
$liste = $r['DataList'] ?? $r['EntityDataList'] ?? $r['Data'] ?? null;
if ($liste === null) {
    throw new RuntimeException('Beklenmeyen yanıt yapısı: ' . implode(', ', array_keys($r)));
}
```

> Gerçek vaka (29.07.2026): IRIS `Report/GetReportList` yanıtı `DataList`'ten
> `Data`'ya geçti. Kod listeyi boş gördü, kuyruktaki rapor `TAMAMLANDI` olmasına
> rağmen bulunamadı, görev 300 sn bekleyip "zaman aşımı" dedi. Kök neden yapı
> değişikliğiydi ama belirti timeout olarak göründüğü için teşhis uzadı.

Sonra dağıtıcıya (`gorevCalistir`) ekle:

```php
function gorevCalistir(string $gorevKodu, array $params, $db): array
{
    return match($gorevKodu) {
        'ornek_gorev' => gorevOrnek($params, $db),
        // ... diğer görevler ...
        default => throw new RuntimeException("Bilinmeyen görev kodu: {$gorevKodu}"),
    };
}
```

Son olarak görevi DB'ye kaydet (guard'lı, idempotent):

```sql
IF NOT EXISTS (SELECT 1 FROM CronGorevler WHERE CronGorevler_GorevKodu = 'ornek_gorev')
BEGIN
    INSERT INTO CronGorevler
    (CronGorevler_Ad, CronGorevler_Aciklama, CronGorevler_GorevKodu, CronGorevler_Parametreler,
     OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi, Durum)
    VALUES (N'Örnek Görev', N'Açıklama', 'ornek_gorev',
        N'[{"ad":"tarih","etiket":"Tarih","tip":"text","zorunlu":false}]',
        1, GETDATE(), 1, GETDATE(), 1);
END
```

Zamanlamayı (`CronZamanlamalar`) arayüzden veya SQL ile ekle:

```sql
INSERT INTO CronZamanlamalar
(CronZamanlamalar_GorevId, CronZamanlamalar_Ad, CronZamanlamalar_CronIfadesi,
 CronZamanlamalar_SabitParametreler, CronZamanlamalar_BaslangicTarihi,
 OlusturanKullanici, OlusturmaTarihi, GuncelleyenKullanici, GuncellemeTarihi, Durum)
VALUES (
    (SELECT CronGorevler_Id FROM CronGorevler WHERE CronGorevler_GorevKodu = 'ornek_gorev'),
    N'Her gece örnek', '0 3 * * *', N'{"tarih":"{dun}"}', GETDATE(),
    1, GETDATE(), 1, GETDATE(), 1);
```

## Güvenlik

- **Secret key:** Kodda tutulmaz; `dbo.tanim_site_ayarlari.site_ayarlari_cron_anahtari`
  kolonundan `cron/anahtar.php` → `cronAnahtari()` ile okunur.
  Web'den çağrıda `?key=...` `hash_equals()` ile doğrulanır; eşleşmezse veya DB'de
  anahtar boşsa `403`. CLI çağrılarında anahtar aranmaz.
- `.htaccess` ile `cron/` altındaki hassas dosyaları ve `*.log` dosyalarını
  doğrudan erişime kapat.
- Her projede anahtarı **değiştir** (kriptografik rastgele, en az 32 karakter).

## Kurulum (Yeni Projeye Taşıma)

1. `cron/` klasörünü (runner.php, worker.php, tasks.php, anahtar.php, .htaccess) kopyala.
2. Üç tabloyu (`CronGorevler`, `CronZamanlamalar`, `CronCalismaLog`) yeni DB'ye oluştur.
3. `tanim_site_ayarlari`'na `site_ayarlari_cron_anahtari NVARCHAR(100)` kolonunu ekleyip yeni anahtarı yaz.
4. `tasks.php` içindeki domain'e özel görev fonksiyonlarını sil, kendi görevlerini
   yaz; sadece çekirdeği (parser + log yardımcıları + `gorevCalistir`) bırak.
5. `require_once __DIR__ . '/../admin/db.php';` yolunu projenin `Database` sınıfına göre düzelt.
6. Plesk Cron'a **dakikada bir** çalışacak tek satır ekle.

### Plesk Cron Kaydı

```
* * * * *   →   https://<domain>/cron/runner.php?key=<SECRET>
```

> Plesk "Scheduled Tasks" → "Fetch a URL" seçeneği ile URL'yi ver, aralığı
> her dakika (`* * * * *`) yap.

## Manuel Çalıştırma (worker.php)

Test/elle tetikleme için:

```bash
# Kayıtlı zamanlama parametreleriyle
php cron/worker.php zamanlama=5

# Görev kodu + özel parametrelerle
php cron/worker.php gorev=ornek_gorev tarih=01.01.2026
```

Web üzerinden:

```
https://<domain>/cron/worker.php?zamanlama=5&key=<SECRET>
https://<domain>/cron/worker.php?gorev=ornek_gorev&key=<SECRET>&tarih=01.01.2026
```

> **Uzun görevleri web'den tetiklemeyin.** `worker.php` senkron çalışır; görev
> IIS/FastCGI timeout'unu (varsayılan ~300 sn) aşarsa tarayıcı *"Sunucuya
> ulaşılamadı"* der. Görev arka planda devam eder ama `runner.php`'deki shutdown
> koruması `worker.php`'de olmadığı için log `durum=0`'da asılı kalır. Uzun
> görevlerde CLI kullanın — orada süre sınırı yoktur ve çıktı canlı akar.

## `Database` Sınıfı Bağımlılıkları

`tasks.php` görev fonksiyonları şu metotları kullanır (kendi projendeki
`Database` sınıfında bunların olması gerekir):

| Metot | Kullanım |
|-------|----------|
| `Database::getInstance()` | Singleton bağlantı |
| `$db->fetchAll($sql, $params)` | Çoklu satır |
| `$db->fetchOne($sql, $params)` | Tek satır |
| `$db->insert($table, $data)` | INSERT → son ID döner |
| `$db->update($table, $data, $where)` | UPDATE |
| `$db->query($sql, $params)` | Ham sorgu |

> 💡 İpucu: Çekirdek sistem tamamen domain'den bağımsızdır — sadece
> `cronEslesiyor`, `cronAlanEslesiyor`, `dinamikTarih`, `dinamikParamCoz`,
> `cronLogOlustur`, `cronLogBitir` ve `gorevCalistir` fonksiyonlarını taşırsan
> yeni projede yalnız kendi `gorevXxx()` fonksiyonlarını yazman yeterli.
