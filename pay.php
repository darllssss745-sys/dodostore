<?php
require 'config.php';

// ============================================================
// Handler AJAX Cek Manual (Taruh Paling Atas Biar Mulus)
// ============================================================
if(isset($_POST['action']) && $_POST['action'] == 'manual_check') {
    error_reporting(0);
    ob_start(); // Tahan semua output biar ga ngerusak JSON
    
    $trx_ajax = mysqli_real_escape_string($conn, $_GET['trx']);
    $q_now = mysqli_query($conn, "SELECT status, inv_deposit FROM transactions WHERE trx_local='$trx_ajax'");
    $d_now = mysqli_fetch_assoc($q_now);
    
    if(!$d_now) {
        ob_end_clean();
        echo json_encode(['success' => false, 'msg' => 'Transaksi ga ketemu!']);
        exit;
    }
    
    if($d_now['status'] !== 'WAITING') {
        ob_end_clean();
        echo json_encode(['success' => true, 'status' => $d_now['status']]);
        exit;
    }

    // Tanya Pusat
    $res_api = requestAPI('pay_status', ['invoice' => $d_now['inv_deposit']]);
    $new_api_stat = $res_api['data']['status'] ?? 'waiting';

    ob_end_clean(); // Bersihin output kalau ada error dari curl
    
    if($new_api_stat == 'success' || $new_api_stat == 'PAID') {
        mysqli_query($conn, "UPDATE transactions SET status='PAID' WHERE trx_local='$trx_ajax'");
        echo json_encode(['success' => true, 'status' => 'PAID', 'msg' => 'Pembayaran Terdeteksi!']);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Pembayaran lu belum masuk, tunggu bentar atau cek lagi nominalnya!']);
    }
    exit;
}

// ============================================================
// 1. AMBIL SETTING & DATA TRANSAKSI
// ============================================================
$res_settings = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key IN ('web_name', 'web_logo', 'nomor_admin')");
$settings = [];
while($s = mysqli_fetch_assoc($res_settings)) { $settings[$s['setting_key']] = $s['setting_value']; }

$web_name = $settings['web_name'] ?? 'Sarang Digital';
$web_logo = $settings['web_logo'] ?? '';
$nomor_admin = $settings['nomor_admin'] ?? '';
$favicon = $web_logo ? $web_logo : 'https://cdn-icons-png.flaticon.com/512/3003/3003149.png';

$trx = mysqli_real_escape_string($conn, $_GET['trx']);
$q = mysqli_query($conn, "SELECT t.*, s.name as prod_name FROM transactions t LEFT JOIN services s ON t.service_id = s.id WHERE t.trx_local='$trx'");
$d = mysqli_fetch_assoc($q);

if(!$d) die("Transaksi tidak ditemukan.");

$status = $d['status'];

// --- LOGIC AUTO CANCEL JIKA EXPIRED ---
if($status == 'WAITING') {
    $res_cek = requestAPI('pay_status', ['invoice' => $d['inv_deposit']]);
    $api_stat = $res_cek['data']['status'] ?? 'waiting';

    if($api_stat == 'expired' || $api_stat == 'canceled') {
        mysqli_query($conn, "UPDATE transactions SET status='FAILED', account_data='Transaksi Expired / Dibatalkan' WHERE trx_local='$trx'");
        $status = 'FAILED';
    }
}

$is_completed = ($status == 'COMPLETED');
$is_failed    = ($status == 'FAILED');
$is_pending   = ($status == 'WAITING' || $status == 'PAID' || $status == 'PROCESSING');

$qr_image = '';
if($status == 'WAITING') {
    $res_qr = requestAPI('pay_status', ['invoice' => $d['inv_deposit']]);
    $qr_image = $res_qr['data']['qr_image'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= $trx ?> - <?= $web_name ?></title>
    <link rel="icon" type="image/png" href="<?= $favicon ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        if (!('theme' in localStorage)) { localStorage.theme = 'light'; }
        if (localStorage.theme === 'dark' || (localStorage.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['"Public Sans"', 'sans-serif'] },
                    colors: { neoBg: '#f4f4f0', neoDark: '#121212', neoYellow: '#ffde59', neoBlue: '#5e92f3', neoPink: '#ff90e8', neoGreen: '#00e676' },
                    boxShadow: { 'brutal': '4px 4px 0px 0px rgba(0,0,0,1)', 'brutal-dark': '4px 4px 0px 0px rgba(255,255,255,1)', 'brutal-lg': '8px 8px 0px 0px rgba(0,0,0,1)', 'brutal-lg-dark': '8px 8px 0px 0px rgba(255,255,255,1)' }
                }
            }
        }
    </script>
    <style>
        .brutal-border { border: 3px solid black; }
        .dark .brutal-border { border: 3px solid white; }
        .qr-card { background: white; border: 3px solid black; padding: 15px; display: inline-block; box-shadow: 6px 6px 0px 0px #000; }
    </style>
</head>
<body class="bg-neoBg dark:bg-neoDark text-black dark:text-white font-sans min-h-screen transition-colors duration-300 flex flex-col items-center p-4 md:p-10">

    <div class="max-w-xl w-full">
        <div class="flex flex-col items-center mb-8">
            <a href="/" class="flex items-center gap-3 mb-4">
                <?php if($web_logo): ?>
                    <img src="<?= $web_logo ?>" class="h-10 object-contain">
                <?php endif; ?>
                <span class="font-black text-2xl uppercase tracking-tighter"><?= $web_name ?></span>
            </a>
            <div class="bg-black text-white dark:bg-white dark:text-black px-4 py-1 brutal-border shadow-brutal font-black uppercase text-xs">
                Billing Invoice
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 brutal-border shadow-brutal-lg dark:shadow-brutal-lg-dark overflow-hidden">
            <div class="p-6 border-b-3 border-black dark:border-white flex flex-col md:flex-row justify-between items-center gap-4 bg-neoYellow dark:bg-neoBlue text-black font-black uppercase text-sm">
                <div class="text-center md:text-left">
                    <p class="text-[10px] opacity-70">Order ID</p>
                    <h2 class="text-xl tracking-tighter">#<?= $trx ?></h2>
                </div>
                <div class="text-center md:text-right">
                    <p class="text-[10px] opacity-70">Produk</p>
                    <h2 class="tracking-tight uppercase"><?= $d['prod_name'] ?></h2>
                </div>
            </div>

            <div class="p-6 md:p-10">
                <?php if($is_pending): ?>
                    <div id="payment-view" class="flex flex-col items-center text-center">
                        <?php if($status == 'WAITING' && !empty($qr_image)): ?>
                            <div class="qr-card mb-8">
                                <img src="<?= $qr_image ?>" class="w-48 h-48 md:w-56 md:h-56" alt="QRIS">
                                <div class="mt-3 bg-black text-white font-black py-1 text-[10px] uppercase">Scan QRIS untuk Bayar</div>
                            </div>
                        <?php endif; ?>

                        <div class="mb-6">
                            <p class="text-xs font-black uppercase mb-1 opacity-60">Total Bayar</p>
                            <div class="flex items-center justify-center gap-2">
                                <span class="text-2xl font-black">Rp</span>
                                <span class="text-5xl md:text-6xl font-black tracking-tighter"><?= number_format($d['amount_pay']) ?></span>
                            </div>
                        </div>

                        <?php if($status == 'WAITING'): ?>
                            <button id="btnCheckManual" onclick="checkPaymentManual()" class="w-full mb-6 bg-neoGreen text-black brutal-border py-4 font-black uppercase shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all flex items-center justify-center gap-2">
                                <i class="fa-solid fa-search-dollar"></i> CEK PEMBAYARAN
                            </button>
                        <?php elseif($status == 'PAID' || $status == 'PROCESSING'): ?>
                            <button id="btnCheckOrder" onclick="checkOrderStatus()" class="w-full mb-6 bg-neoBlue text-black brutal-border py-4 font-black uppercase shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all flex items-center justify-center gap-2">
                                <i class="fa-solid fa-rotate"></i> CEK STATUS PESANAN
                            </button>
                        <?php endif; ?>

                        <div class="w-full bg-gray-100 dark:bg-zinc-800 brutal-border p-4 flex items-center justify-center gap-3">
                            <div class="relative flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-neoBlue opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-neoBlue"></span>
                            </div>
                            <span class="font-black uppercase text-sm tracking-wide" id="status-text">
                                <?= ($status == 'PAID' || $status == 'PROCESSING') ? 'Pembayaran Masuk! Memproses Akun...' : 'Menunggu Transfer...' ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <div id="result-view" class="<?= (!$is_pending) ? '' : 'hidden' ?> text-center">
                    <div class="w-20 h-20 brutal-border rounded-full mx-auto flex items-center justify-center text-4xl mb-6 shadow-brutal <?= $is_completed ? 'bg-neoGreen' : 'bg-red-400' ?>">
                        <i class="fa-solid <?= $is_completed ? 'fa-check' : 'fa-xmark' ?>"></i>
                    </div>
                    <h2 class="text-3xl font-black uppercase tracking-tighter mb-2"><?= $is_completed ? 'Pesanan Terkirim!' : 'Transaksi Gagal' ?></h2>
                    <p class="font-bold opacity-60 mb-8"><?= $is_completed ? 'Data akun lu udah siap di bawah.' : 'Invoice expired atau order gagal diproses pusat.' ?></p>

                    <div class="bg-white dark:bg-zinc-800 brutal-border p-5 text-left relative shadow-[4px_4px_0px_0px_rgba(0,0,0,1)] mb-8">
                        <p class="text-[10px] font-black uppercase mb-3 opacity-50">Data Akun / Info</p>
                        <pre id="account-data" class="font-mono text-sm md:text-base font-bold whitespace-pre-wrap break-all <?= $is_completed ? 'text-black dark:text-neoGreen' : 'text-red-500' ?>"><?= $is_completed ? $d['account_data'] : ($d['account_data'] ?? "Order gagal diproses.") ?></pre>
                        <button onclick="copyToClipboard(document.getElementById('account-data').innerText)" class="absolute top-4 right-4 bg-black text-white dark:bg-white dark:text-black w-8 h-8 brutal-border flex items-center justify-center hover:scale-110 transition-transform"><i class="fa-solid fa-copy text-xs"></i></button>
                    </div>

                    <div class="flex flex-col gap-3">
                        <?php if($is_completed): ?>
                            <a href="/" class="bg-neoYellow brutal-border text-black font-black py-4 uppercase shadow-brutal hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all">Sikat Lagi!</a>
                        <?php else: ?>
                            <a href="/" class="bg-black text-white brutal-border font-black py-4 uppercase shadow-brutal hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all">Balik Ke Menu</a>
                            <a href="https://wa.me/<?= $nomor_admin ?>" target="_blank" class="font-black uppercase text-sm underline mt-2 text-emerald-500">Lapor Admin</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <p class="mt-8 text-center text-[10px] font-black uppercase opacity-40">Invoice System &copy; <?= date('Y') ?> <?= $web_name ?></p>
    </div>

    <div id="copy-toast" class="fixed bottom-10 bg-black text-white px-6 py-3 brutal-border font-black uppercase text-xs shadow-brutal transform translate-y-20 transition-transform duration-300">
        Copied to clipboard! 📋
    </div>

    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text);
            const toast = document.getElementById('copy-toast');
            toast.classList.remove('translate-y-20');
            setTimeout(() => toast.classList.add('translate-y-20'), 2000);
        }

        // ============================================
        // 1. FUNGSI CEK PEMBAYARAN JQUERY AJAX SAFE
        // ============================================
        function checkPaymentManual() {
            const btn = $('#btnCheckManual');
            const originalHtml = btn.html();
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner animate-spin"></i> LAGI DICEK...');

            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: { action: 'manual_check' },
                dataType: 'json',
                success: function(data) {
                    if(data.success && data.status === 'PAID') {
                        $('#status-text').text('PEMBAYARAN MASUK! LAGI PROSES ORDER...');
                        
                        // TEMBAK WORKER LANGSUNG
                        $.post('worker.php', {trx: '<?= $trx ?>'}, function(workerData) {
                            if(workerData.status === 'COMPLETED') {
                                Swal.fire({ icon: 'success', title: 'MANTAP!', text: 'Orderan sukses, data akun muncul!', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} })
                                .then(() => location.reload());
                            } else if(workerData.status === 'FAILED') {
                                Swal.fire({ icon: 'error', title: 'YAH GAGAL', text: workerData.msg || 'Transaksi gagal diproses pusat.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} })
                                .then(() => location.reload());
                            } else {
                                location.reload(); 
                            }
                        }, 'json').fail(function() {
                            location.reload();
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'ZONK!', text: data.msg || 'Belum dibayar.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} });
                        btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr) {
                    Swal.fire({ icon: 'error', title: 'ERROR', text: 'Gagal cek ke server, coba ntar lagi.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} });
                    btn.prop('disabled', false).html(originalHtml);
                }
            });
        }

        // ============================================
        // 2. FUNGSI CEK STATUS PESANAN (WORKER TRIGGER)
        // ============================================
        function checkOrderStatus() {
            const btn = $('#btnCheckOrder');
            const originalHtml = btn.html();
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner animate-spin"></i> NGECEK PUSAT...');

            $.ajax({
                url: 'worker.php',
                type: 'POST',
                data: { trx: '<?= $trx ?>' },
                dataType: 'json',
                success: function(workerData) {
                    if(workerData.status === 'COMPLETED') {
                        Swal.fire({ icon: 'success', title: 'AKHIRNYA!', text: 'Orderan beres, data akun langsung muncul!', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} })
                        .then(() => location.reload());
                    } else if(workerData.status === 'FAILED') {
                        Swal.fire({ icon: 'error', title: 'GAGAL', text: workerData.msg || 'Ada masalah dari pusat.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} })
                        .then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'info', title: 'MASIH DIPROSES', text: 'Pusat masih nyiapin akun lu, tungguin bentar lagi yak!', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} });
                        btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr) {
                    Swal.fire({ icon: 'error', title: 'ERROR', text: 'Pusat error atau lagi ganti struktur.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} });
                    btn.prop('disabled', false).html(originalHtml);
                }
            });
        }

        <?php if($is_pending): ?>
        // Interval auto check tiap 5 detik jalan di background
        setInterval(function() {
            $.get('check_trx.php?trx=<?= $trx ?>', function(res) {
                if(res.status === 'COMPLETED' || res.status === 'FAILED' || (res.status === 'PAID' && '<?= $status ?>' === 'WAITING')) {
                    location.reload();
                }
            });
        }, 5000);
        <?php endif; ?>
    </script>
</body>
</html>