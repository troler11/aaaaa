<?php
// ==========================================
// 1. CONFIGURAÇÕES
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// URLs
$URL_BASE   = "https://abmtecnologia.abmprotege.net";
$URL_LOGIN  = $URL_BASE . "/emp/abmtecnologia";
$URL_MAPA   = $URL_BASE . "/mapaGeral"; // <--- PÁGINA INTERMEDIÁRIA
$URL_API    = $URL_BASE . "/mapaGeral/filtrarRastreadosPorPlacaOuIdentificacao";

// Credenciais
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";

// User Agent idêntico ao do seu teste manual (importante)
$USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36";

// ==========================================
// 2. FUNÇÃO CURIOSA (cURL)
// ==========================================
function curl_request($method, $url, $cookieFile, $data = null, $customHeaders = []) {
    global $USER_AGENT;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    // Headers Padrão
    $headers = [
        "User-Agent: $USER_AGENT",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7"
    ];
    
    // Mescla headers customizados
    if (!empty($customHeaders)) {
        $headers = array_merge($headers, $customHeaders);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // Configura Método POST/GET
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error    = curl_error($ch);
    curl_close($ch);

    return ['body' => $response, 'code' => $httpCode, 'url' => $finalUrl, 'error' => $error];
}

// ==========================================
// 3. EXECUÇÃO PASSO A PASSO
// ==========================================
echo "<pre>";
$cookieFile = sys_get_temp_dir() . '/abm_cookie_v3.txt';
if (file_exists($cookieFile)) @unlink($cookieFile); // Começa limpo para garantir

// --- PASSO 1: LOGIN ---
echo "<h3>1. Realizando Login...</h3>";
$loginData = http_build_query(["login" => $ABM_USER, "senha" => $ABM_PASS, "password" => $ABM_PASS]);
$headersLogin = ["Content-Type: application/x-www-form-urlencoded"];

$resLogin = curl_request('POST', $URL_LOGIN, $cookieFile, $loginData, $headersLogin);

if (strpos($resLogin['url'], "emp/abmtecnologia") !== false) {
    die("<b style='color:red'>❌ Falha no login. Verifique usuário/senha.</b>");
}
echo "<b style='color:green'>✅ Login OK!</b>\n";


// --- PASSO 2: VISITAR O MAPA (Para pegar tokens e validar sessão) ---
echo "<h3>2. Acessando página do Mapa (Aquecimento)...</h3>";
$resMapa = curl_request('GET', $URL_MAPA, $cookieFile);

// Tenta extrair Token CSRF (caso exista no HTML)
$csrfToken = '';
// Procura em meta tag: <meta name="csrf-token" content="...">
if (preg_match('/<meta name="csrf-token" content="(.*?)">/', $resMapa['body'], $matches)) {
    $csrfToken = $matches[1];
    echo "🔑 Token CSRF encontrado: $csrfToken\n";
} else {
    echo "ℹ️ Nenhum Token CSRF explícito encontrado (o sistema pode usar apenas cookie).\n";
}


// --- PASSO 3: CHAMADA DA API (Onde dava erro 403) ---
echo "<h3>3. Consultando API da Placa...</h3>";

$payload = [
    'placa_ou_identificacao' => "1107040",
    'index_view_ft' => "7259" // Esse ID pode mudar? Se falhar, talvez tenhamos que extrair do HTML do passo 2
];

// Headers Simulando AJAX
$headersAPI = [
    "X-Requested-With: XMLHttpRequest",
    "Origin: $URL_BASE",
    "Referer: $URL_MAPA"
];

// Se achamos token, adiciona
if ($csrfToken) {
    $headersAPI[] = "X-CSRF-TOKEN: $csrfToken";
}

// Importante: Enviar como ARRAY para gerar multipart/form-data
$resAPI = curl_request('POST', $URL_API, $cookieFile, $payload, $headersAPI);

echo "\n--- STATUS FINAL: " . $resAPI['code'] . " ---\n";

if ($resAPI['code'] == 200) {
    $json = json_decode($resAPI['body'], true);
    if ($json) {
        echo "<h2 style='color:green'>🏆 SUCESSO!</h2>";
        print_r($json);
    } else {
        echo "⚠️ Retornou 200, mas não é JSON. Veja o início:\n";
        echo htmlspecialchars(substr($resAPI['body'], 0, 500));
    }
} elseif ($resAPI['code'] == 403) {
    echo "<b style='color:red'>🚫 Ainda deu 403 Forbidden.</b>\n";
    echo "Isso significa que o servidor bloqueou o script. Possíveis causas:\n";
    echo "1. O IP do servidor onde este script roda está bloqueado.\n";
    echo "2. O 'index_view_ft' (7259) expirou e precisa ser pego dinamicamente.\n";
} else {
    echo "Erro inesperado: " . $resAPI['code'];
    echo htmlspecialchars(substr($resAPI['body'], 0, 500));
}

echo "</pre>";
?>