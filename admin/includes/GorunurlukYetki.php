<?php
/**
 * Görünürlük Yetki Kısıtları (Personel/Kullanıcı kayıtları için)
 *
 * birim_gor (can_view_birim): admin değil → yalnız kendi birimi + alt birimleri
 * kendi_kullanicini_gor (can_view_own_records): admin değil → yalnız kendi eklediği kayıtlar
 *
 * kullanicilar tablosu alias'ı 'k' varsayılır (k.kullanici_birim_id, k.kullanici_olusturan_id).
 */

/**
 * Mevcut kullanıcı için görünürlük kısıt bağlamını hesaplar.
 * @return array ['birimKisitli'=>bool, 'kendiKisitli'=>bool, 'izinliBirimler'=>int[], 'kullaniciId'=>int]
 */
function gorunurlukKisitiHesapla($db, array $pagePermissions, int $kullaniciId): array {
    $birimKisitli = (!$pagePermissions['is_admin'] && !empty($pagePermissions['can_view_birim']));
    $kendiKisitli = (!$pagePermissions['is_admin'] && !empty($pagePermissions['can_view_own_records']));

    $izinliBirimler = [];
    if ($birimKisitli) {
        $kb = $db->fetchOne("SELECT kullanici_birim_id FROM kullanicilar WHERE kullanici_id = ?", [$kullaniciId]);
        $kullaniciBirimId = $kb['kullanici_birim_id'] ?? null;
        if ($kullaniciBirimId) {
            $rows = $db->fetchAll("
                WITH BirimAgaci AS (
                    SELECT KullaniciBirim_id FROM KullaniciBirim WHERE KullaniciBirim_id = ?
                    UNION ALL
                    SELECT b.KullaniciBirim_id FROM KullaniciBirim b
                    INNER JOIN BirimAgaci a ON b.KullaniciBirim_UstBirim_id = a.KullaniciBirim_id
                )
                SELECT KullaniciBirim_id FROM BirimAgaci
            ", [$kullaniciBirimId]);
            $izinliBirimler = array_map(fn($r) => (int)$r['KullaniciBirim_id'], $rows);
        }
        // kullanici_birim_id boşsa $izinliBirimler boş → hiçbir kayıt görünmez (güvenli varsayılan)
    }

    return [
        'birimKisitli'   => $birimKisitli,
        'kendiKisitli'   => $kendiKisitli,
        'izinliBirimler' => $izinliBirimler,
        'kullaniciId'    => $kullaniciId,
    ];
}

/**
 * Görünürlük kısıt WHERE parçalarını ($whereConditions) ve parametrelerini ($params)
 * referansla doldurur. Liste / Excel / stats sorgularında ortak kullanılır.
 */
function uygulaGorunurlukKisiti(array &$whereConditions, array &$params, bool $birimKisitli, array $izinliBirimler, bool $kendiKisitli, int $kullaniciId): void {
    if ($birimKisitli) {
        if (empty($izinliBirimler)) {
            $whereConditions[] = "1=0"; // birimi yoksa hiçbir kayıt görünmesin
        } else {
            $ph = implode(',', array_fill(0, count($izinliBirimler), '?'));
            $whereConditions[] = "k.kullanici_birim_id IN ($ph)";
            foreach ($izinliBirimler as $bId) $params[] = $bId;
        }
    }
    if ($kendiKisitli) {
        $whereConditions[] = "k.kullanici_olusturan_id = ?";
        $params[] = $kullaniciId;
    }
}

/**
 * Verilen kullanıcı kaydı, mevcut kullanıcının görünürlük kapsamında mı?
 * Düzenle/Sil gibi tekil işlemlerde IDOR koruması için kullanılır.
 */
function kayitKapsamdaMi($db, int $hedefId, bool $birimKisitli, array $izinliBirimler, bool $kendiKisitli, int $kullaniciId): bool {
    $where = ["k.kullanici_id = ?"];
    $params = [$hedefId];
    uygulaGorunurlukKisiti($where, $params, $birimKisitli, $izinliBirimler, $kendiKisitli, $kullaniciId);
    $row = $db->fetchOne("SELECT TOP 1 1 AS v FROM kullanicilar k WHERE " . implode(" AND ", $where), $params);
    return (bool)$row;
}
