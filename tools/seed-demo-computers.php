<?php

declare(strict_types=1);

function buildDemoComputers(): array
{
    $profiles = [
        [
            'manufacturer' => 'HP',
            'model' => 'ProBook 440 G10',
            'type' => 'Laptop',
            'state' => 'En uso',
            'location' => 'DP COMPUTACIÓN',
            'operating_system' => 'Windows 11 Pro',
            'processor' => 'Intel Core i5-1335U',
        ],
        [
            'manufacturer' => 'Lenovo',
            'model' => 'ThinkPad E14 Gen 5',
            'type' => 'Laptop',
            'state' => 'En prestamo',
            'location' => 'Dirección de TI',
            'operating_system' => 'Ubuntu 24.04 LTS',
            'processor' => 'Intel Core i5-1335U',
        ],
        [
            'manufacturer' => 'Dell',
            'model' => 'Latitude 5440',
            'type' => 'Laptop',
            'state' => 'En uso',
            'location' => 'DP COMPUTACIÓN',
            'operating_system' => 'Windows 11 Pro',
            'processor' => 'Intel Core i5-1345U',
        ],
        [
            'manufacturer' => 'Acer',
            'model' => 'TravelMate P2',
            'type' => 'Laptop',
            'state' => 'En prestamo',
            'location' => 'Dirección de TI',
            'operating_system' => 'Windows 11 Pro',
            'processor' => 'Intel Core i5-1235U',
        ],
        [
            'manufacturer' => 'Apple',
            'model' => 'MacBook Air 13',
            'type' => 'Laptop',
            'state' => 'En uso',
            'location' => 'DP COMPUTACIÓN',
            'operating_system' => 'macOS Sonoma',
            'processor' => 'Apple M2',
        ],
    ];
    $technicians = array_map(
        static fn (int $sequence): string => sprintf('demo-siudt-tech-%02d', $sequence),
        range(1, 5)
    );
    $computers = [];

    for ($i = 1; $i <= 100; $i++) {
        $profile = $profiles[($i - 1) % count($profiles)];
        $sequence = sprintf('%04d', $i);

        $computers[] = [
            'name' => sprintf('DEMO-SIUDT-PC-%03d', $i),
            'serial' => "DEMO-SIUDT-SN-$sequence",
            'otherserial' => "DEMO-SIUDT-ACT-$sequence",
            'manufacturer' => $profile['manufacturer'],
            'model' => $profile['model'],
            'type' => $profile['type'],
            'state' => $profile['state'],
            'location' => $profile['location'],
            'operating_system' => $profile['operating_system'],
            'processor' => $profile['processor'],
            'technician' => $technicians[($i - 1) % count($technicians)],
            'comment' => 'Fictional SIUDT demonstration computer.',
        ];
    }

    return $computers;
}

function glpiRequest(
    string $apiBase,
    string $method,
    string $path,
    array $headers,
    ?array $payload = null
): array {
    $curl = curl_init($apiBase . '/' . ltrim($path, '/'));
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the HTTP client.');
    }

    $requestHeaders = ['Accept: application/json'];
    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }
    foreach ($headers as $name => $value) {
        $requestHeaders[] = "$name: $value";
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        throw new RuntimeException("GLPI API request failed: $curlError");
    }

    if (trim($responseBody) === '' && $statusCode >= 200 && $statusCode < 300) {
        return [];
    }

    try {
        $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $bodyPreview = trim(preg_replace('/\s+/', ' ', strip_tags($responseBody)) ?? '');
        if (strlen($bodyPreview) > 300) {
            $bodyPreview = substr($bodyPreview, 0, 300) . '...';
        }

        throw new RuntimeException(
            "GLPI API returned a non-JSON response (HTTP $statusCode) for "
            . "$method " . $apiBase . '/' . ltrim($path, '/') . ": $bodyPreview",
            0,
            $exception
        );
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        $message = is_array($response) && isset($response['message'])
            ? (string) $response['message']
            : $responseBody;
        throw new RuntimeException("GLPI API returned HTTP $statusCode: $message");
    }

    if ($path === 'killSession' && $response === true) {
        return [];
    }

    if ($response === true) {
        return ['success' => true];
    }

    if (!is_array($response)) {
        throw new RuntimeException("GLPI API returned an unexpected response (HTTP $statusCode).");
    }

    return $response;
}

function getSearchFieldId(array $searchOptions, string $table, string $field): int
{
    foreach ($searchOptions as $fieldId => $option) {
        if (
            is_array($option)
            && ($option['table'] ?? null) === $table
            && ($option['field'] ?? null) === $field
            && ctype_digit((string) $fieldId)
        ) {
            return (int) $fieldId;
        }
    }

    throw new RuntimeException("GLPI did not expose the search field $table.$field.");
}

function findExistingCatalogId(
    string $apiBase,
    array $headers,
    string $itemtype,
    string $table,
    array $candidateFields,
    string $name,
    array &$searchOptionsCache
): int {
    if (!isset($searchOptionsCache[$itemtype])) {
        $searchOptionsCache[$itemtype] = glpiRequest(
            $apiBase,
            'GET',
            "listSearchOptions/$itemtype",
            $headers
        );
    }
    $options = $searchOptionsCache[$itemtype];
    $idField = getSearchFieldId($options, $table, 'id');
    $nameField = null;
    foreach ($candidateFields as $candidateField) {
        try {
            $nameField = getSearchFieldId($options, $table, $candidateField);
            break;
        } catch (RuntimeException) {
            continue;
        }
    }
    if ($nameField === null) {
        throw new RuntimeException(
            "GLPI did not expose a searchable name field for $itemtype in $table."
        );
    }

    $query = http_build_query([
        'criteria' => [[
            'field' => $nameField,
            'searchtype' => 'equals',
            'value' => $name,
        ]],
        'forcedisplay' => [$idField, $nameField],
        'range' => '0-999',
    ]);
    $result = glpiRequest($apiBase, 'GET', "search/$itemtype/?$query", $headers);
    $matches = [];
    foreach ($result['data'] ?? [] as $row) {
        $id = $row[(string) $idField] ?? null;
        $foundName = $row[(string) $nameField] ?? null;
        if (is_numeric($id) && is_string($foundName) && $foundName === $name) {
            $matches[(int) $id] = true;
        }
    }

    if (count($matches) !== 1) {
        $reason = $matches === []
            ? 'was not found'
            : 'is ambiguous (more than one matching catalog item)';
        throw new RuntimeException("$itemtype catalog value \"$name\" $reason.");
    }

    return (int) array_key_first($matches);
}

function resolveDemoComputerReferences(string $apiBase, array $headers, array $computers): array
{
    $searchOptionsCache = [];
    $resolved = [];
    $catalogs = [
        'manufacturer' => ['Manufacturer', 'glpi_manufacturers', ['name'], 'manufacturers_id'],
        'model' => ['ComputerModel', 'glpi_computermodels', ['name'], 'computermodels_id'],
        'type' => ['ComputerType', 'glpi_computertypes', ['name'], 'computertypes_id'],
        'state' => ['State', 'glpi_states', ['completename', 'name'], 'states_id'],
        'location' => ['Location', 'glpi_locations', ['completename', 'name'], 'locations_id'],
        'technician' => ['User', 'glpi_users', ['name'], 'users_id_tech'],
        'operating_system' => ['OperatingSystem', 'glpi_operatingsystems', ['name'], 'operatingsystems_id'],
        'processor' => ['DeviceProcessor', 'glpi_deviceprocessors', ['name'], 'deviceprocessors_id'],
    ];

    foreach ($computers as $computer) {
        $key = $computer['otherserial'];
        foreach ($catalogs as $sourceField => [$itemtype, $table, $nameFields, $targetField]) {
            $cacheKey = "$itemtype|$table|" . $computer[$sourceField];
            if (!isset($resolved[$cacheKey])) {
                $resolved[$cacheKey] = findExistingCatalogId(
                    $apiBase,
                    $headers,
                    $itemtype,
                    $table,
                    $nameFields,
                    $computer[$sourceField],
                    $searchOptionsCache
                );
            }
            $resolved[$key][$targetField] = $resolved[$cacheKey];
        }
    }

    return $resolved;
}

function getRelationSearchFields(
    string $apiBase,
    array $headers,
    string $itemtype,
    string $table,
    string $catalogField
): array {
    $options = glpiRequest($apiBase, 'GET', "listSearchOptions/$itemtype", $headers);
    return [
        'id' => getSearchFieldId($options, $table, 'id'),
        'itemtype' => getSearchFieldId($options, $table, 'itemtype'),
        'items_id' => getSearchFieldId($options, $table, 'items_id'),
        $catalogField => getSearchFieldId($options, $table, $catalogField),
    ];
}

function syncComputerRelation(
    string $apiBase,
    array $headers,
    string $itemtype,
    string $table,
    string $catalogField,
    int $computerId,
    int $catalogId,
    array $searchFields
): void {
    $query = http_build_query([
        'criteria' => [
            [
                'field' => $searchFields['itemtype'],
                'searchtype' => 'equals',
                'value' => 'Computer',
            ],
            [
                'field' => $searchFields['items_id'],
                'searchtype' => 'equals',
                'value' => $computerId,
            ],
        ],
        'forcedisplay' => [$searchFields['id'], $searchFields[$catalogField]],
        'range' => '0-999',
    ]);
    $result = glpiRequest($apiBase, 'GET', "search/$itemtype/?$query", $headers);
    $relations = [];
    foreach ($result['data'] ?? [] as $row) {
        $id = $row[(string) $searchFields['id']] ?? null;
        $existingCatalogId = $row[(string) $searchFields[$catalogField]] ?? null;
        if (is_numeric($id)) {
            $relations[(int) $id] = is_numeric($existingCatalogId) ? (int) $existingCatalogId : 0;
        }
    }

    if (count($relations) > 1) {
        throw new RuntimeException(
            "Computer $computerId has multiple $itemtype relations; refusing to alter them."
        );
    }

    $input = [
        'itemtype' => 'Computer',
        'items_id' => $computerId,
        $catalogField => $catalogId,
    ];
    if ($relations === []) {
        glpiRequest($apiBase, 'POST', "$itemtype/", $headers, ['input' => $input]);
        return;
    }

    $relationId = (int) array_key_first($relations);
    if ($relations[$relationId] === $catalogId) {
        return;
    }

    glpiRequest(
        $apiBase,
        'PUT',
        "$itemtype/$relationId",
        $headers,
        ['input' => ['id' => $relationId] + $input]
    );
}

function runSeeder(array $arguments): int
{
    $computers = buildDemoComputers();
    $apply = false;
    $checkOnly = false;

    foreach ($arguments as $argument) {
        if ($argument === '--apply' && !$checkOnly) {
            $apply = true;
        } elseif ($argument === '--check' && !$apply) {
            $checkOnly = true;
        } else {
            fwrite(STDERR, "Usage: php tools/seed-demo-computers.php [--apply|--check]\n");
            return 2;
        }
    }

    if (!$apply && !$checkOnly) {
        echo 'Preview only: ' . count($computers) . " fictional GLPI computers.\n";
        echo 'Fields: name, serial, inventory number, manufacturer, model, type, status, '
            . "location, technician, operating system, processor.\n";
        echo 'Example: ' . $computers[0]['name'] . ' — '
            . $computers[0]['state'] . ', ' . $computers[0]['manufacturer'] . ' '
            . $computers[0]['model'] . ', ' . $computers[0]['location'] . ', '
            . $computers[0]['technician'] . ', ' . $computers[0]['operating_system']
            . ', ' . $computers[0]['processor'] . ".\n";
        echo 'Last:  ' . $computers[count($computers) - 1]['name'] . ' — '
            . $computers[count($computers) - 1]['manufacturer'] . ' '
            . $computers[count($computers) - 1]['model'] . ', '
            . $computers[count($computers) - 1]['technician'] . "\n";
        echo "No records were sent. Use --apply to sync them, or --check to validate GLPI catalogs without changes.\n";
        return 0;
    }

    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $baseUrl = getenv('GLPI_URL') ?: '';
    $userToken = getenv('GLPI_USER_TOKEN') ?: '';
    $appToken = getenv('GLPI_APP_TOKEN') ?: '';
    if ($baseUrl === '' || $userToken === '') {
        throw new RuntimeException('Set GLPI_URL and GLPI_USER_TOKEN before using --apply or --check.');
    }

    $urlParts = parse_url($baseUrl);
    if (
        $urlParts === false
        || !isset($urlParts['scheme'], $urlParts['host'])
        || !in_array(strtolower($urlParts['scheme']), ['http', 'https'], true)
        || isset($urlParts['query'])
        || isset($urlParts['fragment'])
    ) {
        throw new RuntimeException('GLPI_URL must be an HTTP(S) base URL without a query or fragment.');
    }

    $apiBase = rtrim($baseUrl, '/');
    if (!str_ends_with(strtolower($apiBase), '/apirest.php')) {
        $apiBase .= '/apirest.php';
    }

    $apiHeaders = [];
    if ($appToken !== '') {
        $apiHeaders['App-Token'] = $appToken;
    }

    $session = glpiRequest(
        $apiBase,
        'GET',
        'initSession',
        array_merge($apiHeaders, ['Authorization' => "user_token $userToken"])
    );
    $sessionToken = $session['session_token'] ?? null;
    if (!is_string($sessionToken) || $sessionToken === '') {
        throw new RuntimeException('GLPI did not return a session token.');
    }
    $apiHeaders['Session-Token'] = $sessionToken;

    $created = 0;
    $updated = 0;

    try {
        $references = resolveDemoComputerReferences($apiBase, $apiHeaders, $computers);
        $relationSearchFields = [
            'Item_OperatingSystem' => getRelationSearchFields(
                $apiBase,
                $apiHeaders,
                'Item_OperatingSystem',
                'glpi_items_operatingsystems',
                'operatingsystems_id'
            ),
            'Item_DeviceProcessor' => getRelationSearchFields(
                $apiBase,
                $apiHeaders,
                'Item_DeviceProcessor',
                'glpi_items_deviceprocessors',
                'deviceprocessors_id'
            ),
        ];
        $searchOptions = glpiRequest(
            $apiBase,
            'GET',
            'listSearchOptions/Computer',
            $apiHeaders
        );
        $idField = getSearchFieldId($searchOptions, 'glpi_computers', 'id');
        $assetTagField = getSearchFieldId($searchOptions, 'glpi_computers', 'otherserial');

        $searchQuery = http_build_query([
            'criteria' => [[
                'field' => $assetTagField,
                'searchtype' => 'contains',
                'value' => '^DEMO-SIUDT-ACT-',
            ]],
            'forcedisplay' => [$idField, $assetTagField],
            'range' => '0-999',
        ]);
        $matches = glpiRequest(
            $apiBase,
            'GET',
            "search/Computer/?$searchQuery",
            $apiHeaders
        );

        $existingTags = [];
        foreach ($matches['data'] ?? [] as $row) {
            $tag = $row[(string) $assetTagField] ?? null;
            $id = $row[(string) $idField] ?? null;
            if (is_string($tag) && is_numeric($id)) {
                if (isset($existingTags[$tag])) {
                    throw new RuntimeException("Inventory number $tag matches multiple computers.");
                }
                $existingTags[$tag] = (int) $id;
            }
        }

        if ($checkOnly) {
            $willUpdate = 0;
            $willCreate = 0;
            foreach ($computers as $computer) {
                if (isset($existingTags[$computer['otherserial']])) {
                    $willUpdate++;
                } else {
                    $willCreate++;
                }
            }
            echo "Check complete: all required catalog entries and relation fields are available.\n";
            echo "No computers were changed.\n";
            echo "$willUpdate existing demo computers would be updated; "
                . "$willCreate missing demo computers would be created.\n";
            return 0;
        }

        foreach ($computers as $computer) {
            $record = [
                'name' => $computer['name'],
                'serial' => $computer['serial'],
                'otherserial' => $computer['otherserial'],
                'manufacturers_id' => $references[$computer['otherserial']]['manufacturers_id'],
                'computermodels_id' => $references[$computer['otherserial']]['computermodels_id'],
                'computertypes_id' => $references[$computer['otherserial']]['computertypes_id'],
                'states_id' => $references[$computer['otherserial']]['states_id'],
                'locations_id' => $references[$computer['otherserial']]['locations_id'],
                'users_id_tech' => $references[$computer['otherserial']]['users_id_tech'],
                'comment' => $computer['comment'],
            ];
            $computerId = $existingTags[$computer['otherserial']] ?? null;

            try {
                if ($computerId === null) {
                    $result = glpiRequest(
                        $apiBase,
                        'POST',
                        'Computer/',
                        $apiHeaders,
                        ['input' => $record]
                    );
                    if (!isset($result['id']) || !is_numeric($result['id']) || (int) $result['id'] < 1) {
                        throw new RuntimeException(
                            "GLPI did not confirm creation of {$computer['name']} after $created records."
                        );
                    }
                    $computerId = (int) $result['id'];
                    $created++;
                } else {
                    glpiRequest(
                        $apiBase,
                        'PUT',
                        "Computer/$computerId",
                        $apiHeaders,
                        ['input' => ['id' => $computerId] + $record]
                    );
                    $updated++;
                }

                syncComputerRelation(
                    $apiBase,
                    $apiHeaders,
                    'Item_OperatingSystem',
                    'glpi_items_operatingsystems',
                    'operatingsystems_id',
                    $computerId,
                    $references[$computer['otherserial']]['operatingsystems_id'],
                    $relationSearchFields['Item_OperatingSystem']
                );
                syncComputerRelation(
                    $apiBase,
                    $apiHeaders,
                    'Item_DeviceProcessor',
                    'glpi_items_deviceprocessors',
                    'deviceprocessors_id',
                    $computerId,
                    $references[$computer['otherserial']]['deviceprocessors_id'],
                    $relationSearchFields['Item_DeviceProcessor']
                );
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "Import stopped at {$computer['name']} after $created records were created "
                    . "and $updated were updated: "
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
    } finally {
        try {
            glpiRequest($apiBase, 'GET', 'killSession', $apiHeaders);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Warning: could not close the GLPI API session: {$exception->getMessage()}\n");
        }
    }

    echo "Import complete: $created created, $updated updated, "
        . count($computers) . " total.\n";
    return 0;
}

try {
    exit(runSeeder(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, "Import failed: {$exception->getMessage()}\n");
    exit(1);
}
