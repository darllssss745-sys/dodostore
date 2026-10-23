<?php
require 'config.php';
header('Content-Type: application/json');
error_reporting(0);

// ==============================================
// 1. AMBIL SETTING DARI DATABASE
// ==============================================
$q_set = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
$settings = [];
while($s = mysqli_fetch_assoc($q_set)) { $settings[$s['setting_key']] = $s['setting_value']; }

$web_name    = $settings['web_name'] ?? 'Sarang Digital';
$markup_val  = (float)($settings['profit_markup'] ?? 10);
$markup_type = $settings['markup_type'] ?? 'percent';

// ==============================================
// 2. HELPERS
// ==============================================
function normalizeWa($no) {
    $no = preg_replace('/\D+/', '', (string)$no);
    if ($no === '') return '';
    if (substr($no, 0, 1) === '0') $no = '62' . substr($no, 1);
    elseif (substr($no, 0, 1) === '8') $no = '62' . $no;
    return $no;
}

function isValidWa($no) {
    if ($no === '') return false;
    if (!preg_match('/^62\d+$/', $no)) return false;
    return (strlen($no) >= 10 && strlen($no) <= 16);
}

function rupiah($n) { return number_format((int)$n, 0, ',', '.'); }



// ==============================================
// 3. MAIN LOGIC
// ==============================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'message' => 'Method not allowed!']); exit; }

$sid = isset($_POST['sid']) ? (int)$_POST['sid'] : 0;
$wa  = isset($_POST['wa'])  ? (string)$_POST['wa'] : '';
$qty = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;
$coupon_code = isset($_POST['coupon_code']) ? strtoupper(mysqli_real_escape_string($conn, trim($_POST['coupon_code']))) : '';

if ($qty < 1) $qty = 1;
$wa = normalizeWa($wa);
if (!isValidWa($wa)) { echo json_encode(['success' => false, 'message' => 'Nomor WhatsApp ga valid daks!']); exit; }

$q_prod = mysqli_query($conn, "SELECT * FROM services WHERE id='$sid'");
$prod = mysqli_fetch_assoc($q_prod);

$res_api = requestAPI('products', []);
$api_price = 0; $prod_name = "Produk #".$sid; $stok_pusat = 0;

if (isset($res_api['success']) && $res_api['success']) {
    foreach ($res_api['products'] as $p) {
        if ($p['id'] == $sid) {
            $api_price = (double)$p['price']; $prod_name = $p['name']; $stok_pusat = (int)$p['stock']; break;
        }
    }
}

if ($api_price == 0) { echo json_encode(['success' => false, 'message' => 'Produk tidak ditemukan daks!']); exit; }
if ($stok_pusat < $qty) { echo json_encode(['success' => false, 'message' => 'Yah, stok sisa '.$stok_pusat.' daks!']); exit; }

$price_jual = isset($prod['price_jual']) && $prod['price_jual'] > 0 ? (double)$prod['price_jual'] : (($markup_type == 'nominal') ? ($api_price + $markup_val) : ($api_price + ($api_price * ($markup_val / 100))));
$subtotal = $price_jual * $qty;
$discount = 0; $final_coupon = null;

if (!empty($coupon_code)) {
    $q_coupon = mysqli_query($conn, "SELECT * FROM coupons WHERE code='$coupon_code' AND status='active' AND expired_at >= CURDATE() AND quota > 0");
    $coupon = mysqli_fetch_assoc($q_coupon);
    if ($coupon && $subtotal >= $coupon['min_order']) {
        $discount = $subtotal * ((float)$coupon['discount_percent'] / 100);
        if ($discount > (float)$coupon['max_discount']) $discount = (float)$coupon['max_discount'];
        $final_coupon = $coupon_code;
    }
}

$total_pay = $subtotal - $discount;
$res_pay = requestAPI('pay', ['amount' => $total_pay]);

if (!isset($res_pay['success']) || $res_pay['success'] != true) {
    echo json_encode(['success' => false, 'message' => 'Gagal konek ke sistem pusat daks!']); exit;
}

$inv_depo = $res_pay['data']['invoice'] ?? '';
$total_final = (int)($res_pay['data']['total_bayar'] ?? $total_pay);

$trx_local = "TRX-" . time() . rand(100,999);
$wa_esc = mysqli_real_escape_string($conn, $wa);
$coupon_esc = $final_coupon ? "'$final_coupon'" : "NULL";

$sql = "INSERT INTO transactions (trx_local, inv_deposit, service_id, qty, target_wa, amount_pay, coupon_used, status)
        VALUES ('$trx_local', '$inv_depo', '$sid', '$qty', '$wa_esc', '$total_final', $coupon_esc, 'WAITING')";

if (mysqli_query($conn, $sql)) {
    if ($final_coupon) mysqli_query($conn, "UPDATE coupons SET quota = quota - 1 WHERE code='$final_coupon'");



    echo json_encode(['success' => true, 'trx' => $trx_local]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error daks!']);
}