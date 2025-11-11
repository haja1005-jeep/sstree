<?php
/**
 * 데이터베이스 연결 설정
 * Smart Tree Map - Sinan County
 */

class Database {
    private $host = "localhost";
    private $db_name = "sstree";
    private $username = "sstree";
    private $password = "v1dbsstree$";
    public $conn;

    // 데이터베이스 연결
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            echo "연결 오류: " . $e->getMessage();
        }

        return $this->conn;
    }
}
?>
