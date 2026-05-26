-- 1. Create the database if it doesn't already exist
CREATE DATABASE IF NOT EXISTS inventry_management_system
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

-- 2. Switch to using the newly created database
USE inventry_management_system;

-- 3. Create the users table with the role column included
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Insert default accounts for instant testing
-- The password for both accounts below is exactly: password123
-- (These are pre-hashed using PHP's standard password_hash BCRYPT algorithm)

INSERT INTO users (username, email, password, role) 
VALUES 
('admin_user', 'admin@gmail.com', '$2y$10$vD7b1b3mREb62CgR1qFm/.7L6L8hC9iW4qM3aU7Iym7C1Y7vWshG6', 'admin'),
('regular_user', 'user@gmail.com', '$2y$10$vD7b1b3mREb62CgR1qFm/.7L6L8hC9iW4qM3aU7Iym7C1Y7vWshG6', 'user')
ON DUPLICATE KEY UPDATE username=username;