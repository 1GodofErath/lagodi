<?php
// Файл конфігурації бази даних
class Database1 {
    private $host = "localhost";
    private $db_name = "l131113_login";
    private $username = "l131113_login_us";
    private $password = "ki4x03a91wlzdz0zgv";
    private $conn;

    // Метод підключення до бази даних
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8", $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            echo "Помилка підключення до бази даних: " . $e->getMessage();
        }

        return $this->conn;
    }
}
?>