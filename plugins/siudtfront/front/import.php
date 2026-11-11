<?php

include('../../../inc/includes.php');

Html::header('Importar activos desde CSV', $_SERVER['PHP_SELF'], 'tools');

function siudtCsvImportEscape(string $value): string
{
    return htmlescape($value);
}

function siudtCsvImportAssetSelect(?string $selected): void
{
    $types = PluginSiudtfrontCsvimport::getAssetTypes();
    $available = [];
    foreach ($types as $type) {
        $item = getItemForItemtype($type);
        if ($item instanceof CommonDBTM && $item->canCreate()) {
            $available[] = $type;
        }
    }
    Dropdown::showItemTypes('itemtype', $available, [
        'value' => $selected ?? '',
        'display_emptychoice' => true,
        'emptylabel' => 'Selecciona un tipo de activo',
    ]);
}

function siudtCsvImportRenderMapping(array $draft, string $itemtype): void
{
    $fields = PluginSiudtfrontCsvimport::getImportableFields($itemtype);
    echo '<h2>Relaciona las columnas del CSV con los campos de GLPI</h2>';
    echo '<p>Las listas relacionadas se buscan por su nombre dentro de la entidad activa. '
        . 'Si el valor no existe o es ambiguo, esa fila se marcará con error.</p>';
    echo '<form method="post" action="">';
    echo '<input type="hidden" name="action" value="preview">';
    echo '<input type="hidden" name="itemtype" value="' . siudtCsvImportEscape($itemtype) . '">';
    echo '<table class="tab_cadre_fixehov"><thead><tr><th>Columna CSV</th><th>Campo GLPI</th></tr></thead><tbody>';

    foreach ($draft['headers'] as $index => $header) {
        echo '<tr><td>' . siudtCsvImportEscape($header) . '</td><td>';
        echo '<select name="mapping[' . (int) $index . ']" class="form-select">';
        echo '<option value="">No importar</option>';
        foreach ($fields as $field => $label) {
            echo '<option value="' . siudtCsvImportEscape($field) . '">'
                . siudtCsvImportEscape($label) . '</option>';
        }
        echo '</select></td></tr>';
    }

    echo '</tbody></table>';
    echo '<button type="submit" class="btn btn-primary">Validar y mostrar vista previa</button>';
    echo '</form>';
}

function siudtCsvImportRenderPreview(array $rows, string $itemtype): void
{
    $valid = count(array_filter($rows, static fn($row): bool => $row['errors'] === []));
    echo '<h2>Vista previa</h2>';
    echo '<p>' . (int) $valid . ' filas válidas de ' . count($rows)
        . '. Las filas con error se omitirán; no se crearán valores nuevos en los diccionarios.</p>';
    echo '<table class="tab_cadre_fixehov"><thead><tr><th>Fila</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $errors = $row['errors'];
        echo '<tr><td>' . (int) $row['line'] . '</td><td>'
            . ($errors === [] ? 'Válida' : 'Error')
            . '</td><td>' . siudtCsvImportEscape(implode(' ', $errors)) . '</td></tr>';
    }
    echo '</tbody></table>';

    if ($valid > 0) {
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="confirm">';
        echo '<input type="hidden" name="itemtype" value="' . siudtCsvImportEscape($itemtype) . '">';
        echo '<button type="submit" class="btn btn-primary">Importar ' . $valid . ' filas válidas</button>';
        echo '</form>';
    }
}

$message = '';
$draft = $_SESSION['plugin_siudtfront_csv_import'] ?? null;
$selectedItemtype = isset($_POST['itemtype']) && is_string($_POST['itemtype'])
    ? $_POST['itemtype']
    : null;
$selectedItemtype ??= is_array($draft) && isset($draft['itemtype'])
    ? (string) $draft['itemtype']
    : null;
$action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
$previewRows = null;

try {
    if ($action === 'upload') {
        unset($_SESSION['plugin_siudtfront_csv_import']);
        $draft = null;
        $selectedItemtype = (string) ($_POST['itemtype'] ?? '');
        PluginSiudtfrontCsvimport::assertAllowedItemtype($selectedItemtype);
        $file = $_FILES['csv_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecciona un archivo CSV válido para subir.');
        }
        if (($file['size'] ?? 0) > 2097152 || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new RuntimeException('El archivo no es una carga válida o supera 2 MB.');
        }

        $parsed = PluginSiudtfrontCsvimport::readCsv($file['tmp_name']);
        $draft = [
            'itemtype' => $selectedItemtype,
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
        ];
        $_SESSION['plugin_siudtfront_csv_import'] = $draft;
    } elseif ($action === 'preview' || $action === 'confirm') {
        if (!is_array($draft) || !isset($draft['itemtype'], $draft['headers'], $draft['rows'])) {
            throw new RuntimeException('La sesión de importación expiró. Vuelve a seleccionar el CSV.');
        }
        $selectedItemtype = (string) $draft['itemtype'];
        PluginSiudtfrontCsvimport::assertAllowedItemtype($selectedItemtype);
        if ($action === 'preview') {
            $mapping = PluginSiudtfrontCsvimport::validateMapping(
                $selectedItemtype,
                is_array($_POST['mapping'] ?? null) ? $_POST['mapping'] : [],
                count($draft['headers'])
            );
            $draft['mapping'] = $mapping;
            $_SESSION['plugin_siudtfront_csv_import'] = $draft;
        } else {
            $mapping = $draft['mapping'] ?? [];
            $mapping = PluginSiudtfrontCsvimport::validateMapping(
                $selectedItemtype,
                is_array($mapping) ? $mapping : [],
                count($draft['headers'])
            );
        }
        $previewRows = PluginSiudtfrontCsvimport::prepareRows(
            $selectedItemtype,
            $draft,
            $mapping
        );

        if ($action === 'confirm') {
            $result = PluginSiudtfrontCsvimport::importRows($selectedItemtype, $previewRows);
            unset($_SESSION['plugin_siudtfront_csv_import']);
            $draft = null;
            $message = 'Importación terminada: ' . $result['created'] . ' activos creados.';
            foreach ($result['errors'] as $error) {
                $message .= ' Fila ' . $error['line'] . ': ' . $error['message'];
            }
        }
    }
} catch (Throwable $exception) {
    $message = $exception->getMessage();
}

if ($message !== '') {
    echo '<div class="alert alert-info">' . siudtCsvImportEscape($message) . '</div>';
}

echo '<div class="center"><h1>Importar activos desde CSV</h1>';
echo '<p>Elige el tipo de activo, carga el archivo, relaciona sus columnas y revisa la vista previa antes de importar.</p>';

echo '<form method="post" enctype="multipart/form-data" action="" class="mb-3">';
echo '<input type="hidden" name="action" value="upload">';
echo '<div class="mb-3"><label for="itemtype" class="form-label">Tipo de activo</label>';
siudtCsvImportAssetSelect($selectedItemtype);
echo '</div><div class="mb-3"><label for="csv_file" class="form-label">Archivo CSV (máximo 2 MB y 5,000 filas)</label>';
echo '<input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required class="form-control"></div>';
echo '<button type="submit" class="btn btn-primary">Cargar CSV</button></form>';

if (is_array($draft) && isset($draft['headers'], $draft['rows'], $draft['itemtype'])) {
    if (is_array($previewRows)) {
        siudtCsvImportRenderPreview($previewRows, (string) $draft['itemtype']);
    } else {
        echo '<p>Archivo listo: ' . count($draft['rows']) . ' filas.</p>';
        siudtCsvImportRenderMapping($draft, (string) $draft['itemtype']);
    }
}

echo '</div>';
Html::footer();
