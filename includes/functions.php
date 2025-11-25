<?php

function responder_json($data, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function simple_get($url, $headers = [], $timeout = 10) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$response_body, $http_code, $error];
}

function curl_with_session($metodo, $url, $cookieFile, $headers, $data = null, $timeout = 10) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); 
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($metodo == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data !== null) {
            // SE FOR ARRAY -> Envia como Form-Data (Login usa isso)
            // SE FOR STRING -> Envia Cru (API JSON usa isso)
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
    }

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_error($ch);
    curl_close($ch);

    return [$response_body, $http_code, $effective_url, $error];
}

function get_session_autenticada($force_login = false) {
    global $URL_LOGIN, $ABM_USER, $ABM_PASS, $HEADERS_COMMON;
    
    $COOKIE_FILE_PATH = sys_get_temp_dir() . '/abm_session_cookie.txt';

    // ... (lógica de verificar se arquivo existe mantida) ...

    // Se precisar logar:
    $payload = ["login" => $ABM_USER, "senha" => $ABM_PASS, "password" => $ABM_PASS];

    // ATENÇÃO: Adicionei headers específicos de form-data aqui para garantir
    // que o login use o formato correto se a nova função curl_with_session estiver sendo usada
    $headers_login = array_merge($HEADERS_COMMON, ["Content-Type: application/x-www-form-urlencoded"]);

    list($response_body, $http_code, $effective_url, $error) = curl_with_session(
        'POST', $URL_LOGIN, $COOKIE_FILE_PATH, $headers_login, $payload
    );

    // --- BLOCO DE DEBUG (ADICIONE ISSO) ---
    if (file_exists($COOKIE_FILE_PATH)) {
        $conteudo_cookie = file_get_contents($COOKIE_FILE_PATH);
        
        // Se quiser interromper tudo e mostrar o cookie na tela:
        /*
        header('Content-Type: text/plain');
        echo "=== CONTEÚDO DO ARQUIVO DE COOKIE ===\n";
        echo $conteudo_cookie;
        echo "\n\n=== RESPOSTA DO LOGIN ===\n";
        echo $response_body;
        exit; 
        */
    }
    // --------------------------------------

    if ($error || strpos($effective_url, "emp/abmtecnologia") !== false) {
        return null;
    }
    return $COOKIE_FILE_PATH;
}

function fazer_requisicao_resiliente($metodo, $url, $data = null, $extra_headers = []) {
    global $HEADERS_COMMON;
    $headers_finais = array_merge($HEADERS_COMMON, $extra_headers);

    $sessionCookieFile = get_session_autenticada(false);
    if (!$sessionCookieFile) return [null, 401, "Falha no login inicial"];

    list($response_body, $http_code, $effective_url, $error) = curl_with_session(
        $metodo, $url, $sessionCookieFile, $headers_finais, $data
    );
    
    if ($error) return [null, 500, "Erro cURL: $error"];

    // Se sessão caiu, reloga e tenta de novo
    if ($http_code == 401 || strpos($effective_url, "emp/abmtecnologia") !== false) {
        $sessionCookieFile = get_session_autenticada(true); 
        if (!$sessionCookieFile) return [null, 401, "Falha ao renovar login"];

        list($response_body, $http_code, $effective_url, $error) = curl_with_session(
            $metodo, $url, $sessionCookieFile, $headers_finais, $data
        );
    }
    return [$response_body, $http_code, null];
}
?>