<?php
session_start();

// Hapus semua session
session_unset();
session_destroy();

// Balikin ke halaman admin (nanti bakal minta password lagi)
header("Location: admin.php");
exit;
?>