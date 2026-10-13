<?php
/**
 * Backend API REST - Inventario de Laboratorio (Dominio 6: Entidad "Equipo")
 * Servicios integrados:
 *  - MariaDB (Persistencia relacional mediante PDO)
 *  - Redis (Caché con TTL >= 30s e incremento de 'contador_consultas')
 *  - Mailpit (Notificación SMTP en puerto 1025 al registrar nuevo equipo)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// =============================================================================
// Configuración desde Variables de Entorno
// =============================================================================
$dbHost     = getenv('DB_HOST') ?: 'db';
$dbPort     = getenv('DB_PORT') ?: '3306';
$dbName     = getenv('DB_NAME') ?: 'inventario_db';
$dbUser     = getenv('DB_USER') ?: 'lab_admin';
$dbPass     = getenv('DB_PASSWORD') ?: '';

$redisHost  = getenv('REDIS_HOST') ?: 'redis';
$redisPort  = (int)(getenv('REDIS_PORT') ?: 6379);
$cacheTtl   = max(30, (int)(getenv('REDIS_CACHE_TTL') ?: 30));

$smtpHost   = getenv('SMTP_HOST') ?: 'mailpit';
$smtpPort   = (int)(getenv('SMTP_PORT') ?: 1025);
$smtpFrom   = getenv('SMTP_FROM') ?: 'notificaciones@laboratorio.local';
$smtpTo     = getenv('SMTP_TO') ?: 'admin@laboratorio.local';

const REDIS_CACHE_KEY   = 'equipos_cache';
const REDIS_COUNTER_KEY = 'contador_consultas';

// =============================================================================
// Funciones de Conexión y Utilidades
// =============================================================================

function getDbConnection(string $host, string $port, string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

function getRedisConnection(string $host, int $port): Redis
{
    $redis = new Redis();
    $redis->connect($host, $port, 2.5);
    return $redis;
}

/**
 * Envía un correo mediante protocolo SMTP directo hacia Mailpit (mailpit:1025)
 */
function sendSmtpNotification(
    string $smtpHost,
    int $smtpPort,
    string $from,
    string $to,
    array $equipo
): bool {
    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 3.0);
    if (!$socket) {
        error_log("[SMTP ERROR] No se pudo conectar a {$smtpHost}:{$smtpPort} - {$errstr} ({$errno})");
        return false;
    }

    $readResponse = static function ($fp): string {
        $data = '';
        while ($str = fgets($fp, 515)) {
            $data .= $str;
            if (substr($str, 3, 1) === ' ') {
                break;
            }
        }
        return $data;
    };

    $sendCommand = static function ($fp, string $cmd) use ($readResponse): string {
        fwrite($fp, $cmd . "\r\n");
        return $readResponse($fp);
    };

    $readResponse($socket); // Banner inicial 220
    $sendCommand($socket, "EHLO backend.app_network");
    $sendCommand($socket, "MAIL FROM:<{$from}>");
    $sendCommand($socket, "RCPT TO:<{$to}>");
    $sendCommand($socket, "DATA");

    $subjectPlain = "Nuevo Equipo Registrado en Laboratorio: {$equipo['nombre']}";
    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subjectPlain) . '?=';
    $dateHeader = date('r');

    $htmlBody = "
    <html>
    <body style='font-family: Arial, sans-serif; color: #1e293b;'>
        <h2 style='color: #0f766e;'>Inventario de Laboratorio - Alta de Equipo</h2>
        <p>Se ha registrado un nuevo equipo en el sistema de inventario:</p>
        <table border='1' cellpadding='8' cellspacing='0' style='border-collapse: collapse; border-color: #cbd5e1;'>
            <tr><th align='left' style='background:#f1f5f9;'>ID</th><td>#{$equipo['id']}</td></tr>
            <tr><th align='left' style='background:#f1f5f9;'>Nombre</th><td>" . htmlspecialchars($equipo['nombre']) . "</td></tr>
            <tr><th align='left' style='background:#f1f5f9;'>Marca</th><td>" . htmlspecialchars($equipo['marca']) . "</td></tr>
            <tr><th align='left' style='background:#f1f5f9;'>Número de Serie</th><td>" . htmlspecialchars($equipo['numero_serie']) . "</td></tr>
            <tr><th align='left' style='background:#f1f5f9;'>Ubicación</th><td>" . htmlspecialchars($equipo['ubicacion']) . "</td></tr>
            <tr><th align='left' style='background:#f1f5f9;'>Estado</th><td>" . htmlspecialchars($equipo['estado']) . "</td></tr>
        </table>
        <p style='font-size: 12px; color: #64748b; margin-top: 16px;'>
            Notificación automática enviada por el servicio Backend PHP a través de Mailpit (SMTP {$smtpHost}:{$smtpPort}).
        </p>
    </body>
    </html>";

    $headers = [
        "Date: {$dateHeader}",
        "From: Sistema de Inventario <{$from}>",
        "To: Administrador de Laboratorio <{$to}>",
        "Subject: {$subjectEncoded}",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
    $dataResp = $sendCommand($socket, $message);
    $sendCommand($socket, "QUIT");
    fclose($socket);

    $success = str_starts_with($dataResp, '250');
    error_log($success
        ? "[SMTP OK] Correo enviado a {$to} para el equipo ID #{$equipo['id']}"
        : "[SMTP WARN] Respuesta inesperada de SMTP: " . trim($dataResp)
    );
    return $success;
}

function sendJson(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// =============================================================================
// Enrutamiento de la Petición
// =============================================================================
$method = $_SERVER['REQUEST_METHOD'];
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Soportar tanto rutas directas (/equipos, /health) como prefijadas (/api/equipos, /api/health)
$normalizedPath = preg_replace('#^/api(?=/|$)#', '', $uriPath);
$normalizedPath = rtrim($normalizedPath, '/') ?: '/';
$segments = array_values(array_filter(explode('/', $normalizedPath)));

$resource = $segments[0] ?? 'equipos';
$resourceId = isset($segments[1]) && is_numeric($segments[1]) ? (int)$segments[1] : null;
if ($resourceId === null && isset($_GET['id']) && is_numeric($_GET['id'])) {
    $resourceId = (int)$_GET['id'];
}

// =============================================================================
// 1. Endpoint /health (Verificación de Conectividad con MariaDB y Redis)
// =============================================================================
if ($resource === 'health') {
    $health = [
        'status'    => 'healthy',
        'service'   => 'backend-inventario-laboratorio',
        'timestamp' => date('c'),
        'checks'    => [
            'mariadb' => ['status' => 'down'],
            'redis'   => ['status' => 'down'],
        ],
    ];
    $httpCode = 200;

    try {
        $pdo = getDbConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
        $pdo->query('SELECT 1');
        $health['checks']['mariadb'] = [
            'status' => 'up',
            'host'   => $dbHost,
            'port'   => (int)$dbPort,
        ];
    } catch (Throwable $e) {
        $health['status'] = 'unhealthy';
        $health['checks']['mariadb']['error'] = $e->getMessage();
        $httpCode = 503;
    }

    try {
        $redis = getRedisConnection($redisHost, $redisPort);
        $ping = $redis->ping();
        $health['checks']['redis'] = [
            'status'             => ($ping === true || $ping === '+PONG' || $ping === 'PONG') ? 'up' : 'down',
            'host'               => $redisHost,
            'port'               => $redisPort,
            'contador_consultas' => (int)($redis->get(REDIS_COUNTER_KEY) ?: 0),
            'cache_activa'       => (bool)$redis->exists(REDIS_CACHE_KEY),
            'cache_ttl_restante' => max(0, (int)$redis->ttl(REDIS_CACHE_KEY)),
        ];
    } catch (Throwable $e) {
        $health['status'] = 'unhealthy';
        $health['checks']['redis']['error'] = $e->getMessage();
        $httpCode = 503;
    }

    sendJson($httpCode, $health);
}

// Validar que el recurso solicitado sea 'equipos'
if ($resource !== 'equipos') {
    sendJson(404, [
        'error'   => 'Ruta no encontrada',
        'message' => "El recurso '{$resource}' no existe. Usa /equipos o /health.",
    ]);
}

// =============================================================================
// Inicializar Conexiones a MariaDB y Redis para el CRUD de Equipos
// =============================================================================
try {
    $pdo = getDbConnection($dbHost, $dbPort, $dbName, $dbUser, $dbPass);
} catch (Throwable $e) {
    error_log("[DB ERROR] " . $e->getMessage());
    sendJson(500, [
        'error'   => 'Error de conexión a MariaDB',
        'details' => $e->getMessage(),
    ]);
}

$redis = null;
try {
    $redis = getRedisConnection($redisHost, $redisPort);
} catch (Throwable $e) {
    error_log("[REDIS ERROR] " . $e->getMessage());
}

// =============================================================================
// 2. Operaciones CRUD sobre la entidad "Equipo"
// =============================================================================

switch ($method) {
    // -------------------------------------------------------------------------
    // GET /equipos o GET /equipos/{id}
    // -------------------------------------------------------------------------
    case 'GET':
        if ($resourceId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM equipos WHERE id = :id');
            $stmt->execute(['id' => $resourceId]);
            $equipo = $stmt->fetch();

            if (!$equipo) {
                sendJson(404, ['error' => "El equipo con ID #{$resourceId} no existe."]);
            }
            sendJson(200, ['data' => $equipo]);
        }

        // Mejora Individual: Exportación JSON descargable (?export=json)
        if (isset($_GET['export']) && $_GET['export'] === 'json') {
            $stmt = $pdo->query('SELECT * FROM equipos ORDER BY id DESC');
            $equipos = $stmt->fetchAll();
            header('Content-Disposition: attachment; filename="inventario_equipos.json"');
            sendJson(200, $equipos);
        }

        // Mejora Individual: Filtro de búsqueda en MariaDB (?buscar=termino)
        $buscar = trim((string)($_GET['buscar'] ?? ''));
        if ($buscar !== '') {
            $contador = $redis !== null ? (int)$redis->incr(REDIS_COUNTER_KEY) : 0;
            $stmt = $pdo->prepare(
                'SELECT * FROM equipos
                 WHERE nombre LIKE :q1 OR marca LIKE :q2 OR numero_serie LIKE :q3 OR ubicacion LIKE :q4
                 ORDER BY id DESC'
            );
            $like = "%{$buscar}%";
            $stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
            $equipos = $stmt->fetchAll();

            sendJson(200, [
                'source'             => 'database_search',
                'cache_hit'          => false,
                'cache_ttl_restante' => 0,
                'contador_consultas' => $contador,
                'filtro_buscar'      => $buscar,
                'total'              => count($equipos),
                'data'               => $equipos,
            ]);
        }

        // Consulta general del inventario: Incrementar contador_consultas y verificar caché en Redis
        $contador = 0;
        $ttlRestante = 0;

        if ($redis !== null) {
            $contador = (int)$redis->incr(REDIS_COUNTER_KEY);
            $cachedPayload = $redis->get(REDIS_CACHE_KEY);

            if ($cachedPayload !== false) {
                $ttlRestante = max(0, (int)$redis->ttl(REDIS_CACHE_KEY));
                error_log("[REDIS CACHE HIT] Sirviendo listado de equipos desde caché (TTL restante: {$ttlRestante}s, contador_consultas: {$contador})");
                $equipos = json_decode($cachedPayload, true);

                sendJson(200, [
                    'source'             => 'cache',
                    'cache_hit'          => true,
                    'cache_ttl_restante' => $ttlRestante,
                    'contador_consultas' => $contador,
                    'total'              => count($equipos),
                    'data'               => $equipos,
                ]);
            }
        }

        // Cache Miss: Consultar MariaDB y guardar resultado en Redis por al menos 30s
        $stmt = $pdo->query('SELECT * FROM equipos ORDER BY id DESC');
        $equipos = $stmt->fetchAll();

        if ($redis !== null) {
            $redis->setex(REDIS_CACHE_KEY, $cacheTtl, json_encode($equipos, JSON_UNESCAPED_UNICODE));
            $ttlRestante = $cacheTtl;
            error_log("[REDIS CACHE MISS] Consultando MariaDB y almacenando en Redis por {$cacheTtl}s (contador_consultas: {$contador})");
        }

        sendJson(200, [
            'source'             => 'database',
            'cache_hit'          => false,
            'cache_ttl_restante' => $ttlRestante,
            'contador_consultas' => $contador,
            'total'              => count($equipos),
            'data'               => $equipos,
        ]);

    // -------------------------------------------------------------------------
    // POST /equipos (Crear nuevo equipo + Invalidar caché Redis + Correo Mailpit)
    // -------------------------------------------------------------------------
    case 'POST':
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            sendJson(400, ['error' => 'El cuerpo de la petición debe ser JSON válido.']);
        }

        $nombre      = trim((string)($input['nombre'] ?? ''));
        $marca       = trim((string)($input['marca'] ?? ''));
        $numeroSerie = trim((string)($input['numero_serie'] ?? ''));
        $ubicacion   = trim((string)($input['ubicacion'] ?? 'Laboratorio General'));
        $estado      = trim((string)($input['estado'] ?? 'Disponible'));

        $estadosPermitidos = ['Disponible', 'En uso', 'En mantenimiento', 'Fuera de servicio'];
        if ($nombre === '' || $marca === '' || $numeroSerie === '') {
            sendJson(422, [
                'error' => 'Los campos nombre, marca y numero_serie son obligatorios.',
            ]);
        }
        if (!in_array($estado, $estadosPermitidos, true)) {
            sendJson(422, [
                'error' => 'Estado inválido. Valores permitidos: ' . implode(', ', $estadosPermitidos),
            ]);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO equipos (nombre, marca, numero_serie, ubicacion, estado)
                 VALUES (:nombre, :marca, :numero_serie, :ubicacion, :estado)'
            );
            $stmt->execute([
                'nombre'       => $nombre,
                'marca'        => $marca,
                'numero_serie' => $numeroSerie,
                'ubicacion'    => $ubicacion ?: 'Laboratorio General',
                'estado'       => $estado,
            ]);
            $newId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                sendJson(409, ['error' => "Ya existe un equipo con el número de serie '{$numeroSerie}'."]);
            }
            sendJson(500, ['error' => 'Error al registrar el equipo en MariaDB.', 'details' => $e->getMessage()]);
        }

        // Invalidar caché en Redis al crear un equipo
        if ($redis !== null) {
            $redis->del(REDIS_CACHE_KEY);
            error_log("[REDIS CACHE INVALIDATED] Clave '" . REDIS_CACHE_KEY . "' eliminada tras POST /equipos (ID #{$newId})");
        }

        $stmt = $pdo->prepare('SELECT * FROM equipos WHERE id = :id');
        $stmt->execute(['id' => $newId]);
        $nuevoEquipo = $stmt->fetch();

        // Enviar correo de notificación por SMTP a Mailpit (puerto 1025)
        $mailSent = sendSmtpNotification($smtpHost, $smtpPort, $smtpFrom, $smtpTo, $nuevoEquipo);

        sendJson(201, [
            'message'              => 'Equipo de laboratorio registrado exitosamente.',
            'cache_invalidated'    => true,
            'notificacion_enviada' => $mailSent,
            'data'                 => $nuevoEquipo,
        ]);

    // -------------------------------------------------------------------------
    // PUT /equipos/{id} (Actualizar equipo + Invalidar caché Redis)
    // -------------------------------------------------------------------------
    case 'PUT':
        if ($resourceId === null) {
            sendJson(400, ['error' => 'Se requiere especificar el ID del equipo a actualizar (/equipos/{id}).']);
        }

        $stmt = $pdo->prepare('SELECT * FROM equipos WHERE id = :id');
        $stmt->execute(['id' => $resourceId]);
        $existente = $stmt->fetch();
        if (!$existente) {
            sendJson(404, ['error' => "El equipo con ID #{$resourceId} no existe."]);
        }

        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            sendJson(400, ['error' => 'El cuerpo de la petición debe ser JSON válido.']);
        }

        $nombre      = trim((string)($input['nombre'] ?? $existente['nombre']));
        $marca       = trim((string)($input['marca'] ?? $existente['marca']));
        $numeroSerie = trim((string)($input['numero_serie'] ?? $existente['numero_serie']));
        $ubicacion   = trim((string)($input['ubicacion'] ?? $existente['ubicacion']));
        $estado      = trim((string)($input['estado'] ?? $existente['estado']));

        $estadosPermitidos = ['Disponible', 'En uso', 'En mantenimiento', 'Fuera de servicio'];
        if ($nombre === '' || $marca === '' || $numeroSerie === '') {
            sendJson(422, ['error' => 'Los campos nombre, marca y numero_serie no pueden quedar vacíos.']);
        }
        if (!in_array($estado, $estadosPermitidos, true)) {
            sendJson(422, ['error' => 'Estado inválido. Valores permitidos: ' . implode(', ', $estadosPermitidos)]);
        }

        try {
            $updateStmt = $pdo->prepare(
                'UPDATE equipos
                 SET nombre = :nombre,
                     marca = :marca,
                     numero_serie = :numero_serie,
                     ubicacion = :ubicacion,
                     estado = :estado
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'nombre'       => $nombre,
                'marca'        => $marca,
                'numero_serie' => $numeroSerie,
                'ubicacion'    => $ubicacion ?: 'Laboratorio General',
                'estado'       => $estado,
                'id'           => $resourceId,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                sendJson(409, ['error' => "Ya existe otro equipo con el número de serie '{$numeroSerie}'."]);
            }
            sendJson(500, ['error' => 'Error al actualizar el equipo.', 'details' => $e->getMessage()]);
        }

        // Invalidar caché en Redis al actualizar un equipo
        if ($redis !== null) {
            $redis->del(REDIS_CACHE_KEY);
            error_log("[REDIS CACHE INVALIDATED] Clave '" . REDIS_CACHE_KEY . "' eliminada tras PUT /equipos/{$resourceId}");
        }

        $stmt = $pdo->prepare('SELECT * FROM equipos WHERE id = :id');
        $stmt->execute(['id' => $resourceId]);
        $actualizado = $stmt->fetch();

        sendJson(200, [
            'message'           => 'Equipo actualizado correctamente.',
            'cache_invalidated' => true,
            'data'              => $actualizado,
        ]);

    // -------------------------------------------------------------------------
    // DELETE /equipos/{id} (Eliminar equipo + Invalidar caché Redis)
    // -------------------------------------------------------------------------
    case 'DELETE':
        if ($resourceId === null) {
            sendJson(400, ['error' => 'Se requiere especificar el ID del equipo a eliminar (/equipos/{id}).']);
        }

        $stmt = $pdo->prepare('SELECT * FROM equipos WHERE id = :id');
        $stmt->execute(['id' => $resourceId]);
        $existente = $stmt->fetch();
        if (!$existente) {
            sendJson(404, ['error' => "El equipo con ID #{$resourceId} no existe."]);
        }

        $delStmt = $pdo->prepare('DELETE FROM equipos WHERE id = :id');
        $delStmt->execute(['id' => $resourceId]);

        // Invalidar caché en Redis al eliminar un equipo
        if ($redis !== null) {
            $redis->del(REDIS_CACHE_KEY);
            error_log("[REDIS CACHE INVALIDATED] Clave '" . REDIS_CACHE_KEY . "' eliminada tras DELETE /equipos/{$resourceId}");
        }

        sendJson(200, [
            'message'           => "Equipo '{$existente['nombre']}' eliminado correctamente.",
            'cache_invalidated' => true,
            'deleted_id'        => $resourceId,
        ]);

    default:
        sendJson(405, ['error' => "Método HTTP '{$method}' no permitido."]);
}
