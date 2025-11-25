<?php
// --- ADICIONE ISTO PARA VER O ERRO ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// 1. INICIA A SESSÃO

session_start();
$empresas_permitidas = $_SESSION['allowed_companies'] ?? [];

// 2. Incluir Configuração e Funções
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/api_handlers.php';
require_once 'includes/page_logic.php';

// 3. Parsear a Rota
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path = explode('?', $path)[0]; // Remover query string

// 4. Lógica de Roteamento
if (preg_match('!^/buscar_rastreamento/([^/]+)$!', $path, $matches)) {
    handle_buscar_rastreamento($matches[1]);

} elseif (preg_match('!^/previsao/([^/]+)$!', $path, $matches)) {
    handle_calcular_rota($matches[1], "Final");

} elseif (preg_match('!^/previsaoinicial/([^/]+)$!', $path, $matches)) {
    handle_calcular_rota($matches[1], "Inicial");

// **** A CORREÇÃO ESTÁ AQUI ****
// Agora, ele aceita a raiz (/) OU o acesso direto ao /index.php
} elseif ($path == '/' || $path == '/index.php') {
    
    // 5. VERIFICAÇÃO DE LOGIN PARA A PÁGINA PRINCIPAL
    if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        header('Location: login.php');
        exit;
    }
    
    // 6. Rota da Página Principal
    $data = handle_index_data($empresas_permitidas);

    if (isset($data['erro'])) {
        http_response_code(500);
        echo "<h1>Erro ao carregar o Dashboard</h1>";
        echo "<p>" . htmlspecialchars($data['erro']) . "</p>";
        exit;
    }

    extract($data);
    require_once 'templates/dashboard.php';
    exit;

} elseif ($path == '/login.php' || $path == '/logout.php') {
    // Deixa o servidor carregar 'login.php' e 'logout.php' diretamente,
    // sem que o roteador os intercepte como um "404".
}
else {
    // 7. Rota não encontrada (404)
    responder_json(["erro" => "Rota não encontrada: $path"], 404);
}