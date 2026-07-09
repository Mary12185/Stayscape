<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'stayscape';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

$action = $_GET['action'] ?? '';

// ========== HOST SIGNUP ==========
if ($action === 'host_signup') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $full_name = trim($input['full_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $phone = trim($input['phone'] ?? '');
    $business_name = trim($input['business_name'] ?? '');
    
    if (empty($full_name) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'All fields required']);
        exit();
    }
    
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
        exit();
    }
    
    // Check if email exists in hosts table
    $stmt = $pdo->prepare("SELECT id FROM hosts WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Email already registered as host']);
        exit();
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $pdo->prepare("INSERT INTO hosts (full_name, email, password, phone, business_name) VALUES (?, ?, ?, ?, ?)");
    
    if ($stmt->execute([$full_name, $email, $hashedPassword, $phone, $business_name])) {
        $_SESSION['host'] = [
            'id' => $pdo->lastInsertId(),
            'name' => $full_name,
            'email' => $email,
            'type' => 'host'
        ];
        echo json_encode(['success' => true, 'host' => $_SESSION['host']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Database insert failed']);
    }
    exit();
}

// ========== HOST LOGIN ==========
if ($action === 'host_login') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Email and password required']);
        exit();
    }
    
    $stmt = $pdo->prepare("SELECT * FROM hosts WHERE email = ?");
    $stmt->execute([$email]);
    $host = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($host && password_verify($password, $host['password'])) {
        $_SESSION['host'] = [
            'id' => $host['id'],
            'name' => $host['full_name'],
            'email' => $host['email'],
            'type' => 'host'
        ];
        echo json_encode(['success' => true, 'host' => $_SESSION['host']]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid email or password']);
    }
    exit();
}

// ========== CHECK HOST LOGIN STATUS ==========
if ($action === 'host_me') {
    if (isset($_SESSION['host'])) {
        echo json_encode(['loggedIn' => true, 'host' => $_SESSION['host']]);
    } else {
        echo json_encode(['loggedIn' => false]);
    }
    exit();
}

// ========== HOST LOGOUT ==========
if ($action === 'host_logout') {
    unset($_SESSION['host']);
    echo json_encode(['success' => true]);
    exit();
}

// ========== HOST STATS ==========
if ($action === 'host_stats') {
    if (!isset($_SESSION['host'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    
    $host_id = $_SESSION['host']['id'];
    
    // Total earnings from bookings of host's properties
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(b.total_price), 0) as earnings
        FROM bookings b
        JOIN properties p ON b.property_id = p.id
        WHERE p.host_id = ?
    ");
    $stmt->execute([$host_id]);
    $earnings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Total bookings
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM bookings b
        JOIN properties p ON b.property_id = p.id
        WHERE p.host_id = ?
    ");
    $stmt->execute([$host_id]);
    $bookings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Properties listed
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM properties WHERE host_id = ?");
    $stmt->execute([$host_id]);
    $properties = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'total_earnings' => $earnings['earnings'],
        'total_bookings' => $bookings['count'],
        'properties_listed' => $properties['count']
    ]);
    exit();
}

// ========== HOST PROPERTIES ==========
if ($action === 'host_properties') {
    if (!isset($_SESSION['host'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    
    $host_id = $_SESSION['host']['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM properties WHERE host_id = ? ORDER BY id DESC");
    $stmt->execute([$host_id]);
    $properties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'properties' => $properties]);
    exit();
}

// ========== ADD PROPERTY (HOST) ==========
if ($action === 'add_property') {
    if (!isset($_SESSION['host'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $host_id = $_SESSION['host']['id'];
    $title = $input['title'] ?? '';
    $location = $input['location'] ?? '';
    $price_night = $input['price_night'] ?? 0;
    $property_type = $input['property_type'] ?? '';
    $guests = $input['guests'] ?? 1;
    $bedrooms = $input['bedrooms'] ?? 1;
    $description = $input['description'] ?? '';
    $image_url = $input['image_url'] ?? '';
    
    $stmt = $pdo->prepare("
        INSERT INTO properties (host_id, title, location, price_night, property_type, guests, bedrooms, description, image_url)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $success = $stmt->execute([$host_id, $title, $location, $price_night, $property_type, $guests, $bedrooms, $description, $image_url]);
    
    if ($success) {
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to add property']);
    }
    exit();
}

// ========== UPDATE PROPERTY (HOST) ==========
if ($action === 'update_property') {
    if (!isset($_SESSION['host'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $id = $input['id'] ?? 0;
    $host_id = $_SESSION['host']['id'];
    $title = $input['title'] ?? '';
    $location = $input['location'] ?? '';
    $price_night = $input['price_night'] ?? 0;
    $property_type = $input['property_type'] ?? '';
    $guests = $input['guests'] ?? 1;
    $bedrooms = $input['bedrooms'] ?? 1;
    $description = $input['description'] ?? '';
    $image_url = $input['image_url'] ?? '';
    
    // Check if property belongs to host
    $stmt = $pdo->prepare("SELECT id FROM properties WHERE id = ? AND host_id = ?");
    $stmt->execute([$id, $host_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Property not found or unauthorized']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        UPDATE properties 
        SET title = ?, location = ?, price_night = ?, property_type = ?, guests = ?, bedrooms = ?, description = ?, image_url = ?
        WHERE id = ? AND host_id = ?
    ");
    
    $success = $stmt->execute([$title, $location, $price_night, $property_type, $guests, $bedrooms, $description, $image_url, $id, $host_id]);
    
    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update property']);
    }
    exit();
}

// ========== DELETE PROPERTY (HOST) ==========
if ($action === 'delete_property') {
    if (!isset($_SESSION['host'])) {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
        exit();
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? 0;
    $host_id = $_SESSION['host']['id'];
    
    $stmt = $pdo->prepare("DELETE FROM properties WHERE id = ? AND host_id = ?");
    $success = $stmt->execute([$id, $host_id]);
    
    if ($success && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Property not found or unauthorized']);
    }
    exit();
}

// ========== GET SINGLE PROPERTY ==========
if ($action === 'get_property') {
    $id = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ?");
    $stmt->execute([$id]);
    $property = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($property) {
        echo json_encode(['success' => true, 'property' => $property]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Property not found']);
    }
    exit();
}

// Default response
echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
?>