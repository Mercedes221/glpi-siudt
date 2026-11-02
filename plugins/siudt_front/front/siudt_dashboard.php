<?php

include('../../../inc/includes.php');

if (!PluginSiudtFront::canView()) {
    Html::displayRightError();
}

$page_url = $_SERVER['PHP_SELF'];
$message = null;
$preview = null;
$import_result = null;
$action = $_POST['action'] ?? '';
$escape = static function ($value) {
    return htmlentities((string) $value, ENT_QUOTES, 'UTF-8');
};

if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="plantilla_activos_siudt.csv"');
    $output = fopen('php://output', 'wb');
    foreach (PluginSiudtFrontImporter::getTemplate() as $record) {
        fputcsv($output, $record, ';', '"', '\\');
    }
    fclose($output);
    exit;
}

if ($action !== '') {
    Session::checkCSRF($_POST);

    if ($action === 'preview') {
        unset($_SESSION[PluginSiudtFrontImporter::PREVIEW_SESSION_KEY]);
        $upload = $_FILES['csv_file'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $upload_error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
                $message = __('El archivo excede el tamaño permitido por el servidor.');
            } elseif ($upload_error === UPLOAD_ERR_PARTIAL) {
                $message = __('El archivo se recibió parcialmente. Intenta cargarlo de nuevo.');
            } elseif ($upload_error === UPLOAD_ERR_NO_FILE) {
                $message = __('Selecciona un archivo CSV.');
            } else {
                $message = __('No fue posible recibir el archivo CSV.');
            }
        } elseif ((int) $upload['size'] > PluginSiudtFrontImporter::MAX_FILE_SIZE) {
            $message = __('El archivo excede el límite de 5 MB.');
        } elseif (strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $message = __('El archivo debe tener la extensión .csv.');
        } elseif (!isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            $message = __('No se pudo validar el archivo recibido.');
        } else {
            $parsed = PluginSiudtFrontImporter::parseCsv($upload['tmp_name']);
            if ($parsed['error'] !== null) {
                $message = $parsed['error'];
            } else {
                $preview = PluginSiudtFrontImporter::validateRows($parsed['rows']);
                $preview_id = bin2hex(random_bytes(16));
                $_SESSION[PluginSiudtFrontImporter::PREVIEW_SESSION_KEY] = [
                    'id'         => $preview_id,
                    'created_at' => time(),
                    'rows'       => $preview,
                ];
            }
        }
    } elseif ($action === 'import') {
        $stored_preview = $_SESSION[PluginSiudtFrontImporter::PREVIEW_SESSION_KEY] ?? null;
        $preview_id = $_POST['preview_id'] ?? '';
        if (
            !is_array($stored_preview)
            || !isset($stored_preview['id'], $stored_preview['created_at'], $stored_preview['rows'])
            || !hash_equals($stored_preview['id'], $preview_id)
            || time() - (int) $stored_preview['created_at'] > 1800
        ) {
            unset($_SESSION[PluginSiudtFrontImporter::PREVIEW_SESSION_KEY]);
            $message = __('La vista previa venció o ya fue utilizada. Carga el archivo nuevamente.');
        } else {
            $import_result = PluginSiudtFrontImporter::importRows($stored_preview['rows']);
            unset($_SESSION[PluginSiudtFrontImporter::PREVIEW_SESSION_KEY]);
        }
    } else {
        $message = __('La acción solicitada no es válida.');
    }
}

Html::header(__('Importador de activos SIUDT'), $page_url, 'tools', 'PluginSiudtFront');

echo "<div class='container-fluid'>";
echo "<h1>" . $escape(__('Importación de activos SIUDT')) . "</h1>";
echo "<p>" . $escape(__('Carga computadoras, monitores, impresoras y periféricos desde un CSV. Se requiere nombre, tipo y número de serie; el número de serie se usa para detectar duplicados entre los tipos admitidos.')) . "</p>";

if ($message !== null) {
    echo "<div class='alert alert-warning' role='alert'>" . $escape($message) . "</div>";
}

if (is_array($import_result)) {
    $imported_count = (int) $import_result['imported'];
    echo "<div class='alert alert-info' role='status'>";
    echo $escape(sprintf(__('Se importaron %d activos.'), $imported_count));
    echo "</div>";
    $preview = $import_result['rows'];
}

echo "<section class='card mb-3'><div class='card-body'>";
echo "<h2>" . $escape(__('1. Descargar plantilla CSV')) . "</h2>";
echo "<p>" . $escape(__('La plantilla incluye los encabezados normalizados y un registro de ejemplo que puedes reemplazar. Acepta archivos delimitados por punto y coma o coma, en UTF-8.')) . "</p>";
echo "<a class='btn btn-secondary' href='" . $escape($page_url . '?download_template=1') . "'>";
echo $escape(__('Descargar plantilla'));
echo "</a>";
echo "</div></section>";

echo "<section class='card mb-3'><div class='card-body'>";
echo "<h2>" . $escape(__('2. Validar archivo')) . "</h2>";
echo "<form method='post' enctype='multipart/form-data' action='" . $escape($page_url) . "'>";
echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
echo "<input type='hidden' name='action' value='preview'>";
echo "<input type='hidden' name='MAX_FILE_SIZE' value='" . PluginSiudtFrontImporter::MAX_FILE_SIZE . "'>";
echo "<div class='mb-3'><label class='form-label' for='csv_file'>" . $escape(__('Archivo CSV (máximo 5 MB)')) . "</label>";
echo "<input class='form-control' id='csv_file' type='file' name='csv_file' accept='.csv,text/csv' required></div>";
echo "<button class='btn btn-primary' type='submit'>" . $escape(__('Validar y mostrar vista previa')) . "</button>";
echo "</form>";
echo "</div></section>";

if (is_array($preview)) {
    $valid_count = 0;
    foreach ($preview as $row) {
        if (count($row['errors']) === 0) {
            $valid_count++;
        }
    }

    echo "<section class='card mb-3'><div class='card-body'>";
    echo "<h2>" . $escape(__('3. Revisar e importar')) . "</h2>";
    echo "<p>" . $escape(sprintf(
        __('Filas válidas: %1$d de %2$d. La importación se habilita únicamente cuando todas las filas son válidas.'),
        $valid_count,
        count($preview)
    )) . "</p>";
    echo "<div class='table-responsive'><table class='table table-striped'>";
    echo "<thead><tr><th>" . $escape(__('Fila')) . "</th><th>" . $escape(__('Tipo')) . "</th>";
    echo "<th>" . $escape(__('Nombre')) . "</th><th>" . $escape(__('Número de serie')) . "</th>";
    echo "<th>" . $escape(__('Resultado')) . "</th></tr></thead><tbody>";

    foreach ($preview as $row) {
        $data = $row['data'];
        $errors = $row['errors'];
        echo '<tr>';
        echo '<td>' . (int) $row['line'] . '</td>';
        echo '<td>' . $escape($data['tipo'] ?? '') . '</td>';
        echo '<td>' . $escape($data['nombre'] ?? '') . '</td>';
        echo '<td>' . $escape($data['numero_serie'] ?? '') . '</td>';
        echo '<td>';
        if (!empty($row['imported'])) {
            echo $escape(__('Importado'));
        } elseif (count($errors) > 0) {
            echo $escape(implode(' ', $errors));
        } else {
            echo $escape(__('Lista para importar'));
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';

    if ($import_result === null && $valid_count === count($preview)) {
        $stored_preview = $_SESSION[PluginSiudtFrontImporter::PREVIEW_SESSION_KEY] ?? [];
        echo "<form method='post' action='" . $escape($page_url) . "'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo "<input type='hidden' name='action' value='import'>";
        echo "<input type='hidden' name='preview_id' value='" . $escape($stored_preview['id'] ?? '') . "'>";
        echo "<button class='btn btn-success' type='submit'>" . $escape(__('Importar activos válidos')) . "</button>";
        echo "</form>";
    }
    echo "</div></section>";
}

echo "</div>";
Html::footer();
