<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_auth']['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_auth']['id'];
    $data = json_decode(file_get_contents("php://input"), true);
    
    $rating = $data['rating'] ?? '';
    // CAPTURE THE COMMENT (Trim whitespace, allow null)
    $comment = isset($data['comment']) ? trim($data['comment']) : null;
    if($comment === '') $comment = null;

    if ($rating === 'yes' || $rating === 'no') {
        // Update query to include comment
        $stmt = $mysqli->prepare("INSERT INTO feedback (user_id, rating, comment) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $user_id, $rating, $comment);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid rating']);
    }
}
$mysqli->close();
?>