<?php
// config.php
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('America/Sao_Paulo');

// --- 1. CONFIGURAÇÃO DO BANCO DE DADOS (MySQL) ---
$host = 'bx55....mysql.services.clever-cloud.com'; // O Host da Clever Cloud
$db   = 'bf4.....'; // O nome do Database
$user = 'uNc.....'; // O usuário
$pass = 'SuaSenhaGiganteAqui';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // echo "Conectado com sucesso!";
} catch (\PDOException $e) {
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
}

// --- 2. CONFIGURAÇÃO DE URLS (ABM Tecnologia) ---
$URL_BASE      = "https://abmtecnologia.abmprotege.net";
$URL_LOGIN     = $URL_BASE . "/emp/abmtecnologia";
$URL_MAPA      = $URL_BASE . "/mapaGeral"; // <--- NOVO: Essencial para aquecer a sessão
$URL_API_RASTREAMENTO = $URL_BASE . "/mapaGeral/filtrarRastreadosPorPlacaOuIdentificacao";

// --- 3. CONFIGURAÇÃO DASHBOARD EXTRA (Mantido do seu original) ---
$URL_DASHBOARD_MAIN = "https://abmbus.com.br:8181/api/dashboard/mongo/95?naoVerificadas=false&agrupamentos=";
$HEADERS_DASHBOARD_MAIN = [
    "Accept: application/json, text/plain, */*",
    "Authorization: eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJtaW1vQGFibXByb3RlZ2UuY29tLmJyIiwiZXhwIjoxODYwNzEwOTEyfQ.2yLysK8kK1jwmSCYJODCvWgppg8WtjuLxCwxyLnm2S0qAzSp12bFVmhwhVe8pDSWWCqYBCXuj0o2wQLNtHFpRw"
];

// --- 4. CREDENCIAIS & SESSÃO ---
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";

// Onde salvar o cookie (Usa pasta temporária do sistema para evitar problemas de permissão)
$COOKIE_FILE = sys_get_temp_dir() . '/abm_session_cookie.txt';

// --- 5. HEADERS ---
// Atualizado para o User-Agent que funcionou no teste
$USER_AGENT_CORRETO = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36";

$HEADERS_COMMON = [
    "User-Agent: " . $USER_AGENT_CORRETO,
    "Accept: application/json, text/javascript, */*; q=0.01",
    "Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7"
];

?>

