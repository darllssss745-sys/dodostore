<?php
/**
 * ====================================================================
 * PremkuIntegrated - AUTOMATED DIGITAL PRODUCT STORE (PHP NATIVE)
 * ====================================================================
 * @project     : PremkuIntegrated Premium Script
 * @author      : MARSHEP OLLO
 * @copyright   : 2026 [MARSHEP OLLO X PT PACIFIC PEDIA DIGITAL]
 * @license     : STRICT ATTRIBUTION (Wajib Cantumkan Credit)
 * * PENTING:
 * 1. Dilarang keras menghapus atau mengubah nama author di file ini.
 * 2. Script ini boleh dijual kembali (Resell) tapi kredit tetap milik Author.
 * 3. Segala resiko penggunaan ditanggung pengguna.
 * ====================================================================
 */
// config.php
session_start();
date_default_timezone_set('Asia/Jakarta');

$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "sarangdigital";

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) { die("Koneksi Gagal: " . mysqli_connect_error()); }

// --- AMBIL SETTINGAN DARI DB ---
$q_set = mysqli_query($conn, "SELECT * FROM settings");
$settings = [];
while($r = mysqli_fetch_assoc($q_set)) {
    $settings[$r['setting_key']] = $r['setting_value'];
}

// DEFINE CONSTANT
define('API_KEY', $settings['api_key']);
define('NOMOR_ADMIN', $settings['nomor_admin']);
define('ADMIN_PASSWORD', $settings['admin_password']);
// Ambil markup profit, default 0.30 kalo blm ada
define('PROFIT_MARKUP', isset($settings['profit_markup']) ? (float)$settings['profit_markup'] : 0.30);
define('WEB_NAME', $settings['web_name']);

// HELPER API
function requestAPI($endpoint, $data) {
    global $settings;
    $data['api_key'] = API_KEY;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://premku.com/api/" . $endpoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}
?>