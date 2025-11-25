<?php
// config.php
$host = 'localhost';
$dbname = 'sistema_login';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    // Grava o erro num log (fora do diretório público)
    error_log("Erro de conexão: " . $e->getMessage(), 3, __DIR__ . '/logs/error.log');
    die("Erro interno. Tente novamente mais tarde.");
}
?>
