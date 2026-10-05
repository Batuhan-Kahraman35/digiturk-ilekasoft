<?php
/**
 * Ana Sayfa - Yönlendirme veya "Çok Yakında" Sayfası
 * DB'deki site_ayarlari_anasayfa_yonlendirme = 1 ise /admin'e yönlendirir
 */

require_once __DIR__ . '/admin/db.php';
$db = Database::getInstance();
$ayar = $db->fetchOne("SELECT TOP 1 site_ayarlari_anasayfa_yonlendirme, site_ayarlari_favicon_url FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");

if ($ayar && (int)$ayar['site_ayarlari_anasayfa_yonlendirme'] === 1) {
    header('Location: /admin/anasayfa');
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çok Yakında</title>
    <meta name="description" content="Çok yakında yeni tasarımımızla karşınızdayız.">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= htmlspecialchars($ayar['site_ayarlari_favicon_url'] ?? '/favicon.ico') ?>" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400&family=Outfit:wght@200;300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --gold: #C9A96E;
            --gold-light: #E8D5A8;
            --cream: #F5F0E8;
            --dark: #1A1A1A;
            --text-muted: rgba(245, 240, 232, 0.5);
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--dark);
            color: var(--cream);
            min-height: 100vh;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .bg-gradient {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: 
                radial-gradient(ellipse at 20% 50%, rgba(201, 169, 110, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 20%, rgba(201, 169, 110, 0.05) 0%, transparent 40%),
                linear-gradient(180deg, #0D0D0D 0%, #1A1A1A 50%, #0D0D0D 100%);
            z-index: 0;
        }

        .content {
            position: relative;
            z-index: 10;
            text-align: center;
            animation: fadeInUp 1.2s ease-out;
        }

        .divider {
            width: 80px;
            height: 1px;
            background: linear-gradient(90deg, transparent, var(--gold), transparent);
            margin: 1.5rem auto;
        }

        .divider::before {
            content: '◆';
            display: block;
            text-align: center;
            font-size: 8px;
            color: var(--gold);
            background: var(--dark);
            width: 30px;
            margin: -6px auto 0;
        }

        .coming-soon {
            font-family: 'Cormorant Garamond', serif;
            font-size: clamp(1.4rem, 3vw, 2rem);
            font-weight: 400;
            font-style: italic;
            color: var(--gold-light);
            letter-spacing: 0.05em;
        }

        .sub-text {
            font-size: clamp(0.85rem, 1.5vw, 1rem);
            font-weight: 200;
            color: var(--text-muted);
            margin-top: 1rem;
            letter-spacing: 0.05em;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <div class="bg-gradient"></div>
    <div class="content">
        <p class="coming-soon">Çok yakında yeni tasarımımızla karşınızdayız</p>
        <div class="divider"></div>
        <p class="sub-text">Sizlere daha iyi hizmet verebilmek için hazırlanıyoruz.</p>
    </div>
</body>
</html>