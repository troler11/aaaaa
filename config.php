<?php
// config.php
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('America/Sao_Paulo');

// --- 1. CONFIGURAÇÃO DO BANCO DE DADOS (MySQL) ---
$host = 'aws-1-us-east-1.pooler.supabase.com'; // O Host da Clever Cloud
$db   = 'postgres'; // O nome do Database
$user = 'postgres.vwnzbfrefhkdkbfxhfhd'; // O usuário
$pass = 'Lukinha2009@';
$charset = 'utf8mb4';
$port = '6543'; // Porta do Pooler do Supabase (tente 5432 se 6543 falhar)

$dsn = "pgsql:host=$host;port=$port;dbname=$db";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Para garantir UTF-8 no Postgres:
    $pdo->exec("SET NAMES 'UTF8'");
} catch (\PDOException $e) {
    // É boa prática não exibir o erro detalhado em produção para não vazar credenciais
    die("Erro de conexão: " . $e->getMessage()); 
}

// --- 2. CONFIGURAÇÃO DE URLS (ABM Tecnologia) ---
$URL_BASE      = "https://abmtecnologia.abmprotege.net";
define('URL_WORKER_RENDER', 'https://testeservidor-wg1g.onrender.com');
define('RENDER_TOKEN', 'teste');


// --- 3. CONFIGURAÇÃO DASHBOARD EXTRA (Mantido do seu original) ---
$URL_DASHBOARD_MAIN = "https://abmbus.com.br:8181/api/dashboard/mongo/95?naoVerificadas=false&agrupamentos=";
$HEADERS_DASHBOARD_MAIN = [
    "Accept: application/json, text/plain, */*",
    "Authorization: eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJtaW1vQGFibXByb3RlZ2UuY29tLmJyIiwiZXhwIjoxODYwNzEwOTEyfQ.2yLysK8kK1jwmSCYJODCvWgppg8WtjuLxCwxyLnm2S0qAzSp12bFVmhwhVe8pDSWWCqYBCXuj0o2wQLNtHFpRw"
];

// --- 4. CREDENCIAIS & SESSÃO ---


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
function verificarPermissaoPagina($menu_necessario) {
    // 1. Garante que o usuário está logado
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        header("Location: login.php");
        exit;
    }

    // 2. Se for ADMIN, libera tudo imediatamente
    if (($_SESSION['user_role'] ?? '') === 'admin') {
        return true;
    }

    // 3. Se for USUÁRIO COMUM, verifica se tem a permissão no JSON da sessão
    $permissoes = json_decode($_SESSION['user_menus'] ?? '[]', true);
    if (!is_array($permissoes)) {
        $permissoes = [];
    }

    // 4. Se a permissão NÃO estiver na lista, chuta para o dashboard
    if (!in_array($menu_necessario, $permissoes)) {
        // Opcional: define uma mensagem de erro na sessão
        $_SESSION['msg_erro'] = "Acesso negado: Você não tem permissão para acessar essa área.";
        header("Location: /");
        exit; // IMPORTANTE: o exit para o script aqui
    }
}

