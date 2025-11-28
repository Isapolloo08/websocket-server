<?php
// ============================================
// Environment Variables Setup for WebSocket
// ============================================
// Configure these with your Render server domain
$websocket_client_url = getenv('WEBSOCKET_CLIENT_URL');
$websocket_broadcast_url = getenv('WEBSOCKET_BROADCAST_URL');

// If env vars not set, use defaults (update these manually or set via putenv)
if (!$websocket_client_url) {
    $websocket_client_url = 'wss://websocket-server-abc123.onrender.com'; // UPDATE THIS
}
if (!$websocket_broadcast_url) {
    $websocket_broadcast_url = 'https://websocket-server-abc123.onrender.com/broadcast'; // UPDATE THIS
}

// Make available globally
putenv("WEBSOCKET_CLIENT_URL=$websocket_client_url");
putenv("WEBSOCKET_BROADCAST_URL=$websocket_broadcast_url");
$_ENV['WEBSOCKET_CLIENT_URL'] = $websocket_client_url;
$_ENV['WEBSOCKET_BROADCAST_URL'] = $websocket_broadcast_url;

// ============================================
// Database configuration
$servername = "localhost";
$username = "u792590767_vemed";
$password = "Vemed@08";
$dbname = "u792590767_vemed";

try {
    
    // Create PDO connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$conn = new mysqli($host, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Start session (if not already started)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// API Endpoint Handling
if (isset($_GET['action'])) {
    header("Content-Type: application/json");
    
    try {
        switch ($_GET['action']) {
            case 'get_dashboard_data':
                echo json_encode(getDashboardData($conn));
                break;

            case 'get_rfid_cards':
                echo json_encode(getRfidCards($conn));
                break;

            case 'get_inventory':
                echo json_encode(getMedicineInventory($conn));
                break;
                
            case 'get_user_records':
                echo json_encode(getUserRecords($conn));
                break;
                
            case 'log_rfid_tap':
                // Handle both POST form data and JSON input
                $inputData = [];
                if (!empty($_POST)) {
                    $inputData = $_POST;
                } else {
                    $jsonInput = file_get_contents('php://input');
                    if (!empty($jsonInput)) {
                        $inputData = json_decode($jsonInput, true);
                    }
                }
                
                // Log to original table AND update display table
                $logResult = logRfidTap($conn, $inputData);
                $displayResult = updateRfidDisplay($conn, $inputData);
                
                echo json_encode($displayResult);
                break;

            case 'get_current_card':
                echo json_encode(getCurrentDisplayCard($conn));
                break;



    case 'get_patient_records':
    echo json_encode(getPatientRecords($conn));
    break;
                
            case 'record_dispense':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    echo json_encode(recordDispense($conn, $data));
                }
                break;
                
            case 'get_rfid_status':
                echo json_encode(getRfidStatus($conn));
                break;
                
            case 'process_rfid_dispense':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    echo json_encode(processRfidDispense($conn, $data));
                }
                break;
                
            case 'card_detected':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    echo json_encode(handleCardDetected($conn, $data));
                }
                break;
                
            case 'get_recent_events':
                echo json_encode(getRecentEvents($conn));
                break;

            case 'get_latest_card':
                echo json_encode(getLatestCard($conn));
                break;
                
            case 'unlink_rfid_card':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $data = json_decode(file_get_contents('php://input'), true);
                    echo json_encode(unlinkRfidCard($conn, $data));
                }
                break;

            // Temperature monitoring endpoints
            case 'get_temperature_data':
                echo json_encode(getTemperatureData($conn));
                break;
                
            case 'log_temperature':
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Your existing database logging
        $logResult = logTemperature($conn, $data);
        
        // Broadcast to WebSocket
        $broadcastResult = broadcastToWebSocket($data);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Temperature logged and broadcasted',
            'database_log' => $logResult,
            'websocket_broadcast' => $broadcastResult
        ]);
    }
    break;
                
                
            case 'get_temperature_alerts':
                echo json_encode(getTemperatureAlerts($conn));
                break;
                
            default:
                echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        error_log("API Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Get nurse data
$nurse_id = $_SESSION['nurse_id'];
$stmt = $pdo->prepare("SELECT `id`, `type`, `username`, `password_hash`, `full_name`, `email`, `is_admin`, `status`, `created_at`, `updated_at` FROM `nurses` WHERE id = ?");
$stmt->execute([$nurse_id]);
$nurse = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$nurse) {
    header("Location: mainhome.php");
    exit;
}

// Determine role display text
$role_display = "Nurse";
if ($nurse['is_admin']) {
    $role_display = "Admin Nurse";
} elseif ($nurse['type'] === 'head') {
    $role_display = "Head Nurse";
}

// Temperature monitoring functions
function getTemperatureData($conn) {
    $result = ['status' => 'error', 'data' => null];
    
    try {
        // Get latest temperature reading
        $sql = "SELECT * FROM temperature_log 
                ORDER BY reading_time DESC 
                LIMIT 1";
        $res = $conn->query($sql);
        
        if ($res && $row = $res->fetch_assoc()) {
            // Calculate status
            $temp = floatval($row['temperature']);
            $min_temp = 15.0;
            $max_temp = 30.0;
            
            if ($temp < $min_temp) {
                $status = 'too_cold';
                $status_text = 'TOO COLD';
                $alert_level = 'danger';
            } else if ($temp > $max_temp) {
                $status = 'too_hot';
                $status_text = 'TOO HOT';
                $alert_level = 'danger';
            } else {
                $status = 'ideal';
                $status_text = 'IDEAL';
                $alert_level = 'success';
            }
            
            $row['status'] = $status;
            $row['status_text'] = $status_text;
            $row['alert_level'] = $alert_level;
            $row['min_temp'] = $min_temp;
            $row['max_temp'] = $max_temp;
            $row['safe_range'] = "{$min_temp}°C to {$max_temp}°C";
            
            $result = ['status' => 'success', 'data' => $row];
        } else {
            $result = ['status' => 'success', 'data' => null, 'message' => 'No temperature data available'];
        }
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

function logTemperature($conn, $data) {
    // Validate required data
    if (!isset($data['temperature']) || !is_numeric($data['temperature'])) {
        return ['status' => 'error', 'message' => 'Valid temperature value is required'];
    }
    
    $temperature = floatval($data['temperature']);
    $device_id = isset($data['device_id']) ? $data['device_id'] : 'vendo_machine_1';
    $location = isset($data['location']) ? $data['location'] : 'Medicine Storage';
    
    try {
        // Insert temperature reading
        $stmt = $conn->prepare("INSERT INTO temperature_log (device_id, location, temperature, reading_time) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("ssd", $device_id, $location, $temperature);
        
        if ($stmt->execute()) {
            // Check if temperature is outside safe range and create alert if needed
            $min_temp = 15.0;
            $max_temp = 30.0;
            
            if ($temperature < $min_temp || $temperature > $max_temp) {
                $alert_type = $temperature < $min_temp ? 'low_temperature' : 'high_temperature';
                $message = $temperature < $min_temp ? 
                    "Temperature too low: {$temperature}°C (below minimum {$min_temp}°C)" : 
                    "Temperature too high: {$temperature}°C (above maximum {$max_temp}°C)";
                
                $stmt = $conn->prepare("INSERT INTO temperature_alerts (device_id, location, temperature, alert_type, message, alert_time) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssdss", $device_id, $location, $temperature, $alert_type, $message);
                $stmt->execute();
            }
            
            return [
                'status' => 'success',
                'message' => 'Temperature logged successfully',
                'data' => [
                    'temperature' => $temperature,
                    'device_id' => $device_id,
                    'location' => $location,
                    'reading_time' => date('Y-m-d H:i:s')
                ]
            ];
        } else {
            return ['status' => 'error', 'message' => 'Failed to log temperature'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function getTemperatureAlerts($conn) {
    $result = ['status' => 'error', 'data' => []];
    
    try {
        // Get recent temperature alerts (last 24 hours)
        $sql = "SELECT * FROM temperature_alerts 
                WHERE alert_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY alert_time DESC 
                LIMIT 50";
        $res = $conn->query($sql);
        
        if ($res) {
            $alerts = [];
            while ($row = $res->fetch_assoc()) {
                $row['alert_time_ago'] = timeAgo($row['alert_time']);
                $row['is_resolved'] = !empty($row['resolved_at']);
                $alerts[] = $row;
            }
            $result = ['status' => 'success', 'data' => $alerts];
        }
    } catch (Exception $e) {
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

function getLatestCard($conn) {
    $result = ['status' => 'error', 'data' => null];
    try {
        $sql = "SELECT 
                    rcl.*, 
                    u.employee_id, 
                    u.student_id,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name,
                    u.year_level, 
                    u.block,
                    u.type as user_type
                FROM rfid_card_log rcl
                LEFT JOIN users u ON u.id = rcl.user_id
                ORDER BY rcl.tap_time DESC
                LIMIT 1";
        $res = $conn->query($sql);
        if ($res && $row = $res->fetch_assoc()) {
            $uid = $row['card_uid'] ?? '';
            $row['card_uid_masked'] = strlen($uid) > 8 ? substr($uid, 0, 4) . '****' . substr($uid, -4) : $uid;
            $row['registered'] = !empty($row['user_id']);
            $result = ['status' => 'success', 'data' => $row];
        }
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = $e->getMessage();
    }
    return $result;
}
function broadcastToWebSocket($data) {
    $defaultUrl = 'https://websocket-server-71q6q786m-markandrieremot25-gmailcoms-projects.vercel.app/broadcast';
    $vercelWebsocketUrl = getenv('WEBSOCKET_BROADCAST_URL') ? getenv('WEBSOCKET_BROADCAST_URL') : $defaultUrl;
    $apiToken = getenv('WEBSOCKET_API_TOKEN');

    $payload = json_encode([
        'temperature' => isset($data['temperature']) ? $data['temperature'] : null,
        'device_id' => isset($data['device_id']) ? $data['device_id'] : 'vendo_machine_1',
        'location' => isset($data['location']) ? $data['location'] : 'Medicine Storage',
        'alert' => isset($data['alert']) ? $data['alert'] : null,
        'message' => isset($data['message']) ? $data['message'] : null,
        'min_temp' => isset($data['min_temp']) ? $data['min_temp'] : null,
        'max_temp' => isset($data['max_temp']) ? $data['max_temp'] : null,
        'source' => 'hostinger'
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $vercelWebsocketUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ];
    if ($apiToken) {
        $headers[] = 'Authorization: Bearer ' . $apiToken;
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => ($httpCode >= 200 && $httpCode < 300),
        'http_code' => $httpCode,
        'response' => $response,
        'curl_error' => $curlErr
    ];
}
function logRfidTap($conn, $data) {
    // Handle form-encoded data from ESP32
    if (empty($data) || (!isset($data['card_uid']) && isset($_POST['card_uid']))) {
        $data = $_POST;
    }
    
    // Also handle JSON input as fallback
    if (empty($data) || !isset($data['card_uid'])) {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input && isset($input['card_uid'])) {
            $data = $input;
        }
    }
    
    // Validate required data
    if (!isset($data['card_uid']) || empty($data['card_uid'])) {
        return [
            'status' => 'error', 
            'message' => 'Card UID is required',
            'debug_data' => $data,
            'post_data' => $_POST,
            'raw_input' => file_get_contents('php://input')
        ];
    }
    
    $cardUid = trim($data['card_uid']);
    $user = getUserByRfid($conn, $cardUid);

    $status = 'unregistered';
    $userId = null;
    $fullName = null;
    $yearLevel = null;
    $block = null;
    $userType = null;
    $identifier = null;

    if ($user) {
        $status = $user['status'] ? 'registered' : 'inactive';
        $userId = $user['id'];
        $fullName = $user['first_name'] . ' ' . $user['last_name'];
        $yearLevel = $user['year_level'] ?? null;
        $block = $user['block'] ?? null;
        $userType = $user['type'];
        $identifier = $userType === 'student' ? $user['student_id'] : $user['employee_id'];
    }

    // Insert into log
    $sql = "INSERT INTO rfid_card_log (card_uid, status, user_id, tap_time) 
            VALUES (?, ?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE 
            status = VALUES(status), user_id = VALUES(user_id)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $cardUid, $status, $userId);
    $stmt->execute();

    return [
        'status' => 'success',
        'card_uid' => $cardUid,
        'card_uid_masked' => substr($cardUid, 0, 4) . str_repeat('*', strlen($cardUid) - 4),
        'user_id' => $userId,
        'full_name' => $fullName,
        'user_type' => $userType,
        'identifier' => $identifier,
        'year_level' => $yearLevel,
        'block' => $block,
        'registered' => $status === 'registered',
        'tap_time' => date('c'), // ISO 8601 timestamp
    ];
}

function updateRfidDisplay($conn, $data) {
    if (empty($data['card_uid'])) {
        return ['status' => 'error', 'message' => 'Card UID required'];
    }

    $cardUid = trim($data['card_uid']);
    $cardUidMasked = strlen($cardUid) > 8 ? 
        substr($cardUid, 0, 4) . '****' . substr($cardUid, -4) : $cardUid;
    
    // Check if user exists
    $user = getUserByRfid($conn, $cardUid);
    
    $userId = null;
    $fullName = null;
    $identifier = null;
    $userType = null;
    $yearLevel = null;
    $block = null;
    $registered = false;
    
    if ($user) {
        $userId = $user['id'];
        $fullName = $user['first_name'] . ' ' . $user['last_name'];
        $userType = $user['type'];
        $identifier = $userType === 'student' ? $user['student_id'] : $user['employee_id'];
        $yearLevel = $user['year_level'] ?? null;
        $block = $user['block'] ?? null;
        $registered = true;
    }
    
    // Clear old entries and insert new one
    $conn->query("DELETE FROM current_rfid_display WHERE expires_at < NOW()");
    
    // Insert current card (expires in 30 seconds)
    $stmt = $conn->prepare("
        INSERT INTO current_rfid_display 
        (card_uid, card_uid_masked, user_id, full_name, identifier, user_type, year_level, block, registered, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 SECOND))
        ON DUPLICATE KEY UPDATE
        card_uid_masked = VALUES(card_uid_masked),
        user_id = VALUES(user_id),
        full_name = VALUES(full_name),
        identifier = VALUES(identifier),
        user_type = VALUES(user_type),
        year_level = VALUES(year_level),
        block = VALUES(block),
        registered = VALUES(registered),
        tap_time = CURRENT_TIMESTAMP,
        expires_at = DATE_ADD(NOW(), INTERVAL 30 SECOND)
    ");
    
    $stmt->bind_param("ssisssssi", 
        $cardUid, $cardUidMasked, $userId, $fullName, 
        $identifier, $userType, $yearLevel, $block, $registered
    );
    
    $stmt->execute();
    
    return [
        'status' => 'success',
        'registered' => $registered,
        'user_data' => [
            'card_uid' => $cardUid,
            'card_uid_masked' => $cardUidMasked,
            'user_id' => $userId,
            'full_name' => $fullName,
            'identifier' => $identifier,
            'user_type' => $userType,
            'year_level' => $yearLevel,
            'block' => $block,
            'registered' => $registered,
            'tap_time' => date('Y-m-d H:i:s')
        ]
    ];
}

// Get current display card
function getCurrentDisplayCard($conn) {
    // Clean expired entries first
    $conn->query("DELETE FROM current_rfid_display WHERE expires_at < NOW()");
    
    // Get the most recent valid entry
    $stmt = $conn->query("
        SELECT * FROM current_rfid_display 
        WHERE expires_at > NOW() 
        ORDER BY tap_time DESC 
        LIMIT 1
    ");
    
    if ($stmt && $row = $stmt->fetch_assoc()) {
        return [
            'status' => 'success',
            'data' => $row
        ];
    }
    
    return [
        'status' => 'success',
        'data' => null
    ];
}

function getUserByRfid($conn, $cardUid) {
    $sql = "SELECT id, employee_id, student_id, first_name, last_name, type, status, year_level, block 
            FROM users 
            WHERE rfid_uid = ? AND status = 1 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("s", $cardUid);
    
    if (!$stmt->execute()) {
        error_log("Execute failed: " . $stmt->error);
        return null;
    }
    
    $result = $stmt->get_result();
    return $result->num_rows > 0 ? $result->fetch_assoc() : null;
}

function getRecentEvents($conn) {
    $result = [
        'status' => 'success',
        'data' => [
            'card_detections' => [],
            'recent_dispenses' => []
        ]
    ];
    
    try {
        // Get recent card detections from rfid_card_log table
        $sql = "SELECT 
                    rcl.*, 
                    u.employee_id, 
                    u.student_id,
                    CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name,
                    u.year_level, 
                    u.block,
                    u.type as user_type
                FROM rfid_card_log rcl
                LEFT JOIN users u ON u.id = rcl.user_id
                WHERE rcl.tap_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY rcl.tap_time DESC LIMIT 10";
        
        $res = $conn->query($sql);
        if ($res === false) {
            error_log("SQL Error in getRecentEvents: " . $conn->error);
        } else {
            while ($row = $res->fetch_assoc()) {
                // Add masked UID for UI display
                $uid = $row['card_uid'] ?? '';
                $row['card_uid_masked'] = strlen($uid) > 8 ? substr($uid, 0, 4) . '****' . substr($uid, -4) : $uid;
                $row['registered'] = !empty($row['user_id']);
                $result['data']['card_detections'][] = $row;
            }
        }
        
        // Get recent dispenses
        $sql = "SELECT v.*, 
                       u.employee_id, 
                       u.student_id,
                       CONCAT(u.first_name, ' ', u.last_name) as full_name, 
                       m.name as medicine_name 
                FROM vendo_transactions v
                LEFT JOIN users u ON v.user_id = u.id
                LEFT JOIN vendo_medicines m ON v.medicine_id = m.id
                WHERE v.transaction_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY v.transaction_time DESC LIMIT 5";
        
        $res = $conn->query($sql);
        if ($res === false) {
            error_log("SQL Error in recent_dispenses: " . $conn->error);
        } else {
            while ($row = $res->fetch_assoc()) {
                $result['data']['recent_dispenses'][] = $row;
            }
        }
        
    } catch (Exception $e) {
        error_log("Error in getRecentEvents: " . $e->getMessage());
        $result['status'] = 'error';
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

function getDashboardData($conn) {
    $response = [
        'status' => 'error',
        'message' => 'Initialization error',
        'data' => []
    ];

    try {
        // Check database connection
        if ($conn->connect_error) {
            throw new Exception("Database connection failed");
        }

        // Get today's dispense count (last 24 hours)
        $todayCount = 0;
        $todayQuery = "SELECT COUNT(*) as count FROM vendo_transactions 
                      WHERE transaction_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $todayResult = $conn->query($todayQuery);
        if ($todayResult) {
            $todayCount = $todayResult->fetch_assoc()['count'];
        }

        // Get last transaction (within 24 hours)
        $lastTransaction = null;
        $lastQuery = "SELECT v.*, 
                             u.employee_id, 
                             u.student_id,
                             CONCAT(u.first_name, ' ', u.last_name) as full_name,
                             m.name as medicine_name
                      FROM vendo_transactions v
                      LEFT JOIN users u ON v.user_id = u.id
                      LEFT JOIN vendo_medicines m ON v.medicine_id = m.id
                      WHERE v.transaction_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                      ORDER BY v.transaction_time DESC LIMIT 1";
        $lastResult = $conn->query($lastQuery);
        if ($lastResult && $lastResult->num_rows > 0) {
            $lastTransaction = $lastResult->fetch_assoc();
        }

        // Get recent transactions (last 24 hours, limit 10)
        $transactions = [];
        $transQuery = "SELECT v.*, 
                              u.employee_id, 
                              u.student_id,
                              CONCAT(u.first_name, ' ', u.last_name) as full_name,
                              m.name as medicine_name
                       FROM vendo_transactions v
                       LEFT JOIN users u ON v.user_id = u.id
                       LEFT JOIN vendo_medicines m ON v.medicine_id = m.id
                       WHERE v.transaction_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                       ORDER BY v.transaction_time DESC LIMIT 10";
        $transResult = $conn->query($transQuery);
        if ($transResult) {
            while ($row = $transResult->fetch_assoc()) {
                $transactions[] = $row;
            }
        }

        // Get RFID status
        $rfidStatus = getRfidStatus($conn);

        // Get temperature data
        $temperatureData = getTemperatureData($conn);

        $response = [
            'status' => 'success',
            'data' => [
                'today_count' => $todayCount,
                'last_transaction' => $lastTransaction,
                'transactions' => $transactions,
                'rfid_status' => $rfidStatus['data'],
                'temperature_data' => $temperatureData['data']
            ]
        ];

    } catch (Exception $e) {
        $response = [
            'status' => 'error',
            'message' => $e->getMessage(),
            'data' => []
        ];
    }

    return $response;
}

function getMedicineInventory($conn) {
    $result = [
        'status' => 'success',
        'data' => []
    ];
    
    $sql = "SELECT * FROM vendo_medicines ORDER BY name";
    $res = $conn->query($sql);
    
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ($row['store_stock'] <= 0) {
                $row['status'] = 'Out of Stock';
                $row['status_class'] = 'text-danger';
            } elseif ($row['store_stock'] < 10) {
                $row['status'] = 'Critical';
                $row['status_class'] = 'inventory-low';
            } elseif ($row['store_stock'] < $row['low_stock_threshold']) {
                $row['status'] = 'Low';
                $row['status_class'] = 'inventory-warning';
            } else {
                $row['status'] = 'Normal';
                $row['status_class'] = 'text-success';
            }
            
            $row['last_dispensed'] = $row['last_dispensed'] ? timeAgo($row['last_dispensed']) : 'Never';
            
            $result['data'][] = $row;
        }
    }
    
    return $result;
}

function getUserRecords($conn) {
    $result = [
        'status' => 'success',
        'data' => []
    ];
    
    $sql = "SELECT u.id, 
                   u.employee_id, 
                   u.student_id,
                   CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name,
                   u.type as user_type,
                   COUNT(v.id) as total_pills,
                   MAX(v.transaction_time) as last_dispensed,
                   (SELECT m.name FROM vendo_transactions vt 
                    JOIN vendo_medicines m ON vt.medicine_id = m.id
                    WHERE vt.user_id = u.id 
                    ORDER BY vt.transaction_time DESC LIMIT 1) as last_medicine
            FROM users u
            LEFT JOIN vendo_transactions v ON u.id = v.user_id
            WHERE u.status = 1
            GROUP BY u.id, u.employee_id, u.student_id, u.first_name, u.last_name, u.type
            ORDER BY total_pills DESC";
    
    $res = $conn->query($sql);
    
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['identifier'] = $row['user_type'] === 'student' ? $row['student_id'] : $row['employee_id'];
            $row['last_dispensed'] = $row['last_dispensed'] ? timeAgo($row['last_dispensed']) : 'Never';
            $row['last_medicine'] = $row['last_medicine'] ?: 'None';
            $result['data'][] = $row;
        }
    }
    
    return $result;
}

function recordDispense($conn, $data) {
    if (empty($data['user_id']) || empty($data['medicine_id'])) {
        return ['status' => 'error', 'message' => 'Missing required fields: user_id and medicine_id'];
    }
    
    if (!is_numeric($data['medicine_id'])) {
        return ['status' => 'error', 'message' => 'Invalid medicine ID'];
    }
    
    $conn->begin_transaction();
    
    try {
        // Verify user exists
        if (is_numeric($data['user_id'])) {
            $stmt = $conn->prepare("SELECT id, employee_id, student_id, type FROM users WHERE id = ? AND status = 1");
            $stmt->bind_param("i", $data['user_id']);
        } else {
            $stmt = $conn->prepare("SELECT id, employee_id, student_id, type FROM users WHERE (employee_id = ? OR student_id = ?) AND status = 1");
            $stmt->bind_param("ss", $data['user_id'], $data['user_id']);
        }
        
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user) {
            throw new Exception("User not found with ID: " . $data['user_id']);
        }
        
        // Get current medicine count with row locking
        $stmt = $conn->prepare("SELECT id, name, current_count FROM vendo_medicines WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $data['medicine_id']);
        $stmt->execute();
        $medicine = $stmt->get_result()->fetch_assoc();
        
        if (!$medicine) {
            throw new Exception("Medicine not found with ID: " . $data['medicine_id']);
        }
        
        $currentCount = intval($medicine['current_count']);
        $quantity = isset($data['quantity']) ? intval($data['quantity']) : 1;
        
        if ($currentCount < $quantity) {
            throw new Exception("Insufficient stock for {$medicine['name']}. Available: {$currentCount}, Requested: {$quantity}");
        }
        
        $newCount = $currentCount - $quantity;
        
        // Record transaction
        $stmt = $conn->prepare("INSERT INTO vendo_transactions 
                              (user_id, medicine_id, quantity, previous_count, new_count, transaction_time)
                              VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iiiii", $user['id'], $data['medicine_id'], $quantity, $currentCount, $newCount);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to record transaction: " . $stmt->error);
        }
        
        $transactionId = $conn->insert_id;
        
        // Update medicine count and last dispensed time
        $stmt = $conn->prepare("UPDATE vendo_medicines SET current_count = ?, last_dispensed = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $newCount, $data['medicine_id']);
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to update medicine inventory: " . $stmt->error);
        }
        
        // Enhanced audit logging
        $nurseId = isset($_SESSION['nurse_id']) ? $_SESSION['nurse_id'] : null;
        $stmt = $conn->prepare("INSERT INTO audit_log 
                              (nurse_id, action, table_name, record_id, old_values, new_values, ip_address, action_time)
                              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        
        $identifier = $user['type'] === 'student' ? $user['student_id'] : $user['employee_id'];
        $action = "Vendo dispense: {$medicine['name']} (Qty: {$quantity}) to User {$identifier}";
        $oldValues = json_encode(['medicine' => $medicine['name'], 'count' => $currentCount]);
        $newValues = json_encode(['medicine' => $medicine['name'], 'count' => $newCount, 'quantity_dispensed' => $quantity]);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $stmt->bind_param("ississs", $nurseId, $action, 'vendo_transactions', $transactionId, $oldValues, $newValues, $ipAddress);
        $stmt->execute();
        
        $conn->commit();
        
        return [
            'status' => 'success',
            'message' => "Successfully dispensed {$quantity} {$medicine['name']}(s) to user {$identifier}",
            'data' => [
                'medicine_name' => $medicine['name'],
                'quantity_dispensed' => $quantity,
                'previous_count' => $currentCount,
                'new_count' => $newCount,
                'transaction_id' => $transactionId,
                'user_info' => $user
            ]
        ];
    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function unlinkRfidCard($conn, $data) {
    if (empty($data['card_id'])) {
        return ['status' => 'error', 'message' => 'Card ID is required'];
    }
    
    try {
        $stmt = $conn->prepare("UPDATE users SET rfid_uid = NULL WHERE id = ?");
        $stmt->bind_param("i", $data['card_id']);
        
        if ($stmt->execute()) {
            return ['status' => 'success', 'message' => 'RFID card unlinked successfully'];
        } else {
            return ['status' => 'error', 'message' => 'Failed to unlink RFID card'];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

function timeAgo($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') {
        return "Never";
    }
    
    try {
        // Assume the datetime from database is in UTC
        $utcTime = new DateTime($datetime, new DateTimeZone('UTC'));
        
        // Convert to local timezone
        $localTimeZone = new DateTimeZone('Asia/Manila');
        $utcTime->setTimezone($localTimeZone);
        
        $now = new DateTime('now', $localTimeZone);
        $timeDiff = $now->getTimestamp() - $utcTime->getTimestamp();
        
        if ($timeDiff < 60) return "Just now";
        if ($timeDiff < 3600) return floor($timeDiff / 60) . " min ago";
        if ($timeDiff < 86400) return floor($timeDiff / 3600) . " hr ago";
        if ($timeDiff < 604800) return floor($timeDiff / 86400) . " days ago";
        if ($timeDiff < 2592000) return floor($timeDiff / 604800) . " weeks ago";
        if ($timeDiff < 31536000) return floor($timeDiff / 2592000) . " months ago";
        return floor($timeDiff / 31536000) . " years ago";
    } catch (Exception $e) {
        error_log("Error in timeAgo function: " . $e->getMessage());
        return "Invalid date";
    }
}

function handleCardDetected($conn, $data) {
    // Validate input
    if (empty($data['card_uid'])) {
        return ['status' => 'error', 'message' => 'Card UID is required'];
    }

    $cardUid = trim($data['card_uid']);
    
    // Use prepared statements to prevent SQL injection
    $stmt = $conn->prepare("INSERT INTO rfid_card_log (card_uid, tap_time, status) VALUES (?, NOW(), 'unregistered')");
    $stmt->bind_param("s", $cardUid);
    
    if (!$stmt->execute()) {
        error_log("Failed to log RFID tap: " . $stmt->error);
        // Continue processing even if logging fails
    }
    $stmt->close();

    // Check if card is registered to a user
    $stmt = $conn->prepare("SELECT id, employee_id, student_id, 
                                   CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name, 
                                   year_level, block, status, type
                            FROM users 
                            WHERE rfid_uid = ? AND rfid_uid IS NOT NULL AND rfid_uid != ''");
    $stmt->bind_param("s", $cardUid);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        // Update the log with user_id and get the tap time
        $updateStmt = $conn->prepare("UPDATE rfid_card_log SET user_id = ? 
                     WHERE card_uid = ? 
                     ORDER BY tap_time DESC LIMIT 1");
        $updateStmt->bind_param("is", $user['id'], $cardUid);
        $updateStmt->execute();
        
        // Get the updated log entry with tap_time
        $timeStmt = $conn->prepare("SELECT tap_time FROM rfid_card_log 
                                  WHERE card_uid = ? 
                                  ORDER BY tap_time DESC LIMIT 1");
        $timeStmt->bind_param("s", $cardUid);
        $timeStmt->execute();
        $timeResult = $timeStmt->get_result();
        $logEntry = $timeResult->fetch_assoc();
        
        $updateStmt->close();
        $timeStmt->close();

        // Check for pending dispense requests
        $requestStmt = $conn->prepare("SELECT dr.id, dr.status, dri.medicine_id, m.name as medicine_name, dri.quantity 
                      FROM dispense_requests dr 
                      JOIN dispense_request_items dri ON dr.id = dri.request_id 
                      JOIN vendo_medicines m ON dri.medicine_id = m.id 
                      WHERE dr.user_id = ? 
                      AND dr.status = 'pending' 
                      AND dr.expiry_time > NOW() 
                      ORDER BY dr.request_time ASC 
                      LIMIT 1");
        $requestStmt->bind_param("i", $user['id']);
        $requestStmt->execute();
        $requestResult = $requestStmt->get_result();
        $request = $requestResult && $requestResult->num_rows > 0 ? $requestResult->fetch_assoc() : null;
        $requestStmt->close();

        return [
            'status' => 'success',
            'registered' => true,
            'user_active' => (bool)$user['status'],
            'user' => $user,
            'request' => $request,
            'card_uid' => $cardUid,
            'card_uid_masked' => substr($cardUid, 0, 4) . '****' . substr($cardUid, -4),
            'tap_time' => $logEntry['tap_time'],
            'message' => $request ? 
                "Card recognized. Ready to dispense {$request['quantity']} {$request['medicine_name']}(s)" : 
                'Card recognized but no pending prescriptions'
        ];
    } else {
        // For unregistered cards, also get the tap time
        $timeStmt = $conn->prepare("SELECT tap_time FROM rfid_card_log 
                                  WHERE card_uid = ? 
                                  ORDER BY tap_time DESC LIMIT 1");
        $timeStmt->bind_param("s", $cardUid);
        $timeStmt->execute();
        $timeResult = $timeStmt->get_result();
        $logEntry = $timeResult->fetch_assoc();
        $timeStmt->close();

        return [
            'status' => 'success',
            'registered' => false,
            'user_active' => false,
            'card_uid' => $cardUid,
            'card_uid_masked' => substr($cardUid, 0, 4) . '****' . substr($cardUid, -4),
            'tap_time' => $logEntry['tap_time'],
            'message' => 'Card not registered to any user'
        ];
    }
}

function getPatientRecords($conn) {
    $result = [
        'status' => 'success',
        'data' => []
    ];
    
    $sql = "SELECT u.id, 
                   u.employee_id, 
                   u.student_id,
                   u.type as user_type,
                   CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name,
                   COUNT(v.id) as total_pills,
                   MAX(v.transaction_time) as last_dispensed,
                   (SELECT m.name FROM vendo_transactions vt 
                    JOIN vendo_medicines m ON vt.medicine_id = m.id
                    WHERE vt.user_id = u.id 
                    ORDER BY vt.transaction_time DESC LIMIT 1) as last_medicine
            FROM users u
            LEFT JOIN vendo_transactions v ON u.id = v.user_id
            WHERE u.status = 1
            GROUP BY u.id, u.employee_id, u.student_id, u.first_name, u.last_name, u.type
            ORDER BY total_pills DESC";
    
    $res = $conn->query($sql);
    
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            // Set identifier based on user type
            $row['identifier'] = $row['user_type'] === 'student' ? $row['student_id'] : $row['employee_id'];
            $row['last_dispensed'] = $row['last_dispensed'] ? timeAgo($row['last_dispensed']) : 'Never';
            $row['last_medicine'] = $row['last_medicine'] ?: 'None';
            $result['data'][] = $row;
        }
    }
    
    return $result;
}

function getRfidCards($conn) {
    $result = [
        'status' => 'success',
        'data' => []
    ];
    
    $sql = "SELECT u.id, 
                   u.employee_id, 
                   u.student_id,
                   CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as full_name, 
                   u.type as user_type,
                   u.rfid_uid as card_uid, 
                   MAX(vt.transaction_time) as last_used,
                   u.created_at as registered_at
            FROM users u
            LEFT JOIN vendo_transactions vt ON u.id = vt.user_id
            WHERE u.rfid_uid IS NOT NULL AND u.rfid_uid != ''
            GROUP BY u.id, u.employee_id, u.student_id, u.first_name, u.last_name, u.type, u.rfid_uid, u.created_at
            ORDER BY u.first_name, u.last_name";
    
    $res = $conn->query($sql);
    
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['identifier'] = $row['user_type'] === 'student' ? $row['student_id'] : $row['employee_id'];
            $row['last_used_formatted'] = $row['last_used'] ? timeAgo($row['last_used']) : 'Never';
            $row['registered_at_formatted'] = $row['registered_at'] ? timeAgo($row['registered_at']) : 'Unknown';
            // Mask RFID for security
            $row['card_uid_masked'] = substr($row['card_uid'], 0, 4) . '****' . substr($row['card_uid'], -4);
            $result['data'][] = $row;
        }
    }
    
    return $result;
}

function getRfidStatus($conn) {
    $result = [
        'status' => 'success',
        'data' => [
            'connected' => true,  // Always show as connected since no heartbeat
            'status_text' => 'Ready to scan',
            'last_seen' => null,
            'pending_requests' => 0,
            'device_online' => true
        ]
    ];
    
    // Check for recent card detections
    $sql = "SELECT COUNT(*) as count FROM card_detections 
            WHERE detection_time > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
    $res = $conn->query($sql);
    
    if ($res) {
        $recentActivity = $res->fetch_assoc()['count'];
        if ($recentActivity > 0) {
            $result['data']['connected'] = true;
            $result['data']['last_seen'] = date('Y-m-d H:i:s');
        }
    }
    
    // Check for pending requests
    $sql = "SELECT COUNT(*) as count FROM dispense_requests 
            WHERE status = 'pending' AND expiry_time > NOW()";
    $res = $conn->query($sql);
    
    if ($res) {
        $result['data']['pending_requests'] = intval($res->fetch_assoc()['count']);
    }
    
    return $result;
}

function processRfidDispense($conn, $data) {
    // Accept both "card_uid" (new) and "rfid_uid" (legacy)
    $rfidUid = null;
    if (isset($data['card_uid']) && !empty(trim($data['card_uid']))) {
        $rfidUid = trim($data['card_uid']);
    } elseif (isset($data['rfid_uid']) && !empty(trim($data['rfid_uid']))) {
        $rfidUid = trim($data['rfid_uid']);
    }

    if (empty($rfidUid)) {
        return ['status' => 'error', 'message' => 'RFID UID is required'];
    }

    if (strlen($rfidUid) < 4) {
        return ['status' => 'error', 'message' => 'Invalid RFID UID format'];
    }

    $conn->begin_transaction();

    try {
        // Find user by RFID
        $stmt = $conn->prepare("
            SELECT u.id AS user_id, 
                   u.employee_id, 
                   u.student_id,
                   u.type as user_type,
                   CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) AS full_name
            FROM users u
            WHERE UPPER(u.rfid_uid) = UPPER(?) 
              AND u.rfid_uid IS NOT NULL 
              AND u.rfid_uid != ''
              AND u.status = 1
        ");
        $stmt->bind_param("s", $rfidUid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if (!$user) {
            throw new Exception("No user registered with RFID: " . substr($rfidUid, 0, 8) . "...");
        }

        $stmt = $conn->prepare("
            SELECT dr.id, dr.user_id
            FROM dispense_requests dr
            WHERE dr.user_id = ? 
              AND dr.status = 'pending' 
              AND dr.is_used = 0
              AND dr.expiry_time > NOW()
            ORDER BY dr.request_time ASC 
            LIMIT 1
        ");
        $stmt->bind_param("i", $user['user_id']);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();

        if (!$request) {
            throw new Exception("No valid prescription found for user: " . $user['full_name']);
        }

        // Fetch ALL request items
        $stmt = $conn->prepare("
            SELECT id, medicine_id, medicine_name, quantity
            FROM dispense_request_items
            WHERE request_id = ?
        ");
        $stmt->bind_param("i", $request['id']);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (!$items) {
            throw new Exception("No medicines found in prescription request");
        }

        $dispensedItems = [];

        foreach ($items as $item) {
            // Get medicine details including servo channel number
            $stmt = $conn->prepare("
                SELECT id, name, current_count, store_stock, separate_stock, channel_number 
                FROM vendo_medicines 
                WHERE id = ? FOR UPDATE
            ");
            $stmt->bind_param("i", $item['medicine_id']);
            $stmt->execute();
            $medicine = $stmt->get_result()->fetch_assoc();

            if (!$medicine) {
                throw new Exception("Medicine not found: " . $item['medicine_name']);
            }

            $currentCount = intval($medicine['current_count']);
            $storeStock   = intval($medicine['store_stock']);
            $separateStock = intval($medicine['separate_stock']);
            $requestedQuantity = intval($item['quantity']);
            $channelNumber = isset($medicine['channel_number']) ? intval($medicine['channel_number']) : null;

            if ($currentCount < $requestedQuantity) {
                throw new Exception("Insufficient {$medicine['name']} in stock. Available: {$currentCount}, Needed: {$requestedQuantity}");
            }

            // Reduce from storeStock first
            $deductFromStore = min($requestedQuantity, $storeStock);
            $storeStock -= $deductFromStore;

            $remaining = $requestedQuantity - $deductFromStore;
            if ($remaining > 0) {
                // Reduce from separateStock
                if ($separateStock < $remaining) {
                    throw new Exception("Separate stock inconsistency for {$medicine['name']}");
                }
                $separateStock -= $remaining;
            }

            $newCount = $storeStock + $separateStock;

            // Record vendo transaction
            $stmt = $conn->prepare("
                INSERT INTO vendo_transactions 
                    (user_id, medicine_id, quantity, previous_count, new_count, transaction_time)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("iiiii", $user['user_id'], $item['medicine_id'], $requestedQuantity, $currentCount, $newCount);
            $stmt->execute();
            $transactionId = $conn->insert_id;

            // Update medicine stock (both store + separate + total)
            $stmt = $conn->prepare("
                UPDATE vendo_medicines 
                SET store_stock = ?, separate_stock = ?, current_count = ?, last_dispensed = NOW() 
                WHERE id = ?
            ");
            $stmt->bind_param("iiii", $storeStock, $separateStock, $newCount, $item['medicine_id']);
            $stmt->execute();

            // Mark dispense request item
            $stmt = $conn->prepare("UPDATE dispense_request_items SET status = 'dispensed', dispense_time = NOW() WHERE id = ?");
            $stmt->bind_param("i", $item['id']);
            $stmt->execute();

            // Collect for response
            $dispensedItems[] = [
                'medicine' => $medicine['name'],
                'quantity' => $requestedQuantity,
                'previous_count' => $currentCount,
                'new_count' => $newCount,
                'transaction_id' => $transactionId,
                'channel_number' => $channelNumber
            ];
        }

        // Mark whole request as dispensed
        $stmt = $conn->prepare("UPDATE dispense_requests SET status = 'dispensed', is_used = 1, dispense_time = NOW() WHERE id = ?");
        $stmt->bind_param("i", $request['id']);
        $stmt->execute();

        $conn->commit();

        return [
            'status' => 'success',
            'message' => 'Medication dispensed successfully via RFID',
            'data' => [
                'user' => $user,
                'items' => $dispensedItems,
                'card_uid' => substr($rfidUid, 0, 8) . '...'
            ]
        ];
    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
?>

<!-- HTML section remains the same as in your original code -->
<!-- The HTML and JavaScript don't need significant changes, just some text updates -->
<html lang="en">
<head>
    <!-- WebSocket Configuration - injected from environment -->
    <script>
      // Configure WebSocket client URL from environment variables
      // Set these env vars on your host (Hostinger / Render):
      //   WEBSOCKET_CLIENT_URL = wss://your-websocket-server.com
      //   WEBSOCKET_BROADCAST_URL = https://your-websocket-server.com/broadcast (used by PHP)
      const WEBSOCKET_URL = '<?php echo htmlspecialchars(getenv("WEBSOCKET_CLIENT_URL") ?: "wss://your-realtime-host.example.com"); ?>';
      console.log('WebSocket URL configured:', WEBSOCKET_URL);
    </script>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pill Vendo Monitor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
     <link rel="icon" type="image/png" sizes="192x192" href="../assets/css/vemedSystem_logo1.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2c3e50;
            --accent-color: #e74c3c;
            --light-gray: #f8f9fa;
            --dark-gray: #343a40;
        }

        body {
            background-color: #f5f7fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 20px;
        }
        
        .monitor-container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .monitor-header {
            background-color: var(--secondary-color);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .status-card {
            height: 100%;
        }
        
        .status-value {
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        .transaction-history {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .transaction-item {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            font-size: 0.95rem;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .student-badge {
            background-color: #e9ecef;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
        }
        
        .student-badge i {
            margin-right: 5px;
        }
        
        .last-updated {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .real-time-blink {
            animation: blink 2s infinite;
        }
        
        @keyframes blink {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .inventory-low {
            color: #e74c3c;
            font-weight: bold;
        }
        
        .inventory-warning {
            color: #f39c12;
            font-weight: bold;
        }
        
        .tab-content {
            padding: 20px 0;
        }

        .scanner-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 25px;
            margin: 20px 0;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .scanner-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            z-index: 1;
        }
        
        .scanner-content {
            position: relative;
            z-index: 2;
        }
        
        .scanner-active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            animation: pulse 2s infinite;
        }
        
        .scanner-offline {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        }
        
        .scanner-warning {
            background: linear-gradient(135deg, #ffd32a 0%, #ff8235 100%);
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(79, 172, 254, 0.7); }
            70% { box-shadow: 0 0 0 20px rgba(79, 172, 254, 0); }
            100% { box-shadow: 0 0 0 0 rgba(79, 172, 254, 0); }
        }
        
        .rfid-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
            color: #333;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .card-placeholder {
            background: rgba(255, 255, 255, 0.1);
            border: 2px dashed rgba(255, 255, 255, 0.3);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            margin-top: 15px;
            color: rgba(255, 255, 255, 0.8);
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .badge-online {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
            border: 1px solid #28a745;
        }
        
        .badge-offline {
            background: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        
        .badge-warning {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid #ffc107;
        }
        
        .student-info-row {
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
            min-width: 100px;
            display: inline-block;
        }
        
        .info-value {
            color: #333;
            font-weight: 500;
        }
        
        .card-uid-display {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 6px;
            border-left: 4px solid #007bff;
            font-size: 0.9rem;
            letter-spacing: 1px;
        }
        
        .prescription-alert {
            background: linear-gradient(90deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 10px;
            font-weight: 500;
        }
        
        .scanner-icon {
            font-size: 2.5rem;
            margin-bottom: 10px;
            opacity: 0.9;
        }
        
        .last-update {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-top: 10px;
        }
        
        .manual-entry {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .manual-entry input {
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            width: 100%;
            font-family: 'Courier New', monospace;
        }
        
        .simulate-controls {
            background: rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .simulate-btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            margin: 2px;
            font-size: 0.8rem;
        }
        
        .simulate-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            color: white;
        }
        
        .navbar {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            background-color: white;
        }
        
        .navbar-brand {
            font-weight: 700;
            color: var(--secondary-color);
        }

        .nav-link {
            color: var(--secondary-color);
            font-weight: 500;
            font-size: 13px;
        }
        
        .rfid-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background-color: #f9f9f9;
        }
        
        .rfid-unregistered {
            border-left: 4px solid #6c757d;
        }
        
        .rfid-registered {
            border-left: 4px solid #28a745;
        }
        
        .card-status {
            font-size: 0.85rem;
            padding: 3px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .status-registered {
            background-color: #d4edda;
            color: #155724;
        }
        
        .status-unregistered {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .nav-item.vendo-machine {
            background-color: rgba(52, 152, 219, 0.1);
            border-radius: 5px;
            margin-left: 10px;
        }
        
        .nav-item.vendo-machine .nav-link {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .nav-item.vendo-machine .nav-link:hover {
            color: white;
            background-color: var(--primary-color);
            border-radius: 5px;
        }
        
        #rfid-scanner {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .scanner-active {
            animation: pulse 1.5s infinite;
            border: 2px solid #28a745;
        }
        
        .scanner-offline {
            border: 2px solid #dc3545;
            background-color: #f8d7da;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(40, 167, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(40, 167, 69, 0); }
        }
        
        .device-status-online {
            color: #28a745;
        }
        
        .device-status-offline {
            color: #dc3545;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
            min-width: 300px;
        }

        /* NEW: Temperature monitoring styles */
        .temperature-container {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 25px;
            margin: 20px 0;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .temperature-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            z-index: 1;
        }
        
        .temperature-content {
            position: relative;
            z-index: 2;
        }
        
        .temperature-display {
            font-size: 3rem;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
        }
        
        .temperature-ideal {
            color: #28a745;
        }
        
        .temperature-warning {
            color: #ffc107;
        }
        
        .temperature-danger {
            color: #dc3545;
            animation: blink 1s infinite;
        }
        
        .temperature-status {
            text-align: center;
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
        
        .temperature-range {
            text-align: center;
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .temperature-alert {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid rgba(220, 53, 69, 0.5);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            text-align: center;
        }
        
        .temperature-normal {
            background: rgba(40, 167, 69, 0.2);
            border: 1px solid rgba(40, 167, 69, 0.5);
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            text-align: center;
        }
        
        .temperature-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 20px;
            margin-top: 15px;
            color: #333;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        /* Profile Picture Styles */
.profile-picture-container {
    width: 40px;
    height: 40px;
}

.profile-picture {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border: 2px solid #e9ecef;
    transition: border-color 0.3s ease;
}

.profile-picture:hover {
    border-color: #3498db;
}

.profile-placeholder {
    width: 80%;
    height: 80%;
    background-color: #3498db;
    color: white;
    font-size: 1.2rem;
    border: 2px solid #e9ecef;
}

.profile-picture-sm {
    width: 32px;
    height: 32px;
    object-fit: cover;
}

.profile-placeholder-sm {
    width: 32px;
    height: 32px;
    background-color: #3498db;
    color: white;
    font-size: 0.9rem;
}

/* Dropdown Header Styling */
.dropdown-header {
    padding: 0.75rem 1rem;
    background-color: #f8f9fa;
}

/* Dropdown Item Styling */
.dropdown-item {
    padding: 0.5rem 1rem;
    transition: background-color 0.3s ease;
}

.dropdown-item:hover {
    background-color: #e9ecef;
}
    </style>
</head>

<body>
 <!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-light fixed-top">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <i class="fas fa-clinic-medical me-2"></i>VeMedSystem
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="index.php"><i class="fa-solid fa-house"></i> Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="inventory.php"><i class="fas fa-boxes me-1"></i> Inventory</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="nurse_medicine_request.php">
                        <i class="fas fa-prescription-bottle-alt me-1"></i> Dispense Medicine
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="Medicine-Request-Management.php">
                        <i class="fas fa-user-edit me-1"></i> Dispense Log
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="reports.php"><i class="fas fa-chart-line me-1"></i> Reports</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="user-management.php"><i class="fas fa-users me-1"></i> User Management</a>
                </li>
            </ul>
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link mt-2 active" href="VendoMachine_Monitor.php">
                        <i class="fas fa-pills me-1"></i> Vendo Machine
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <div class="profile-picture-container">
                            <?php if (!empty($nurse['profile_photo'])): ?>
                                <img src="../uploads/profile_photos/<?= htmlspecialchars($nurse['profile_photo']) ?>" 
                                     alt="Profile" class="profile-picture rounded-circle">
                            <?php else: ?>
                                <div class="profile-placeholder rounded-circle d-flex align-items-center justify-content-center">
                                    <i class="fas fa-user"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">
                            <div class="d-flex align-items-center">
                                <?php if (!empty($nurse['profile_photo'])): ?>
                                    <img src="../uploads/profile_photos/<?= htmlspecialchars($nurse['profile_photo']) ?>" 
                                         alt="Profile" class="profile-picture-sm rounded-circle me-2">
                                <?php else: ?>
                                    <div class="profile-placeholder-sm rounded-circle d-flex align-items-center justify-content-center me-2">
                                        <i class="fas fa-user"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <strong><?= htmlspecialchars($nurse['full_name']) ?></strong>
                                    <div class="text-muted small">Nurse</div>
                                </div>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="profile-nurse.php">
                                <i class="fas fa-user-circle me-2"></i> My Account
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="./login/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <div class="monitor-header text-center">
        <h2><i class="fas fa-pills me-2"></i> Pill Vendo Machine Monitor</h2>
        <p class="mb-0">RFID-based pill dispensing system - <span id="connection-status">System Active</span></p>
    </div>
    
    <ul class="nav nav-tabs" id="monitorTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="dashboard-tab" data-bs-toggle="tab" data-bs-target="#dashboard" type="button" role="tab">Dashboard</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="inventory-tab" data-bs-toggle="tab" data-bs-target="#inventory" type="button" role="tab">Medicine Inventory</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="patients-tab" data-bs-toggle="tab" data-bs-target="#patients" type="button" role="tab">Patient Records</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="rfid-tab" data-bs-toggle="tab" data-bs-target="#rfid" type="button" role="tab">RFID Cards</button>
        </li>
         <li class="nav-item" role="presentation">
            <button class="nav-link" id="temperature-tab" data-bs-toggle="tab" data-bs-target="#temperature" type="button" role="tab">Temperature</button>
        </li>
    </ul>
 <div class="tab-content" id="monitorTabsContent">
        <!-- Dashboard Tab -->
        <div class="tab-pane fade show active" id="dashboard" role="tabpanel">
             <div class="temperature-container">
                <div class="temperature-content">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-3">
                                <i class="fas fa-thermometer-half scanner-icon me-3"></i>
                                <div>
                                    <h5 class="mb-1">Medicine Storage Temperature</h5>
                                    <div class="status-badge" id="temperature-status-badge">
                                        <i class="fas fa-circle me-1"></i> Monitoring...
                                    </div>
                                </div>
                            </div>
                            
                            <div class="temperature-display" id="temperature-value">
                                --.- °C
                            </div>
                            
                            <div class="temperature-status" id="temperature-status-text">
                                Monitoring temperature...
                            </div>
                            
                            <div class="temperature-range">
                                Safe Range: <strong>15°C to 30°C</strong> for medicine pills
                            </div>
                            
                            <div class="last-update">
                                <i class="fas fa-clock me-1"></i>
                                Last update: <span id="temperature-last-update">--:--:--</span>
                            </div>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <div class="scanner-icon">
                                <i class="fas fa-thermometer-half" id="temperature-main-icon"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div id="temperature-alert-info">
                        <div class="temperature-normal">
                            <i class="fas fa-check-circle me-2"></i>
                            Temperature within safe range for medicine storage
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card status-card">
                        <div class="card-body text-center">
                            <i class="fas fa-pills fa-2x text-primary mb-2"></i>
                            <h5 class="card-title">Today's Dispenses</h5>
                            <div class="status-value text-primary" id="today-count">0</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card status-card">
                        <div class="card-body text-center">
                            <i class="fas fa-wifi fa-2x mb-2" id="rfid-icon"></i>
                            <h5 class="card-title">RFID Status</h5>
                            <div class="status-value" id="rfid-status">Ready to scan</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card status-card">
                        <div class="card-body text-center">
                            <i class="fas fa-clock fa-2x text-info mb-2"></i>
                            <h5 class="card-title">Last Transaction</h5>
                            <div class="status-value text-info" id="last-transaction">Never</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3 mb-3">
                    <div class="card status-card">
                        <div class="card-body text-center">
                            <i class="fas fa-server fa-2x mb-2" id="device-icon"></i>
                            <h5 class="card-title">Device Status</h5>
                            <div class="status-value" id="device-status">Online</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- RFID Scanner Status -->
<div id="rfid-scanner-enhanced" class="scanner-container scanner-active">
    <div class="scanner-content">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center mb-3">
                    <i class="fas fa-qrcode scanner-icon me-3"></i>
                    <div>
                        <h5 class="mb-1">RFID Scanner Status</h5>
                        <div class="status-badge badge-online" id="scanner-status-badge">
                            <i class="fas fa-circle me-1"></i> Online
                        </div>
                    </div>
                </div>
                
                <p id="scanner-message" class="mb-3">
                    RFID scanner active and ready to scan cards
                </p>
                
                <div class="last-update">
                    <i class="fas fa-clock me-1"></i>
                    Last update: <span id="scanner-last-update">Just now</span>
                </div>
            </div>
            
            <div class="col-md-4 text-center">
                <div class="scanner-icon">
                    <i class="fas fa-wifi" id="scanner-main-icon"></i>
                </div>
            </div>
        </div>
        
        <div id="student-card-info">
            <div class="card-placeholder">
                <i class="fas fa-id-card fa-2x mb-3"></i>
                <p class="mb-2">No card detected</p>
                <small>Present an RFID card to view student information</small>
            </div>
        </div>
        
            <!-- Manual Card Entry (for testing when offline) -->
            <div class="manual-entry" id="manual-entry" style="display: none;">
                <h6><i class="fas fa-keyboard me-2"></i>Manual Card Entry (Testing)</h6>
                <div class="row">
                    <div class="col-8">
                        <input type="text" id="manual-card-uid" placeholder="Enter Card UID (e.g., A1B2C3D4)" maxlength="16">
                    </div>
                    <div class="col-4">
                        <button class="btn btn-light btn-sm w-100" onclick="simulateCardDetection()">
                            <i class="fas fa-search"></i> Test Card
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Simulation Controls -->
            <div class="simulate-controls">
                <div class="d-flex flex-wrap gap-2">
                    <button class="simulate-btn" onclick="simulateStatus('online')">
                        <i class="fas fa-power-off"></i> Online
                    </button>
                    <button class="simulate-btn" onclick="simulateStatus('offline')">
                        <i class="fas fa-times"></i> Offline
                    </button>
                    <button class="simulate-btn" onclick="simulateStatus('warning')">
                        <i class="fas fa-exclamation-triangle"></i> Warning
                    </button>
                    <button class="simulate-btn" onclick="toggleManualEntry()">
                        <i class="fas fa-keyboard"></i> Manual Entry
                    </button>
                    <button class="simulate-btn" onclick="clearStudentInfo()">
                        <i class="fas fa-eraser"></i> Clear
                    </button>
                </div>
            </div>
        </div>
    </div>

            <!-- Recent Transactions -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Transactions</h5>
                            <div class="real-time-blink">
                                <i class="fas fa-circle text-success"></i>
                                <small>Live</small>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="transaction-history" id="transaction-list">
                                <div class="text-center p-4">
                                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                                    <p class="mt-2 text-muted">Loading transactions...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory Tab -->
        <div class="tab-pane fade" id="inventory" role="tabpanel">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-boxes me-2"></i>Medicine Inventory</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Medicine</th>
                                            <th>Current Stock</th>
                                            <th>Status</th>
                                            <th>Low Stock Alert</th>
                                            <th>Last Dispensed</th>
                                        </tr>
                                    </thead>
                                    <tbody id="inventory-table">
                                        <tr>
                                            <td colspan="5" class="text-center">
                                                <i class="fas fa-spinner fa-spin"></i> Loading inventory...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- In the Patients Tab -->
<div class="tab-pane fade" id="patients" role="tabpanel">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i>Patient Usage Records</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID Number</th>
                                    <th>Name</th>
                                    <th>Total Pills</th>
                                    <th>Last Medicine</th>
                                    <th>User Type</th>
                                    <th>Last Used</th>
                                </tr>
                            </thead>
                            <tbody id="patients-table">
                                <tr>
                                    <td colspan="6" class="text-center">
                                        <i class="fas fa-spinner fa-spin"></i> Loading patient records...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
        <!-- RFID Cards Tab -->
        <div class="tab-pane fade" id="rfid" role="tabpanel">
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Registered RFID Cards</h5>
                            <button class="btn btn-primary btn-sm" onclick="refreshRfidCards()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                        <div class="card-body">
                            <div id="rfid-cards-list">
                                <div class="text-center p-4">
                                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                                    <p class="mt-2 text-muted">Loading RFID cards...</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
         <div class="tab-pane fade" id="temperature" role="tabpanel">
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-thermometer-half me-2"></i>Temperature Monitoring</h5>
                        </div>
                        <div class="card-body">
                            <div class="temperature-card">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="temperature-display temperature-ideal" id="detail-temperature-value">
                                            --.- °C
                                        </div>
                                        <div class="temperature-status" id="detail-temperature-status">
                                            Current Temperature
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <strong>Safe Range</strong>
                                            <div class="fs-4">15°C - 30°C</div>
                                            <small class="text-muted">For medicine pills</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <strong>Last Updated</strong>
                                            <div class="fs-6" id="detail-last-update">--:--:--</div>
                                            <small class="text-muted" id="detail-time-ago">--</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="detail-temperature-alert" class="mt-3">
                                    <div class="alert alert-success">
                                        <i class="fas fa-check-circle me-2"></i>
                                        Temperature within safe range
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <h6><i class="fas fa-history me-2"></i>Recent Temperature Readings</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Temperature</th>
                                                <th>Status</th>
                                                <th>Location</th>
                                            </tr>
                                        </thead>
                                        <tbody id="temperature-history">
                                            <tr>
                                                <td colspan="4" class="text-center">
                                                    <i class="fas fa-spinner fa-spin"></i> Loading temperature history...
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Temperature Alerts</h6>
                        </div>
                        <div class="card-body">
                            <div id="temperature-alerts-list">
                                <div class="text-center p-3">
                                    <i class="fas fa-spinner fa-spin"></i>
                                    <div class="mt-2 text-muted">Loading alerts...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Storage Guidelines</h6>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled small">
                                <li class="mb-2"><i class="fas fa-check text-success me-2"></i><strong>15°C - 30°C:</strong> Ideal for most pills</li>
                                <li class="mb-2"><i class="fas fa-exclamation-triangle text-warning me-2"></i><strong>Below 15°C:</strong> Too cold - may affect efficacy</li>
                                <li class="mb-2"><i class="fas fa-times text-danger me-2"></i><strong>Above 30°C:</strong> Too hot - risk of degradation</li>
                                <li class="mb-2"><i class="fas fa-shield-alt text-info me-2"></i>Store in cool, dry place</li>
                                <li class="mb-2"><i class="fas fa-ban text-danger me-2"></i>Avoid direct sunlight and moisture</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Notification Area -->
<div id="notification-area"></div>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// FIXED RFID CARD DETECTION SYSTEM
// Replace the existing JavaScript section with this corrected version

let systemState = {
    lastUpdate: 0,
    dashboardData: {},
    currentStudent: null,
    isUpdating: false,
    lastCardCheck: 0
};
let cardCheckInterval;
// Initialize the monitor
document.addEventListener('DOMContentLoaded', function() {
    console.log('VendoMachine Monitor initialized');
     console.log('Simple RFID system initialized');
      temperatureInterval = setInterval(updateTemperature, 1000);
    // Clear any existing intervals
    if (cardCheckInterval) clearInterval(cardCheckInterval);
    
    // Simple interval - just check for current card every 2 seconds
    cardCheckInterval = setInterval(checkCurrentCard, 2000);
    
    // Initial check
    checkCurrentCard();
    // Clear any existing intervals
    if (window.systemInterval) clearInterval(window.systemInterval);
    if (window.cardCheckInterval) clearInterval(window.cardCheckInterval);
    
    // Main system update every 5 seconds
    window.systemInterval = setInterval(updateSystem, 5000);
    
    // FIXED: Separate, faster interval specifically for card detection
    window.cardCheckInterval = setInterval(checkForNewCards, 2000);
    
    // Initial load
    updateSystem();
    loadInventoryData();
    loadStudentRecords();
    loadRfidCards();
     updateTemperature();
    loadTemperatureHistory();
    loadTemperatureAlerts();
    
    // Tab change handlers
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach(tab => {
        tab.addEventListener('shown.bs.tab', function(event) {
            const target = event.target.getAttribute('data-bs-target');
            switch(target) {
                case '#inventory':
                    loadInventoryData();
                    break;
                case '#patients':
                    loadPatientRecords();
                    break;
                case '#rfid':
                    loadRfidCards();
                    break;
                case '#temperature':
                    updateTemperature();
                    loadTemperatureHistory();
                    loadTemperatureAlerts();
                break;
            }
        });
    });
});

async function checkCurrentCard() {
    try {
        const response = await fetch('?action=get_current_card');
        const result = await response.json();
        
        console.log('Card check result:', result);
        
        if (result.status === 'success') {
            displayStudentInfo(result.data);
        }
    } catch (error) {
        console.error('Card check failed:', error);
    }
}
// Load inventory data
async function loadInventoryData() {
    try {
        const response = await fetch('?action=get_inventory');
        const result = await response.json();
        
        if (result.status === 'success') {
            updateInventoryTable(result.data);
        }
    } catch (error) {
        console.error('Inventory loading error:', error);
    }
}

// Load patient records
async function loadPatientRecords() {
    try {
        const response = await fetch('?action=get_patient_records');
        const result = await response.json();
        
        if (result.status === 'success') {
            updatePatientsTable(result.data);
        }
    } catch (error) {
        console.error('Patients loading error:', error);
    }
}

// Update inventory table
function updateInventoryTable(inventory) {
    const tbody = document.getElementById('inventory-table');
    if (!tbody) return;
    
    if (!inventory || inventory.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5" class="text-center py-4">
                    <i class="fas fa-box-open text-muted"></i>
                    <div class="mt-2 text-muted">No medicines in inventory</div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = inventory.map(item => {
        let statusBadge;
        if (item.store_stock <= 0) {
            statusBadge = '<span class="badge bg-danger">Out of Stock</span>';
        } else if (item.store_stock < 10) {
            statusBadge = '<span class="badge bg-warning">Critical</span>';
        } else if (item.store_stock < item.low_stock_threshold) {
            statusBadge = '<span class="badge bg-warning">Low Stock</span>';
        } else {
            statusBadge = '<span class="badge bg-success">In Stock</span>';
        }
        
        return `
            <tr>
                <td>
                    <strong>${item.name}</strong>
                    <div class="small text-muted">${item.description || ''}</div>
                </td>
                <td>
                    <span class="badge ${item.store_stock <= 0 ? 'bg-danger' : item.store_stock < 10 ? 'bg-warning' : 'bg-success'}">
                        ${item.store_stock}
                    </span>
                </td>
                <td>${statusBadge}</td>
                <td>
                    <span class="text-muted">${item.low_stock_threshold}</span>
                </td>
                <td>
                    <small class="text-muted">
                        ${item.last_dispensed || 'Never'}
                    </small>
                </td>
            </tr>
        `;
    }).join('');
}

// Load student records
async function loadStudentRecords() {
    try {
        const response = await fetch('?action=get_student_records');
        const result = await response.json();
        
        if (result.status === 'success') {
            updateStudentsTable(result.data);
        }
    } catch (error) {
        console.error('Students loading error:', error);
    }
}

// Update students table
// Update patients table (all users)
function updatePatientsTable(patients) {
    const tbody = document.getElementById('patients-table');
    if (!tbody) return;
    
    if (!patients || patients.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center py-4">
                    <i class="fas fa-users text-muted"></i>
                    <div class="mt-2 text-muted">No patient records found</div>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = patients.map(patient => `
        <tr>
            <td>
                <strong>${patient.identifier}</strong>
                <br>
                <small class="text-muted">${patient.user_type}</small>
            </td>
            <td>${patient.full_name}</td>
            <td>
                <span class="badge ${patient.total_pills > 10 ? 'bg-primary' : patient.total_pills > 0 ? 'bg-info' : 'bg-secondary'}">
                    ${patient.total_pills || 0}
                </span>
            </td>
            <td>
                <span class="text-muted">${patient.last_medicine || 'None'}</span>
            </td>
            <td>
                <span class="badge ${getUserTypeBadge(patient.user_type)}">
                    ${patient.user_type}
                </span>
            </td>
            <td>
                <small class="text-muted">
                    ${patient.last_dispensed || 'Never'}
                </small>
            </td>
        </tr>
    `).join('');
}

// Helper function to get badge color based on user type
function getUserTypeBadge(userType) {
    switch(userType) {
        case 'student': return 'bg-success';
        case 'teaching': return 'bg-primary';
        case 'non-teaching': return 'bg-warning';
        default: return 'bg-secondary';
    }
}


// MAIN UPDATE FUNCTION - System stats only
async function updateSystem() {
    if (systemState.isUpdating) return;
    
    systemState.isUpdating = true;
    
    try {
        const response = await fetch('?action=get_dashboard_data', {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache'
            }
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            systemState.dashboardData = result.data;
            systemState.lastUpdate = Date.now();
            
            updateDashboardStats(result.data);
            updateTransactionHistory(result.data.transactions);
            updateRfidStatus(result.data.rfid_status);
            updateConnectionStatus(result.data);
            
        } else {
            console.error('Dashboard error:', result.message);
        }
    } catch (error) {
        console.error('System update error:', error);
    } finally {
        systemState.isUpdating = false;
    }
}

// FIXED: Separate function specifically for checking new RFID cards
async function checkForNewCards() {
    try {
        const response = await fetch('?action=get_latest_card', {
            method: 'GET',
            headers: {
                'Cache-Control': 'no-cache'
            }
        });
        
        const result = await response.json();
        
        if (result.status === 'success' && result.data) {
            const cardData = result.data;
            const cardTime = new Date(cardData.tap_time).getTime();
            const now = Date.now();
            const timeDiff = (now - cardTime) / 1000; // seconds
            
            console.log('Latest card check:', {
                card_uid: cardData.card_uid_masked,
                tap_time: cardData.tap_time,
                seconds_ago: timeDiff,
                registered: cardData.registered
            });
            
            // Show card info if tapped within last 30 seconds
            if (timeDiff <= 30) {
                // Only update if it's a different card or significantly newer
                const shouldUpdate = !systemState.currentStudent || 
                                   systemState.currentStudent.card_uid !== cardData.card_uid ||
                                   cardTime > systemState.lastCardCheck;
                
                if (shouldUpdate) {
                    console.log('Updating student info display');
                    displayStudentInfo(cardData);
                    systemState.lastCardCheck = cardTime;
                }
            } else if (timeDiff > 30 && systemState.currentStudent) {
                // Clear display if card is old
                console.log('Clearing student info - card too old');
                displayStudentInfo(null);
            }
        } else {
            // No card data available
            if (systemState.currentStudent) {
                displayStudentInfo(null);
            }
        }
    } catch (error) {
        console.error('Card check failed:', error);
    }
}

function displayStudentInfo(cardData) {
    const container = document.getElementById('student-card-info');
    if (!container) return;
    
    console.log('Displaying card data:', cardData);
    
    if (!cardData) {
        // No card detected
        container.innerHTML = `
            <div class="card-placeholder">
                <i class="fas fa-id-card fa-2x mb-3"></i>
                <p class="mb-2">No card detected</p>
                <small>Present an RFID card to view student information</small>
            </div>
        `;
        return;
    }
    
    const tapTime = new Date(cardData.tap_time).toLocaleString();
    const secondsAgo = Math.floor((Date.now() - new Date(cardData.tap_time)) / 1000);
    
    if (cardData.registered) {
        // Registered student
        container.innerHTML = `
            <div class="rfid-card" style="border-left: 4px solid #28a745;">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h6 class="mb-0 text-success">
                                <i class="fas fa-user-check me-2"></i>Registered Student
                            </h6>
                            <span class="badge bg-success">Active</span>
                        </div>
                        
                        <div class="mb-2">
                            <strong>Name:</strong> ${cardData.full_name}
                        </div>
                        <div class="mb-2">
                            <strong>Student ID:</strong> ${cardData.student_number}
                        </div>
                        <div class="mb-2">
                            <strong>Year & Block:</strong> ${cardData.year_level || 'N/A'} - ${cardData.block || 'N/A'}
                        </div>
                        <div class="mb-2">
                            <strong>Card:</strong> <code>${cardData.card_uid_masked}</code>
                        </div>
                        <div class="mb-2">
                            <strong>Detected:</strong> ${secondsAgo}s ago
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <i class="fas fa-id-card fa-4x text-success"></i>
                        <div class="mt-2">
                            <small class="text-success">Card Active</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
    } else {
        // Unregistered card
        container.innerHTML = `
            <div class="rfid-card" style="border-left: 4px solid #ffc107;">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <h6 class="mb-0 text-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>Unregistered Card
                            </h6>
                            <span class="badge bg-warning text-dark">Not Linked</span>
                        </div>
                        
                        <div class="mb-2">
                            <strong>Status:</strong> Card not linked to student
                        </div>
                        <div class="mb-2">
                            <strong>Card:</strong> <code>${cardData.card_uid_masked}</code>
                        </div>
                        <div class="mb-2">
                            <strong>Detected:</strong> ${secondsAgo}s ago
                        </div>
                        
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            Register this card to enable dispensing
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <i class="fas fa-id-card fa-4x text-warning"></i>
                        <div class="mt-2">
                            <small class="text-warning">Unknown Card</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
}
// FIXED: Update dashboard statistics
function updateDashboardStats(data) {
     const todayCountEl = document.getElementById('today-count');
    if (todayCountEl) {
        todayCountEl.textContent = data.today_count || 0;
    }
    
    // Update last transaction (only if within 24 hours)
    const lastTransactionEl = document.getElementById('last-transaction');
    if (lastTransactionEl) {
        if (data.last_transaction) {
            const lastTransactionTime = new Date(data.last_transaction.transaction_time);
            const twentyFourHoursAgo = new Date(Date.now() - (24 * 60 * 60 * 1000));
            
            if (lastTransactionTime >= twentyFourHoursAgo) {
                lastTransactionEl.textContent = timeAgo(data.last_transaction.transaction_time);
            } else {
                lastTransactionEl.textContent = 'No recent transactions';
            }
        } else {
            lastTransactionEl.textContent = 'Never';
        }
    }
    
    // Update RFID status
    const rfidElement = document.getElementById('rfid-status');
    const rfidIcon = document.getElementById('rfid-icon');
    if (rfidElement && rfidIcon) {
        rfidElement.textContent = 'Ready to scan';
        rfidElement.className = 'status-value text-success';
        rfidIcon.className = 'fas fa-wifi fa-2x text-success mb-2';
    }
    
    // Update device status
    const deviceElement = document.getElementById('device-status');
    const deviceIcon = document.getElementById('device-icon');
    if (deviceElement && deviceIcon) {
        deviceElement.textContent = 'Online';
        deviceElement.className = 'status-value device-status-online';
        deviceIcon.className = 'fas fa-server fa-2x text-success mb-2';
    }
}

// JavaScript version of timeAgo function
function timeAgo(datetime) {
    if (!datetime || datetime === '0000-00-00 00:00:00') {
        return "Never";
    }
    
    try {
        // Parse the datetime string (assume it's in UTC)
        const utcTime = new Date(datetime + ' UTC');
        
        // Convert to local timezone (Asia/Manila)
        const localTime = new Date(utcTime.toLocaleString("en-US", {timeZone: "Asia/Manila"}));
        
        const now = new Date();
        const timeDiff = Math.floor((now - localTime) / 1000); // difference in seconds
        
        if (timeDiff < 60) return "Just now";
        if (timeDiff < 3600) return Math.floor(timeDiff / 60) + " min ago";
        if (timeDiff < 86400) return Math.floor(timeDiff / 3600) + " hr ago";
        if (timeDiff < 604800) return Math.floor(timeDiff / 86400) + " days ago";
        if (timeDiff < 2592000) return Math.floor(timeDiff / 604800) + " weeks ago";
        if (timeDiff < 31536000) return Math.floor(timeDiff / 2592000) + " months ago";
        return Math.floor(timeDiff / 31536000) + " years ago";
    } catch (e) {
        console.error("Error in timeAgo function: ", e);
        return "Invalid date";
    }
}
async function loadRfidCards() {
    try {
        const response = await fetch('?action=get_rfid_cards');
        const result = await response.json();
        
        if (result.status === 'success') {
            updateRfidCardsList(result.data);
        }
    } catch (error) {
        console.error('RFID cards loading error:', error);
    }
}

// Update transaction history - AUTO CLEAR after 24 hours
function updateTransactionHistory(transactions) {
    const container = document.getElementById('transaction-list');
    if (!container) return;
    
    if (!transactions || transactions.length === 0) {
        container.innerHTML = `
            <div class="text-center p-4">
                <i class="fas fa-info-circle fa-2x text-muted"></i>
                <p class="mt-2 text-muted">No recent transactions</p>
            </div>
        `;
        return;
    }
    
    // Filter out transactions older than 24 hours
    const now = new Date();
    const twentyFourHoursAgo = new Date(now.getTime() - (24 * 60 * 60 * 1000));
    
    const recentTransactions = transactions.filter(transaction => {
        if (!transaction.transaction_time) return false;
        const transactionTime = new Date(transaction.transaction_time);
        return transactionTime >= twentyFourHoursAgo;
    });
    
    if (recentTransactions.length === 0) {
        container.innerHTML = `
            <div class="text-center p-4">
                <i class="fas fa-clock fa-2x text-muted"></i>
                <p class="mt-2 text-muted">No transactions in the last 24 hours</p>
            </div>
        `;
        return;
    }
    
    const html = recentTransactions.map((transaction, index) => {
        const transactionTime = new Date(transaction.transaction_time);
        const timeDiff = (now - transactionTime) / (1000 * 60 * 60); // hours difference
        
        // Add visual indicator for very recent transactions (last hour)
        const isVeryRecent = timeDiff < 1;
        const recentClass = isVeryRecent ? 'border-start border-4 border-success' : '';
        
        // Determine which ID to display
        const displayId = transaction.employee_id || transaction.student_id || 'N/A';
        const idType = transaction.employee_id ? 'Employee ID' : 'Student ID';
        const idIcon = transaction.employee_id ? 'fas fa-id-badge' : 'fas fa-graduation-cap';
        
        return `
            <div class="transaction-item ${recentClass} ${index === 0 ? 'latest-transaction' : ''}">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center mb-1">
                            <strong class="medicine-name">${transaction.medicine_name || 'Unknown Medicine'}</strong>
                            <span class="badge bg-light text-dark ms-2">Qty: ${transaction.quantity || 1}</span>
                            ${isVeryRecent ? '<span class="badge bg-success ms-2"><i class="fas fa-bolt"></i> Recent</span>' : ''}
                        </div>
                        <div class="student-info mb-1">
                            <span class="student-badge">
                                <i class="fas fa-user"></i>
                                ${transaction.full_name || 'Unknown'}
                            </span>
                            <span class="badge bg-secondary ms-2">
                                <i class="${idIcon}"></i>
                                ${idType}: ${displayId}
                            </span>
                        </div>
                        <div class="stock-info text-muted small">
                            <i class="fas fa-boxes"></i>
                            Stock: <span class="stock-before">${transaction.previous_count || 0}</span> 
                            <i class="fas fa-arrow-right mx-1"></i> 
                            <span class="stock-after">${transaction.new_count || 0}</span>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="transaction-time">
                            <small class="text-muted">${timeAgo(transaction.transaction_time)}</small>
                            <br>
                            <small class="text-muted">${transactionTime.toLocaleTimeString()}</small>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
    
    // Add a note about auto-clearing
    const noteElement = document.createElement('div');
    noteElement.className = 'text-center text-muted small mt-3 p-2 bg-light rounded';
    noteElement.innerHTML = '<i class="fas fa-info-circle me-1"></i> Transactions older than 24 hours are automatically cleared from this view';
    container.appendChild(noteElement);
}

// Update RFID status
function updateRfidStatus(rfidData) {
    const statusElement = document.getElementById('rfid-status');
    if (statusElement && rfidData) {
        const isConnected = rfidData.connected !== false;
        statusElement.textContent = isConnected ? 'Ready to scan' : 'Disconnected';
        statusElement.className = `status-value ${isConnected ? 'text-success' : 'text-danger'}`;
    }
}

// Update connection status
function updateConnectionStatus(data) {
    const statusElement = document.getElementById('connection-status');
    if (statusElement) {
        statusElement.textContent = 'System Active';
        statusElement.className = 'text-success';
    }
}

function simulateCardDetection() {
    const cardUidInput = document.getElementById('manual-card-uid');
    if (!cardUidInput) return;
    
    const cardUid = cardUidInput.value.trim();
    if (!cardUid) {
        alert('Enter a card UID first');
        return;
    }
    
    // Send test card to server
    fetch('?action=log_rfid_tap', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({card_uid: cardUid})
    })
    .then(response => response.json())
    .then(data => {
        console.log('Test card result:', data);
        cardUidInput.value = '';
        // The interval will pick up the change automatically
    })
    .catch(error => console.error('Test failed:', error));
}
// Update RFID cards list
function updateRfidCardsList(cards) {
    const container = document.getElementById('rfid-cards-list');
    if (!container) return;
    
    if (!cards || cards.length === 0) {
        container.innerHTML = `
            <div class="text-center p-4">
                <i class="fas fa-id-card fa-2x text-muted"></i>
                <p class="mt-2 text-muted">No RFID cards registered</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = cards.map(card => `
        <div class="rfid-card rfid-registered">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <strong>${card.full_name}</strong>
                    <br>
                    <small class="text-muted">${card.student_id}</small>
                </div>
                <div class="col-md-3">
                    <span class="card-status status-registered">Registered</span>
                    <br>
                    <small>Card: ${card.card_uid_masked}</small>
                </div>
                <div class="col-md-3">
                    <small class="text-muted">
                        Last used: ${card.last_used_formatted}
                        <br>
                        Registered: ${card.registered_at_formatted}
                    </small>
                </div>
                <div class="col-md-3 text-end">
                    <button class="btn btn-outline-danger btn-sm" onclick="unlinkCard(${card.id})">
                        <i class="fas fa-unlink"></i> Unlink
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

// Refresh RFID cards function
function refreshRfidCards() {
    const refreshBtn = document.querySelector('[onclick="refreshRfidCards()"]');
    if (refreshBtn) {
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
        refreshBtn.disabled = true;
    }
    
    loadRfidCards().finally(() => {
        if (refreshBtn) {
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
            refreshBtn.disabled = false;
        }
    });
}

// Unlink card function
function unlinkCard(cardId) {
    if (!confirm('Are you sure you want to unlink this RFID card?')) {
        return;
    }
    
    fetch('?action=unlink_rfid_card', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({card_id: cardId})
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            showNotification('success', data.message);
            loadRfidCards();
        } else {
            showNotification('error', data.message);
        }
    })
    .catch(error => {
        console.error('Error unlinking card:', error);
        showNotification('error', 'Failed to unlink RFID card');
    });
}

function clearStudentInfo() {
    console.log('Manual clear requested');
    displayStudentInfo(null);
    systemState.currentStudent = null;
    systemState.lastCardCheck = 0;
}

// Rest of the utility functions remain the same...
function timeAgo(datetime) {
    if (!datetime) return "Never";
    
    const now = Date.now();
    const past = new Date(datetime).getTime();
    const diffMs = now - past;
    const diffMins = Math.floor(diffMs / 60000);
    
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffMins < 1440) return `${Math.floor(diffMins / 60)}h ago`;
    return `${Math.floor(diffMins / 1440)}d ago`;
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
        if (temperatureInterval) clearInterval(temperatureInterval);

    if (window.systemInterval) clearInterval(window.systemInterval);
    if (window.cardCheckInterval) clearInterval(window.cardCheckInterval);
});

// Add remaining functions (toggleManualEntry, simulateStatus, etc.) from original code...
function toggleManualEntry() {
    const manualEntry = document.getElementById('manual-entry');
    if (manualEntry) {
        manualEntry.style.display = manualEntry.style.display === 'none' ? 'block' : 'none';
    }
}

function simulateStatus(status) {
    const container = document.getElementById('rfid-scanner-enhanced');
    const badge = document.getElementById('scanner-status-badge');
    const message = document.getElementById('scanner-message');
    const icon = document.getElementById('scanner-main-icon');
    
    if (!container || !badge || !message || !icon) return;
    
    container.className = 'scanner-container';
    
    switch(status) {
        case 'online':
            container.classList.add('scanner-active');
            badge.className = 'status-badge badge-online';
            badge.innerHTML = '<i class="fas fa-circle me-1"></i> Online & Active';
            message.textContent = 'RFID scanner active and ready to scan cards';
            icon.className = 'fas fa-wifi';
            break;
        case 'warning':
            container.classList.add('scanner-warning');
            badge.className = 'status-badge badge-warning';
            badge.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Warning';
            message.textContent = 'Device online but RFID scanner not responding';
            icon.className = 'fas fa-exclamation-triangle';
            break;
        default:
            container.classList.add('scanner-offline');
            badge.className = 'status-badge badge-offline';
            badge.innerHTML = '<i class="fas fa-circle me-1"></i> Offline';
            message.textContent = 'Vending machine offline - RFID scanner not accessible';
            icon.className = 'fas fa-wifi-slash';
    }
    
    const lastUpdate = document.getElementById('scanner-last-update');
    if (lastUpdate) {
        lastUpdate.textContent = new Date().toLocaleTimeString();
    }
}

// NEW: Temperature update function
async function updateTemperature() {
    try {
        const response = await fetch('?action=get_temperature_data');
        const result = await response.json();
        
        if (result.status === 'success') {
            displayTemperatureData(result.data);
        }
    } catch (error) {
        console.error('Temperature update failed:', error);
    }
}

// NEW: Display temperature data
function displayTemperatureData(tempData) {
    if (!tempData) {
        // No temperature data available
        document.getElementById('temperature-value').textContent = '--.- °C';
        document.getElementById('temperature-status-text').textContent = 'No data available';
        document.getElementById('temperature-last-update').textContent = '--:--:--';
        document.getElementById('temperature-status-badge').innerHTML = '<i class="fas fa-circle me-1"></i> No Data';
        document.getElementById('temperature-status-badge').className = 'status-badge badge-warning';
        return;
    }

    const temp = parseFloat(tempData.temperature);
    const status = tempData.status;
    const readingTime = new Date(tempData.reading_time);
    
    // Update main dashboard display
    const tempValueEl = document.getElementById('temperature-value');
    const statusTextEl = document.getElementById('temperature-status-text');
    const lastUpdateEl = document.getElementById('temperature-last-update');
    const statusBadgeEl = document.getElementById('temperature-status-badge');
    const alertInfoEl = document.getElementById('temperature-alert-info');
    
    tempValueEl.textContent = `${temp.toFixed(1)} °C`;
    lastUpdateEl.textContent = readingTime.toLocaleTimeString();
    
    // Update temperature tab details
    document.getElementById('detail-temperature-value').textContent = `${temp.toFixed(1)} °C`;
    document.getElementById('detail-last-update').textContent = readingTime.toLocaleString();
    document.getElementById('detail-time-ago').textContent = timeAgo(tempData.reading_time);
    
    const detailAlertEl = document.getElementById('detail-temperature-alert');
    
    // Set colors and status based on temperature
    switch(status) {
        case 'ideal':
            tempValueEl.className = 'temperature-display temperature-ideal';
            statusTextEl.textContent = 'IDEAL - Perfect for medicine storage';
            statusTextEl.className = 'temperature-status text-success';
            statusBadgeEl.innerHTML = '<i class="fas fa-check-circle me-1"></i> Ideal';
            statusBadgeEl.className = 'status-badge badge-online';
            
            alertInfoEl.innerHTML = `
                <div class="temperature-normal">
                    <i class="fas fa-check-circle me-2"></i>
                    Temperature within safe range for medicine storage
                </div>
            `;
            
            detailAlertEl.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i>
                    Temperature within safe range (${tempData.safe_range})
                </div>
            `;
            break;
            
        case 'too_cold':
            tempValueEl.className = 'temperature-display temperature-danger';
            statusTextEl.textContent = 'TOO COLD - Below minimum safe temperature';
            statusTextEl.className = 'temperature-status text-warning';
            statusBadgeEl.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Too Cold';
            statusBadgeEl.className = 'status-badge badge-warning';
            
            alertInfoEl.innerHTML = `
                <div class="temperature-alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ⚠️ TOO COLD: ${temp.toFixed(1)}°C - Below minimum for pills (${tempData.min_temp}°C)
                </div>
            `;
            
            detailAlertEl.innerHTML = `
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Temperature too cold: ${temp.toFixed(1)}°C (below minimum ${tempData.min_temp}°C)
                </div>
            `;
            break;
            
        case 'too_hot':
            tempValueEl.className = 'temperature-display temperature-danger';
            statusTextEl.textContent = 'TOO HOT - Above maximum safe temperature';
            statusTextEl.className = 'temperature-status text-danger';
            statusBadgeEl.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Too Hot';
            statusBadgeEl.className = 'status-badge badge-offline';
            
            alertInfoEl.innerHTML = `
                <div class="temperature-alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ⚠️ TOO HOT: ${temp.toFixed(1)}°C - Above maximum for pills (${tempData.max_temp}°C)
                </div>
            `;
            
            detailAlertEl.innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Temperature too hot: ${temp.toFixed(1)}°C (above maximum ${tempData.max_temp}°C)
                </div>
            `;
            break;
    }
    
    // Update detail status
    document.getElementById('detail-temperature-status').textContent = statusTextEl.textContent;
    document.getElementById('detail-temperature-status').className = statusTextEl.className;
}

// NEW: Load temperature history
async function loadTemperatureHistory() {
    try {
        // You might want to create a separate endpoint for history
        const response = await fetch('?action=get_temperature_data&history=true');
        const result = await response.json();
        
        if (result.status === 'success') {
            updateTemperatureHistory(result.data);
        }
    } catch (error) {
        console.error('Temperature history loading failed:', error);
    }
}

// NEW: Update temperature history table
function updateTemperatureHistory(history) {
    const tbody = document.getElementById('temperature-history');
    if (!tbody) return;
    
    if (!history || history.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center py-3 text-muted">
                    No temperature history available
                </td>
            </tr>
        `;
        return;
    }
    
    // For now, we'll just show the latest reading in detail
    // You can expand this to show multiple readings
    tbody.innerHTML = `
        <tr>
            <td>${timeAgo(history.reading_time)}</td>
            <td>
                <span class="badge bg-secondary">${parseFloat(history.temperature).toFixed(1)}°C</span>
            </td>
            <td>
                <span class="badge bg-success">Normal</span>
            </td>
            <td>${history.location || 'Medicine Storage'}</td>
        </tr>
    `;
}

// NEW: Load temperature alerts
async function loadTemperatureAlerts() {
    try {
        const response = await fetch('?action=get_temperature_alerts');
        const result = await response.json();
        
        if (result.status === 'success') {
            updateTemperatureAlerts(result.data);
        }
    } catch (error) {
        console.error('Temperature alerts loading failed:', error);
    }
}

// NEW: Update temperature alerts
function updateTemperatureAlerts(alerts) {
    const container = document.getElementById('temperature-alerts-list');
    if (!container) return;
    
    if (!alerts || alerts.length === 0) {
        container.innerHTML = `
            <div class="text-center p-3">
                <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                <div class="text-muted">No active temperature alerts</div>
            </div>
        `;
        return;
    }
    
    container.innerHTML = alerts.map(alert => `
        <div class="alert ${alert.is_resolved ? 'alert-secondary' : alert.alert_type === 'high_temperature' ? 'alert-danger' : 'alert-warning'} mb-2">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <i class="fas ${alert.is_resolved ? 'fa-check' : 'fa-exclamation-triangle'} me-2"></i>
                    ${alert.message}
                </div>
                <small class="text-muted">${alert.alert_time_ago}</small>
            </div>
            ${alert.is_resolved ? '<small class="text-muted"><i class="fas fa-check me-1"></i>Resolved</small>' : ''}
        </div>
    `).join('');
}

// WebSocket client: receives real-time temperature updates
(function() {
    const WS_URL = (function(){
        // Prefer env-configured client URL if set via a global JS variable injected server-side
        if (typeof WEBSOCKET_URL !== 'undefined' && WEBSOCKET_URL) {
            console.log('Using configured WebSocket URL:', WEBSOCKET_URL);
            return WEBSOCKET_URL;
        }
        console.warn('WEBSOCKET_URL not configured, using fallback (will fail)');
        return 'wss://your-websocket-server.example.com'; // placeholder - must be configured
    })();

    try {
        const socket = new WebSocket(WS_URL);

        socket.addEventListener('open', () => {
            console.log('WebSocket connected');
        });

        socket.addEventListener('message', (event) => {
            try {
                const msg = JSON.parse(event.data);
                if (msg.type === 'temperature_update') {
                    const t = msg.data;
                    const el = document.getElementById('current-temp');
                    if (el && t && typeof t.temperature !== 'undefined') {
                        el.textContent = parseFloat(t.temperature).toFixed(1) + '°C';
                    }
                    // Optionally refresh other UI parts via custom event
                    window.dispatchEvent(new CustomEvent('temperature_update', { detail: t }));
                } else if (msg.type === 'connected') {
                    console.log('Connected as', msg.clientId);
                }
            } catch (e) {
                console.error('Invalid WS message', e);
            }
        });

        socket.addEventListener('close', (e) => {
            console.log('WebSocket closed', e);
            // simple reconnect with delay
            setTimeout(() => { location.reload(); }, 5000);
        });

        socket.addEventListener('error', (err) => {
            console.error('WebSocket error', err);
        });
    } catch (err) {
        console.error('WebSocket initialization failed', err);
    }
})();

</script>

</body>
</html>