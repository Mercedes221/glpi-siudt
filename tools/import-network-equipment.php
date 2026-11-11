<?php

declare(strict_types=1);

function readNetworkEquipmentCsv(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("CSV file does not exist or cannot be read: $path");
    }

    $file = fopen($path, 'rb');
    if ($file === false) {
        throw new RuntimeException("Could not open CSV file: $path");
    }

    try {
        $headers = fgetcsv($file, 0, ';', '"', '\\');
        if ($headers === false) {
            throw new RuntimeException('The CSV file is empty.');
        }

        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? '';
        $headerIndexes = [];
        foreach ($headers as $index => $header) {
            $key = mb_strtolower(trim((string) $header), 'UTF-8');
            if ($key === '' || isset($headerIndexes[$key])) {
                throw new RuntimeException('CSV headers must be non-empty and unique.');
            }
            $headerIndexes[$key] = $index;
        }

        $requiredColumns = [
            'name' => 'Nombre',
            'serial' => 'Número de serie',
            'otherserial' => 'Número de inventario',
        ];
        $columns = [];
        foreach ($requiredColumns as $field => $header) {
            $key = mb_strtolower($header, 'UTF-8');
            if (!isset($headerIndexes[$key])) {
                throw new RuntimeException("CSV is missing the required column \"$header\".");
            }
            $columns[$field] = $headerIndexes[$key];
        }

        foreach (['Descripción del sistema', 'Comentarios'] as $header) {
            $key = mb_strtolower($header, 'UTF-8');
            if (isset($headerIndexes[$key])) {
                $columns['comment'][] = $headerIndexes[$key];
            }
        }

        $records = [];
        $seenInventoryNumbers = [];
        $seenNames = [];
        $lineNumber = 1;

        while (($values = fgetcsv($file, 0, ';', '"', '\\')) !== false) {
            $lineNumber++;
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($values) !== count($headers)) {
                throw new RuntimeException(
                    "CSV row $lineNumber has " . count($values)
                    . ' columns; expected ' . count($headers) . '.'
                );
            }

            $record = [];
            foreach ($requiredColumns as $field => $_header) {
                $record[$field] = trim((string) $values[$columns[$field]]);
                if ($record[$field] === '') {
                    throw new RuntimeException("CSV row $lineNumber has an empty required value.");
                }
            }

            if (isset($seenInventoryNumbers[$record['otherserial']])) {
                throw new RuntimeException(
                    "CSV row $lineNumber repeats inventory number {$record['otherserial']}."
                );
            }
            if (isset($seenNames[$record['name']])) {
                throw new RuntimeException("CSV row $lineNumber repeats name {$record['name']}.");
            }
            $seenInventoryNumbers[$record['otherserial']] = true;
            $seenNames[$record['name']] = true;

            $comments = [];
            foreach ($columns['comment'] ?? [] as $columnIndex) {
                $value = trim((string) $values[$columnIndex]);
                if ($value !== '') {
                    $comments[] = $value;
                }
            }
            if ($comments !== []) {
                $record['comment'] = implode("\n", array_unique($comments));
            }

            $records[] = $record;
        }
    } finally {
        fclose($file);
    }

    if ($records === []) {
        throw new RuntimeException('The CSV contains no equipment records.');
    }

    return $records;
}

function networkEquipmentApiRequest(
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
            "GLPI API returned a non-JSON response (HTTP $statusCode): $bodyPreview",
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
    if (!is_array($response)) {
        throw new RuntimeException("GLPI API returned an unexpected response (HTTP $statusCode).");
    }

    return $response;
}

function findNetworkEquipmentSearchField(array $options, string $field): int
{
    foreach ($options as $id => $option) {
        if (
            is_array($option)
            && ($option['table'] ?? null) === 'glpi_networkequipments'
            && ($option['field'] ?? null) === $field
            && ctype_digit((string) $id)
        ) {
            return (int) $id;
        }
    }

    throw new RuntimeException("GLPI did not expose the network equipment \"$field\" search field.");
}

function findCommonInventoryPrefix(array $records): string
{
    $prefix = $records[0]['otherserial'];
    foreach ($records as $record) {
        $length = min(strlen($prefix), strlen($record['otherserial']));
        $index = 0;
        while ($index < $length && $prefix[$index] === $record['otherserial'][$index]) {
            $index++;
        }
        $prefix = substr($prefix, 0, $index);
        if ($prefix === '') {
            break;
        }
    }

    return $prefix;
}

function runNetworkEquipmentImport(array $arguments): int
{
    $apply = false;
    $csvPath = null;
    $usage = "Usage: php tools/import-network-equipment.php <csv-path> [--apply]\n";

    foreach ($arguments as $argument) {
        if ($argument === '--apply') {
            $apply = true;
        } elseif (str_starts_with($argument, '--')) {
            fwrite(STDERR, $usage);
            return 2;
        } elseif ($csvPath === null) {
            $csvPath = $argument;
        } else {
            fwrite(STDERR, $usage);
            return 2;
        }
    }

    if ($csvPath === null) {
        fwrite(STDERR, $usage);
        return 2;
    }

    $records = readNetworkEquipmentCsv($csvPath);
    if (!$apply) {
        echo 'Preview only: ' . count($records) . " network equipment records.\n";
        echo "Fields to import: name, serial, inventory number";
        if (isset($records[0]['comment'])) {
            echo ', comments';
        }
        echo ".\n";
        echo 'First: ' . $records[0]['name'] . ' [' . $records[0]['otherserial'] . "]\n";
        echo 'Last:  ' . $records[count($records) - 1]['name']
            . ' [' . $records[count($records) - 1]['otherserial'] . "]\n";
        echo "No records were sent. Use --apply to import them.\n";
        return 0;
    }

    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $baseUrl = getenv('GLPI_URL') ?: '';
    $userToken = getenv('GLPI_USER_TOKEN') ?: '';
    $appToken = getenv('GLPI_APP_TOKEN') ?: '';
    if ($baseUrl === '' || $userToken === '') {
        throw new RuntimeException('Set GLPI_URL and GLPI_USER_TOKEN before using --apply.');
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

    $session = networkEquipmentApiRequest(
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
    $skipped = 0;
    try {
        $searchOptions = networkEquipmentApiRequest(
            $apiBase,
            'GET',
            'listSearchOptions/NetworkEquipment',
            $apiHeaders
        );
        $inventoryField = findNetworkEquipmentSearchField($searchOptions, 'otherserial');
        $idField = findNetworkEquipmentSearchField($searchOptions, 'id');

        $prefix = findCommonInventoryPrefix($records);
        $existingInventories = [];
        if ($prefix !== '') {
            $searchQuery = http_build_query([
                'criteria' => [[
                    'field' => $inventoryField,
                    'searchtype' => 'contains',
                    'value' => '^' . preg_quote($prefix, '/'),
                ]],
                'forcedisplay' => [$idField, $inventoryField],
                'range' => '0-999',
            ]);
            $matches = networkEquipmentApiRequest(
                $apiBase,
                'GET',
                "search/NetworkEquipment/?$searchQuery",
                $apiHeaders
            );
            foreach ($matches['data'] ?? [] as $row) {
                if (isset($row[(string) $inventoryField])) {
                    $existingInventories[(string) $row[(string) $inventoryField]] = true;
                }
            }
        } else {
            foreach ($records as $record) {
                $searchQuery = http_build_query([
                    'criteria' => [[
                        'field' => $inventoryField,
                        'searchtype' => 'equals',
                        'value' => $record['otherserial'],
                    ]],
                    'forcedisplay' => [$idField, $inventoryField],
                    'range' => '0-1',
                ]);
                $matches = networkEquipmentApiRequest(
                    $apiBase,
                    'GET',
                    "search/NetworkEquipment/?$searchQuery",
                    $apiHeaders
                );
                foreach ($matches['data'] ?? [] as $row) {
                    if (isset($row[(string) $inventoryField])) {
                        $existingInventories[(string) $row[(string) $inventoryField]] = true;
                    }
                }
            }
        }

        foreach ($records as $record) {
            if (isset($existingInventories[$record['otherserial']])) {
                $skipped++;
                continue;
            }

            try {
                $result = networkEquipmentApiRequest(
                    $apiBase,
                    'POST',
                    'NetworkEquipment/',
                    $apiHeaders,
                    ['input' => $record]
                );
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "Import stopped at {$record['name']} after $created records were created: "
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            if (!isset($result['id']) || !is_numeric($result['id']) || (int) $result['id'] < 1) {
                throw new RuntimeException(
                    "GLPI did not confirm creation of {$record['name']} after $created records."
                );
            }
            $created++;
        }
    } finally {
        try {
            networkEquipmentApiRequest($apiBase, 'GET', 'killSession', $apiHeaders);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Warning: could not close the GLPI API session: {$exception->getMessage()}\n");
        }
    }

    echo "Import complete: $created created, $skipped already present, "
        . count($records) . " total.\n";
    return 0;
}

try {
    exit(runNetworkEquipmentImport(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, "Import failed: {$exception->getMessage()}\n");
    exit(1);
}
