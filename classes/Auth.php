<?php
class Auth {
    private $db;
    private $table = "users";

    public function __construct($db_connection) {
        $this->db = $db_connection;
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    // Register a new user
    public function register($username, $email, $password) {
        // Check if username or email already exists
        $checkQuery = "SELECT id FROM " . $this->table . " WHERE username = :username OR email = :email LIMIT 1";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':username' => $username, ':email' => $email]);
        
        if ($checkStmt->rowCount() > 0) {
            return "Username or Email already exists.";
        }

        // Hash password securely
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        $query = "INSERT INTO " . $this->table . " (username, email, password) VALUES (:username, :email, :password)";
        $stmt = $this->db->prepare($query);

        try {
            $stmt->execute([
                ':username' => $username,
                ':email' => $email,
                ':password' => $hashed_password
            ]);
            return true;
        } catch (PDOException $e) {
            return "Registration failed: " . $e->getMessage();
        }
    }

    // Login user
    public function login($username_or_email, $password) {
        $query = "SELECT * FROM " . $this->table . " WHERE username = :input OR email = :input LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':input' => $username_or_email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Password is correct, start session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['name'] = isset($user['name']) ? $user['name'] : '';
            $_SESSION['profile_picture'] = isset($user['profile_picture']) ? $user['profile_picture'] : '';
            return true;
        }
        return false;
    }

    // Check if user is logged in
    public function isLoggedIn() {
        return isset($_SESSION['user_id']);
    }

    // Get user details
    public function getUserDetails($userId) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = :id LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch();
    }

    // Update user profile
    public function updateProfile($userId, $name, $password = null, $profilePicture = null) {
        $query = "UPDATE " . $this->table . " SET name = :name";
        $params = [':name' => $name, ':id' => $userId];

        if (!empty($password)) {
            $query .= ", password = :password";
            $params[':password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        if ($profilePicture !== null) {
            $query .= ", profile_picture = :profile_picture";
            $params[':profile_picture'] = $profilePicture;
        }

        $query .= " WHERE id = :id";
        $stmt = $this->db->prepare($query);
        
        try {
            $stmt->execute($params);
            
            // Update session values
            $_SESSION['username'] = $this->getUserDetails($userId)['username'];
            $_SESSION['name'] = $name;
            if ($profilePicture !== null) {
                $_SESSION['profile_picture'] = $profilePicture;
            }
            return true;
        } catch (PDOException $e) {
            return "Update failed: " . $e->getMessage();
        }
    }

    // Restrict access to logged-in users only
    public function restrictToLoggedIn() {
        if (!$this->isLoggedIn()) {
            header("Location: login.php");
            exit();
        }
    }

    // Logout
    public function logout() {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }
}
?>