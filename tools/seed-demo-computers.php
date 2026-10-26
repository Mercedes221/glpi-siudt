<?php

declare(strict_types=1);      

function buildDemoComputers(): array
{
    $models = [
        ['Dell', 'Latitude 5440'],
        ['HP', 'ProBook 440 G10'],
        ['Lenovo', 'ThinkPad E14 Gen 5'],
        ['Apple', 'MacBook Air 13'],
        ['Acer', 'TravelMate P2'],
    ];
    $states = ['Available', 'Assigned', 'In storage', 'Maintenance'];
    $computers = [];

    for ($i = 1; $i <= 100; $i++) {
        [$manufacturer, $model] = $models[($i - 1) % count($models)];
        $state = $states[($i - 1) % count($states)];
        $sequence = sprintf('%04d', $i);

        $computers[] = [
            'name' => sprintf('DEMO-SIUDT-PC-%03d', $i),
            'serial' => "DEMO-SIUDT-SN-$sequence",
            'otherserial' => "DEMO-SIUDT-ACT-$sequence",
            'comment' => "Fictional test data; manufacturer: $manufacturer; model: $model; status: $state.",
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

    if (!is_array($response)) {
        throw new RuntimeException("GLPI API returned an unexpected response (HTTP $statusCode).");
    }

    return $response;
}

function runSeeder(array $arguments): int
{
    $computers = buildDemoComputers();

    if ($arguments !== ['--apply']) {
        if ($arguments !== []) {
            fwrite(STDERR, "Usage: php tools/seed-demo-computers.php [--apply]\n");
            return 2;
        }

        echo 'Preview only: ' . count($computers) . " fictional GLPI computers.\n";
        echo 'First: ' . $computers[0]['name'] . "\n";
        echo 'Last:  ' . $computers[count($computers) - 1]['name'] . "\n";
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
    $skipped = 0;

    try {
        $searchOptions = glpiRequest(
            $apiBase,
            'GET',
            'listSearchOptions/Computer',
            $apiHeaders
        );

        $assetTagField = null;
        foreach ($searchOptions as $fieldId => $option) {
            if (
                is_array($option)
                && ($option['table'] ?? null) === 'glpi_computers'
                && ($option['field'] ?? null) === 'otherserial'
                && ctype_digit((string) $fieldId)
            ) {
                $assetTagField = (int) $fieldId;
                break;
            }
        }
        if ($assetTagField === null) {
            throw new RuntimeException('GLPI did not expose the computer asset-tag search field.');
        }

        $searchQuery = http_build_query([
            'criteria' => [[
                'field' => $assetTagField,
                'searchtype' => 'contains',
                'value' => '^DEMO-SIUDT-ACT-',
            ]],
            'forcedisplay' => [$assetTagField],
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
            if (isset($row[(string) $assetTagField])) {
                $existingTags[(string) $row[(string) $assetTagField]] = true;
            }
        }

        foreach ($computers as $computer) {
            if (isset($existingTags[$computer['otherserial']])) {
                $skipped++;
                continue;
            }

            try {
                $result = glpiRequest(
                    $apiBase,
                    'POST',
                    'Computer/',
                    $apiHeaders,
                    ['input' => $computer]
                );
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "Import stopped at {$computer['name']} after $created records were created: "
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            if (!isset($result['id']) || !is_numeric($result['id']) || (int) $result['id'] < 1) {
                throw new RuntimeException(
                    "GLPI did not confirm creation of {$computer['name']} after $created records."
                );
            }
            $created++;
        }
    } finally {
        try {
            glpiRequest($apiBase, 'GET', 'killSession', $apiHeaders);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Warning: could not close the GLPI API session: {$exception->getMessage()}\n");
        }
    }

    echo "Import complete: $created created, $skipped already present, "
        . count($computers) . " total.\n";
    return 0;
}

try {
    exit(runSeeder(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, "Import failed: {$exception->getMessage()}\n");
    exit(1);
}
