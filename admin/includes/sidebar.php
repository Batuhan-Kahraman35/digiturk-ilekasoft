<?php
/**
 * Admin Panel - Sidebar Component
 * Portal Örnek Yazılım
 * Dinamik Menü Sistemi
 */

// $user ve $db değişkenleri ana sayfada tanımlanmalı
if (!isset($user) || !isset($db)) {
    die('Sidebar component requires $user and $db variables to be set.');
}

// Yetki kontrolü için gerekli
$userDepartmanId = $user['departman_id'] ?? null;
$isAdmin = ($userDepartmanId == 1); // Administrator departmanı

// Site ayarlarından logo ve başlığı çek
$siteAyarlari = $db->fetchOne("SELECT TOP 1 site_ayarlari_logo_url, site_ayarlari_site_title FROM dbo.tanim_site_ayarlari ORDER BY site_ayarlari_id DESC");
$logoUrl = $siteAyarlari['site_ayarlari_logo_url'] ?? null;
$siteTitle = $siteAyarlari['site_ayarlari_site_title'] ?? 'Örnek Yazılım Portal';

// Logo yolunu mutlak yap
if ($logoUrl && strpos($logoUrl, 'http') !== 0) {
    if (strpos($logoUrl, 'assets/') === 0) {
        $logoUrl = '/admin/' . $logoUrl;
    } elseif (strpos($logoUrl, '/') !== 0) {
        $logoUrl = '/admin/assets/' . $logoUrl;
    }
}

// Kullanıcının yetkili olduğu menü ve sayfaları çek
if ($isAdmin) {
    // Administrator: Tüm menüleri göster
    $menuQuery = "
        SELECT 
            m.menuler_id,
            m.menuler_menu_adi,
            m.menuler_ikon,
            m.menuler_parent_id,
            m.menuler_sira_no
        FROM Menuler m
        WHERE m.menuler_durum = 1 AND m.menuler_header_goster = 0
        ORDER BY ISNULL(m.menuler_parent_id, 0), m.menuler_sira_no
    ";
    $allMenus = $db->fetchAll($menuQuery);
} else {
    // Diğer departmanlar: Yetkili menüleri + parent menüleri göster
    $menuQuery = "
        SELECT DISTINCT
            m.menuler_id,
            m.menuler_menu_adi,
            m.menuler_menu_url,
            m.menuler_ikon,
            m.menuler_parent_id,
            m.menuler_sira_no
        FROM Menuler m
        WHERE m.menuler_durum = 1
          AND (
              -- Doğrudan yetki verilen menüler
              EXISTS (
                  SELECT 1 FROM Menu_SayfaYetkileri msy
                  WHERE msy.menu_id = m.menuler_id
                    AND msy.departman_id = ?
                    AND msy.durum = 1
                    AND msy.gor = 1
              )
              OR
              -- Alt menüsü yetkili olan parent menüler
              EXISTS (
                  SELECT 1 FROM Menuler m2
                  INNER JOIN Menu_SayfaYetkileri msy2 ON m2.menuler_id = msy2.menu_id
                  WHERE m2.menuler_parent_id = m.menuler_id
                    AND msy2.departman_id = ?
                    AND msy2.durum = 1
                    AND msy2.gor = 1
              )
              OR
              -- Sayfası yetkili olan menüler
              EXISTS (
                  SELECT 1 FROM Menu_Sayfalar s
                  INNER JOIN Menu_SayfaYetkileri msy3 ON s.sayfalar_id = msy3.sayfa_id
                  WHERE s.sayfalar_menu_id = m.menuler_id
                    AND msy3.departman_id = ?
                    AND msy3.durum = 1
                    AND msy3.gor = 1
              )
          )
        ORDER BY ISNULL(m.menuler_parent_id, 0), m.menuler_sira_no
    ";
    // Yetkili menüleri + parent menüleri çek
    try {
        // Önce direkt yetkili menüleri al
        $directMenus = $db->fetchAll("
            SELECT DISTINCT
                m.menuler_id,
                m.menuler_menu_adi,
                m.menuler_ikon,
                m.menuler_parent_id,
                m.menuler_sira_no
            FROM Menuler m
            INNER JOIN Menu_SayfaYetkileri msy ON m.menuler_id = msy.menu_id
            WHERE m.menuler_durum = 1
              AND m.menuler_header_goster = 0
              AND msy.departman_id = ?
              AND msy.durum = 1
              AND msy.gor = 1
            ORDER BY m.menuler_sira_no
        ", [$userDepartmanId]);
        
        // Parent menü ID'lerini topla
        $parentIds = [];
        foreach ($directMenus as $menu) {
            if ($menu['menuler_parent_id']) {
                $parentIds[] = $menu['menuler_parent_id'];
            }
        }
        
        // Parent menüleri de ekle (yetki kontrolü olmadan)
        $allMenus = $directMenus;
        if (!empty($parentIds)) {
            $parentIds = array_unique($parentIds);
            $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
            
            $parentMenus = $db->fetchAll("
                SELECT
                    menuler_id,
                    menuler_menu_adi,
                    menuler_ikon,
                    menuler_parent_id,
                    menuler_sira_no
                FROM Menuler
                WHERE menuler_durum = 1
                  AND menuler_header_goster = 0
                  AND menuler_id IN ($placeholders)
                ORDER BY menuler_sira_no
            ", $parentIds);
            
            // Parent menüleri ana listeye ekle (duplicate'leri önle)
            $existingIds = array_column($allMenus, 'menuler_id');
            foreach ($parentMenus as $parent) {
                if (!in_array($parent['menuler_id'], $existingIds)) {
                    $allMenus[] = $parent;
                }
            }
            
            // Sıralama düzeni için tekrar sırala
            usort($allMenus, function($a, $b) {
                return $a['menuler_sira_no'] - $b['menuler_sira_no'];
            });
        }
    } catch (Exception $e) {
        error_log("Sidebar Menu Query Error: " . $e->getMessage());
        $allMenus = [];
    }
}

// Sayfa listesini çek
if ($isAdmin) {
    // Administrator: Tüm sayfaları göster
    $pageQuery = "
        SELECT
            s.sayfalar_id,
            s.sayfalar_menu_id,
            s.sayfalar_sayfa_adi,
            s.sayfalar_sayfa_url,
            s.sayfalar_ikon,
            s.sayfalar_sira_no
        FROM Menu_Sayfalar s
        INNER JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
        WHERE s.sayfalar_durum = 1 AND m.menuler_header_goster = 0
        ORDER BY s.sayfalar_sira_no
    ";
    $allPages = $db->fetchAll($pageQuery);
} else {
    // Diğer departmanlar: Sadece yetkili sayfaları göster
    $pageQuery = "
        SELECT DISTINCT
            s.sayfalar_id,
            s.sayfalar_menu_id,
            s.sayfalar_sayfa_adi,
            s.sayfalar_sayfa_url,
            s.sayfalar_ikon,
            s.sayfalar_sira_no
        FROM Menu_Sayfalar s
        INNER JOIN Menu_SayfaYetkileri msy ON s.sayfalar_id = msy.sayfa_id
        INNER JOIN Menuler m ON s.sayfalar_menu_id = m.menuler_id
        WHERE s.sayfalar_durum = 1
          AND m.menuler_header_goster = 0
          AND msy.departman_id = ?
          AND msy.durum = 1
          AND msy.gor = 1
        ORDER BY s.sayfalar_sira_no
    ";
    $allPages = $db->fetchAll($pageQuery, [$userDepartmanId]);
}

// Ana menüleri ve alt menüleri grupla
$menuTree = [];
$subMenus = [];

foreach ($allMenus as $menu) {
    if ($menu['menuler_parent_id'] === null) {
        $menuTree[$menu['menuler_id']] = $menu;
        $menuTree[$menu['menuler_id']]['children'] = [];
        $menuTree[$menu['menuler_id']]['pages'] = [];
    } else {
        $subMenus[$menu['menuler_parent_id']][] = $menu;
    }
}

// Alt menüleri ana menülere ekle ve pages dizisi oluştur
foreach ($subMenus as $parentId => $children) {
    if (isset($menuTree[$parentId])) {
        $menuTree[$parentId]['children'] = [];
        foreach ($children as $child) {
            $child['pages'] = [];
            $menuTree[$parentId]['children'][] = $child;
        }
    }
}

// Sayfaları ilgili menülere ekle
foreach ($allPages as $page) {
    $menuId = $page['sayfalar_menu_id'];
    
    // Ana menünün altındaki sayfa mı?
    if (isset($menuTree[$menuId])) {
        $menuTree[$menuId]['pages'][] = $page;
    } else {
        // Alt menünün altındaki sayfa mı?
        foreach ($menuTree as $parentId => &$parentMenu) {
            if (isset($parentMenu['children'])) {
                foreach ($parentMenu['children'] as $childIdx => &$child) {
                    if ($child['menuler_id'] == $menuId) {
                        $menuTree[$parentId]['children'][$childIdx]['pages'][] = $page;
                        break 2;
                    }
                }
            }
        }
        unset($parentMenu, $child);
    }
}

// Mevcut sayfa URL'sini belirle
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!--begin::Sidebar-->
<aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark" style="min-height: 100vh;">
    <!--begin::Sidebar Brand-->
    <div class="sidebar-brand">
        <a href="/admin/anasayfa" class="brand-link">
            <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($siteTitle) ?>" class="brand-image" style="opacity: .8; max-height: 33px;">
                <span class="brand-text fw-light"><?= htmlspecialchars($siteTitle) ?></span>
            <?php else: ?>
                <span class="brand-text fw-light"><?= htmlspecialchars($siteTitle) ?></span>
            <?php endif; ?>
        </a>
    </div>
    <!--end::Sidebar Brand-->
    
    <!--begin::Sidebar Wrapper-->
    <div class="sidebar-wrapper">

        
        <nav class="mt-2">
            <!--begin::Sidebar Menu-->
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
                <?php foreach ($menuTree as $menu): ?>
                    <?php 
                    $hasChildren = !empty($menu['children']);
                    $hasPages = !empty($menu['pages']);
                    $hasSubItems = $hasChildren || $hasPages;
                    $menuIcon = $menu['menuler_ikon'] ?: 'bi bi-folder';
                    
                    // Eğer menünün alt öğesi yoksa gösterme
                    if (!$hasSubItems) {
                        continue;
                    }
                    ?>
                    
                    <li class="nav-item <?= $hasSubItems ? 'has-treeview' : '' ?>">
                        <?php if ($hasSubItems): ?>
                            <!-- Ana menü (alt öğeleri var) - URL'yi görmezden gel -->
                            <a href="#" class="nav-link" onclick="return false;">
                                <i class="nav-icon <?= htmlspecialchars($menuIcon) ?>"></i>
                                <p>
                                    <?= htmlspecialchars($menu['menuler_menu_adi']) ?>
                                    <i class="nav-arrow bi bi-chevron-right"></i>
                                </p>
                            </a>
                            <ul class="nav nav-treeview">
                                <!-- Ana menünün doğrudan sayfaları -->
                                <?php foreach ($menu['pages'] as $page): ?>
                                    <?php 
                                    // SEO dostu URL (uzantıyı ve pages/ prefix'ini kaldır)
                                    $cleanUrl = str_replace('.php', '', $page['sayfalar_sayfa_url']);
                                    $cleanUrl = preg_replace('/^pages\//', '', $cleanUrl);
                                    $pageUrl = '/admin/' . $cleanUrl;
                                    $pageIcon = $page['sayfalar_ikon'] ?: 'bi bi-circle';
                                    $isActive = basename($page['sayfalar_sayfa_url']) === $currentPage;
                                    ?>
                                    <li class="nav-item">
                                        <a href="<?= htmlspecialchars($pageUrl) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                                            <i class="nav-icon <?= htmlspecialchars($pageIcon) ?>"></i>
                                            <p><?= htmlspecialchars($page['sayfalar_sayfa_adi']) ?></p>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                                
                                <!-- Alt menüler -->
                                <?php foreach ($menu['children'] as $subMenu): ?>
                                    <?php 
                                    $hasSubPages = !empty($subMenu['pages']);
                                    $subMenuIcon = $subMenu['menuler_ikon'] ?: 'bi bi-folder';
                                    
                                    // Eğer alt menünün sayfası yoksa gösterme
                                    if (!$hasSubPages) {
                                        continue;
                                    }
                                    ?>
                                    
                                    <?php if ($hasSubPages): ?>
                                        <!-- Alt menü (sayfaları var) - URL'yi görmezden gel -->
                                        <li class="nav-item has-treeview">
                                            <a href="#" class="nav-link" onclick="return false;">
                                                <i class="nav-icon <?= htmlspecialchars($subMenuIcon) ?>"></i>
                                                <p>
                                                    <?= htmlspecialchars($subMenu['menuler_menu_adi']) ?>
                                                    <i class="nav-arrow bi bi-chevron-right"></i>
                                                </p>
                                            </a>
                                            <ul class="nav nav-treeview">
                                                <?php foreach ($subMenu['pages'] as $page): ?>
                                                    <?php 
                                                    // SEO dostu URL (uzantıyı ve pages/ prefix'ini kaldır)
                                                    $cleanUrl = str_replace('.php', '', $page['sayfalar_sayfa_url']);
                                                    $cleanUrl = preg_replace('/^pages\//', '', $cleanUrl);
                                                    $pageUrl = '/admin/' . $cleanUrl;
                                                    $pageIcon = $page['sayfalar_ikon'] ?: 'bi bi-circle';
                                                    $isActive = basename($page['sayfalar_sayfa_url']) === $currentPage;
                                                    ?>
                                                    <li class="nav-item">
                                                        <a href="<?= htmlspecialchars($pageUrl) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                                                            <i class="nav-icon <?= htmlspecialchars($pageIcon) ?>"></i>
                                                            <p><?= htmlspecialchars($page['sayfalar_sayfa_adi']) ?></p>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <!--end::Sidebar Menu-->
        </nav>
    </div>
    <!--end::Sidebar Wrapper-->
</aside>
<!--end::Sidebar-->
