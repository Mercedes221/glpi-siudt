<?php

class PluginSiudtFrontImporter
{
    public const MAX_FILE_SIZE = 5242880;
    public const MAX_ROWS = 500;
    public const PREVIEW_SESSION_KEY = 'plugin_siudt_front_import_preview';

    private const ASSET_TYPES = [
        'computadora' => Computer::class,
        'computer'    => Computer::class,
        'monitor'     => Monitor::class,
        'impresora'   => Printer::class,
        'printer'     => Printer::class,
        'periferico'  => Peripheral::class,
        'peripheral'  => Peripheral::class,
    ];

    private const HEADERS = [
        'tipo',
        'nombre',
        'numero_serie',
        'codigo_inventario',
        'estado',
        'ubicacion',
        'comentarios',
    ];

    public static function getAssetTypes(): array
    {
        return [
            Computer::class,
            Monitor::class,
            Printer::class,
            Peripheral::class,
        ];
    }

    public static function getTemplate(): array
    {
        return [
            self::HEADERS,
            ['computadora', 'Equipo de ejemplo', 'SERIE-001', 'INV-001', '', '', 'Registro de ejemplo'],
        ];
    }

    public static function normalizeType(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ]);

        return self::ASSET_TYPES[$value] ?? null;
    }

    public static function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['error' => __('No fue posible leer el archivo CSV.'), 'rows' => []];
        }

        $first_line = fgets($handle);
        if ($first_line === false) {
            fclose($handle);
            return ['error' => __('El archivo CSV está vacío.'), 'rows' => []];
        }

        $delimiter = substr_count($first_line, ';') >= substr_count($first_line, ',') ? ';' : ',';
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!is_array($headers)) {
            fclose($handle);
            return ['error' => __('No se pudo leer el encabezado del archivo CSV.'), 'rows' => []];
        }

        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }
        $headers = array_map(
            static function ($header) {
                return strtolower(trim((string) $header));
            },
            $headers
        );

        foreach (['tipo', 'nombre', 'numero_serie'] as $required_header) {
            if (!in_array($required_header, $headers, true)) {
                fclose($handle);
                return [
                    'error' => sprintf(__('Falta la columna obligatoria "%s".'), $required_header),
                    'rows'  => [],
                ];
            }
        }

        if (count($headers) !== count(array_unique($headers))) {
            fclose($handle);
            return ['error' => __('El encabezado contiene columnas repetidas.'), 'rows' => []];
        }

        $unknown_headers = array_diff($headers, self::HEADERS);
        if (count($unknown_headers) > 0) {
            fclose($handle);
            return [
                'error' => sprintf(
                    __('Hay columnas no reconocidas: %s. Descarga la plantilla para consultar los nombres admitidos.'),
                    implode(', ', $unknown_headers)
                ),
                'rows'  => [],
            ];
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                return [
                    'error' => sprintf(__('El archivo supera el máximo permitido de %d filas.'), self::MAX_ROWS),
                    'rows'  => [],
                ];
            }
            if (count($values) !== count($headers)) {
                $rows[] = [
                    'line'   => $line,
                    'data'   => [],
                    'errors' => [__('La cantidad de columnas no coincide con el encabezado.')],
                ];
                continue;
            }

            $data = array_combine($headers, array_map(
                static function ($value) {
                    return trim((string) $value);
                },
                $values
            ));

            $rows[] = [
                'line'   => $line,
                'data'   => $data,
                'errors' => [],
            ];
        }

        fclose($handle);

        if (count($rows) === 0) {
            return ['error' => __('El archivo no contiene registros para importar.'), 'rows' => []];
        }

        return ['error' => null, 'rows' => $rows];
    }

    public static function validateRows(array $rows): array
    {
        $serial_rows = [];
        $serials_in_file = [];

        foreach ($rows as &$row) {
            if (count($row['data']) === 0) {
                continue;
            }

            $data = $row['data'];
            $asset_class = self::normalizeType($data['tipo'] ?? '');

            if ($asset_class === null) {
                $row['errors'][] = __('Tipo de activo inválido. Usa computadora, monitor, impresora o periferico.');
                continue;
            }
            $row['asset_class'] = $asset_class;

            if (trim($data['nombre'] ?? '') === '') {
                $row['errors'][] = __('El nombre es obligatorio.');
            } elseif (Toolbox::strlen($data['nombre']) > 255) {
                $row['errors'][] = __('El nombre no puede superar los 255 caracteres.');
            }
            if (trim($data['numero_serie'] ?? '') === '') {
                $row['errors'][] = __('El número de serie es obligatorio.');
            } else {
                $serial_key = strtolower(trim($data['numero_serie']));
                if (Toolbox::strlen($data['numero_serie']) > 255) {
                    $row['errors'][] = __('El número de serie no puede superar los 255 caracteres.');
                }
                if (isset($serials_in_file[$serial_key])) {
                    $row['errors'][] = sprintf(
                        __('El número de serie está repetido en las filas %1$s y %2$s.'),
                        $serials_in_file[$serial_key],
                        $row['line']
                    );
                } else {
                    $serials_in_file[$serial_key] = $row['line'];
                    $serial_rows[$serial_key] = trim($data['numero_serie']);
                }
            }
            if (Toolbox::strlen($data['codigo_inventario'] ?? '') > 255) {
                $row['errors'][] = __('El código de inventario no puede superar los 255 caracteres.');
            }

            if (!$asset_class::canCreate()) {
                $row['errors'][] = __('No tienes permiso para crear este tipo de activo.');
            } else {
                $permission_input = ['name' => $data['nombre'] ?? ''];
                $entity_id = (int) ($_SESSION['glpiactive_entity'] ?? 0);
                if ($entity_id >= 0) {
                    $permission_input['entities_id'] = $entity_id;
                }
                $asset = new $asset_class();
                if (!$asset->can(-1, CREATE, $permission_input)) {
                    $row['errors'][] = __('No tienes permiso para crear activos en la entidad seleccionada.');
                }
            }

            if (!empty($data['estado'])) {
                $state_id = self::findDropdownId(State::class, $data['estado']);
                if ($state_id === null) {
                    $row['errors'][] = sprintf(
                        __('No se encontró un estado único llamado "%s".'),
                        $data['estado']
                    );
                } else {
                    $row['states_id'] = $state_id;
                }
            }

            if (!empty($data['ubicacion'])) {
                $location_id = self::findDropdownId(Location::class, $data['ubicacion']);
                if ($location_id === null) {
                    $row['errors'][] = sprintf(
                        __('No se encontró una ubicación única llamada "%s".'),
                        $data['ubicacion']
                    );
                } else {
                    $row['locations_id'] = $location_id;
                }
            }
        }
        unset($row);

        if (count($serial_rows) === 0) {
            return $rows;
        }

        $existing_serials = [];
        foreach (self::getAssetTypes() as $asset_class) {
            $iterator = $GLOBALS['DB']->request([
                'SELECT' => 'serial',
                'FROM'   => $asset_class::getTable(),
                'WHERE'  => [
                    'serial' => array_values($serial_rows),
                ],
            ]);
            foreach ($iterator as $record) {
                $existing_serials[strtolower(trim((string) $record['serial']))] = true;
            }
        }

        foreach ($rows as &$row) {
            $serial_key = strtolower(trim($row['data']['numero_serie'] ?? ''));
            if ($serial_key !== '' && isset($existing_serials[$serial_key])) {
                $row['errors'][] = __('Ya existe un activo con este número de serie.');
            }
        }
        unset($row);

        return $rows;
    }

    private static function findDropdownId(string $class, string $name): ?int
    {
        $dropdown = new $class();
        $matches = $dropdown->find(
            [
                'name'       => $name,
                'is_deleted' => 0,
            ],
            [],
            2
        );
        if (count($matches) !== 1) {
            return null;
        }

        $id = (int) key($matches);
        if (!$dropdown->getFromDB($id) || !$dropdown->can($id, READ)) {
            return null;
        }

        return $id;
    }

    public static function importRows(array $rows): array
    {
        $rows = self::validateRows($rows);
        $imported = 0;

        foreach ($rows as &$row) {
            if (count($row['errors']) > 0 || empty($row['asset_class'])) {
                continue;
            }

            $data = $row['data'];
            $input = [
                'name'        => $data['nombre'],
                'serial'      => $data['numero_serie'],
                'otherserial' => $data['codigo_inventario'] ?? '',
                'comment'     => $data['comentarios'] ?? '',
            ];
            $entity_id = (int) ($_SESSION['glpiactive_entity'] ?? 0);
            if ($entity_id >= 0) {
                $input['entities_id'] = $entity_id;
            }
            if (isset($row['states_id'])) {
                $input['states_id'] = $row['states_id'];
            }
            if (isset($row['locations_id'])) {
                $input['locations_id'] = $row['locations_id'];
            }

            $asset_class = $row['asset_class'];
            $asset = new $asset_class();
            $id = $asset->add($input);
            if ($id === false || (int) $id <= 0) {
                $row['errors'][] = __('GLPI no pudo guardar este activo.');
                continue;
            }

            $row['imported'] = true;
            $imported++;
        }
        unset($row);

        return ['rows' => $rows, 'imported' => $imported];
    }
}
