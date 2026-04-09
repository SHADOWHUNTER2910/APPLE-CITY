<?php
require_once __DIR__ . '/../src/db.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDbConnection();
    
    if ($method === 'GET') {
        // Get all company settings
        $stmt = $db->query("SELECT setting_key, setting_value FROM company_settings");
        $settings = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        echo json_encode(['success' => true, 'settings' => $settings]);
        
    } elseif ($method === 'POST') {
        // Update company settings
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            throw new Exception('Invalid input data');
        }
        
        $db->beginTransaction();
        
        // Update each setting
        $stmt = $db->prepare("
            INSERT INTO company_settings (setting_key, setting_value, updated_at) 
            VALUES (:key, :value, CURRENT_TIMESTAMP)
            ON CONFLICT(setting_key) 
            DO UPDATE SET setting_value = :value, updated_at = CURRENT_TIMESTAMP
        ");
        
        foreach ($input as $key => $value) {
            $stmt->execute([
                ':key' => $key,
                ':value' => $value
            ]);
        }
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Company settings updated successfully']);
        
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
