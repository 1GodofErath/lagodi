<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Site settings
define('SITE_NAME', 'Сервісний центр');
define('SITE_URL', 'https://example.com');
define('ADMIN_EMAIL', 'admin@example.com');
// Database settings
define('DB_HOST', 'localhost');
define('DB_USER', 'l131113_login_us');
define('DB_PASSWORD', 'ki4x03a91wlzdz0zgv');
define('DB_NAME', 'l131113_login');

// File upload settings
define('UPLOAD_DIR', 'uploads/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/mpeg', 'video/quicktime']);
define('ALLOWED_TEXT_TYPES', ['text/plain', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);

// Session settings
ini_set('session.cookie_lifetime', 0);
ini_set('session.use_cookies', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Set to 0 if not using HTTPS
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 1800); // 30 minutes

// Error reporting (turn off in production)
//error_reporting(E_ALL);
//ini_set('display_errors', 1);
error_reporting(0);
ini_set('display_errors', 0);

// Time zone
date_default_timezone_set('Europe/Kiev');

// Security settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('PASSWORD_MIN_LENGTH', 8);
define('SESSION_TIMEOUT', 30); // Minutes

// Initialize CSRF token if not set
function initCSRFToken() {
    if (!isset($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

// Validate CSRF token
function validateCSRFToken($token) {
    if (!isset($_SESSION[CSRF_TOKEN_NAME]) || $token !== $_SESSION[CSRF_TOKEN_NAME]) {
        return false;
    }
    return true;
}

// Add CSRF meta tag
function getCSRFMeta() {
    $token = initCSRFToken();
    return '<meta name="csrf-token" content="' . $token . '">';
}

// Clean input data
function cleanInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}