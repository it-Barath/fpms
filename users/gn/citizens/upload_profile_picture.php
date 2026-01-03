<?php
// users/gn/citizens/upload_profile_picture.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering to catch any stray output
ob_start();

require_once '../../../config.php';
require_once '../../../classes/Auth.php';

// Check if session is already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize Auth
$auth = new Auth();

// Check if user is logged in and has GN level access
if (!$auth->isLoggedIn() || $_SESSION['user_type'] !== 'gn') {
    // Clear any output
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get POST data
$citizen_id = $_POST['citizen_id'] ?? 0;
$action = $_POST['action'] ?? '';

if ($action !== 'upload_profile_picture' || !$citizen_id) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters']);
    exit();
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error. Error code: ' . ($_FILES['profile_image']['error'] ?? 'Unknown'));
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $file_type = mime_content_type($_FILES['profile_image']['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPG, PNG, and GIF are allowed.');
    }
    
    // Validate file size (5MB max)
    if ($_FILES['profile_image']['size'] > 5 * 1024 * 1024) {
        throw new Exception('File size must be less than 5MB.');
    }
    
    // Create directory if it doesn't exist
    $upload_dir = '../../../assets/uploads/members/' . $citizen_id . '/';
    if (!file_exists($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory.');
        }
    }
    
    // Create main profile image path
    $main_image_path = $upload_dir . 'profile.jpg';
    
    // Move uploaded file to destination
    if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $main_image_path)) {
        throw new Exception('Failed to save uploaded file.');
    }
    
    // Check if ImageProcessor class exists
    $imageProcessorPath = '../../../classes/ImageProcessor.php';
    if (file_exists($imageProcessorPath)) {
        require_once $imageProcessorPath;
        
        // Initialize ImageProcessor
        $imageProcessor = new ImageProcessor();
        
        try {
            // Create thumbnail (100x100)
            $thumb_image_path = $upload_dir . 'profile_thumb.jpg';
            $imageProcessor->createThumbnail($main_image_path, $thumb_image_path, 100, 100);
            
            // Add watermark to thumbnail
            $imageProcessor->addWatermark($thumb_image_path, 'SYSCGAA', 12);
            
            // Add watermark to main image as well
            $imageProcessor->addWatermark($main_image_path, 'SYSCGAA', 24);
            
        } catch (Exception $e) {
            // If image processing fails, still keep the uploaded file
            error_log('Image processing error: ' . $e->getMessage());
            // Create a simple copy for thumbnail if processing failed
            copy($main_image_path, $upload_dir . 'profile_thumb.jpg');
        }
    } else {
        // If ImageProcessor doesn't exist, just copy the file
        copy($main_image_path, $upload_dir . 'profile_thumb.jpg');
    }
    
    // Clear output buffer
    ob_end_clean();
    
    // Return success
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture uploaded successfully',
        'main_image' => str_replace('../../../', '', $main_image_path),
        'thumb_image' => str_replace('../../../', '', $thumb_image_path ?? $main_image_path)
    ]);
    
} catch (Exception $e) {
    // Clear output buffer
    ob_end_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error uploading profile picture: ' . $e->getMessage()
    ]);
}

// Make sure no other output is sent
exit();
?>