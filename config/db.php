<?php
/**
 * config/db.php  —  DATABASE CONNECTION
 * ------------------------------------------------------------
 * WHAT THIS FILE DOES
 *   Opens ONE connection to the PostgreSQL database and stores it in
 *   the variable $pdo. Every page that needs data does:
 *
 *        require_once 'config/db.php';
 *
 *   and can then use $pdo->query(...) or $pdo->prepare(...).
 *
 * UNCHANGED FROM YOUR PREVIOUS VERSION
 *   (Only comments were added.) Change the values below if your
 *   database name / user / password are different on another computer.
 * ------------------------------------------------------------
 */

$host = 'localhost';   // The computer where PostgreSQL runs (your own PC = localhost)
$port = '5432';        // PostgreSQL's default port
$db   = 'gotake_db';   // The database name
$user = 'postgres';    // Database user
$pass = 'aryavan32';   // Database password  (never share this file publicly!)

// DSN = "Data Source Name": one string that tells PDO what to connect to.
$dsn = "pgsql:host=$host;port=$port;dbname=$db;";

$options = [
    // If a query fails, throw an Exception (so we can use try/catch) instead of failing silently.
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Rows come back as ['column_name' => value] arrays (easy to read).
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    // Use REAL prepared statements (safer against SQL injection).
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
