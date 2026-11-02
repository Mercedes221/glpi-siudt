<?php

declare(strict_types=1);

function buildDemoTickets(): array
{
    $incidents = [
        ['No inicia el equipo de prueba', 'El equipo dejó de iniciar después de una actualización. Se requiere diagnóstico del arranque.'],
        ['Conexión Wi-Fi intermitente', 'La conexión inalámbrica se interrumpe varias veces durante la jornada de prueba.'],
        ['Teclado con teclas que no responden', 'Algunas teclas dejan de responder. Revisar configuración y periférico.'],
        ['Rendimiento lento al abrir aplicaciones', 'Las aplicaciones de oficina tardan más de lo esperado en iniciar.'],
        ['Monitor sin señal de video', 'El monitor de prueba muestra el mensaje de ausencia de señal. Revisar cable y puerto.'],
    ];
    $requests = [
        ['Instalación de aplicación de prueba', 'Solicito instalar una aplicación aprobada para validar el flujo de software.'],
        ['Acceso a carpeta compartida de pruebas', 'Solicito acceso a la carpeta compartida del entorno de demostración.'],
        ['Configuración de impresora de prueba', 'Solicito agregar la impresora de pruebas al perfil del equipo.'],
        ['Revisión preventiva del equipo', 'Solicito una revisión preventiva del equipo y sus actualizaciones.'],
        ['Habilitar segundo monitor', 'Solicito conectar y configurar un segundo monitor para el puesto de pruebas.'],
    ];
    $tickets = [];

    foreach (array_merge($incidents, $requests) as $index => [$title, $description]) {
        $sequence = $index + 1;
        $tickets[] = [
            'name' => sprintf('DEMO-SIUDT-TICKET-%03d - %s', $sequence, $title),
            'content' => $description . "\n\nTicket de datos ficticios para pruebas de SIUDT.",
            'type' => $sequence <= 5 ? 1 : 2,
            'urgency' => ($sequence % 3) + 1,
            'impact' => ($sequence % 3) + 1,
            'priority' => 2,
            'status' => 1,
        ];
    }

    return $tickets;
}

function ticketApiRequest(
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

function getTicketSearchFieldId(array $searchOptions, string $table, string $field): int
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

function findTicketRelatedIds(
    string $apiBase,
    array $headers,
    string $itemtype,
    string $table,
    string $matchField,
    string $matchValue
): array {
    $options = ticketApiRequest(
        $apiBase,
        'GET',
        "listSearchOptions/$itemtype",
        $headers
    );
    $idField = getTicketSearchFieldId($options, $table, 'id');
    $matchFieldId = getTicketSearchFieldId($options, $table, $matchField);
    $query = http_build_query([
        'criteria' => [[
            'field' => $matchFieldId,
            'searchtype' => 'contains',
            'value' => "^$matchValue",
        ]],
        'forcedisplay' => [$idField, $matchFieldId],
        'range' => '0-999',
    ]);
    $results = ticketApiRequest(
        $apiBase,
        'GET',
        "search/$itemtype/?$query",
        $headers
    );
    $ids = [];

    foreach ($results['data'] ?? [] as $row) {
        $id = $row[(string) $idField] ?? null;
        $value = $row[(string) $matchFieldId] ?? null;
        if (is_numeric($id) && is_string($value) && str_starts_with($value, $matchValue)) {
            $ids[] = (int) $id;
        }
    }

    return $ids;
}

function runTicketSeeder(array $arguments): int
{
    $tickets = buildDemoTickets();
    $actorCount = 0;
    $computerCount = 0;

    if ($arguments !== ['--apply']) {
        if ($arguments !== []) {
            fwrite(STDERR, "Usage: php tools/seed-demo-tickets.php [--apply]\n");
            return 2;
        }

        echo 'Preview only: ' . count($tickets) . " fictional tickets (5 incidents, 5 requests).\n";
        foreach ($tickets as $ticket) {
            echo ($ticket['type'] === 1 ? '[Incidente] ' : '[Solicitud] ')
                . $ticket['name'] . "\n";
        }
        echo "No records were sent. Use --apply to create the tickets.\n";
        echo "The importer will link any demo computers and technician accounts it finds.\n";
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

    $session = ticketApiRequest(
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
    $missing = [];

    try {
        $computerIds = findTicketRelatedIds(
            $apiBase,
            $apiHeaders,
            'Computer',
            'glpi_computers',
            'otherserial',
            'DEMO-SIUDT-ACT-'
        );
        $technicianIds = findTicketRelatedIds(
            $apiBase,
            $apiHeaders,
            'User',
            'glpi_users',
            'name',
            'demo-siudt-tech-'
        );
        $computerIds = array_values(array_unique($computerIds));
        $technicianIds = array_values(array_unique($technicianIds));

        if ($computerIds === []) {
            $missing[] = 'demo computers';
        }
        if ($technicianIds === []) {
            $missing[] = 'demo technicians';
        }
        $actorCount = count($technicianIds);
        $computerCount = count($computerIds);

        $ticketOptions = ticketApiRequest(
            $apiBase,
            'GET',
            'listSearchOptions/Ticket',
            $apiHeaders
        );
        $ticketIdField = getTicketSearchFieldId($ticketOptions, 'glpi_tickets', 'id');
        $ticketNameField = getTicketSearchFieldId($ticketOptions, 'glpi_tickets', 'name');
        $query = http_build_query([
            'criteria' => [[
                'field' => $ticketNameField,
                'searchtype' => 'contains',
                'value' => '^DEMO-SIUDT-TICKET-',
            ]],
            'forcedisplay' => [$ticketIdField, $ticketNameField],
            'range' => '0-999',
        ]);
        $existingTickets = ticketApiRequest(
            $apiBase,
            'GET',
            "search/Ticket/?$query",
            $apiHeaders
        );
        $existingNames = [];
        foreach ($existingTickets['data'] ?? [] as $row) {
            if (isset($row[(string) $ticketNameField])) {
                $existingNames[(string) $row[(string) $ticketNameField]] = true;
            }
        }

        foreach ($tickets as $index => $ticket) {
            if (isset($existingNames[$ticket['name']])) {
                $skipped++;
                continue;
            }

            $userInput = $ticket + [
                'entities_id' => 0,
                '_users_id_requester' => $technicianIds === []
                    ? []
                    : [$technicianIds[$index % count($technicianIds)]],
                '_users_id_assign' => $technicianIds === []
                    ? []
                    : [$technicianIds[$index % count($technicianIds)]],
                'items_id' => $computerIds === []
                    ? []
                    : ['Computer' => [$computerIds[$index % count($computerIds)]]],
            ];

            try {
                $result = ticketApiRequest(
                    $apiBase,
                    'POST',
                    'Ticket/',
                    $apiHeaders,
                    ['input' => $userInput]
                );
            } catch (RuntimeException $exception) {
                throw new RuntimeException(
                    "Import stopped at {$ticket['name']} after $created tickets were created: "
                    . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            if (!isset($result['id']) || !is_numeric($result['id']) || (int) $result['id'] < 1) {
                throw new RuntimeException(
                    "GLPI did not confirm creation of {$ticket['name']} after $created tickets."
                );
            }

            $created++;
            echo "Created {$ticket['name']} (GLPI ID {$result['id']}).\n";
        }
    } finally {
        try {
            ticketApiRequest($apiBase, 'GET', 'killSession', $apiHeaders);
        } catch (Throwable $exception) {
            fwrite(STDERR, "Warning: could not close the GLPI API session: {$exception->getMessage()}\n");
        }
    }

    echo "Import complete: $created created, $skipped already present, "
        . count($tickets) . ' total.';
    if ($missing !== []) {
        echo ' No matching ' . implode(' or ', $missing)
            . ' were found; those associations were left empty.';
    } else {
        echo " All tickets are linked to demo computers and technician accounts.";
    }
    echo "\n";
    return 0;
}

try {
    exit(runTicketSeeder(array_slice($argv, 1)));
} catch (Throwable $exception) {
    fwrite(STDERR, "Import failed: {$exception->getMessage()}\n");
    exit(1);
}
