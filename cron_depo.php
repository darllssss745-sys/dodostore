<?php
// cron_deposit.php
// Script ini dijalankan otomatis oleh server (Cron Job) tiap menit/5 menit
// Fungsinya: Cek status pembayaran yang masih 'WAITING'

require 'config.php';

// Atur timeout biar script gak mati di tengah jalan kalau data banyak
set_time_limit(120); 

// Header text biasa buat log (kalo dijalanin manual di browser/terminal keliatan rapi)
echo "<pre>--- START CRON CHECK DEPOSIT ---\n";

// 1. Ambil transaksi yang statusnya masih WAITING
// Kita limit 50 per jalan biar server gak keberatan
$query = mysqli_query($conn, "SELECT * FROM transactions WHERE status='WAITING' ORDER BY id ASC LIMIT 10");

$count = mysqli_num_rows($query);
echo "Ditemukan: $count transaksi pending.\n\n";

if($count > 0) {
    while($d = mysqli_fetch_assoc($query)) {
        $trx = $d['trx_local'];
        $inv = $d['inv_deposit'];

        // Request ke API buat nanya status pembayaran
        $res = requestAPI('pay_status', ['invoice' => $inv]);

        // Cek Respon API
        if(isset($res['success']) && $res['success'] == true) {
            
            // Status dari API Pusat (Biasanya: Unpaid, Paid, Expired, Failed)
            // Sesuaikan key-nya dengan respon real API lu, disini asumsi pake $res['data']['status']
            $status_pusat = strtolower($res['data']['status']); 

            // ==========================================
            // CASE 1: SUKSES BAYAR (LUNAS)
            // ==========================================
            if($status_pusat == 'success' || $status_pusat == 'paid') {
                mysqli_query($conn, "UPDATE transactions SET status='PAID' WHERE id='{$d['id']}'");
                echo "[SUCCESS] $trx : Pembayaran Lunas. Status updated to PAID.\n";
            }
            
            // ==========================================
            // CASE 2: KADALUARSA / GAGAL (AUTO CANCEL)
            // ==========================================
            elseif($status_pusat == 'expired' || $status_pusat == 'failed' || $status_pusat == 'canceled') {
                
                // Update jadi FAILED biar gak diproses lagi
                $alasan = "Pembayaran " . ucfirst($status_pusat); // Contoh: "Pembayaran Expired"
                $alasan_db = mysqli_real_escape_string($conn, $alasan);
                
                mysqli_query($conn, "UPDATE transactions SET status='FAILED', account_data='$alasan_db' WHERE id='{$d['id']}'");
                
                echo "[CANCEL] $trx : $alasan. Status updated to FAILED.\n";
            }
            
            // ==========================================
            // CASE 3: MASIH MENUNGGU
            // ==========================================
            else {
                echo "[PENDING] $trx : Masih menunggu pembayaran user.\n";
            }

        } else {
            // Kalau API Error / Invoice Gak Ditemukan di pusat
            echo "[SKIP] $trx : Gagal cek API (Koneksi/Invoice Invalid).\n";
            
            // Opsional: Kalau lu mau sadis, yg invalid bisa langsung di FAILED-kan juga
            // mysqli_query($conn, "UPDATE transactions SET status='FAILED' WHERE id='{$d['id']}'");
        }
        
        // Kasih jeda 1 detik biar gak dikira spamming ke API pusat
        sleep(1);
    }
} else {
    echo "Tidak ada transaksi yang perlu dicek.";
}

echo "\n--- DONE ---</pre>";
?>