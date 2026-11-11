<?php

class PluginSiudtfrontCsvimport extends CommonGLPI
{
    private const MAX_FILE_SIZE = 2097152;
    private const MAX_ROWS = 5000;
    private const MAX_COLUMNS = 100;

    public static function getTypeName($nb = 0)
    {
        return 'Importar activos (CSV)';
    }

    public static function getIcon()
    {
        return 'ti ti-file-import';
    }

    public static function getMenuContent(): array
    {
        if (!self::canImportAnyAsset()) {
            return [];
        }

        return [
            'title' => self::getTypeName(),
            'page' => '/plugins/siudtfront/front/import.php',
            'icon' => self::getIcon(),
        ];
    }

    public static function getAssetTypes(): array
    {
        global $CFG_GLPI;

        $types = [];
        foreach ($CFG_GLPI['asset_types'] ?? [] as $type) {
            if (
                is_string($type)
                && class_exists($type)
                && is_subclass_of($type, CommonDBTM::class)
            ) {
                $types[] = $type;
            }
        }

        return $types;
    }

    public static function canImportAnyAsset(): bool
    {
        foreach (self::getAssetTypes() as $type) {
            $item = getItemForItemtype($type);
            if ($item instanceof CommonDBTM && $item->canCreate()) {
                return true;
            }
        }

        return false;
    }

    public static function getImportableFields(string $itemtype): array
    {
        global $DB;

        self::assertAllowedItemtype($itemtype);
        $table = $itemtype::getTable();
        $fields = [];

        foreach (Search::getOptions($itemtype) as $option) {
            $field = $option['linkfield'] ?? null;
            if (
                !is_string($field)
                || in_array($field, [
                    'id',
                    'entities_id',
                    'is_deleted',
                    'is_template',
                    'is_recursive',
                    'date_creation',
                    'date_mod',
                ], true)
                || !$DB->fieldExists($table, $field)
            ) {
                continue;
            }

            $label = (string) ($option['name'] ?? $field);
            if (isForeignKeyField($field)) {
                $label .= ' (lista relacionada)';
            }
            $fields[$field] = $label;
        }

        return $fields;
    }

    public static function assertAllowedItemtype(string $itemtype): void
    {
        if (!in_array($itemtype, self::getAssetTypes(), true)) {
            throw new InvalidArgumentException('El tipo seleccionado no es un activo importable.');
        }

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM || !$item->canCreate()) {
            throw new RuntimeException('Tu perfil no tiene permiso para crear este tipo de activo.');
        }
    }

    public static function readCsv(string $path): array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > self::MAX_FILE_SIZE) {
            throw new RuntimeException('El archivo no existe, no se puede leer o supera 2 MB.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                throw new RuntimeException('El CSV está vacío.');
            }

            $delimiter = self::detectDelimiter($firstLine);
            rewind($handle);
            $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
            if ($headers === false) {
                throw new RuntimeException('No se pudieron leer los encabezados del CSV.');
            }

            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? '';
            $normalizedHeaders = [];
            foreach ($headers as $index => $header) {
                $header = trim((string) $header);
                $key = mb_strtolower($header, 'UTF-8');
                if ($key === '' || isset($normalizedHeaders[$key])) {
                    throw new RuntimeException('Los encabezados deben tener nombre y ser únicos.');
                }
                $normalizedHeaders[$key] = $index;
                $headers[$index] = $header;
            }
            if (count($headers) > self::MAX_COLUMNS) {
                throw new RuntimeException('El CSV supera el límite de 100 columnas.');
            }

            $rows = [];
            $lineNumber = 1;
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $lineNumber++;
                if ($row === [null] || $row === []) {
                    continue;
                }
                if (count($row) !== count($headers)) {
                    throw new RuntimeException(
                        "La fila $lineNumber tiene " . count($row)
                        . ' columnas; se esperaban ' . count($headers) . '.'
                    );
                }

                $rows[] = array_map(
                    static fn($value): string => trim((string) $value),
                    $row
                );
                if (count($rows) > self::MAX_ROWS) {
                    throw new RuntimeException('El CSV supera el límite de 5,000 filas.');
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new RuntimeException('El CSV no contiene filas de datos.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function detectDelimiter(string $line): string
    {
        $bestDelimiter = ';';
        $bestCount = 1;
        foreach ([";", ",", "\t"] as $delimiter) {
            $count = count(str_getcsv($line, $delimiter, '"', '\\'));
            if ($count > $bestCount) {
                $bestDelimiter = $delimiter;
                $bestCount = $count;
            }
        }

        return $bestDelimiter;
    }

    public static function validateMapping(string $itemtype, array $mapping, int $headerCount): array
    {
        $availableFields = self::getImportableFields($itemtype);
        $validated = [];
        $usedFields = [];

        foreach ($mapping as $index => $field) {
            if (!is_numeric($index) || (int) $index < 0 || (int) $index >= $headerCount) {
                throw new InvalidArgumentException('El mapeo contiene una columna inválida.');
            }
            if ($field === '') {
                continue;
            }
            if (!is_string($field) || !isset($availableFields[$field])) {
                throw new InvalidArgumentException('El mapeo contiene un campo no permitido.');
            }
            if (isset($usedFields[$field])) {
                throw new InvalidArgumentException('No se puede mapear más de una columna al mismo campo GLPI.');
            }

            $validated[(int) $index] = $field;
            $usedFields[$field] = true;
        }

        if ($validated === []) {
            throw new InvalidArgumentException('Selecciona al menos una columna para importar.');
        }

        return $validated;
    }

    public static function prepareRows(string $itemtype, array $draft, array $mapping): array
    {
        global $DB;

        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM) {
            throw new RuntimeException('No se pudo inicializar el tipo de activo.');
        }
        $entityId = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $prepared = [];
        $fieldsForItem = self::getImportableFields($itemtype);
        $seenIdentifiers = ['serial' => [], 'otherserial' => []];
        $identifierValues = ['serial' => [], 'otherserial' => []];

        foreach ($draft['rows'] as $rowIndex => $row) {
            $input = [];
            $errors = [];
            foreach ($mapping as $columnIndex => $field) {
                $value = trim((string) ($row[$columnIndex] ?? ''));
                if ($value === '') {
                    continue;
                }

                if (isForeignKeyField($field)) {
                    try {
                        $input[$field] = self::resolveForeignValue($field, $value, $entityId);
                    } catch (RuntimeException $exception) {
                        $errors[] = $exception->getMessage();
                    }
                } else {
                    $input[$field] = $value;
                }
            }

            if (
                array_key_exists('name', $fieldsForItem)
                && trim((string) ($input['name'] ?? '')) === ''
            ) {
                $errors[] = 'El nombre del activo está vacío.';
            }
            if ($input === [] && $errors === []) {
                $errors[] = 'La fila no contiene valores para importar.';
            }
            foreach (array_keys($seenIdentifiers) as $field) {
                $identifier = trim((string) ($input[$field] ?? ''));
                if ($identifier === '') {
                    continue;
                }

                $key = mb_strtolower($identifier, 'UTF-8');
                if (isset($seenIdentifiers[$field][$key])) {
                    $firstLine = $seenIdentifiers[$field][$key];
                    $errors[] = 'El valor «' . $identifier . '» de «' . $field
                        . '» ya aparece en la fila ' . $firstLine . '.';
                } else {
                    $seenIdentifiers[$field][$key] = $rowIndex + 2;
                    $identifierValues[$field][$key] = $identifier;
                }
            }
            if ($item->isEntityAssign()) {
                $input['entities_id'] = $entityId;
            }

            $prepared[] = [
                'line' => $rowIndex + 2,
                'input' => $input,
                'errors' => $errors,
            ];
        }

        foreach (array_keys($seenIdentifiers) as $field) {
            $values = array_values($identifierValues[$field]);
            if ($values === []) {
                continue;
            }

            $criteria = [$field => ['IN' => $values]];
            if ($DB->fieldExists($itemtype::getTable(), 'is_deleted')) {
                $criteria['is_deleted'] = 0;
            }
            if ($item->isEntityAssign()) {
                $criteria += getEntitiesRestrictCriteria(
                    $itemtype::getTable(),
                    '',
                    $entityId,
                    false
                );
            }
            $existing = $item->find($criteria);
            $existingValues = [];
            foreach ($existing as $record) {
                $existingValues[mb_strtolower((string) $record[$field], 'UTF-8')] = true;
            }

            foreach ($prepared as &$row) {
                $identifier = mb_strtolower((string) ($row['input'][$field] ?? ''), 'UTF-8');
                if ($identifier !== '' && isset($existingValues[$identifier])) {
                    $row['errors'][] = 'Ya existe un activo con «' . $field . '» = «'
                        . $row['input'][$field] . '».';
                }
            }
            unset($row);
        }

        return $prepared;
    }

    private static function resolveForeignValue(string $field, string $value, int $entityId): int
    {
        global $DB;

        $table = getTableNameForForeignKeyField($field);
        $itemtype = $table !== '' ? getItemTypeForTable($table) : null;
        $reference = $itemtype !== null ? getItemForItemtype($itemtype) : null;
        if (!$reference instanceof CommonDBTM) {
            throw new RuntimeException("No se pudo resolver el campo relacionado «$field».");
        }

        $nameFields = [$itemtype::getCompleteNameField(), $itemtype::getNameField()];
        foreach (array_unique($nameFields) as $nameField) {
            if (!$DB->fieldExists($table, $nameField)) {
                continue;
            }

            $criteria = [$nameField => $value];
            if ($DB->fieldExists($table, 'is_deleted')) {
                $criteria['is_deleted'] = 0;
            }
            if ($DB->fieldExists($table, 'is_active')) {
                $criteria['is_active'] = 1;
            }
            if ($reference->isEntityAssign()) {
                $criteria += getEntitiesRestrictCriteria(
                    $table,
                    '',
                    $entityId,
                    $reference->maybeRecursive()
                );
            }

            $matches = $reference->find($criteria, [], 2);
            if (count($matches) === 1) {
                return (int) reset($matches)['id'];
            }
            if (count($matches) > 1) {
                throw new RuntimeException(
                    'El valor «' . $value . '» es ambiguo para «' . $field . '».'
                );
            }
        }

        throw new RuntimeException(
            'No existe el valor «' . $value . '» en la lista «' . $field . '».'
        );
    }

    public static function importRows(string $itemtype, array $rows): array
    {
        self::assertAllowedItemtype($itemtype);
        $result = ['created' => 0, 'errors' => []];

        foreach ($rows as $row) {
            if ($row['errors'] !== []) {
                $result['errors'][] = [
                    'line' => $row['line'],
                    'message' => implode(' ', $row['errors']),
                ];
                continue;
            }

            try {
                $item = getItemForItemtype($itemtype);
                $id = $item->add($row['input']);
                if (!is_numeric($id) || (int) $id < 1) {
                    $result['errors'][] = [
                        'line' => $row['line'],
                        'message' => 'GLPI no pudo crear el activo; revisa los campos obligatorios y el registro de GLPI.',
                    ];
                    continue;
                }
                $result['created']++;
            } catch (Throwable $exception) {
                $result['errors'][] = [
                    'line' => $row['line'],
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $result;
    }
}
