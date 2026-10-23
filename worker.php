<?php
require 'config.php';
header('Content-Type: application/json');
error_reporting(0);

// ==============================================
// 1. AMBIL SEMUA SETTING DARI DATABASE
// ==============================================
$q_set = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");
$settings = [];
while($s = mysqli_fetch_assoc($q_set)) { $settings[$s['setting_key']] = $s['setting_value']; }

$web_name   = $settings['web_name'] ?? 'Sarang Digital';

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



// ==============================================
// 3. MAIN LOGIC
// ==============================================
$trx = isset($_POST['trx']) ? mysqli_real_escape_string($conn, $_POST['trx']) : '';
if ($trx === '') { echo json_encode(['status' => 'ERROR', 'msg' => 'ID tidak valid']); exit; }

$q = mysqli_query($conn, "SELECT t.*, s.name as prod_name FROM transactions t LEFT JOIN services s ON t.service_id = s.id WHERE t.trx_local='$trx'");
$d = mysqli_fetch_assoc($q);

if(!$d) { echo json_encode(['status' => 'ERROR', 'msg' => 'ID tidak ditemukan']); exit; }

$cust_wa = normalizeWa($d['target_wa']);
$qty = (int)($d['qty'] ?? 1);

// Jika sudah final
if($d['status'] == 'COMPLETED') { echo json_encode(['status' => 'COMPLETED', 'akun' => $d['account_data']]); exit; }
if($d['status'] == 'FAILED') { echo json_encode(['status' => 'FAILED', 'msg' => $d['account_data']]); exit; }

// A. SINKRONISASI STATUS PEMBAYARAN
if($d['status'] == 'WAITING') {
    $res = requestAPI('pay_status', ['invoice' => $d['inv_deposit']]);
    if(isset($res['data']['status']) && $res['data']['status'] == 'success') {
        mysqli_query($conn, "UPDATE transactions SET status='PAID' WHERE id='{$d['id']}'");
        $d['status'] = 'PAID';
    }
}

// B. TEMBAK ORDER KE PUSAT (UPDATE PAKE ref_id)
if($d['status'] == 'PAID') {
    $res_ord = requestAPI('order', [
        'product_id' => (int)$d['service_id'],
        'qty'        => $qty,
        'ref_id'     => $trx 
    ]);

    if(isset($res_ord['success']) && $res_ord['success'] == true) {
        $inv_ord = $res_ord['invoice'];
        mysqli_query($conn, "UPDATE transactions SET status='PROCESSING', inv_order='$inv_ord' WHERE id='{$d['id']}'");
        $d['inv_order'] = $inv_ord;
        $d['status'] = 'PROCESSING';
    } else {
        $error_msg = $res_ord['message'] ?? 'Produk tidak tersedia di pusat.';
        mysqli_query($conn, "UPDATE transactions SET status='FAILED', account_data='GAGAL: $error_msg' WHERE id='{$d['id']}'");
        

        
        echo json_encode(['status' => 'FAILED', 'msg' => $error_msg]);
        exit;
    }
}

// C. CEK STATUS PESANAN & AMBIL AKUN DARI PUSAT
if($d['status'] == 'PROCESSING' && !empty($d['inv_order'])) {
    $res_stat = requestAPI('status', ['invoice' => $d['inv_order']]);

    if(isset($res_stat['success']) && $res_stat['success'] == true) {
        
        // JIKA STATUS SUCCESS DI PUSAT
        if (strtolower($res_stat['status']) == 'success') {
            $akun_txt = "";
            
            if (!empty($res_stat['accounts']) && is_array($res_stat['accounts'])) {
                foreach($res_stat['accounts'] as $index => $acc) {
                    $akun_txt .= "📦 *Data Akun ".($index+1)."*\n";
                    $akun_txt .= "👤 User : " . ($acc['username'] ?? '-') . "\n";
                    $akun_txt .= "🔑 Pass : " . ($acc['password'] ?? '-') . "\n\n";
                }
            } else {
                $akun_txt = "Akun telah siap. Silakan login dengan data dari email/invoice lu .";
            }

            $akun_clean = mysqli_real_escape_string($conn, $akun_txt);
            mysqli_query($conn, "UPDATE transactions SET status='COMPLETED', account_data='$akun_clean' WHERE id='{$d['id']}'");



            echo json_encode(['status' => 'COMPLETED', 'akun' => $akun_txt]);
            exit;
            
        } 
        // JIKA STATUS FAILED DI PUSAT
        else if (strtolower($res_stat['status']) == 'failed' || strtolower($res_stat['status']) == 'canceled' || strtolower($res_stat['status']) == 'error') {
            $error_msg = $res_stat['message'] ?? 'Dibatalkan oleh pusat.';
            mysqli_query($conn, "UPDATE transactions SET status='FAILED', account_data='GAGAL: $error_msg' WHERE id='{$d['id']}'");
            

            
            echo json_encode(['status' => 'FAILED', 'msg' => $error_msg]);
            exit;
        }
    }
}

echo json_encode(['status' => $d['status']]);