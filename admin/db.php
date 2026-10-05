<?php
/**
 * Veritabanı Bağlantı Sınıfı
 * Portal Örnek Yazılım - MSSQL
 */

// Saat dilimi burada set edilir: db.php her giriş noktasından (admin, api, webhook,
// cron) yükleniyor. Önceden yalnız admin/auth.php ayarlıyordu; auth.php yüklemeyen
// api/index.php UTC ile çalışıp tarih alanlarını 3 saat geri yazıyordu.
if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', 'Europe/Istanbul');
}
date_default_timezone_set(APP_TIMEZONE);

class Database {
    private static $instance = null;
    private $conn;
    private $config;
    
    private function __construct() {
        $this->config = require __DIR__ . '/../config/database.php';
        $this->connect();
    }
    
    private function connect() {
        $db = $this->config['connections']['sqlsrv'];
        
        $serverName = $db['host'];
        $connectionInfo = [
            "Database" => $db['database'],
            "UID" => $db['username'],
            "PWD" => $db['password'],
            "CharacterSet" => "UTF-8"
        ];
        
        $this->conn = sqlsrv_connect($serverName, $connectionInfo);
        
        if ($this->conn === false) {
            die(json_encode([
                'success' => false,
                'message' => 'Veritabanı bağlantısı başarısız: ' . print_r(sqlsrv_errors(), true)
            ]));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function query($sql, $params = []) {
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = isset($trace[1]) ? ($trace[1]['file'] ?? '?') . ':' . ($trace[1]['line'] ?? '?') : 'unknown';
            error_log('SQL Hatası [' . $caller . ']: ' . print_r(sqlsrv_errors(), true));
            return false;
        }
        
        return $stmt;
    }
    
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) return null;
        
        $result = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if (!$result) return $result;

        // NVARCHAR(MAX) gibi alanlar PHP stream, DATETIME alanlar DateTime nesnesi olarak gelebilir
        foreach ($result as $key => $value) {
            if (is_resource($value)) {
                $result[$key] = stream_get_contents($value);
            } elseif ($value instanceof DateTime) {
                $result[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $result;
    }
    
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) return [];
        
        $results = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            // NVARCHAR(MAX) gibi alanlar PHP stream, DATETIME alanlar DateTime nesnesi olarak gelebilir
            foreach ($row as $key => $value) {
                if (is_resource($value)) {
                    $row[$key] = stream_get_contents($value);
                } elseif ($value instanceof DateTime) {
                    $row[$key] = $value->format('Y-m-d H:i:s');
                }
            }
            $results[] = $row;
        }
        
        sqlsrv_free_stmt($stmt);
        return $results;
    }
    
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        if ($stmt === false) return false;
        
        sqlsrv_free_stmt($stmt);
        return true;
    }
    
    public function getLastInsertId() {
        $result = $this->fetchOne("SELECT @@IDENTITY as id");
        return $result ? $result['id'] : null;
    }
    
    /**
     * INSERT işlemi
     * @param string $table Tablo adı
     * @param array $data Kolon => Değer dizisi
     * @return bool|int Başarılıysa son eklenen ID, hata varsa false
     */
    public function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($data), '?');
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = sqlsrv_query($this->conn, $sql, array_values($data));
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('INSERT Hatası: ' . print_r($errors, true));
            throw new Exception('Kayıt eklenirken hata oluştu: ' . $errors[0]['message']);
        }
        
        sqlsrv_free_stmt($stmt);
        return $this->getLastInsertId();
    }
    
    /**
     * UPDATE işlemi
     * @param string $table Tablo adı
     * @param array $data Güncellenecek kolon => değer dizisi
     * @param array $where Koşul dizisi (kolon => değer)
     * @return bool Başarı durumu
     */
    public function update($table, $data, $where) {
        $setClauses = [];
        $params = [];
        
        foreach ($data as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $params[] = $value;
        }
        
        $whereClauses = [];
        foreach ($where as $column => $value) {
            $whereClauses[] = "{$column} = ?";
            $params[] = $value;
        }
        
        $sql = "UPDATE {$table} SET " . implode(', ', $setClauses) . " WHERE " . implode(' AND ', $whereClauses);
        
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('UPDATE Hatası: ' . print_r($errors, true));
            throw new Exception('Kayıt güncellenirken hata oluştu: ' . $errors[0]['message']);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }
    
    /**
     * DELETE işlemi
     * @param string $table Tablo adı
     * @param array $where Koşul dizisi (kolon => değer)
     * @return bool Başarı durumu
     */
    public function delete($table, $where) {
        $whereClauses = [];
        $params = [];
        
        foreach ($where as $column => $value) {
            $whereClauses[] = "{$column} = ?";
            $params[] = $value;
        }
        
        $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $whereClauses);
        
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            error_log('DELETE Hatası: ' . print_r($errors, true));
            throw new Exception('Kayıt silinirken hata oluştu: ' . $errors[0]['message']);
        }
        
        sqlsrv_free_stmt($stmt);
        return true;
    }
    
    /**
     * Ham SQL çalıştır (GO komutuyla ayrılmış çoklu sorgular için)
     * @param string $sql SQL komutları
     * @return bool Başarı durumu
     */
    public function executeRaw($sql) {
        // Önce çok satırlı yorumları temizle
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        
        // Tek satırlık yorumları temizle
        $sql = preg_replace('/--[^\r\n]*/', '', $sql);
        
        // GO komutlarını ayır
        $batches = preg_split('/^\s*GO\s*$/im', $sql);
        
        // PRINT ve bilgi mesajlarının (severity < 10) hata sayılmasını geçici olarak devre dışı bırak
        // Aksi hâlde sqlsrv_query PRINT ifadelerini hata olarak döndürür
        sqlsrv_configure("WarningsReturnAsErrors", 0);
        
        try {
            foreach ($batches as $batch) {
                $batch = trim($batch);
                if (empty($batch)) continue;
                
                // USE [database] komutlarını atla
                if (preg_match('/^\s*USE\s+\[/i', $batch)) {
                    continue;
                }
                
                $stmt = sqlsrv_query($this->conn, $batch);
                
                if ($stmt === false) {
                    // Yalnızca gerçek hataları al (uyarı/bilgi mesajlarını değil)
                    $errors = sqlsrv_errors(SQLSRV_ERR_ERRORS);
                    error_log('SQL Batch Hatası: ' . print_r($errors, true));
                    throw new Exception('SQL çalıştırılırken hata oluştu: ' . ($errors[0]['message'] ?? 'Bilinmeyen hata'));
                }
                
                sqlsrv_free_stmt($stmt);
            }
        } finally {
            // Her durumda eski ayarı geri yükle
            sqlsrv_configure("WarningsReturnAsErrors", 1);
        }
        
        return true;
    }
    
    public function __destruct() {
        if ($this->conn) {
            sqlsrv_close($this->conn);
        }
    }
}
