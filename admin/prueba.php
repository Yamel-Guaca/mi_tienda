<?php
echo "Hora del servidor: " . date("Y-m-d H:i:s") . "<br>";

date_default_timezone_set("America/Bogota");
echo "Hora ajustada a Bogotá: " . date("Y-m-d H:i:s");
?>
