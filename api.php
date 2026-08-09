<?php
require_once __DIR__ . '/lib/store.php';

session_name('park_app');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => false]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, $data = null): void {
    http_response_code($status);
    if ($data !== null) { echo json_encode($data, JSON_UNESCAPED_UNICODE); }
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw === false ? '' : $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function require_auth(): void {
    if (empty($_SESSION['auth'])) { respond(401, ['error' => 'unauthorized']); }
}

$db = db_open();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'add': {
        $entryTime = read_json_body()['entry_time'] ?? null;
        if (!is_string($entryTime) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $entryTime)) {
            respond(400, ['error' => 'entry_time must be HH:MM']);
        }
        $rec = add_record($db, $entryTime);
        if ($rec === null) { respond(400, ['error' => 'entry_time must be HH:MM']); }
        respond(201, $rec);
        break;
    }
    case 'today': {
        respond(200, get_today($db));
        break;
    }
    case 'monthly': {
        $year = $_GET['year'] ?? null;
        $month = $_GET['month'] ?? null;
        $year = is_string($year) && ctype_digit($year) ? (int)$year : null;
        $month = is_string($month) && ctype_digit($month) ? (int)$month : null;
        $result = get_monthly_totals($db, $year, $month);
        if ($result === null) { respond(400, ['error' => 'year (2000-2100) and month (1-12) are required']); }
        respond(200, $result);
        break;
    }
    case 'delete': {
        require_auth();
        $id = $_GET['id'] ?? null;
        $id = is_string($id) && ctype_digit($id) ? (int)$id : null;
        if ($id === null || !delete_record($db, $id)) { respond(404, ['error' => 'not found']); }
        respond(204);
        break;
    }
    case 'login': {
        $pw = read_json_body()['pw'] ?? null;
        if (!is_string($pw) || !hash_equals(ADMIN_PW, $pw)) {
            sleep(1); // 監査F1: ブルートフォース抑止（誤PW時に固定遅延）
            respond(401, ['error' => 'unauthorized']);
        }
        session_regenerate_id(true);
        $_SESSION['auth'] = true;
        respond(200, ['ok' => true]);
        break;
    }
    case 'logout': {
        $_SESSION = [];
        session_destroy();
        respond(200, ['ok' => true]);
        break;
    }
    case 'day': {
        $date = $_GET['date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            respond(400, ['error' => 'invalid date']);
        }
        respond(200, get_day($db, $date));
        break;
    }
    case 'version': {
        respond(200, get_db_version($db));
        break;
    }
    case 'auth': {
        require_auth();
        respond(200, ['ok' => true]);
        break;
    }
    case 'update': {
        require_auth();
        $in = read_json_body();
        $id = $in['id'] ?? null;
        $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int)$id : null);
        if ($id === null || $id < 1) { respond(400, ['error' => 'invalid id']); }
        $entryTime = $in['entry_time'] ?? null;
        if ($entryTime !== null && (!is_string($entryTime) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $entryTime))) {
            respond(400, ['error' => 'invalid entry_time']);
        }
        $createdAt = $in['created_at'] ?? null;
        if ($createdAt !== null) {
            if (!is_string($createdAt) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $createdAt)) {
                respond(400, ['error' => 'invalid datetime']);
            }
            $createdAt .= ':00';
        }
        $result = update_record($db, $id, $entryTime, $createdAt);
        if ($result['error'] === 'not_found') { respond(404, ['error' => 'not found']); }
        if ($result['error'] === 'invalid_entry_time') { respond(400, ['error' => 'invalid entry_time']); }
        if ($result['error'] === 'invalid_datetime') { respond(400, ['error' => 'invalid datetime']); }
        respond(200, ['ok' => true, 'record' => $result['record']]);
        break;
    }
    case 'add_record': {
        require_auth();
        $in = read_json_body();
        $entryTime = $in['entry_time'] ?? null;
        if (!is_string($entryTime) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $entryTime)) {
            respond(400, ['error' => 'entry_time must be HH:MM']);
        }
        $date = $in['date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            respond(400, ['error' => 'invalid date']);
        }
        $rec = add_record($db, $entryTime, new DateTimeImmutable($date . ' ' . $entryTime . ':00', new DateTimeZone(APP_TZ)));
        if ($rec === null) { respond(400, ['error' => 'entry_time must be HH:MM']); }
        respond(201, $rec);
        break;
    }
    case 'stats': {
        require_auth();
        $year = $_GET['year'] ?? null;
        $month = $_GET['month'] ?? null;
        if (!is_string($year) || !preg_match('/^\d{4}$/', $year) || (int)$year < 2000 || (int)$year > 2100) {
            respond(400, ['error' => 'invalid period']);
        }
        $monthNorm = null;
        if ($month !== null) {
            if (!is_string($month) || !preg_match('/^\d{1,2}$/', $month) || (int)$month < 1 || (int)$month > 12) {
                respond(400, ['error' => 'invalid period']);
            }
            $monthNorm = sprintf('%02d', (int)$month);
        }
        $resp = ['year' => (int)$year] + get_stats($db, $year, $monthNorm);
        if ($monthNorm !== null) { $resp['month'] = (int)$monthNorm; }
        respond(200, $resp);
        break;
    }
    case 'yearly': {
        require_auth();
        $year = $_GET['year'] ?? null;
        if (!is_string($year) || !preg_match('/^\d{4}$/', $year) || (int)$year < 2000 || (int)$year > 2100) {
            respond(400, ['error' => 'invalid period']);
        }
        $result = get_yearly_totals($db, (int)$year);
        if ($result === null) { respond(400, ['error' => 'invalid period']); }
        respond(200, $result);
        break;
    }
    default:
        respond(404, ['error' => 'unknown action']);
}
