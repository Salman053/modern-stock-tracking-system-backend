<?php

class Database
{
    // Database connection parameters
    private $host = "localhost";
    private $username = "root";
    private $password = "";
    private $db_name = "textile-track-system";

    // Connection object property
    public $conn;

    /**
     * Establishes and returns the database connection.
     *
     * @return PDO|null The PDO connection object or null on failure.
     */
    public function getConnection()
    {
        $this->conn = null;

        try {
            // Correct DSN: Added 'charset=utf8mb4'
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            
            $this->conn = new PDO($dsn, $this->username, $this->password);
            
            // Set error mode to throw exceptions
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Removed the unnecessary and incorrect $this->conn->exec(); call
            
        } catch (PDOException $e) {
            echo "Connection error : " . $e->getMessage();
        }
        
        return $this->conn;
    }
}