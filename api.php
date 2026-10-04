<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// Helper to read/write JSON flat-db
function getJsonDb($filename, $default = []) {
    global $dataDir;
    $path = $dataDir . '/' . $filename;
    if (!file_exists($path)) {
        file_put_contents($path, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $default;
    }
    $content = file_get_contents($path);
    return json_decode($content, true) ?: $default;
}

function saveJsonDb($filename, $data) {
    global $dataDir;
    $path = $dataDir . '/' . $filename;
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// 20 Initial Injection Molding Machines
function getInitialMachines() {
    $machines = [];
    $tonnages = [120, 120, 160, 160, 200, 200, 250, 250, 250, 300, 300, 350, 350, 400, 450, 500, 550, 650, 800, 1000];
    for ($i = 1; $i <= 20; $i++) {
        $code = 'M' . str_pad($i, 2, '0', STR_PAD_LEFT);
        $ton = $tonnages[$i - 1];
        $machines[$code] = [
            'code' => $code,
            'name' => "Máy $code (Haitian {$ton}T)",
            'tonnage' => $ton,
            'status' => 'running', // running, idle, breakdown, maintenance
            'current_mold' => 'K-' . rand(10, 30),
            'operator' => 'NV-' . rand(10, 40),
            'shift' => 'Ca 1',
            'last_checklist' => date('Y-m-d H:i'),
            'oee' => rand(78, 88),
            'scrap_rate' => round(rand(12, 28) / 10, 1)
        ];
    }
    return $machines;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get_machines':
        $machines = getJsonDb('machines.json', getInitialMachines());
        echo json_encode(['success' => true, 'data' => $machines]);
        break;

    case 'submit_checklist':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['machine_code'])) {
            echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ']);
            exit;
        }

        $checklists = getJsonDb('checklists.json', []);
        $input['id'] = 'CHK-' . date('Ymd-His');
        $input['created_at'] = date('Y-m-d H:i:s');
        array_unshift($checklists, $input);
        saveJsonDb('checklists.json', $checklists);

        // Update machine status
        $machines = getJsonDb('machines.json', getInitialMachines());
        $mCode = $input['machine_code'];
        if (isset($machines[$mCode])) {
            $machines[$mCode]['last_checklist'] = date('Y-m-d H:i');
            $machines[$mCode]['operator'] = $input['operator'] ?? $machines[$mCode]['operator'];
            $machines[$mCode]['shift'] = $input['shift'] ?? $machines[$mCode]['shift'];
            if (($input['has_issue'] ?? false) == true) {
                $machines[$mCode]['status'] = 'maintenance';
            }
            saveJsonDb('machines.json', $machines);
        }

        echo json_encode(['success' => true, 'id' => $input['id'], 'message' => 'Checklist đã được lưu thành công!']);
        break;

    case 'submit_kpi':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['machine_code'])) {
            echo json_encode(['success' => false, 'message' => 'Thiếu mã máy']);
            exit;
        }

        $kpis = getJsonDb('kpis.json', []);
        $input['id'] = 'KPI-' . date('Ymd-His');
        $input['created_at'] = date('Y-m-d H:i:s');

        // Calc OEE
        $plan = max(1, (int)($input['plan_output'] ?? 400));
        $actual = (int)($input['actual_output'] ?? 0);
        $scrap = (int)($input['scrap_count'] ?? 0);
        $downMins = (int)($input['downtime_minutes'] ?? 0);
        $shiftMins = 480; // 8 hours

        $avail = max(0, min(100, (($shiftMins - $downMins) / $shiftMins) * 100));
        $perf = max(0, min(100, ($actual / $plan) * 100));
        $qual = ($actual + $scrap) > 0 ? max(0, min(100, ($actual / ($actual + $scrap)) * 100)) : 100;
        $oee = round(($avail * $perf * $qual) / 10000, 1);
        $scrapRate = ($actual + $scrap) > 0 ? round(($scrap / ($actual + $scrap)) * 100, 1) : 0;

        $input['calc_oee'] = $oee;
        $input['scrap_rate'] = $scrapRate;

        array_unshift($kpis, $input);
        saveJsonDb('kpis.json', $kpis);

        // Update machine
        $machines = getJsonDb('machines.json', getInitialMachines());
        $mCode = $input['machine_code'];
        if (isset($machines[$mCode])) {
            $machines[$mCode]['oee'] = $oee;
            $machines[$mCode]['scrap_rate'] = $scrapRate;
            saveJsonDb('machines.json', $machines);
        }

        echo json_encode(['success' => true, 'oee' => $oee, 'scrap_rate' => $scrapRate, 'message' => 'Đã cập nhật KPI thành công!']);
        break;

    case 'report_incident':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['machine_code'])) {
            echo json_encode(['success' => false, 'message' => 'Thiếu thông tin sự cố']);
            exit;
        }

        $tickets = getJsonDb('tickets.json', []);
        $ticketId = 'TKT-' . date('Ymd-His');
        $newTicket = [
            'id' => $ticketId,
            'machine_code' => $input['machine_code'],
            'issue_type' => $input['issue_type'] ?? 'Khuyết tật bavia',
            'severity' => $input['severity'] ?? 'Vừa',
            'description' => $input['description'] ?? '',
            'photo_base64' => $input['photo_base64'] ?? '',
            'reported_by' => $input['reported_by'] ?? 'Công nhân ca',
            'assigned_to' => 'Tổ Kỹ Thuật / QA',
            'status' => 'Pending', // Pending, In Progress, Closed
            'corrective_action' => '',
            'closure_photo' => '',
            'created_at' => date('Y-m-d H:i:s'),
            'due_date' => date('Y-m-d', strtotime('+2 days'))
        ];

        array_unshift($tickets, $newTicket);
        saveJsonDb('tickets.json', $tickets);

        // Mark machine as issue if severe
        $machines = getJsonDb('machines.json', getInitialMachines());
        $mCode = $input['machine_code'];
        if (isset($machines[$mCode])) {
            $machines[$mCode]['status'] = 'breakdown';
            saveJsonDb('machines.json', $machines);
        }

        echo json_encode(['success' => true, 'ticket_id' => $ticketId, 'message' => 'Đã mở Ticket sự cố 5W2H/8D thành công!']);
        break;

    case 'get_tickets':
        $tickets = getJsonDb('tickets.json', []);
        echo json_encode(['success' => true, 'data' => $tickets]);
        break;

    case 'update_ticket':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['ticket_id'])) {
            echo json_encode(['success' => false, 'message' => 'Thiếu mã ticket']);
            exit;
        }

        $tickets = getJsonDb('tickets.json', []);
        $found = false;
        foreach ($tickets as &$t) {
            if ($t['id'] === $input['ticket_id']) {
                $t['status'] = $input['status'] ?? $t['status'];
                if (!empty($input['corrective_action'])) $t['corrective_action'] = $input['corrective_action'];
                if (!empty($input['closure_photo'])) $t['closure_photo'] = $input['closure_photo'];
                if (!empty($input['assigned_to'])) $t['assigned_to'] = $input['assigned_to'];
                $found = true;
                break;
            }
        }

        if ($found) {
            saveJsonDb('tickets.json', $tickets);
            echo json_encode(['success' => true, 'message' => 'Cập nhật ticket thành công!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy ticket']);
        }
        break;

    default:
        echo json_encode(['success' => true, 'app' => 'ISO Gemba WebApp API', 'version' => '1.0']);
        break;
}
