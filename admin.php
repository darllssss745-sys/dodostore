<?php
require 'config.php';
$alert = null; 

// ============================================================
// 0. AUTO CREATE TABLE KUPON (PENTING BIAR GA ERROR)
// ============================================================
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS coupons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE,
    discount_percent INT,
    max_discount DOUBLE,
    min_order DOUBLE,
    quota INT,
    expired_at DATE,
    status ENUM('active', 'inactive') DEFAULT 'active'
)");

// ============================================================
// 1. CEK LOGIN 
// ============================================================
if(!isset($_SESSION['admin_logged'])) {
    if(isset($_POST['pass'])) { 
        if($_POST['pass'] == ADMIN_PASSWORD) {
            $_SESSION['admin_logged'] = true;
        } else {
            $error_login = "Password salah Bro!";
        }
    }
    
    if(!isset($_SESSION['admin_logged'])) {
        $q_logo = mysqli_fetch_assoc(mysqli_query($conn, "SELECT setting_value FROM settings WHERE setting_key='web_logo'"))['setting_value'] ?? '';
        $fav = $q_logo ? $q_logo : 'https://cdn-icons-png.flaticon.com/512/3003/3003149.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <title>Admin Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= $fav ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;800;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Public Sans', sans-serif; background-color: #f4f4f0; } .brutal-border { border: 3px solid black; } .shadow-brutal { box-shadow: 4px 4px 0px 0px rgba(0,0,0,1); } </style>
</head>
<body class="flex items-center justify-center min-h-screen p-4">
    <form method="post" class="bg-white brutal-border p-8 w-full max-w-sm shadow-[8px_8px_0px_0px_rgba(0,0,0,1)]">
        <?php if($q_logo): ?><img src="<?= $q_logo ?>" class="h-16 mx-auto mb-4 object-contain"><?php endif; ?>
        <h1 class="text-3xl font-black uppercase tracking-tighter mb-6 text-center bg-yellow-300 border-2 border-black inline-block px-4 py-1 mx-auto shadow-brutal transform -rotate-2 w-full">Admin Area</h1>
        <div class="space-y-4">
            <input type="password" name="pass" placeholder="Password..." required class="w-full bg-gray-100 brutal-border py-3 px-4 font-bold outline-none shadow-brutal focus:translate-x-[2px] focus:translate-y-[2px] focus:shadow-none transition-all">
            <button class="w-full bg-blue-500 text-black font-black py-3 brutal-border shadow-brutal hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all uppercase">Login <i class="fa-solid fa-arrow-right"></i></button>
        </div>
        <?php if(isset($error_login)): ?>
            <div class="mt-4 bg-red-400 border-2 border-black p-2 font-bold text-sm text-center shadow-brutal"><?= $error_login ?></div>
        <?php endif; ?>
    </form>
</body>
</html>
<?php exit; } }

// ============================================================
// 2. LOGIC CRUD (TRANSAKSI, HARGA MANUAL, & KUPON)
// ============================================================
if(isset($_POST['update_trx_status'])) {
    $trx_id = (int)$_POST['trx_id']; $new_status = mysqli_real_escape_string($conn, $_POST['status']);
    if(mysqli_query($conn, "UPDATE transactions SET status='$new_status' WHERE id='$trx_id'")) { $alert = ['type' => 'success', 'title' => 'Sip', 'text' => 'Status jadi '.$new_status]; }
}

if(isset($_POST['update_price'])) {
    $id = (int)$_POST['id']; $price_jual = (double)$_POST['price_jual'];
    if($price_jual > 0) { mysqli_query($conn, "INSERT INTO services (id, price_jual) VALUES ('$id', '$price_jual') ON DUPLICATE KEY UPDATE price_jual='$price_jual'"); } 
    else { mysqli_query($conn, "DELETE FROM services WHERE id='$id'"); }
    $alert = ['type' => 'success', 'title' => 'Mantap', 'text' => 'Harga diupdate!'];
}

// LOGIC RESET HARGA KE AUTO
if(isset($_POST['reset_all_prices'])) {
    mysqli_query($conn, "TRUNCATE TABLE services");
    $alert = ['type' => 'success', 'title' => 'Sukses Direset', 'text' => 'Semua harga manual udah dihapus! Sekarang web murni pakai Auto Markup.'];
}

if(isset($_POST['add_coupon'])) {
    $code = strtoupper(mysqli_real_escape_string($conn, trim($_POST['code'])));
    $disc = (int)$_POST['discount_percent']; $max = (double)$_POST['max_discount'];
    $min = (double)$_POST['min_order']; $quota = (int)$_POST['quota'];
    $exp = mysqli_real_escape_string($conn, $_POST['expired_at']);
    try {
        mysqli_query($conn, "INSERT INTO coupons (code, discount_percent, max_discount, min_order, quota, expired_at) VALUES ('$code', '$disc', '$max', '$min', '$quota', '$exp')");
        $alert = ['type' => 'success', 'title' => 'Sukses', 'text' => 'Kupon berhasil dibuat!'];
    } catch(Exception $e) { $alert = ['type' => 'error', 'title' => 'Gagal', 'text' => 'Kode kupon udah pernah dipake bos. Cari kode lain!']; }
}

if(isset($_POST['delete_coupon'])) {
    $id = (int)$_POST['coupon_id']; mysqli_query($conn, "DELETE FROM coupons WHERE id='$id'");
    $alert = ['type' => 'success', 'title' => 'Dihapus', 'text' => 'Kupon berhasil dihapus.'];
}

// ============================================================
// 3. UPDATE SETTINGS & LOGO UPLOAD
// ============================================================
if(isset($_POST['save_settings'])) {
    $settings_to_update = [
        'nomor_admin' => mysqli_real_escape_string($conn, $_POST['wa']), 'api_key' => mysqli_real_escape_string($conn, $_POST['api']),
        'admin_password' => mysqli_real_escape_string($conn, $_POST['pass']), 'profit_markup' => mysqli_real_escape_string($conn, $_POST['markup']), 
        'markup_type' => mysqli_real_escape_string($conn, $_POST['markup_type']), 'web_name' => mysqli_real_escape_string($conn, $_POST['web_name'])
    ];
    foreach($settings_to_update as $key => $val) {
        $cek = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key='$key'");
        if(mysqli_num_rows($cek) > 0) { mysqli_query($conn, "UPDATE settings SET setting_value='$val' WHERE setting_key='$key'"); } 
        else { mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$val')"); }
    }
    if(isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] == 0) {
        if(!is_dir('assets')) mkdir('assets', 0777, true); 
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if(in_array($ext, ['png', 'jpg', 'jpeg', 'svg', 'gif', 'webp'])) {
            $logo_path = 'assets/logo_' . time() . '.' . $ext;
            if(move_uploaded_file($_FILES['logo_file']['tmp_name'], $logo_path)) {
                $cek_logo = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key='web_logo'");
                if(mysqli_num_rows($cek_logo) > 0) { mysqli_query($conn, "UPDATE settings SET setting_value='$logo_path' WHERE setting_key='web_logo'"); } 
                else { mysqli_query($conn, "INSERT INTO settings (setting_key, setting_value) VALUES ('web_logo', '$logo_path')"); }
            }
        }
    }
    $alert = ['type' => 'success', 'title' => 'Aman Bro', 'text' => 'Setting berhasil disimpan.'];
}

// ============================================================
// 4. DATA DASHBOARD & SEARCH LOGIC
// ============================================================
$total_trx = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM transactions WHERE status = 'COMPLETED'"))['total'];
$q_set = mysqli_query($conn, "SELECT * FROM settings");
$set = []; while($r = mysqli_fetch_assoc($q_set)) { $set[$r['setting_key']] = $r['setting_value']; }
$val_web_name = $set['web_name'] ?? 'Sarang Digital'; $markup_val = (float)($set['profit_markup'] ?? 10);
$markup_type = $set['markup_type'] ?? 'percent'; $web_logo = $set['web_logo'] ?? '';
$favicon = $web_logo ? $web_logo : 'https://cdn-icons-png.flaticon.com/512/3003/3003149.png';
$total_income = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(amount_pay) as total FROM transactions WHERE status = 'COMPLETED'"))['total'] ?? 0;
$total_cuan = ($markup_type == 'nominal') ? ($total_trx * $markup_val) : ($total_income - ($total_income / (1 + ($markup_val / 100))));

$search_query = ""; $search_val = "";
if(!empty($_GET['search_order'])) {
    $search_val = mysqli_real_escape_string($conn, $_GET['search_order']);
    $search_query = " AND (t.trx_local LIKE '%$search_val%' OR t.target_wa LIKE '%$search_val%' OR t.inv_order LIKE '%$search_val%') ";
}
$limit = 20; $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; $start = ($page - 1) * $limit;
$total_rows = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM transactions t WHERE 1=1 $search_query"))['total'];
$total_pages = ceil($total_rows / $limit); $start_page = max(1, $page - 2); $end_page = min($total_pages, $start_page + 4);

$res_api = requestAPI('products', []);
$api_products = isset($res_api['success']) && $res_api['success'] ? $res_api['products'] : [];

$res_profile = requestAPI('profile', []);
$premku_saldo = 0;
$premku_user = '-';
if (isset($res_profile['success']) && $res_profile['success']) {
    $premku_saldo = $res_profile['data']['saldo'] ?? 0;
    $premku_user = $res_profile['data']['username'] ?? '-';
}
$local_db = [];
$q_local = mysqli_query($conn, "SELECT id, price_jual FROM services");
while($row = mysqli_fetch_assoc($q_local)) { $local_db[$row['id']] = $row['price_jual']; }
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <title>Admin Panel - <?= htmlspecialchars($val_web_name) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="<?= $favicon ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style> body { font-family: 'Public Sans', sans-serif; background-color: #f4f4f0; } .brutal-border { border: 3px solid black; } .shadow-brutal { box-shadow: 4px 4px 0px 0px rgba(0,0,0,1); } .shadow-brutal-lg { box-shadow: 8px 8px 0px 0px rgba(0,0,0,1); } .tab-active { background-color: black; color: white; } </style>
</head>
<body class="text-black min-h-screen">

    <nav class="sticky top-0 z-40 bg-white brutal-border border-x-0 border-t-0 px-6 h-16 flex items-center justify-between shadow-brutal">
        <div class="flex items-center gap-3">
            <?php if($web_logo): ?><img src="<?= $web_logo ?>" alt="Logo" class="h-8 object-contain"><?php endif; ?>
            <span class="font-black tracking-tighter uppercase text-lg md:text-xl hidden md:inline">🔥 COMMAND CENTER</span>
        </div>
        <div class="flex gap-3">
            <a href="index.php" target="_blank" class="text-xs font-black px-4 py-2 bg-yellow-300 brutal-border shadow-brutal transition-all uppercase"><i class="fa-solid fa-store"></i> <span class="hidden md:inline">Cek Web</span></a>
            <a href="logout.php" class="text-xs font-black px-4 py-2 bg-red-400 brutal-border shadow-brutal transition-all uppercase"><i class="fa-solid fa-power-off"></i> <span class="hidden md:inline">Out</span></a>
        </div>
    </nav>

    <div class="max-w-[1400px] mx-auto p-4 md:p-8">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-6 mb-8">
            <div class="bg-emerald-300 p-6 brutal-border shadow-brutal-lg transform -rotate-1">
                <p class="font-black uppercase text-xs mb-1">Cuan Masuk</p>
                <h3 class="text-2xl md:text-3xl font-black">Rp <?= number_format($total_cuan) ?></h3>
            </div>
            <div class="bg-blue-300 p-6 brutal-border shadow-brutal-lg transform rotate-1">
                <p class="font-black uppercase text-xs mb-1">Trx Sukses</p>
                <h3 class="text-2xl md:text-3xl font-black"><?= number_format($total_trx) ?></h3>
            </div>
            <div class="bg-yellow-300 p-6 brutal-border shadow-brutal-lg">
                <p class="font-black uppercase text-xs mb-2">Status API</p>
                <div class="bg-white border-2 border-black inline-block px-3 py-1 font-black text-sm uppercase"><i class="fa-solid fa-satellite-dish text-green-500"></i> Active</div>
            </div>
            <div class="bg-pink-300 p-6 brutal-border shadow-brutal-lg transform rotate-1 flex flex-col justify-between">
                <p class="font-black uppercase text-xs mb-1">Saldo Premku</p>
                <div>
                    <h3 class="text-xl md:text-2xl font-black">Rp <?= number_format($premku_saldo) ?></h3>
                    <p class="text-[10px] font-bold mt-1 bg-white border border-black inline-block px-1">👤 <?= htmlspecialchars($premku_user) ?></p>
                </div>
            </div>
        </div>

        <div class="flex gap-3 mb-8 overflow-x-auto border-b-4 border-black pb-4 no-scrollbar">
            <button onclick="switchTab('orders')" id="tab-orders" class="px-6 py-2.5 bg-white brutal-border font-black uppercase shadow-brutal transition-all">Orders</button>
            <button onclick="switchTab('products')" id="tab-products" class="px-6 py-2.5 bg-white brutal-border font-black uppercase shadow-brutal transition-all whitespace-nowrap">Produk Harga</button>
            <button onclick="switchTab('coupons')" id="tab-coupons" class="px-6 py-2.5 bg-white brutal-border font-black uppercase shadow-brutal transition-all whitespace-nowrap">Voucher Kupon</button>
            <button onclick="switchTab('settings')" id="tab-settings" class="px-6 py-2.5 bg-white brutal-border font-black uppercase shadow-brutal transition-all whitespace-nowrap">Setting Web</button>
        </div>

        <div id="orders" class="tab-content hidden">
            <div class="mb-6 flex">
                <form method="GET" action="" class="flex w-full md:w-1/2 shadow-brutal">
                    <input type="hidden" name="tab" value="orders">
                    <input type="text" name="search_order" value="<?= htmlspecialchars($search_val) ?>" placeholder="Cari ID Lokal / WA / ID Provider..." class="w-full border-2 border-black border-r-0 py-3 px-4 font-bold outline-none focus:bg-yellow-100">
                    <button type="submit" class="bg-black text-white px-6 font-black uppercase border-2 border-black"><i class="fa-solid fa-search"></i></button>
                    <?php if($search_val): ?>
                        <a href="?tab=orders" class="bg-red-400 text-black px-4 flex items-center justify-center font-black border-2 border-black border-l-0"><i class="fa-solid fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="bg-white brutal-border shadow-brutal-lg overflow-x-auto">
                <table class="w-full text-left font-bold min-w-[900px]">
                    <thead class="bg-black text-white text-xs uppercase border-b-4 border-black">
                        <tr>
                            <th class="px-6 py-4">Waktu & ID Pesanan</th>
                            <th class="px-6 py-4">WA Pembeli</th>
                            <th class="px-6 py-4">Produk</th>
                            <th class="px-6 py-4">Total Bayar</th>
                            <th class="px-6 py-4">Status & Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y-2 divide-black text-sm">
                        <?php 
                        $q_trx = mysqli_query($conn, "SELECT t.*, s.name as local_name FROM transactions t LEFT JOIN services s ON t.service_id = s.id WHERE 1=1 $search_query ORDER BY t.id DESC LIMIT $start, $limit");
                        if(mysqli_num_rows($q_trx) > 0):
                            while($r = mysqli_fetch_assoc($q_trx)): 
                                $st_bg = '';
                                switch($r['status']) {
                                    case 'COMPLETED': $st_bg = 'bg-emerald-300'; break;
                                    case 'FAILED': $st_bg = 'bg-red-400'; break;
                                    case 'PROCESSING': $st_bg = 'bg-blue-300'; break;
                                    case 'PAID': $st_bg = 'bg-purple-300'; break;
                                    default: $st_bg = 'bg-yellow-300'; break;
                                }
                        ?>
                        <tr class="hover:bg-gray-100">
                            <td class="px-6 py-4">
                                <div class="mb-1 text-xs"><?= date('d M Y, H:i', strtotime($r['created_at'])) ?></div>
                                <div class="text-[11px] text-gray-600 font-black">Lokal: #<?= $r['trx_local'] ?></div>
                                <?php if(!empty($r['inv_order'])): ?><div class="text-[11px] text-blue-600 font-black mt-0.5">Provider: <?= $r['inv_order'] ?></div><?php endif; ?>
                            </td>
                            <td class="px-6 py-4 font-black"><i class="fa-brands fa-whatsapp text-emerald-500"></i> <?= htmlspecialchars($r['target_wa']) ?></td>
                            <td class="px-6 py-4 text-xs"><?= htmlspecialchars($r['local_name'] ?? 'ID: '.$r['service_id']) ?> (x<?= $r['qty'] ?? 1 ?>)</td>
                            <td class="px-6 py-4 text-lg font-black">Rp<?= number_format($r['amount_pay']) ?></td>
                            <td class="px-6 py-4">
                                <form method="post" action="?tab=orders" class="m-0">
                                    <input type="hidden" name="update_trx_status" value="1">
                                    <input type="hidden" name="trx_id" value="<?= $r['id'] ?>">
                                    <select name="status" onchange="this.form.submit()" class="border-2 border-black outline-none font-black text-xs uppercase px-2 py-1 cursor-pointer <?= $st_bg ?>">
                                        <option value="WAITING" <?= $r['status'] == 'WAITING' ? 'selected' : '' ?>>WAITING</option>
                                        <option value="PAID" <?= $r['status'] == 'PAID' ? 'selected' : '' ?>>PAID</option>
                                        <option value="PROCESSING" <?= $r['status'] == 'PROCESSING' ? 'selected' : '' ?>>PROCESSING</option>
                                        <option value="COMPLETED" <?= $r['status'] == 'COMPLETED' ? 'selected' : '' ?>>COMPLETED</option>
                                        <option value="FAILED" <?= $r['status'] == 'FAILED' ? 'selected' : '' ?>>FAILED</option>
                                    </select>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="px-6 py-8 text-center uppercase font-black text-gray-500">Pesanan ngga ketemu bro.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if($total_pages > 1): ?>
            <div class="mt-6 flex gap-2 justify-center">
                <a href="?page=<?= max(1, $page - 1) ?>&tab=orders<?= $search_val ? '&search_order='.$search_val : '' ?>" class="px-3 py-1 bg-white brutal-border font-black shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]"><</a>
                <?php for($i=$start_page; $i<=$end_page; $i++): ?>
                    <a href="?page=<?= $i ?>&tab=orders<?= $search_val ? '&search_order='.$search_val : '' ?>" class="px-3 py-1 <?= $page == $i ? 'bg-black text-white' : 'bg-white' ?> brutal-border font-black shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]"><?= $i ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($total_pages, $page + 1) ?>&tab=orders<?= $search_val ? '&search_order='.$search_val : '' ?>" class="px-3 py-1 bg-white brutal-border font-black shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]">></a>
            </div>
            <?php endif; ?>
        </div>

        <div id="products" class="tab-content hidden">
            <div class="mb-4">
                <form method="post" action="?tab=products">
                    <button name="reset_all_prices" onclick="return confirm('Peringatan: Ini akan menghapus SEMUA harga manual yang udah lu input dan mereset sistem ke Auto Markup. Yakin daks?')" class="bg-red-500 text-white px-4 py-3 font-black brutal-border uppercase shadow-[4px_4px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all text-sm">
                        <i class="fa-solid fa-trash"></i> Hapus Semua Harga Manual (Reset ke Auto)
                    </button>
                </form>
            </div>

            <div class="bg-white brutal-border shadow-brutal-lg overflow-x-auto">
                <div class="bg-blue-300 border-b-3 border-black p-4 text-xs font-black uppercase"><i class="fa-solid fa-info-circle"></i> Info: Kosongin kolom "Harga Manual Loe" kalau mau pakai sistem Markup Otomatis.</div>
                <table class="w-full text-left font-bold min-w-[800px]">
                    <thead class="bg-black text-white text-xs uppercase border-b-4 border-black">
                        <tr>
                            <th class="px-6 py-4">Layanan & ID</th>
                            <th class="px-6 py-4 text-center">API</th>
                            <th class="px-6 py-4">Modal Pusat</th>
                            <th class="px-6 py-4">Auto Harga (Markup)</th>
                            <th class="px-6 py-4">Harga Manual Loe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y-2 divide-black text-sm">
                        <?php foreach($api_products as $p): 
                            $id = $p['id']; $modal = (double)$p['price'];
                            $auto = ($markup_type == 'nominal') ? ($modal + $markup_val) : ($modal + ($modal * ($markup_val/100)));
                            $custom = $local_db[$id] ?? '';
                            $badge = ($p['status'] == 'available' && $p['stock'] > 0) ? 'bg-emerald-300 text-black' : 'bg-red-400 text-black';
                        ?>
                        <tr class="hover:bg-gray-100">
                            <td class="px-6 py-4">
                                <div class="font-black text-xs uppercase"><?= htmlspecialchars($p['name']) ?></div>
                                <div class="text-[10px] text-gray-500">ID: <?= $id ?> | Stok: <?= $p['stock'] ?></div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span class="px-2 py-1 text-[9px] font-black uppercase border-2 border-black <?= $badge ?>"><?= $p['stock'] < 1 ? 'KOSONG' : $p['status'] ?></span>
                            </td>
                            <td class="px-6 py-4 font-black">Rp<?= number_format($modal) ?></td>
                            <td class="px-6 py-4 font-black text-blue-600">Rp<?= number_format($auto) ?></td>
                            <td class="px-6 py-4">
                                <form method="post" action="?tab=products" class="flex gap-2">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <input type="number" name="price_jual" value="<?= $custom ?>" placeholder="Auto" class="w-28 bg-white border-2 border-black px-2 py-1 text-xs font-black outline-none focus:bg-yellow-100 shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]">
                                    <button name="update_price" class="bg-black text-white px-3 py-1 font-black text-xs shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:bg-gray-800 border-2 border-black">SAVE</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="coupons" class="tab-content hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-1 bg-pink-200 brutal-border shadow-brutal-lg p-6 transform -rotate-1 self-start">
                    <h2 class="text-xl font-black uppercase mb-4 border-b-4 border-black pb-2"><i class="fa-solid fa-ticket"></i> Buat Kupon</h2>
                    <form method="POST" action="?tab=coupons" class="space-y-3">
                        <div><label class="text-[10px] font-black uppercase">Kode Kupon (Tanpa Spasi)</label><input type="text" name="code" required class="w-full border-2 border-black px-3 py-2 font-bold uppercase shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] outline-none"></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="text-[10px] font-black uppercase">Diskon (%)</label><input type="number" name="discount_percent" required min="1" max="100" class="w-full border-2 border-black px-3 py-2 font-bold shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] outline-none"></div>
                            <div><label class="text-[10px] font-black uppercase">Kuota Limit</label><input type="number" name="quota" required min="1" class="w-full border-2 border-black px-3 py-2 font-bold shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] outline-none"></div>
                        </div>
                        <div><label class="text-[10px] font-black uppercase">Maks Potongan (Rp)</label><input type="number" name="max_discount" required class="w-full border-2 border-black px-3 py-2 font-bold shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] outline-none"></div>
                        <div><label class="text-[10px] font-black uppercase">Min Belanja (Rp)</label><input type="number" name="min_order" required class="w-full border-2 border-black px-3 py-2 font-bold shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] outline-none"></div>
                        <div><label class="text-[10px] font-black uppercase">Expired Date</label><input type="date" name="expired_at" required class="w-full border-2 border-black px-3 py-2 font-bold shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] outline-none cursor-pointer"></div>
                        <button type="submit" name="add_coupon" class="w-full bg-black text-white font-black py-3 mt-4 border-2 border-black shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] uppercase hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all">Tambah Kupon</button>
                    </form>
                </div>
                <div class="lg:col-span-2 bg-white brutal-border shadow-brutal-lg overflow-x-auto self-start">
                    <table class="w-full text-left font-bold min-w-[600px]">
                        <thead class="bg-black text-white text-[10px] uppercase border-b-4 border-black">
                            <tr>
                                <th class="px-4 py-3">KODE KUPON</th><th class="px-4 py-3 text-center">DISKON %</th><th class="px-4 py-3">MAKS POTONGAN</th><th class="px-4 py-3">SISA KUOTA</th><th class="px-4 py-3 text-center">EXPIRED</th><th class="px-4 py-3 text-center">AKSI</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y-2 divide-black text-sm">
                            <?php 
                            $q_coup = mysqli_query($conn, "SELECT * FROM coupons ORDER BY id DESC");
                            if(mysqli_num_rows($q_coup) > 0): while($c = mysqli_fetch_assoc($q_coup)): 
                                $is_active = ($c['status'] == 'active' && strtotime($c['expired_at']) >= strtotime('today') && $c['quota'] > 0);
                            ?>
                            <tr class="hover:bg-gray-100 <?= !$is_active ? 'opacity-50' : '' ?>">
                                <td class="px-4 py-3 font-black text-blue-600 uppercase"><?= htmlspecialchars($c['code']) ?></td>
                                <td class="px-4 py-3 text-center bg-yellow-200 border-x-2 border-black font-black"><?= $c['discount_percent'] ?>%</td>
                                <td class="px-4 py-3"><div>Max: Rp <?= number_format($c['max_discount']) ?></div><div class="text-[9px] text-gray-500 uppercase mt-0.5">Min: Rp <?= number_format($c['min_order']) ?></div></td>
                                <td class="px-4 py-3 font-black text-center"><?= $c['quota'] ?></td>
                                <td class="px-4 py-3 text-xs text-center font-bold text-red-500"><?= date('d M Y', strtotime($c['expired_at'])) ?></td>
                                <td class="px-4 py-3 text-center">
                                    <form method="post" action="?tab=coupons" class="m-0"><input type="hidden" name="coupon_id" value="<?= $c['id'] ?>"><button name="delete_coupon" onclick="return confirm('Hapus kupon ini?')" class="bg-red-500 text-black px-3 py-1 font-black text-xs border-2 border-black shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:bg-black hover:text-white transition-colors">HAPUS</button></form>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr><td colspan="6" class="px-4 py-6 text-center uppercase font-black text-gray-500">Belum ada kupon bro.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="settings" class="tab-content hidden">
            <form method="post" action="?tab=settings" enctype="multipart/form-data">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                <div class="bg-white brutal-border shadow-brutal-lg p-6 md:p-8 space-y-5">
                    <h2 class="text-xl md:text-2xl font-black uppercase border-b-4 border-black pb-2">Web Settings</h2>
                    <div class="bg-pink-200 border-2 border-black p-4">
                        <label class="text-xs font-black uppercase block mb-2">Upload Logo / Favicon (.png/svg)</label>
                        <input type="file" name="logo_file" accept="image/*" class="w-full bg-white border-2 border-black py-2 px-2 font-bold text-xs md:text-sm shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] cursor-pointer">
                    </div>
                    <div><label class="text-xs font-black uppercase block mb-2">Nama Website</label><input type="text" name="web_name" value="<?= htmlspecialchars($val_web_name) ?>" class="w-full bg-white border-2 border-black py-3 px-4 font-bold shadow-brutal"></div>
                    <div><label class="text-xs font-black uppercase block mb-2">WA Admin (CS)</label><input type="text" name="wa" value="<?= htmlspecialchars($set['nomor_admin'] ?? '') ?>" class="w-full bg-white border-2 border-black py-3 px-4 font-bold shadow-brutal"></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-100 p-4 border-2 border-black">
                        <div>
                            <label class="text-[10px] font-black uppercase block mb-2">Tipe Auto Markup</label>
                            <select name="markup_type" class="w-full bg-white border-2 border-black py-3 px-2 font-black cursor-pointer shadow-brutal">
                                <option value="percent" <?= $markup_type == 'percent' ? 'selected' : '' ?>>Persen (%)</option>
                                <option value="nominal" <?= $markup_type == 'nominal' ? 'selected' : '' ?>>Nominal (Rp)</option>
                            </select>
                        </div>
                        <div><label class="text-[10px] font-black uppercase block mb-2">Jumlah Markup</label><input type="number" step="any" name="markup" value="<?= $markup_val ?>" class="w-full bg-white border-2 border-black py-3 px-4 font-black shadow-brutal"></div>
                    </div>
                    <div><label class="text-xs font-black uppercase block mb-2">Password Admin</label><input type="text" name="pass" value="<?= htmlspecialchars($set['admin_password'] ?? '') ?>" class="w-full bg-white border-2 border-black py-3 px-4 font-bold shadow-brutal text-sm"></div>
                    <div><label class="text-xs font-black uppercase block mb-2">API Key Premku</label><input type="text" name="api" value="<?= htmlspecialchars($set['api_key'] ?? '') ?>" class="w-full bg-white border-2 border-black py-3 px-4 text-xs font-black shadow-brutal"></div>
                </div>

                <div class="bg-blue-200 brutal-border shadow-brutal-lg p-6 md:p-8 space-y-5">
                    <h2 class="text-xl md:text-2xl font-black uppercase border-b-4 border-black pb-2">Koneksi WA & Pesan</h2>
                    
                    <div class="mb-6">
                        <p class="font-black text-xs uppercase mb-2">Manual Cron Trigger (Kalo Server Nyantol):</p>
                        <div class="flex gap-2">
                            <button type="button" onclick="runCron('depo')" class="flex-1 bg-neoYellow border-2 border-black px-2 py-2 font-black uppercase shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all text-[10px]"><i class="fa-solid fa-money-bill"></i> Cek Depo</button>
                            <button type="button" onclick="runCron('order')" class="flex-1 bg-neoGreen border-2 border-black px-2 py-2 font-black uppercase shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all text-[10px]"><i class="fa-solid fa-truck-fast"></i> Cek Order</button>
                        </div>
                    </div>


                    <button name="save_settings" class="w-full bg-black hover:bg-gray-800 text-white font-black py-4 border-2 border-black shadow-brutal transition-all uppercase tracking-widest mt-4">SIMPAN SEMUA SETTING 💾</button>
                </div>
            </div>
            </form>
        </div>

    </div>

    <?php if($alert): ?>
    <script> Swal.fire({ icon: '<?= $alert['type'] ?>', title: '<?= $alert['title'] ?>', text: `<?= $alert['text'] ?>`, confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-brutal'} }); </script>
    <?php endif; ?>

    <script>
        async function runCron(type) {
            let url = type === 'depo' ? 'cron_depo.php' : 'cron_ord.php?key=GANTI_DENGAN_SECRET_PANJANG_RANDOM';
            Swal.fire({ title: 'Jalanin Skrip...', allowOutsideClick: false, customClass: {popup: 'brutal-border rounded-none shadow-brutal'}, didOpen: () => { Swal.showLoading(); } });
            try {
                const response = await fetch(url);
                if (response.ok) {
                    Swal.fire({ icon: 'success', title: 'Berhasil', text: 'Perintah dieksekusi.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-brutal'} }).then(() => window.location.reload());
                } else throw new Error('Error');
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Gagal', text: 'File cron nggak ketemu bro.', confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-brutal'} });
            }
        }

        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('button[id^="tab-"]').forEach(btn => {
                btn.classList.remove('tab-active');
                btn.classList.replace('text-white', 'text-black');
                btn.classList.replace('bg-black', 'bg-white');
            });
            document.getElementById(tabId).classList.remove('hidden');
            let activeBtn = document.getElementById('tab-' + tabId);
            activeBtn.classList.add('tab-active');
            activeBtn.classList.replace('bg-white', 'bg-black');
            activeBtn.classList.replace('text-black', 'text-white');
            
            const url = new URL(window.location); url.searchParams.set('tab', tabId); window.history.pushState({}, '', url);
        }
        const urlParams = new URLSearchParams(window.location.search);
        switchTab(urlParams.get('tab') || 'orders');
    </script>
</body>
</html>