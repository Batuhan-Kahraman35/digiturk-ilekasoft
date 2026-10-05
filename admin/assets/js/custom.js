/**
 * Örnek Yazılım Portal - Ortak JavaScript Fonksiyonları
 * Tüm sayfalarda kullanılan ortak fonksiyonlar
 */

// ======================================
// Oturum Denetimi
// Oturum suresi takibi, bitime 30 sn kala uyari ve 401 yakalama artik
// /admin/assets/js/oturum-takip.js dosyasinda (footer.php uzerinden tum sayfalara yuklenir).
// Buradaki eski fetch sarmalayici kaldirildi; iki yakalayici ayni anda calisinca
// cift uyari cikiyordu. Yedek: custom.js.yedek-oturum
// ======================================

// ======================================
// Toast Bildirim Sistemi
// ======================================
function showToast(message, type = 'success') {
    const toastTypes = {
        success: { icon: 'bi-check-circle-fill', bgClass: 'bg-success' },
        error: { icon: 'bi-x-circle-fill', bgClass: 'bg-danger' },
        warning: { icon: 'bi-exclamation-triangle-fill', bgClass: 'bg-warning' },
        info: { icon: 'bi-info-circle-fill', bgClass: 'bg-info' }
    };

    const config = toastTypes[type] || toastTypes.success;

    const toastHtml = `
        <div class="toast align-items-center text-white ${config.bgClass} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi ${config.icon} me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;

    // Toast container yoksa oluştur
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.className = 'toast-container position-fixed top-0 end-0 p-3';
        toastContainer.style.zIndex = '9999';
        document.body.appendChild(toastContainer);
    }

    // Toast'ı ekle
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastElement = toastContainer.lastElementChild;
    const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
    toast.show();

    // Toast kapandıktan sonra DOM'dan kaldır
    toastElement.addEventListener('hidden.bs.toast', function () {
        toastElement.remove();
    });
}

// ======================================
// Select2 Başlatma
// ======================================
function initSelect2(selector = '.form-select', options = {}) {
    const defaultOptions = {
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: 'Seçiniz...',
        allowClear: true,
        language: {
            noResults: function () { return "Sonuç bulunamadı"; },
            searching: function () { return "Aranıyor..."; }
        }
    };

    // Varsayılan ayarları özel ayarlarla birleştir
    const finalOptions = { ...defaultOptions, ...options };

    $(selector).select2(finalOptions);
}

// Modal içinde Select2 başlatma
function initSelect2InModal(selector, modalSelector) {
    initSelect2(selector, {
        dropdownParent: $(modalSelector)
    });
}

// ======================================
// Tarih Formatlama
// ======================================
function formatDate(dateString, format = 'dd.mm.yyyy') {
    if (!dateString) return '-';

    try {
        const date = new Date(dateString.replace(' ', 'T')); // ISO 8601'e çevir

        if (isNaN(date.getTime())) return '-';

        switch (format) {
            case 'dd.mm.yyyy':
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                });
            case 'dd.mm.yyyy hh:mm':
                return date.toLocaleDateString('tr-TR', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            case 'relative':
                // Göreceli tarih (bugün, dün, 2 gün önce)
                const now = new Date();
                const diff = Math.floor((now - date) / (1000 * 60 * 60 * 24));

                if (diff === 0) return 'Bugün';
                if (diff === 1) return 'Dün';
                if (diff < 7) return diff + ' gün önce';
                if (diff < 30) return Math.floor(diff / 7) + ' hafta önce';
                if (diff < 365) return Math.floor(diff / 30) + ' ay önce';
                return Math.floor(diff / 365) + ' yıl önce';
            default:
                return dateString;
        }
    } catch (e) {
        console.error('Tarih formatlama hatası:', e);
        return '-';
    }
}

// ======================================
// Para Formatlama
// ======================================
function formatCurrency(amount, currency = '₺') {
    if (amount === null || amount === undefined) return '-';

    const formatted = parseFloat(amount).toLocaleString('tr-TR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    return formatted + ' ' + currency;
}

// ======================================
// SweetAlert2 Dialog Fonksiyonları
// ======================================

/**
 * Onay gerektiren işlemler için (Silme, güncelleme vb.)
 * @param {string} title - Başlık
 * @param {string} text - Açıklama metni
 * @param {function} callback - Onaylandığında çalışacak fonksiyon
 */
function confirmAction(title, text, callback) {
    Swal.fire({
        title: title || 'Emin misiniz?',
        text: text || 'Bu işlem geri alınamaz!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-check-circle me-1"></i> Evet, Eminim',
        cancelButtonText: '<i class="bi bi-x-circle me-1"></i> İptal',
        reverseButtons: true
    }).then((result) => {
        if (result.isConfirmed && callback) {
            callback();
        }
    });
}

/**
 * Başarılı işlem mesajı
 * @param {string} title - Başlık
 * @param {string} text - Açıklama metni
 */
function showSuccess(title, text) {
    Swal.fire({
        title: title || 'Başarılı!',
        text: text || 'İşlem başarıyla tamamlandı.',
        icon: 'success',
        confirmButtonColor: '#198754',
        confirmButtonText: '<i class="bi bi-check-circle me-1"></i> Tamam'
    });
}

/**
 * Hata mesajı
 * @param {string} title - Başlık
 * @param {string} text - Açıklama metni
 */
function showError(title, text) {
    Swal.fire({
        title: title || 'Hata!',
        text: text || 'İşlem sırasında bir hata oluştu.',
        icon: 'error',
        confirmButtonColor: '#dc3545',
        confirmButtonText: '<i class="bi bi-x-circle me-1"></i> Tamam'
    });
}

/**
 * Uyarı mesajı
 * @param {string} title - Başlık
 * @param {string} text - Açıklama metni
 */
function showWarning(title, text) {
    Swal.fire({
        title: title || 'Dikkat!',
        text: text || 'Bu işleme dikkat etmelisiniz.',
        icon: 'warning',
        confirmButtonColor: '#ffc107',
        confirmButtonText: '<i class="bi bi-exclamation-triangle me-1"></i> Anladım'
    });
}

// ======================================
// Confirm Dialog (ESKİ - Kullanma!)
// ======================================
// ⚠️ UYARI: Aşağıdaki fonksiyonlar eski sistemdir.
// Yeni sayfalarda confirmAction() kullanın!
function confirmDelete(message, callback) {
    console.warn('⚠️ confirmDelete() kullanımdan kaldırılmıştır. confirmAction() kullanın!');
    if (confirm(message || 'Bu kaydı silmek istediğinize emin misiniz?')) {
        callback();
    }
}

// Async confirm (Promise döner)
function confirmDeleteAsync(message) {
    console.warn('⚠️ confirmDeleteAsync() kullanımdan kaldırılmıştır. confirmAction() kullanın!');
    return new Promise((resolve) => {
        if (confirm(message || 'Bu kaydı silmek istediğinize emin misiniz?')) {
            resolve(true);
        } else {
            resolve(false);
        }
    });
}

// ======================================
// Loading Spinner
// ======================================
function showLoading(message = 'Yükleniyor...') {
    const loadingHtml = `
        <div id="globalLoading" class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center" style="background: rgba(0,0,0,0.5); z-index: 10000;">
            <div class="text-center text-white">
                <div class="spinner-border mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div>${message}</div>
            </div>
        </div>
    `;

    $('body').append(loadingHtml);
}

function hideLoading() {
    $('#globalLoading').remove();
}

// ======================================
// Debounce (Arama için)
// ======================================
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ======================================
// DataTable Türkçe Ayarları
// ======================================
const dataTableTurkish = {
    "sEmptyTable": "Tabloda herhangi bir veri mevcut değil",
    "sInfo": "_TOTAL_ kayıttan _START_ - _END_ arası kayıtlar",
    "sInfoEmpty": "Kayıt yok",
    "sInfoFiltered": "(_MAX_ kayıt içerisinden bulunan)",
    "sInfoThousands": ".",
    "sLengthMenu": "_MENU_ kayıt göster",
    "sLoadingRecords": "Yükleniyor...",
    "sProcessing": "İşleniyor...",
    "sSearch": "Ara:",
    "sZeroRecords": "Eşleşen kayıt bulunamadı",
    "oPaginate": {
        "sFirst": "İlk",
        "sLast": "Son",
        "sNext": "Sonraki",
        "sPrevious": "Önceki"
    },
    "oAria": {
        "sSortAscending": ": artan sütun sıralamasını aktifleştir",
        "sSortDescending": ": azalan sütun sıralamasını aktifleştir"
    }
};

// ======================================
// Form Validasyon Yardımcıları
// ======================================
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

function validatePhone(phone) {
    // Türkiye telefon formatı: 5XX XXX XX XX
    const re = /^5[0-9]{2}\s?[0-9]{3}\s?[0-9]{2}\s?[0-9]{2}$/;
    return re.test(phone.replace(/\s/g, ''));
}

function validateTCKN(tckn) {
    // TC Kimlik No kontrolü
    if (tckn.length !== 11) return false;
    if (tckn[0] === '0') return false;

    const digits = tckn.split('').map(Number);
    const sum1 = (digits[0] + digits[2] + digits[4] + digits[6] + digits[8]) * 7;
    const sum2 = digits[1] + digits[3] + digits[5] + digits[7];
    // Negatif modulo düzeltmesi
    const check10 = ((sum1 - sum2) % 10 + 10) % 10;

    if (check10 !== digits[9]) return false;

    const sum11 = digits.slice(0, 10).reduce((a, b) => a + b, 0);
    if (sum11 % 10 !== digits[10]) return false;

    return true;
}

// ======================================
// DataTables Türkçe Karakter Desteği
// ======================================
// Türkçe karakterleri küçük harfe çeviren fonksiyon
function turkishToLower(str) {
    if (!str) return '';
    const charMap = {
        'I': 'ı', 'İ': 'i', 'Ş': 'ş', 'Ğ': 'ğ', 'Ü': 'ü', 'Ö': 'ö', 'Ç': 'ç'
    };
    let result = str.toString();
    for (const [upper, lower] of Object.entries(charMap)) {
        result = result.split(upper).join(lower);
    }
    return result.toLowerCase();
}

// DataTables için Türkçe karakter destekli arama
if (typeof $.fn.dataTable !== 'undefined') {
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex, rowData, counter) {
        // Arama terimi yoksa tüm satırları göster
        const searchTerm = settings.oPreviousSearch.sSearch;
        if (!searchTerm || searchTerm.length === 0) {
            return true;
        }
        
        // Arama terimini Türkçe küçük harfe çevir
        const searchLower = turkishToLower(searchTerm);
        
        // Tüm kolonlarda ara
        for (let i = 0; i < data.length; i++) {
            const cellData = turkishToLower(data[i]);
            if (cellData.indexOf(searchLower) !== -1) {
                return true;
            }
        }
        
        return false;
    });
    
    console.log('✅ DataTables Türkçe karakter desteği aktif');
}

// ======================================
// Sayfa Hazır Olduğunda (Otomatik Başlatmalar)
// ======================================
$(document).ready(function () {
    // Tüm select'lere otomatik Select2 uygula
    if ($('.form-select').length > 0) {
        initSelect2('.form-select');
    }

    // Tooltip'leri başlat
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // ── Sidebar kapalı durumunu kalıcı tut (localStorage) ──
    // AdminLTE init'i masaüstünde her yüklemede zorla expand ediyor; bu handler
    // adminlte.min.js'ten SONRA çalıştığı için kapalı durumu geri uygulayabiliyoruz.
    initSidebarKalici();

    console.log('✅ Örnek Yazılım Portal - Ortak fonksiyonlar yüklendi');
});

// Sidebar aç/kapa durumunu hatırla
function initSidebarKalici() {
    const KEY = 'sidebarCollapsed';
    const BREAKPOINT = 992; // AdminLTE sidebarBreakpoint

    // Hedef durum bellekte tutulur. localStorage'dan okumak yetmiyor: toggle'a
    // basıldığında observer, click handler kaydı yapmadan ÖNCE tetikleniyor ve
    // eski değeri görüp sidebar'ı anında geri kapatıyordu.
    let kapaliIstenir = false;
    try { kapaliIstenir = localStorage.getItem(KEY) === '1'; } catch (e) {}

    // Kayıtlı durum "kapalı" ise masaüstünde geri uygula
    // (zaten kapalıysa dokunma → MutationObserver ile sonsuz döngüyü önler)
    function uygula() {
        if (kapaliIstenir && window.innerWidth > BREAKPOINT
            && !document.body.classList.contains('sidebar-collapse')) {
            document.body.classList.add('sidebar-collapse');
            document.body.classList.remove('sidebar-open');
        }
    }

    // 1) Sayfa açılışında geri yükle (AdminLTE init zorla expand ettikten SONRA çalışır)
    try { uygula(); } catch (e) {}

    // 1b) AdminLTE init/AJAX/redraw expand'lerini yakala: body class'ını izle.
    //     serverSide DataTable (ör. basvuru-yonetimi) AJAX sonrası sidebar'ı zorla
    //     açıyordu; observer her expand'de kapalı durumu anında geri uygular.
    try {
        new MutationObserver(function () { uygula(); })
            .observe(document.body, { attributes: true, attributeFilter: ['class'] });
    } catch (e) {}

    // 2) AdminLTE her window 'resize'da breakpoint'i yeniden çalıştırıp masaüstünde
    //    sidebar'ı zorla AÇIYOR. DataTables AJAX sonrası içerik uzayıp scrollbar
    //    çıkınca resize tetikleniyor → sidebar geri geliyordu. Bizim handler adminlte'den
    //    sonra kayıtlı olduğu için kapalı durumu yeniden uygularız.
    let rzt;
    window.addEventListener('resize', function () {
        clearTimeout(rzt);
        rzt = setTimeout(function () { try { uygula(); } catch (e) {} }, 60);
    });

    // 3) Toggle'ı CAPTURE fazında yakala: AdminLTE class'ı değiştirmeden ve
    //    observer tetiklenmeden önce hedef durumu ters çevir. Aksi halde açma
    //    isteği observer tarafından anında geri alınıyordu.
    document.addEventListener('click', function (e) {
        if (!e.target.closest || !e.target.closest('[data-lte-toggle="sidebar"]')) return;
        if (window.innerWidth <= BREAKPOINT) return; // mobilde sidebar-open kullanılıyor
        kapaliIstenir = !document.body.classList.contains('sidebar-collapse');
        try { localStorage.setItem(KEY, kapaliIstenir ? '1' : '0'); } catch (err) {}
    }, true);
}
