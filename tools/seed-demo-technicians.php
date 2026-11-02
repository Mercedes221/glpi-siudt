<?php

declare(strict_types=1);

function buildDemoTechnicians(): array
{
    $firstNames = ['Alex', 'Sam', 'Dani', 'Cris', 'Pat'];
    $technicians = [];

    foreach ($firstNames as $index => $firstName) {
        $sequence = $index + 1;
        $technicians[] = [
            'name' => sprintf('demo-siudt-tech-%02d', $sequence),
            'firstname' => $firstName,
            'realname' => sprintf('Tecnico de prueba %02d', $sequence),
            'comment' => 'Fictional test account created by the SIUDT demo seeder.',
        ];
    }

    return $technicians;
}

function technicianApiRequest(
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

function getSearchFieldId(array $searchOptions, string $table, string $field): int
{
    foreach ($searchOptions as $id => $option) {
        if (
            is_array($option)
            && ($option['table'] ?? null) === $table
            && ($option['field'] ?? null) === $field
            && ctype_digit((string) $id)
        ) {
            return (int) $id;
        }
    }

    throw new RuntimeException("GLPI did not expose the $table.$field search field.");
}

function normalizeProfileName(string $name): string
{
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $normalized = strtolower($transliterated === false ? $name : $transliterated);
    return preg_replace('/[^a-z0-9]/', '', $normalized) ?? '';
}

function findTechnicianProfile(
    string $apiBase,
    array $headers,
    string $profileName
): int {
    $options = technicianApiRequest(
        $apiBase,
        'GET',
        'listSearchOptions/Profile',
        $headers
    );
    $idField = getSearchFieldId($options, 'glpi_profiles', 'id');
    $nameField = getSearchFieldId($options, 'glpi_profiles', 'name');
    $query = http_build_query([
        'forcedisplay' => [$idField, $nameField],
        'range' => '0-999',
    ]);
    $result = technicianApiRequest($apiBase, 'GET', "search/Profile/?$query", $headers);
    $expectedName = normalizeProfileName($profileName);
    $availableProfiles = [];
    $matches = [];

    foreach ($result['data'] ?? [] as $row) {
        $id = $row[(string) $idField] ?? null;
        $name = $row[(string) $nameField] ?? null;
        if (!is_numeric($id) || !is_string($name)) {
            continue;
        }

        $availableProfiles[] = $name;
        if (normalizeProfileName($name) === $expectedName) {
            $matches[(int) $id] = $name;
        }
    }

    if (count($matches) !== 1) {
        $available = $availableProfiles === [] ? '(none returned)' : implode(', ', $availableProfiles);
        throw new RuntimeException(
            "Could not identify exactly one GLPI profile named \"$profileName\". "
            . "Profiles visible to this account: $available. "
            . 'Set GLPI_TECH_PROFILE_NAME to an exact available profile name.'
        );
    }

    return (int) array_key_first($matches);
}

function generateTemporaryPassword(): string
{
    return 'GlpiDemo9!' . bin2hex(random_bytes(16));
}

function runTechnicianSeeder(array $arguments): int
{
    $technicians = buildDemoTechnicians();

    if ($arguments !== ['--apply']) {
        if ($arguments !== []) {
            fwrite(STDERR, "Usage: php tools/seed-demo-technicians.php [--apply]\n");
            return 2;
        }

        echo 'Preview only: ' . count($technicians) . " fictional GLPI technician accounts.\n";
        foreach ($technicians as $technician) {
            echo $technician['name'] . ' — ' . $technician['firstname'] . ' '
                . $technician['realname'] . "\n";
        }
        echo "No records were sent. Use --apply to create the accounts.\n";
        return 0;
    }

    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required.');
    }

    $baseUrl = getenv('GLPI_URL') ?: '';
    $userToken = getenv('GLPI_USER_TOKEN') ?: '';
    $appToken = getenv('GLPI_APP_TOKEN') ?: '';
    $profileName = getenv('GLPI_TECH_PROFILE_NAME') ?: 'Technician';
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

    $session = technicianApiRequest(
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
        $profileId = findTechnicianProfile($apiBase, $apiHeaders, $profileName);
        $userOptions = technicianApiRequest(
            $apiBase,
            'GET',
            'listSearchOptions/User',
            $apiHeaders
        );
        $userIdField = getSearchFieldId($userOptions, 'glpi_users', 'id');
        $userNameField = getSearchFieldId($userOptions, 'glpi_users', 'name');
        $searchQuery = http_build_query([
            'criteria' => [[
                'field' => $userNameField,
                'searchtype' => 'contains',
                'value' => '^demo-siudt-tech-',
            ]],
            'forcedisplay' => [$userIdField, $userNameField],
            'range' => '0-999',
        ]);
        $matches = technicianApiRequest(
            $apiBase,
            'GET',
            "search/User/?$searchQuery",
            $apiHeaders
        );
        $existingUsers = [];
        foreach ($matches['data'] ?? [] as $row) {
            if (isset($row[(string) $userNameField])) {
                $existingUsers[(string) $row[(string) $userNameField]] = true;
            }
        }

        foreach ($technicians as $technician) {
            if (isset($existingUsers[$technician['name']])) {
                $skipped++;
                continue;
            }

            $password = generateTemporaryPassword();
            $userInput = $technician + [
                'password' => $password,
                'password2' => $password,
                'is_active' => 1,
                'entities_id' => 0,
                'profiles_id' => $profileId,
                '_entities_id' => 0,
                '_profiles_id' => $profileId,
                '_is_recursive' => 0,
            ];

            try {
                $result = technicianApiRequest(
                    $apiBase,
                    'POST',
                    'User/',
                    $apiHeaders,
                    ['input' => $userInput]
                );
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "Import stopped at {$technician['name']} after $created accounts were created: "
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            if (!isset($result['id']) || !is_numeric($result['id']) || (int) $result['id'] < 1) {
                throw new RuntimeException(
                    "GLPI did not confirm creation of {$technician['name']} after $created accounts."
                );
            }

            $created++;
            echo "Created {$technician['name']} (GLPI ID {$result['id']}). "
                . "Temporary password: $password\n";
        }
    } finally {
        try {
            technicianApiRequest($apiBase, 'GET', 'killSession', $apiHeaders);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Warning: could not close the GLPI API session: {$exception->getMessage()}\n");
        }
    }

    echo "Import complete: $created created, $skipped already present, "
        . count($technicians) . ' total.';
    if ($created === 0) {
        echo " No passwords were generated; existing account passwords are unchanged.";
    }
    echo "\n";
    return 0;
}

try {
    exit(runTechnicianSeeder(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, "Import failed: {$exception->getMessage()}\n");
    exit(1);
}
