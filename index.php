<?php
require 'config.php';

// ============================================================
// 0. EMBEDDED AJAX UNTUK CEK KUPON (TANPA PERLU FILE BARU)
// ============================================================
if(isset($_POST['action']) && $_POST['action'] == 'check_coupon') {
    header('Content-Type: application/json');
    $code = mysqli_real_escape_string($conn, strtoupper(trim($_POST['code'])));
    $total_belanja = (double)$_POST['total'];
    
    $q = mysqli_query($conn, "SELECT * FROM coupons WHERE code='$code' AND status='active' AND expired_at >= CURDATE() AND quota > 0");
    $coupon = mysqli_fetch_assoc($q);
    
    if($coupon) {
        if($total_belanja >= $coupon['min_order']) {
            echo json_encode(['success' => true, 'data' => $coupon]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'Gagal bro! Minimal order buat kupon ini Rp ' . number_format($coupon['min_order'])]);
        }
    } else {
        echo json_encode(['success' => false, 'msg' => 'Kupon nggak valid, kuota habis, atau udah expired!']);
    }
    exit;
}

// ============================================================
// 1. PENGATURAN & LACAK PESANAN
// ============================================================
$res_settings = mysqli_query($conn, "SELECT * FROM settings WHERE setting_key IN ('web_name', 'nomor_admin', 'profit_markup', 'markup_type', 'web_logo')");
$settings = [];
while($s = mysqli_fetch_assoc($res_settings)) {
    $settings[$s['setting_key']] = $s['setting_value'];
}
$web_name = $settings['web_name'] ?? 'Sarang Digital';
$nomor_admin = $settings['nomor_admin'] ?? '';
$markup_val = (float)($settings['profit_markup'] ?? 10);
$markup_type = $settings['markup_type'] ?? 'percent'; 
$web_logo = $settings['web_logo'] ?? ''; 
$default_logo = 'https://cdn-icons-png.flaticon.com/512/3003/3003149.png';
$favicon = $web_logo ? $web_logo : $default_logo;
$og_image = $web_logo ? "https://".$_SERVER['HTTP_HOST']."/".$web_logo : $default_logo;

$error_msg = null;
if(isset($_GET['search_invoice'])) {
    $inv = mysqli_real_escape_string($conn, $_GET['search_invoice']);
    $q_inv = mysqli_query($conn, "SELECT trx_local FROM transactions WHERE trx_local = '$inv' OR target_wa = '$inv' ORDER BY id DESC LIMIT 1");
    $data = mysqli_fetch_assoc($q_inv);
    
    if($data) {
        header("Location: pay?trx=" . $data['trx_local']);
        exit;
    } else {
        $error_msg = "Waduh, pesanan ga ketemu. Cek lagi ID / WA lu bro!";
    }
}

// ============================================================
// 2. DATA HARGA LOKAL & HIT API PUSAT
// ============================================================
$local_prices = [];
$q_prices = mysqli_query($conn, "SELECT id, price_jual FROM services");
while($row = mysqli_fetch_assoc($q_prices)) {
    $local_prices[$row['id']] = (double)$row['price_jual'];
}

$res_api = requestAPI('products', []);
$library = [];
$categories = ['Streaming', 'Editing', 'Lainnya'];

$brand_keywords = [
    'capcut' => 'CapCut', 'alight' => 'Alight Motion', 'am ' => 'Alight Motion',
    'chatgpt' => 'ChatGPT', 'gpt' => 'ChatGPT', 'canva' => 'Canva',
    'netflix' => 'Netflix', 'viu' => 'Viu', 'prime' => 'Prime Video',
    'youtube' => 'YouTube', 'yt ' => 'YouTube', 'spotify' => 'Spotify',
    'vidio' => 'Vidio', 'iqiyi' => 'iQIYI', 'wetv' => 'WeTV',
    'hbo' => 'HBO', 'apple' => 'Apple Music', 'loklok' => 'Loklok',
    'discord' => 'Discord', 'nitro' => 'Discord', 'picsart' => 'PicsArt',
    'remini' => 'Remini', 'leonardo' => 'Leonardo', 'zoom' => 'Zoom', 'scribd' => 'Scribd'
];

if(isset($res_api['success']) && $res_api['success']) {
    foreach($res_api['products'] as $row) {
        $current_stock = (int)$row['stock'];
        if($current_stock < 1 || $row['status'] != 'available') continue;

        $id = $row['id'];
        $api_price = (double)$row['price'];
        
        $auto_price = ($markup_type == 'nominal') ? ($api_price + $markup_val) : ($api_price + ($api_price * ($markup_val / 100)));
        $price_jual = isset($local_prices[$id]) ? $local_prices[$id] : $auto_price;
        
        $api_image = !empty($row['image']) ? $row['image'] : 'https://via.placeholder.com/150/000000/FFFFFF?text=Produk';

        $name_lower = strtolower($row['name']);
        $brand_name = 'Lainnya';
        
        foreach($brand_keywords as $key => $val) {
            if(strpos($name_lower, $key) !== false) {
                $brand_name = $val;
                break;
            }
        }

        if($brand_name == 'Lainnya') {
            $first_word = explode(' ', $row['name'])[0];
            $brand_name = is_numeric($first_word) ? 'Digital' : ucfirst($first_word);
        }

        $target_cat = 'Lainnya';
        if(preg_match('/netflix|viu|vidio|iqiyi|prime|youtube|yt|spotify|hbo|apple|wetv|loklok/i', $name_lower)) $target_cat = 'Streaming';
        elseif(preg_match('/canva|capcut|alight|picsart|remini|adobe|am/i', $name_lower)) $target_cat = 'Editing';
        
        if(!isset($library[$brand_name])) {
            $library[$brand_name] = [
                'brand_name' => $brand_name, 'category' => $target_cat, 'image' => $api_image, 'min_price' => $price_jual, 'products' => [] 
            ];
        }
        
        if($price_jual < $library[$brand_name]['min_price']) { 
            $library[$brand_name]['min_price'] = $price_jual; 
        }

        $library[$brand_name]['products'][] = [
            'id' => $id, 'name' => $row['name'], 'description' => $row['description'] ?? 'Garansi penuh. Proses otomatis.', 'price_jual' => $price_jual, 'stock' => $current_stock
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $web_name ?> - Penyedia Premium Apps Termurah</title>
    
    <meta name="description" content="Beli akun premium termurah, aman, dan legal di <?= $web_name ?>. Tersedia Netflix, Canva, Spotify, YouTube Premium dll. Proses otomatis 24 jam!">
    <meta name="keywords" content="beli akun premium, jual akun premium, netflix murah, spotify premium, canva pro legal, youtube premium, top up murah, <?= strtolower($web_name) ?>, akun premium legal, akun premium bergaransi">
    <meta name="author" content="<?= $web_name ?>">
    <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/">

    <meta property="og:type" content="website">
    <meta property="og:url" content="https://<?= $_SERVER['HTTP_HOST'] ?>/">
    <meta property="og:title" content="<?= $web_name ?> - Solusi Akun Premium Murah & Cepat">
    <meta property="og:description" content="Layanan penyedia akun premium legal dan bergaransi. Sikat sekarang daks, proses otomatis tanpa nunggu lama!">
    <meta property="og:image" content="<?= $og_image ?>">
    <meta property="og:site_name" content="<?= $web_name ?>">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="https://<?= $_SERVER['HTTP_HOST'] ?>/">
    <meta name="twitter:title" content="<?= $web_name ?> - Pusat Akun Premium">
    <meta name="twitter:description" content="Beli akun premium favoritmu dengan harga miring. Garansi penuh & proses otomatis 24 Jam nonstop.">
    <meta name="twitter:image" content="<?= $og_image ?>">

    <meta name="theme-color" content="#ffde59">
    <link rel="icon" type="image/png" href="<?= $favicon ?>">

    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "WebSite",
      "name": "<?= $web_name ?>",
      "url": "https://<?= $_SERVER['HTTP_HOST'] ?>/",
      "description": "Layanan penyedia akun premium legal, murah, dan bergaransi. Proses instan otomatis 24 Jam.",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://<?= $_SERVER['HTTP_HOST'] ?>/?search_invoice={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    }
    </script>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['"Public Sans"', 'sans-serif'] },
                    colors: { neoBg: '#f4f4f0', neoDark: '#121212', neoYellow: '#ffde59', neoBlue: '#5e92f3', neoPink: '#ff90e8', neoGreen: '#00e676' },
                    boxShadow: {
                        'brutal': '4px 4px 0px 0px rgba(0,0,0,1)',
                        'brutal-dark': '4px 4px 0px 0px rgba(255,255,255,1)',
                        'brutal-lg': '8px 8px 0px 0px rgba(0,0,0,1)',
                        'brutal-lg-dark': '8px 8px 0px 0px rgba(255,255,255,1)',
                    }
                }
            }
        }
    </script>
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .brutal-border { border: 2px solid black; }
        .dark .brutal-border { border: 2px solid white; }
        .service-radio:checked + div { background-color: #ffde59; }
        .dark .service-radio:checked + div { background-color: #5e92f3; color: black; }
        .service-radio:checked + div .check-icon { display: block; }
    </style>
</head>
<body class="bg-neoBg dark:bg-neoDark text-black dark:text-white font-sans min-h-screen transition-colors duration-300 flex flex-col">

    <script>
        if (!('theme' in localStorage)) { localStorage.theme = 'light'; }
        if (localStorage.theme === 'dark' || (localStorage.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>

    <nav class="sticky top-0 z-40 bg-neoBg dark:bg-neoDark brutal-border border-x-0 border-t-0 transition-colors duration-300">
        <div class="max-w-7xl mx-auto px-4 md:px-8 h-16 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-2">
                <?php if($web_logo): ?>
                    <img src="<?= $web_logo ?>" alt="Logo" class="h-8 md:h-10 object-contain">
                <?php else: ?>
                    <div class="w-8 h-8 bg-neoYellow brutal-border flex items-center justify-center text-black font-black text-lg shadow-brutal"><i class="fa-solid fa-bolt"></i></div>
                <?php endif; ?>
                <span class="font-black text-lg md:text-xl tracking-tighter uppercase"><?= $web_name ?></span>
            </a>
            
            <form action="" method="GET" class="hidden md:flex relative group">
                <input type="text" name="search_invoice" placeholder="Lacak (ID/WA)..." 
                       class="bg-white dark:bg-zinc-800 brutal-border shadow-brutal dark:shadow-brutal-dark py-2 pl-3 pr-12 font-bold outline-none text-xs w-64 transition-all focus:translate-x-[2px] focus:translate-y-[2px] focus:shadow-none dark:focus:shadow-none placeholder-zinc-500">
                <button type="submit" class="absolute right-0 top-0 bottom-0 px-3 bg-neoYellow dark:bg-neoBlue brutal-border border-y-0 border-r-0 border-l-2 text-black hover:bg-black hover:text-white transition-colors focus:translate-x-[2px] focus:translate-y-[2px]">
                    <i class="fa-solid fa-search text-xs"></i>
                </button>
            </form>

            <div class="flex items-center gap-2">
                <button onclick="toggleTheme()" id="themeBtn" class="w-8 h-8 bg-neoPink brutal-border flex items-center justify-center text-black shadow-brutal hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all">
                    <i id="themeIcon" class="fa-solid fa-sun text-xs"></i>
                </button>
                <?php if($nomor_admin): ?>
                <a href="https://wa.me/<?= $nomor_admin ?>" target="_blank" class="hidden md:flex items-center gap-1.5 bg-emerald-400 brutal-border text-black px-3 py-1.5 font-black shadow-brutal hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all uppercase text-xs">
                    <i class="fa-brands fa-whatsapp text-sm"></i> Bantuan
                </a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main class="flex-1 pt-8 pb-16">
        <div class="max-w-7xl mx-auto px-4 md:px-8 text-center mb-10">
            <div class="inline-block bg-neoBlue brutal-border text-black px-3 py-1 font-black text-[10px] md:text-xs uppercase shadow-brutal mb-4 transform -rotate-2">
                ⚡ PUSAT LAYANAN DIGITAL 24 JAM
            </div>
            <h1 class="text-4xl md:text-6xl font-black mb-3 tracking-tighter uppercase leading-none">
                UPGRADE AKUN <br />
                <span class="bg-neoYellow dark:bg-neoBlue text-black px-2 mt-1 md:mt-2 inline-block brutal-border shadow-brutal transform rotate-1">JADI PREMIUM.</span>
            </h1>
            <p class="font-bold text-sm md:text-base max-w-2xl mx-auto opacity-80 mt-3">Akses fitur pro tanpa batas. Harga paling miring, garansi full, diproses otomatis!</p>
            
            <div class="md:hidden mt-6 px-2 flex gap-2">
                <form action="" method="GET" class="flex-1 flex relative">
                    <input type="text" name="search_invoice" placeholder="Cek ID/WA..." class="w-full bg-white dark:bg-zinc-800 brutal-border py-2 pl-3 pr-10 font-bold text-xs outline-none shadow-brutal placeholder-zinc-500">
                    <button type="submit" class="absolute right-0 top-0 bottom-0 px-3 bg-neoYellow dark:bg-neoBlue brutal-border border-y-0 border-r-0 border-l-2 text-black"><i class="fa-solid fa-search text-xs"></i></button>
                </form>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 md:px-8 mb-6">
            <div class="flex gap-2 overflow-x-auto no-scrollbar pb-3 px-1">
                <button onclick="filterCat('all')" data-cat="all" class="cat-btn active bg-black text-white dark:bg-white dark:text-black brutal-border shadow-brutal dark:shadow-brutal-dark whitespace-nowrap px-4 py-1.5 font-black uppercase text-xs md:text-sm transition-all hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none dark:hover:shadow-none">SEMUA</button>
                <?php foreach($categories as $cat): ?>
                <button onclick="filterCat('<?= $cat ?>')" data-cat="<?= $cat ?>" class="cat-btn bg-white dark:bg-zinc-800 brutal-border shadow-brutal dark:shadow-brutal-dark whitespace-nowrap px-4 py-1.5 font-black uppercase text-xs md:text-sm transition-all hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none dark:hover:shadow-none"><?= strtoupper($cat) ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="max-w-7xl mx-auto px-4 md:px-8 pb-10">
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 md:gap-4">
                <?php foreach($library as $key => $brand): 
                    $jsonItems = htmlspecialchars(json_encode($brand['products']), ENT_QUOTES, 'UTF-8');
                ?>
                <div class="card-item group cursor-pointer" data-cat="<?= $brand['category'] ?>" onclick="openProductModal('<?= $brand['brand_name'] ?>', '<?= $brand['image'] ?>', <?= $jsonItems ?>)">
                    <div class="bg-white dark:bg-zinc-900 brutal-border shadow-brutal dark:shadow-brutal-dark transition-all duration-200 hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none dark:hover:shadow-none h-full flex flex-col overflow-hidden">
                        
                        <div class="aspect-[4/3] p-3 flex items-center justify-center bg-gray-100 dark:bg-zinc-800 relative">
                            <img src="<?= $brand['image'] ?>" alt="<?= $brand['brand_name'] ?>" class="w-12 h-12 md:w-16 md:h-16 object-contain transform group-hover:scale-110 transition-transform">
                        </div>
                        
                        <div class="p-2 md:p-3 flex-1 flex flex-col justify-between bg-white dark:bg-zinc-900 border-t-2 border-black dark:border-white">
                            <div>
                                <h3 class="font-black text-xs md:text-sm uppercase truncate tracking-tight mb-0.5"><?= $brand['brand_name'] ?></h3>
                                <div class="text-[9px] md:text-[10px] font-bold text-gray-500 mb-2"><i class="fa-solid fa-layer-group"></i> <?= count($brand['products']) ?> Paket Tersedia</div>
                            </div>
                            <div class="bg-neoYellow dark:bg-neoBlue text-black w-full text-center py-1 brutal-border border-2 font-black text-[9px] md:text-[11px]">
                                Rp <?= number_format($brand['min_price']) ?>
                            </div>
                        </div>

                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <footer class="bg-white dark:bg-neoDark brutal-border border-x-0 border-b-0 mt-auto">
        <div class="max-w-7xl mx-auto px-4 md:px-8 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6 text-black dark:text-white">
                <div class="text-center md:text-left flex-1">
                    <div class="flex items-center justify-center md:justify-start gap-2 mb-1">
                        <?php if($web_logo): ?>
                            <img src="<?= $web_logo ?>" alt="Logo" class="h-6 object-contain">
                        <?php endif; ?>
                        <h2 class="text-2xl md:text-3xl font-black uppercase tracking-tighter"><?= $web_name ?></h2>
                    </div>
                    <p class="text-xs md:text-sm font-bold opacity-80 max-w-sm mx-auto md:mx-0 mt-1">Solusi cerdas buat lo yang mau hemat tapi tetep menikmati aplikasi favorit.</p>
                </div>
                <?php if($nomor_admin): ?>
                <div class="text-center md:text-right shrink-0">
                    <p class="font-black uppercase text-[10px] md:text-xs mb-1.5 tracking-widest">Ada Laporan?</p>
                    <a href="https://wa.me/<?= $nomor_admin ?>" target="_blank" class="inline-flex items-center gap-2 bg-emerald-400 brutal-border text-black px-4 py-2 font-black shadow-brutal hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all uppercase text-sm md:text-base">
                        <i class="fa-brands fa-whatsapp text-lg md:text-xl"></i> HUBUNGI CS
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="bg-black text-white py-3 text-center text-[10px] md:text-xs font-black uppercase tracking-widest">
            &copy; <?= date('Y') ?> <?= strtoupper($web_name) ?>. ALL RIGHTS RESERVED.
        </div>
    </footer>

    <div id="productModal" class="fixed inset-0 z-[60] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" id="modalBackdrop"></div>
        <div class="absolute inset-0 flex items-center justify-center p-3">
            <div class="bg-white dark:bg-neoDark brutal-border shadow-brutal-lg dark:shadow-brutal-lg-dark w-full max-w-lg flex flex-col max-h-[90vh]" id="modalPanel">
                
                <div class="p-3 md:p-4 border-b-2 border-black dark:border-white flex items-center justify-between bg-neoPink dark:bg-neoPink text-black">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-white brutal-border p-1">
                             <img id="modalImg" src="" class="w-full h-full object-contain">
                        </div>
                        <div>
                            <h2 id="modalTitle" class="text-base md:text-lg font-black uppercase tracking-tighter">Brand Name</h2>
                            <div class="text-[9px] font-bold uppercase bg-black text-white px-2 py-0.5 inline-block border border-black mt-0.5">PILIH PAKET</div>
                        </div>
                    </div>
                    <button onclick="closeModal()" class="w-8 h-8 bg-white brutal-border shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all flex items-center justify-center text-sm"><i class="fa-solid fa-times"></i></button>
                </div>

                <div class="p-3 md:p-5 overflow-y-auto custom-scrollbar flex-1">
                    <form id="buyForm" action="buy.php" method="POST">
                        <div class="space-y-2 md:space-y-3 mb-5" id="variantContainer"></div>

                        <div class="mb-5 flex gap-2">
                            <input type="text" id="couponInput" name="coupon_code" placeholder="Kode Kupon (Opsional)" class="w-full bg-white dark:bg-zinc-900 brutal-border py-2 px-3 font-bold outline-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] focus:translate-x-[2px] focus:translate-y-[2px] transition-all text-xs uppercase">
                            <button type="button" onclick="applyCoupon()" class="bg-neoGreen text-black brutal-border px-3 py-2 font-black text-[10px] md:text-xs uppercase shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all whitespace-nowrap">
                                TERAPKAN
                            </button>
                        </div>

                        <div class="bg-gray-100 dark:bg-zinc-800 brutal-border p-3 md:p-4 mb-5">
                            <div class="flex items-center justify-between mb-3">
                                <label class="font-black uppercase text-[10px] md:text-xs">Jumlah Akun <span class="block text-[9px] text-gray-500 font-bold normal-case mt-0.5" id="stockLabel">Stok: -</span></label>
                                <div class="flex items-center gap-1.5">
                                    <button type="button" onclick="updateQty(-1)" class="w-6 h-6 bg-white dark:bg-black brutal-border font-black text-xs shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] active:translate-x-[2px] active:translate-y-[2px] active:shadow-none">-</button>
                                    <input type="number" name="qty" id="qtyInput" value="1" min="1" readonly class="w-8 bg-transparent text-center font-black outline-none text-sm">
                                    <button type="button" onclick="updateQty(1)" class="w-6 h-6 bg-neoYellow dark:bg-neoBlue dark:text-black brutal-border font-black text-xs shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] active:translate-x-[2px] active:translate-y-[2px] active:shadow-none">+</button>
                                </div>
                            </div>
                            
                            <div>
                                <label class="font-black uppercase text-[10px] md:text-xs block mb-1.5">No. WhatsApp</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-2.5 font-black text-gray-500 text-xs">62</span>
                                    <input type="number" name="wa" required placeholder="812xxxx" class="w-full bg-white dark:bg-zinc-900 brutal-border py-2 pl-8 pr-3 font-bold outline-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] focus:translate-x-[2px] focus:translate-y-[2px] focus:shadow-none dark:focus:shadow-none transition-all text-xs md:text-sm">
                                </div>
                            </div>
                        </div>

                        <div class="mt-3 pt-2 flex items-center justify-between">
                            <div>
                                <div class="text-[9px] font-black uppercase mb-0.5">TOTAL BAYAR</div>
                                <div class="text-lg md:text-xl font-black" id="totalPrice">Rp 0</div>
                            </div>
                            <button type="submit" id="btnBeli" disabled class="bg-neoBlue dark:bg-neoYellow text-black px-4 md:px-6 py-2.5 brutal-border shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] font-black uppercase hover:translate-x-[2px] hover:translate-y-[2px] hover:shadow-none transition-all disabled:opacity-50 disabled:pointer-events-none text-xs md:text-sm">
                                CHECKOUT <i class="fa-solid fa-bolt ml-1"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if($error_msg): ?>
    <script>
        Swal.fire({ icon: 'error', title: 'Error!', text: '<?= $error_msg ?>', confirmButtonColor: '#000', customClass: { popup: 'brutal-border rounded-none shadow-brutal' } });
    </script>
    <?php endif; ?>

    <script>
        function updateThemeIcon() {
            const icon = document.getElementById('themeIcon');
            if (!icon) return;
            if (localStorage.theme === 'dark') {
                icon.className = 'fa-solid fa-moon text-xs';
            } else if (localStorage.theme === 'system') {
                icon.className = 'fa-solid fa-desktop text-xs';
            } else {
                icon.className = 'fa-solid fa-sun text-xs';
            }
        }

        function toggleTheme() {
            if (localStorage.theme === 'light') {
                localStorage.theme = 'dark';
            } else if (localStorage.theme === 'dark') {
                localStorage.theme = 'system';
            } else {
                localStorage.theme = 'light';
            }
            
            if (localStorage.theme === 'dark' || (localStorage.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
            updateThemeIcon();
        }
        
        document.addEventListener('DOMContentLoaded', updateThemeIcon);

        const modal = document.getElementById('productModal');
        const variantContainer = document.getElementById('variantContainer');
        const totalPriceEl = document.getElementById('totalPrice');
        const stockLabel = document.getElementById('stockLabel');
        const qtyInput = document.getElementById('qtyInput');
        const btnBeli = document.getElementById('btnBeli');
        
        let selectedPrice = 0; let maxStock = 0;
        let activeCoupon = null;

        function openProductModal(brandName, brandImg, products) {
            document.getElementById('modalTitle').innerText = brandName;
            document.getElementById('modalImg').src = brandImg;
            variantContainer.innerHTML = ''; totalPriceEl.innerText = 'Rp 0';
            stockLabel.innerText = 'Stok: -'; qtyInput.value = 1; selectedPrice = 0; maxStock = 0; btnBeli.disabled = true;
            document.getElementById('couponInput').value = ''; activeCoupon = null;

            products.forEach(item => {
                const priceFmt = new Intl.NumberFormat('id-ID').format(item.price_jual);
                const html = `
                <label class="cursor-pointer group block relative">
                    <input type="radio" name="sid" value="${item.id}" class="peer sr-only service-radio" data-price="${item.price_jual}" data-stock="${item.stock}" onchange="selectVariant(this)">
                    <div class="p-3 bg-white dark:bg-zinc-900 brutal-border shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] transition-all relative">
                        <div class="flex items-start justify-between mb-1">
                            <div class="pr-5">
                                <div class="font-black text-xs md:text-sm uppercase">${item.name}</div>
                                <div class="text-[9px] md:text-[10px] font-bold text-gray-500 dark:text-gray-400 mt-1">${item.description}</div>
                            </div>
                            <div class="w-4 h-4 md:w-5 md:h-5 border-2 border-black dark:border-white flex items-center justify-center bg-white absolute right-3 top-3">
                                <i class="fa-solid fa-check text-black text-[9px] hidden check-icon"></i>
                            </div>
                        </div>
                        <div class="text-xs md:text-sm font-black mt-2">Rp${priceFmt}</div>
                    </div>
                </label>`;
                variantContainer.innerHTML += html;
            });

            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.add('hidden'); document.body.style.overflow = '';
        }

        function selectVariant(radio) {
            selectedPrice = parseInt(radio.getAttribute('data-price'));
            maxStock = parseInt(radio.getAttribute('data-stock'));
            qtyInput.value = 1; stockLabel.innerText = 'Stok: ' + maxStock;
            
            if(activeCoupon) {
                document.getElementById('couponInput').value = '';
                activeCoupon = null;
            }
            
            calculateTotal(); btnBeli.disabled = false;
        }

        function updateQty(val) {
            if(selectedPrice === 0) return;
            let current = parseInt(qtyInput.value); let next = current + val;
            if(next < 1) next = 1;
            if(next > maxStock) { next = maxStock; Swal.fire({ icon: 'warning', title: 'Stok Habis', text: 'Sisa ' + maxStock, toast: true, position: 'top', showConfirmButton: false, timer: 2000, customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'} }); }
            qtyInput.value = next; calculateTotal();
        }

        async function applyCoupon() {
            const code = document.getElementById('couponInput').value.trim();
            const subtotal = selectedPrice * parseInt(qtyInput.value);
            
            if(!code || selectedPrice === 0) {
                activeCoupon = null; calculateTotal(); return;
            }

            const fd = new FormData();
            fd.append('action', 'check_coupon');
            fd.append('code', code);
            fd.append('total', subtotal);

            Swal.fire({ title: 'Mengecek...', allowOutsideClick: false, showConfirmButton: false, didOpen: () => { Swal.showLoading(); } });

            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const data = await res.json();
                
                if(data.success) {
                    activeCoupon = data.data;
                    Swal.fire({icon:'success', title:'Valid!', text:'Diskon diterapkan.', toast:true, position:'top-end', showConfirmButton:false, timer:2000, customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'}});
                    calculateTotal();
                } else {
                    activeCoupon = null;
                    calculateTotal();
                    Swal.fire({icon:'error', title:'Gagal', text:data.msg, toast:true, position:'top-end', showConfirmButton:false, timer:3000, customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'}});
                }
            } catch(e) {
                Swal.close();
            }
        }

        function calculateTotal() {
            let subtotal = selectedPrice * parseInt(qtyInput.value);
            let discount = 0;
            
            if(activeCoupon) {
                if(subtotal >= parseFloat(activeCoupon.min_order)) {
                    discount = subtotal * (parseFloat(activeCoupon.discount_percent) / 100);
                    if(discount > parseFloat(activeCoupon.max_discount)) discount = parseFloat(activeCoupon.max_discount);
                } else {
                    activeCoupon = null; 
                    document.getElementById('couponInput').value = '';
                    Swal.fire({icon:'warning', title:'Batal', text:'Order kurang dari minimal!', toast:true, position:'top-end', showConfirmButton:false, timer:3000, customClass: {popup: 'brutal-border rounded-none shadow-[2px_2px_0px_0px_rgba(0,0,0,1)]'}});
                }
            }
            
            let finalTotal = subtotal - discount;
            let html = `Rp ${new Intl.NumberFormat('id-ID').format(finalTotal)}`;
            
            if(discount > 0) {
                html = `<span class="text-xs text-gray-400 line-through mr-1.5">Rp ${new Intl.NumberFormat('id-ID').format(subtotal)}</span>` + html;
            }
            
            totalPriceEl.innerHTML = html;
        }

        function filterCat(cat) {
            document.querySelectorAll('.cat-btn').forEach(btn => {
                const active = btn.dataset.cat === cat;
                if(active) {
                    btn.className = `cat-btn active bg-black text-white dark:bg-white dark:text-black brutal-border shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] whitespace-nowrap px-4 py-1.5 font-black uppercase text-xs md:text-sm transition-all`;
                } else {
                    btn.className = `cat-btn bg-white dark:bg-zinc-800 brutal-border shadow-[2px_2px_0px_0px_rgba(0,0,0,1)] dark:shadow-[2px_2px_0px_0px_rgba(255,255,255,1)] whitespace-nowrap px-4 py-1.5 font-black uppercase text-xs md:text-sm transition-all`;
                }
            });
            document.querySelectorAll('.card-item').forEach(card => {
                card.style.display = (cat === 'all' || card.dataset.cat === cat) ? 'block' : 'none';
            });
        }

        document.getElementById('buyForm').addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({ title: 'Tunggu Bro...', allowOutsideClick: false, showConfirmButton: false, customClass: {popup: 'brutal-border rounded-none shadow-brutal'}, didOpen: () => { Swal.showLoading(); } });
            fetch('buy.php', { method: 'POST', body: new FormData(this) })
            .then(res => res.json()).then(data => {
                if(data.success) window.location.href = 'pay?trx=' + data.trx;
                else Swal.fire({ icon: 'error', title: 'Gagal', text: data.message, confirmButtonColor: '#000', customClass: {popup: 'brutal-border rounded-none shadow-brutal'} });
            }).catch(err => Swal.fire({ icon: 'error', title: 'Error', text: 'Koneksi error.', customClass: {popup: 'brutal-border rounded-none shadow-brutal'} }));
        });
    </script>
</body>
</html>