<?php
session_start();
require_once '../php/db_connection.php'; // Replace with your database connection file


header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? null;
    $email = $data['email'] ?? null;
    $phone_number_1 = $data['phone_number_1'] ?? null;
    $phone_number_2 = $data['phone_number_2'] ?? null;
    $current_employee_id = $data['exclude_id'] ?? null;
    $old_password = $data['old_password'] ?? null;
    $new_password = $data['new_password'] ?? null;

    try {
        error_log("Gotten employee_id: " . $current_employee_id);
        error_log("Inputted username: " . $username);
        error_log("Inputted email: " . $email);
        error_log("Inputted phone_number_1: " . $phone_number_1);
        error_log("Inputted phone_number_2: " . $phone_number_2);

        // Check username validation
        if ($username) {
            $stmt = $conn->prepare("SELECT username FROM users WHERE employee_id = ?");
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . htmlspecialchars($conn->error));
            }
            $stmt->bind_param("i", $current_employee_id);
            $stmt->execute();
            $stmt->bind_result($current_username);
            $stmt->fetch();
            $stmt->close();
            error_log("Searched table for username, current_username: " . $current_username);

            if ($current_username === $username) {
                echo json_encode([
                    'exists' => true,
                    'message' => "You're already using this username"
                ]);
                error_log("Matched username results: You're already using this username");
                exit;
            }

            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND employee_id != ?");
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . htmlspecialchars($conn->error));
            }
            $stmt->bind_param("si", $username, $current_employee_id);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            error_log("Username count for other users: " . $count);

            if ($count > 0) {
                echo json_encode(['exists' => true, 'message' => 'Username already taken']);
                exit;
            }
        }

        // Check email validation
        if ($email) {
            $stmt = $conn->prepare("SELECT email FROM users WHERE employee_id = ?");
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . htmlspecialchars($conn->error));
            }
            $stmt->bind_param("i", $current_employee_id);
            $stmt->execute();
            $stmt->bind_result($current_email);
            $stmt->fetch();
            $stmt->close();
            error_log("Searched table for email, current_email: " . $current_email);

            if ($current_email === $email) {
                echo json_encode([
                    'email_exists' => true,
                    'email_message' => "You're already using this email"
                ]);
                error_log("Matched email results: You're already using this email");
                exit;
            }

            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND employee_id != ?");
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . htmlspecialchars($conn->error));
            }
            $stmt->bind_param("si", $email, $current_employee_id);
            $stmt->execute();
            $stmt->bind_result($email_count);
            $stmt->fetch();
            $stmt->close();
            error_log("Email count for other users: " . $email_count);

            if ($email_count > 0) {
                echo json_encode([
                    'email_exists' => true,
                    'email_message' => 'Email already in use'
                ]);
                exit;
            }
        }

        // Check phone number validation
        if ($phone_number_1 || $phone_number_2) {
            $stmt = $conn->prepare("
                SELECT employee_id, phone_number_1, phone_number_2
                FROM users
                WHERE (CAST(phone_number_1 AS CHAR) = ? OR CAST(phone_number_2 AS CHAR) = ? 
                       OR CAST(phone_number_1 AS CHAR) = ? OR CAST(phone_number_2 AS CHAR) = ?)
            ");
            if ($stmt === false) {
                throw new Exception('Prepare failed: ' . htmlspecialchars($conn->error));
            }
            $stmt->bind_param("ssss", $phone_number_1, $phone_number_1, $phone_number_2, $phone_number_2);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $matched_employee_id = $row['employee_id'];
                $matched_phone_number_1 = $row['phone_number_1'] ?? 'N/A';
                $matched_phone_number_2 = $row['phone_number_2'] ?? 'N/A';

                error_log("Inputted phone number: $phone_number_1, Compared phone number from DB (phone_number_1): $matched_phone_number_1");
                error_log("Inputted phone number: $phone_number_2, Compared phone number from DB (phone_number_2): $matched_phone_number_2");

                if ($matched_employee_id == $current_employee_id) {
                    echo json_encode([
                        'phone_exists' => true,
                        'phone_message' => "You're already using this phone number",
                        'phone_number_1' => $matched_phone_number_1,
                        'phone_number_2' => $matched_phone_number_2
                    ]);
                    error_log("Matched phone number belongs to current employee: " . $matched_employee_id);
                    exit;
                } else {
                    echo json_encode([
                        'phone_exists' => true,
                        'phone_message' => "Phone number already in use",
                        'phone_number_1' => $matched_phone_number_1,
                        'phone_number_2' => $matched_phone_number_2
                    ]);
                    error_log("Matched phone number belongs to another employee: " . $matched_employee_id);
                    exit;
                }
            }

            $stmt->close();
        }

        // Validate and update password
        if ($old_password) {
            error_log("Starting password validation...");
        
            if (!isset($_SESSION['employee_id'])) {
                echo json_encode(["success" => false, "message" => "User not logged in."]);
                exit();
            }
        
            // Use session-based employee_id
            $current_employee_id = preg_replace('/^[A-Z]+-/', '', $_SESSION['employee_id']);
            error_log("Session employee_id: " . $_SESSION['employee_id']);
            error_log("Processed employee_id: " . $current_employee_id);
        
            $stmt = $conn->prepare("SELECT password_hash FROM users WHERE employee_id = ?");
            if ($stmt === false) {
                error_log("Failed to prepare statement: " . htmlspecialchars($conn->error));
                echo json_encode(["success" => false, "message" => "Database query failed."]);
                exit();
            }
        
            $stmt->bind_param("s", $current_employee_id);
        
            if (!$stmt->execute()) {
                error_log("Query execution failed: " . $conn->error);
                echo json_encode(["success" => false, "message" => "Database query failed."]);
                exit();
            }
        
            $stmt->store_result();
            error_log("Number of rows returned: " . $stmt->num_rows);
        
            if ($stmt->num_rows === 0) {
                error_log("No user found for employee_id: " . $current_employee_id);
                echo json_encode(["success" => false, "message" => "No user found with the given employee_id."]);
                exit();
            }
        
            $stmt->bind_result($password_hash);
            $stmt->fetch();
            error_log("Fetched password hash from DB: " . $password_hash);
            $stmt->close();
        
            if (!password_verify($old_password, $password_hash)) {
                error_log("Password verification failed for employee_id: " . $current_employee_id);
                echo json_encode(["success" => false, "message" => "Incorrect password."]);
                exit();
            }
        
            error_log("Password verification successful for employee_id: " . $current_employee_id);
        }
        

        echo json_encode(['exists' => false, 'email_exists' => false, 'phone_exists' => false, 'success' => true]);
    } catch (Exception $e) {
        error_log("Error occurred: " . $e->getMessage());
        echo json_encode(["success" => false, "error" => $e->getMessage()]);
    }
} else {
    error_log("Invalid request method");
    echo json_encode(["success" => false, "error" => "Invalid request method"]);
}
?>
