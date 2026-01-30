<?php
session_start();
session_unset();
session_destroy();

header("Location: /mi_tienda/admin/login.php");
exit;
