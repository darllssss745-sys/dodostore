-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 08 Agu 2026 pada 20.41
-- Versi server: 10.6.27-MariaDB
-- Versi PHP: 8.4.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `maubuatw_sar`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `coupons`
--

CREATE TABLE `coupons` (
  `id` int(11) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `discount_percent` int(11) DEFAULT NULL,
  `max_discount` double DEFAULT NULL,
  `min_order` double DEFAULT NULL,
  `quota` int(11) DEFAULT NULL,
  `expired_at` date DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data untuk tabel `coupons`
--


-- --------------------------------------------------------

--
-- Struktur dari tabel `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `category` varchar(100) DEFAULT 'General',
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price_modal` double NOT NULL,
  `price_jual` double NOT NULL,
  `status` enum('available','unavailable','','') DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data untuk tabel `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'api_key', 'MASUPINAPIKEYELUH'),
(2, 'nomor_admin', '6281234567'),
(3, 'admin_password', 'admin123'),
(4, 'profit_markup', '35'),
(5, 'web_name', 'MyWeb'),
(6, 'web_logo', 'assets/logo_1788792542.png'),
(7, 'wa_api_key', 'xxxx'),
(8, 'wa_sender', 'xxxxx'),
(9, 'wa_template_order', '🛒 *{web_name}*\n━━━━━━━━━━━━━━━━━━━\n🎉 *Pesanan Berhasil!* 🎉\n\n📋 *Detail:*\n🆔 ID : *{trx}*\n🛍️ Produk : *{prod_name}*\n🔢 Jumlah : *{qty}*\n💰 Total : *Rp {total}*\n\n⏳ *Status:* Menunggu Pembayaran\n━━━━━━━━━━━━━━━━━━━\n💡 Data akun dikirim otomatis setelah bayar daks!'),
(10, 'wa_template_success', '🎉 *{web_name}*\n━━━━━━━━━━━━━━━━━━━\n✅ *Transaksi Berhasil!*\n\n🆔 ID : *{trx}*\n📦 Detail akun daks:\n\n{akun_txt}\n━━━━━━━━━━━━━━━━━━━\n🙏 Makasih daks udah belanja!'),
(11, 'markup_type', 'percent'),
(12, 'msg_pending', '⚡ *{web_name}* ⚡\r\n━━━━━━━━━━━━━━━━━━━\r\nHalo! Pesanan kamu telah berhasil dibuat dan sedang menunggu pembayaran.\r\n\r\n📋 *Ringkasan Order:*\r\n▪️ ID Order : *{trx}*\r\n▪️ Layanan : *{produk}*\r\n▪️ Total Tagihan : *Rp {total}*\r\n\r\n🔗 *Link Invoice & Pembayaran:*\r\nhttps://sarangdigital.biz.id/pay?trx={trx}\r\n\r\nYuk, segera selesaikan pembayaranmu agar sistem kami bisa langsung memproses pesananmu dalam hitungan detik! 🚀\r\n━━━━━━━━━━━━━━━━━━━\r\nTerima kasih!'),
(13, 'msg_success', '🎉 *{web_name}* 🎉\r\n━━━━━━━━━━━━━━━━━━━\r\nSelamat! Pembayaran untuk order *{trx}* telah kami terima dan pesanan berhasil diproses. ✅\r\n\r\n📦 *Detail Data Akun:*\r\n{akun}\r\n\r\n🔗 *Simpan link invoice kamu:*\r\nhttps://sarangdigital.biz.id/pay?trx={trx}\r\n━━━━━━━━━━━━━━━━━━━\r\nTerima kasih sudah mempercayakan kebutuhan digitalmu di {web_name}! Ditunggu orderan selanjutnya 🔥'),
(14, 'msg_failed', '❌ *{web_name}* ❌\r\n━━━━━━━━━━━━━━━━━━━\r\nMohon maaf, transaksi dengan ID *{trx}* gagal diproses.\r\n\r\n📌 *Alasan:* {alasan}\r\n\r\nJika kamu sudah melakukan pembayaran, silakan hubungi Customer Service kami dengan melampirkan bukti transfer untuk proses refund atau penggantian pesanan.\r\n━━━━━━━━━━━━━━━━━━━\r\nTerima kasih atas pengertiannya.');

-- --------------------------------------------------------

--
-- Struktur dari tabel `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `trx_local` varchar(50) NOT NULL,
  `inv_deposit` varchar(50) DEFAULT NULL,
  `inv_order` varchar(50) DEFAULT NULL,
  `service_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `target_wa` varchar(25) NOT NULL,
  `amount_pay` double NOT NULL,
  `coupon_used` varchar(50) DEFAULT NULL,
  `status` enum('WAITING','PAID','PROCESSING','COMPLETED','FAILED') DEFAULT 'WAITING',
  `account_data` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data untuk tabel `transactions`
--


--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `coupons`
--
ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indeks untuk tabel `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indeks untuk tabel `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `coupons`
--
ALTER TABLE `coupons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT untuk tabel `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=165;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
