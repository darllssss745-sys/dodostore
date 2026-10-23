<?php
require 'config.php';
error_reporting(0);
set_time_limit(0);



/* =====================================================
   SECURITY (WAJIB kalau dibuka via web cron)
   Panggil: cron_orders.php?key=SECRET_KAMU
   ===================================================== */
$CRON_SECRET = "GANTI_DENGAN_SECRET_PANJANG_RANDOM";

/* =====================================================
   SETTING CRON
   ===================================================== */
$BATCH_LIMIT = 50;         // transaksi diproses per hit
$LOCK_FILE   = __DIR__ . "/_cron_orders.lock"; // lebih aman & writable di shared hosting

$is_cli = (php_sapi_name() === 'cli');

/* =====================================================
   RESPONSE HEADER (buat web)
   ===================================================== */
if (!$is_cli) {
    header('Content-Type: application/json');
}

/* =====================================================
   AUTH (WEB ONLY)
   ===================================================== */
if (!$is_cli) {
    $key = $_GET['key'] ?? '';
    if ($CRON_SECRET !== '' && !hash_equals($CRON_SECRET, (string)$key)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

/* =====================================================
   LOCK (biar gak double-run)
   ===================================================== */
$lockFp = @fopen($LOCK_FILE, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($is_cli) {
        echo "[SKIP] Locked by another process\n";
    } else {
        echo json_encode(['success' => true, 'skipped' => true, 'reason' => 'locked']);
    }
    exit;
}

/* =====================================================
   HELPERS
   ===================================================== */
function normalizeWa($no) {
    $no = preg_replace('/\D+/', '', (string)$no);
    if ($no === '') return '';

    if (substr($no, 0, 1) === '0') {
        $no = '62' . substr($no, 1);
    } elseif (substr($no, 0, 1) === '8') {
        $no = '62' . $no;
    }
    return $no;
}



/* =====================================================
   MAIN PROCESS
   ===================================================== */
$limit = (int)$BATCH_LIMIT;

// Query diubah dengan JOIN agar bisa mengambil nama produk dari tabel services
$sql = "SELECT t.*, s.name as product_name 
        FROM transactions t
        LEFT JOIN services s ON t.service_id = s.id
        WHERE t.status IN ('WAITING','PAID','PROCESSING')
        ORDER BY t.id ASC
        LIMIT $limit";

$rs = mysqli_query($conn, $sql);
if (!$rs) {
    $out = ['success' => false, 'message' => 'DB query failed', 'error' => mysqli_error($conn)];
    if ($is_cli) print_r($out); else echo json_encode($out);
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit;
}

$stats = [
    'success' => true,
    'checked' => 0,
    'to_paid' => 0,
    'to_processing' => 0,
    'to_completed' => 0,
    'to_failed' => 0
];

while ($d = mysqli_fetch_assoc($rs)) {
    $stats['checked']++;

    $id       = (int)$d['id'];
    $trx      = $d['trx_local'] ?? '';
    $status   = $d['status'] ?? '';
    $invDepo  = $d['inv_deposit'] ?? '';
    $invOrder = $d['inv_order'] ?? '';
    $sid      = (int)($d['service_id'] ?? 0);
    $product_name = $d['product_name'] ?? ('Produk ID: ' . $sid); // Ambil nama produk

    // qty
    $qty = 1;
    if (isset($d['qty'])) $qty = (int)$d['qty'];
    elseif (isset($d['quantity'])) $qty = (int)$d['quantity'];
    elseif (isset($d['jumlah'])) $qty = (int)$d['jumlah'];
    elseif (isset($d['total_qty'])) $qty = (int)$d['total_qty'];
    if ($qty < 1) $qty = 1;

    // WA customer
    $cust_wa = '';
    if (!empty($d['customer_wa'])) $cust_wa = $d['customer_wa'];
    elseif (!empty($d['cust_wa'])) $cust_wa = $d['cust_wa'];
    elseif (!empty($d['target_wa'])) $cust_wa = $d['target_wa'];
    $cust_wa = normalizeWa($cust_wa);

    // WA admin buat order ke pusat
    $adminWa = defined('NOMOR_ADMIN') ? NOMOR_ADMIN : '';
    $adminWa = normalizeWa($adminWa);

    /* =========================
       1) WAITING -> PAID
       ========================= */
    if ($status === 'WAITING') {
        $resPay = requestAPI('pay_status', ['invoice' => $invDepo]);

        if (isset($resPay['data']['status']) && $resPay['data']['status'] === 'success') {
            mysqli_query($conn, "UPDATE transactions SET status='PAID' WHERE id='{$id}' AND status='WAITING'");
            if (mysqli_affected_rows($conn) > 0) {
                $stats['to_paid']++;
            }
            $status = 'PAID';
        } else {
            continue; // belum bayar
        }
    }

    /* =========================
       2) PAID -> PROCESSING (buat order)
       ========================= */
    if ($status === 'PAID') {
        if (empty($invOrder)) {
            $resOrd = requestAPI('order', [
                'product_id' => $sid,
                'qty'        => $qty,
                'whatsapp'   => $adminWa
            ]);

            if (isset($resOrd['success']) && $resOrd['success'] == true && !empty($resOrd['invoice'])) {
                $invOrderNew = mysqli_real_escape_string($conn, $resOrd['invoice']);

                mysqli_query(
                    $conn,
                    "UPDATE transactions
                     SET status='PROCESSING', inv_order='{$invOrderNew}'
                     WHERE id='{$id}' AND status='PAID' AND (inv_order='' OR inv_order IS NULL)"
                );

                if (mysqli_affected_rows($conn) > 0) {
                    $stats['to_processing']++;
                }
                $status   = 'PROCESSING';
                $invOrder = $resOrd['invoice'];
            } else {
                $error_msg = $resOrd['message'] ?? 'Produk tiba-tiba tidak tersedia di pusat.';
                $clean_err = mysqli_real_escape_string($conn, "GAGAL: " . $error_msg);

                mysqli_query(
                    $conn,
                    "UPDATE transactions
                     SET status='FAILED', account_data='{$clean_err}'
                     WHERE id='{$id}' AND status!='FAILED' AND status!='COMPLETED'"
                );
                $changed = mysqli_affected_rows($conn);

                if ($sid > 0) {
                    mysqli_query($conn, "UPDATE services SET status='unavailable' WHERE id='{$sid}'");
                }

                if ($changed > 0) {
                    $stats['to_failed']++;

                }

                continue;
            }
        } else {
            $status = 'PROCESSING';
        }
    }

    /* =========================
       3) PROCESSING -> COMPLETED (ambil akun)
       ========================= */
    if ($status === 'PROCESSING' && !empty($invOrder)) {
        $resStat = requestAPI('status', ['invoice' => $invOrder]);

        if (isset($resStat['success']) && $resStat['success'] == true && !empty($resStat['accounts'])) {
            $akun_txt = "";
            $nomor_urut = 1;
            foreach ($resStat['accounts'] as $acc) {
                if(count($resStat['accounts']) > 1) {
                    $akun_txt .= "📦 *Data Akun {$nomor_urut}*\n";
                }
                $akun_txt .= "👤 Username : " . ($acc['username'] ?? '-') . "\n";
                $akun_txt .= "🔑 Password : " . ($acc['password'] ?? '-') . "\n\n";
                $nomor_urut++;
            }

            $akun_clean = mysqli_real_escape_string($conn, $akun_txt);

            mysqli_query(
                $conn,
                "UPDATE transactions
                 SET status='COMPLETED', account_data='{$akun_clean}'
                 WHERE id='{$id}' AND status!='COMPLETED'"
            );
            $changed = mysqli_affected_rows($conn);

            if ($changed > 0) {
                $stats['to_completed']++;


            }
        }
    }
}

/* =====================================================
   OUTPUT
   ===================================================== */
if ($is_cli) {
    print_r($stats);
} else {
    echo json_encode($stats);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);