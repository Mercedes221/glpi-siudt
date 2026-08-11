<?php
include('../../../inc/includes.php');

Html::header("SIUDT Dashboard", $_SERVER['PHP_SELF'], "helpdesk");

echo "<h1>Bienvenido al Sistema SIUDT</h1>";

echo "<div style='display:flex; gap:20px;'>";

echo "<div style='padding:20px; border:1px solid #ccc;'>Incidencias</div>";
echo "<div style='padding:20px; border:1px solid #ccc;'>Activos</div>";
echo "<div style='padding:20px; border:1px solid #ccc;'>Usuarios</div>";

echo "</div>";

Html::footer();