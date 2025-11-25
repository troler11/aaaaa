<?php

// ==============================================================================
// 1. CONFIGURAÇÕES DE PERFORMANCE & CACHE
// ==============================================================================

// Tempo em segundos para manter os dados da Linha em cache (5 min)
define('CACHE_TTL_DASHBOARD', 300); 

// Configuração de Pastas (Voltando ao padrão original: Pasta Temporária do Sistema)
$cookie_dir = __DIR__ . '/cookies';
if (!file_exists($cookie_dir)) @mkdir($cookie_dir, 0777, true);

// Arquivo de Cookie (Mantém na pasta do script para persistência de sessão)
define('COOKIE_FILE', $cookie_dir . '/sessao_api.txt');

// Arquivo de Cache do Dashboard (Volta para a pasta temporária do sistema)
define('CACHE_FILE_DASH', sys_get_temp_dir() . '/dashboard_main_data.json');

// Chaves TomTom
$keys_tomtom = [
    "8iGI69ukjIFE8M5XwE2aVHJOcmRlhfwR",
    "vbdLg3miOthQgBBkTjZyAaj0TmBoyIGv",
    "WquMopwFZcPTeG4s6WkxkzhnMM3w1OGH",
    "ZQx0TqIl2gkDsgF3Yw4G4qwQG6jKp77N",
    "GK7A9HjGG0cOSN1UqADrkifoN0HExUzy"
];
shuffle($keys_tomtom); 
define('TOMTOM_KEYS', $keys_tomtom);

// ==============================================================================
// 2. MOTOR DE CONEXÃO OTIMIZADO (cURL Ninja)
// ==============================================================================

/**
 * Configura o cURL para máxima velocidade e compatibilidade
 */
function curl_fast_setup($ch, $url) {
    $host = parse_url($url, PHP_URL_HOST);
    
    // Headers de Navegador (Chrome) + Compressão GZIP
    $headers = [
        "Host: $host",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36",
        "Accept: application/json, text/plain, */*",
        "Accept-Encoding: gzip, deflate, br", // Importante: Aceita compressão
        "Connection: keep-alive",
        "Upgrade-Insecure-Requests: 1"
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => COOKIE_FILE,
        CURLOPT_COOKIEFILE     => COOKIE_FILE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 10, 
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_ENCODING       => "", // Decodifica GZIP automaticamente
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1
    ]);
}

/**
 * Realiza o aquecimento APENAS se necessário
 */
function garantir_aquecimento() {
    global $URL_MAPA;
    
    // Verifica se o cookie existe e é recente (< 15 min)
    if (file_exists(COOKIE_FILE) && filesize(COOKIE_FILE) > 0) {
        if (isset($_SESSION['last_api_warmup']) && (time() - $_SESSION['last_api_warmup']) < 900) {
            return;
        }
    }

    if (file_exists(COOKIE_FILE)) @unlink(COOKIE_FILE);

    $ch = curl_init($URL_MAPA);
    curl_fast_setup($ch, $URL_MAPA);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 400) {
        if (!file_exists(COOKIE_FILE)) touch(COOKIE_FILE);
    } else {
        $_SESSION['last_api_warmup'] = time();
    }
}

// ==============================================================================
// 3. FUNÇÕES DE NEGÓCIO
// ==============================================================================

function limpar_placa($placa) {
    return preg_replace("/[^A-Za-z0-9]/", '', strtoupper($placa));
}

function responder_json($dados, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Compacta resposta para o cliente se possível
    if (in_array('ob_gzhandler', ob_list_handlers()) === false) {
        ob_start('ob_gzhandler');
    }
    echo json_encode($dados);
    exit;
}

function get_veiculo_posicao($placa_clean) {
    global $URL_API_RASTREAMENTO;
    
    garantir_aquecimento();

    $payload = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
    
    $ch = curl_init($URL_API_RASTREAMENTO);
    curl_fast_setup($ch, $URL_API_RASTREAMENTO);
    
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    
    // Header Ajax
    $host = parse_url($URL_API_RASTREAMENTO, PHP_URL_HOST);
    $scheme = parse_url($URL_API_RASTREAMENTO, PHP_URL_SCHEME);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(
        curl_getinfo($ch, CURLINFO_HEADER_OUT) ?: [],
        ["X-Requested-With: XMLHttpRequest", "Origin: $scheme://$host"]
    ));

    $resp_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 400 || !$resp_body) {
        @unlink(COOKIE_FILE);
        throw new Exception("Erro de comunicação ($http_code). Tente novamente.");
    }

    $dados = json_decode($resp_body, true);
    if (empty($dados) || !isset($dados[0])) {
        if (strpos($resp_body, '<html') !== false) {
             @unlink(COOKIE_FILE);
             throw new Exception("Sessão renovada. Tente novamente.");
        }
        throw new Exception("Veículo não localizado.");
    }

    return $dados[0];
}

/**
 * Busca dados do Dashboard usando o CACHE do sistema (sys_get_temp_dir)
 */
function get_dados_dashboard_cached() {
    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;

    // 1. Tenta ler do cache temporário
    if (file_exists(CACHE_FILE_DASH)) {
        $idade = time() - filemtime(CACHE_FILE_DASH);
        if ($idade < CACHE_TTL_DASHBOARD) {
            // Cache válido! Retorna rápido.
            return json_decode(file_get_contents(CACHE_FILE_DASH), true);
        }
    }

    // 2. Se não tem ou expirou, baixa novo
    $ch = curl_init($URL_DASHBOARD_MAIN);
    curl_fast_setup($ch, $URL_DASHBOARD_MAIN);
    if (!empty($HEADERS_DASHBOARD_MAIN)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $HEADERS_DASHBOARD_MAIN);
    }
    
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code == 200 && $resp) {
        // Salva no cache (sys_get_temp_dir)
        file_put_contents(CACHE_FILE_DASH, $resp);
        return json_decode($resp, true);
    }

    // Fallback: Se der erro, tenta usar o cache velho mesmo vencido
    if (file_exists(CACHE_FILE_DASH)) {
        return json_decode(file_get_contents(CACHE_FILE_DASH), true);
    }

    throw new Exception("Falha ao obter dados do Dashboard.");
}

function calcular_rota_tomtom($locations_string) {
    foreach (TOMTOM_KEYS as $key) {
        if ($key === "teste") continue; 

        $url = "https://api.tomtom.com/routing/1/calculateRoute/{$locations_string}/json?key={$key}&traffic=true&travelMode=bus";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_ENCODING => "gzip", 
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 200) return json_decode($body, true);
    }
    throw new Exception("Serviço de rotas indisponível temporariamente.");
}

// ==============================================================================
// 4. HANDLERS (Controladores)
// ==============================================================================

function handle_buscar_rastreamento($placa) {
    try {
        $placa_clean = limpar_placa($placa);
        $veiculo = get_veiculo_posicao($placa_clean);
        responder_json([$veiculo]); 
    } catch (Exception $e) {
        responder_json(["erro" => $e->getMessage()], 500);
    }
}

function handle_calcular_rota($placa, $tipo_destino = "Final") {
    try {
        $placa_clean = limpar_placa($placa);

        // 1. Pega Veículo
        $veiculo = get_veiculo_posicao($placa_clean);
        
        $lat1 = 0; $lon1 = 0;
        if (isset($veiculo['loc']) && is_array($veiculo['loc'])) { $lat1 = (float)$veiculo['loc'][0]; $lon1 = (float)$veiculo['loc'][1]; }
        elseif (isset($veiculo['latitude'])) { $lat1 = (float)$veiculo['latitude']; $lon1 = (float)$veiculo['longitude']; }
        
        if ($lat1 == 0) responder_json(["erro" => "Coordenadas inválidas."], 422);

        // 2. Pega Dados do Dashboard (COM CACHE)
        $data_dash = get_dados_dashboard_cached();

        // Procura a linha
        $linha_alvo = null;
        $grupos = ['linhasAndamento', 'linhasCarroDesligado', 'linhasComecaramSemPrimeiroPonto'];
        
        foreach ($grupos as $grupo) {
            if (empty($data_dash[$grupo])) continue;
            foreach ($data_dash[$grupo] as $l) {
                if (($l['veiculo']['veiculo'] ?? '') == $placa) {
                    $linha_alvo = $l; 
                    break 2;
                }
            }
        }

        if (!$linha_alvo) responder_json(["erro" => "Linha não encontrada."], 404);

        // 3. Processa Pontos
        $pontos_mapa = [];
        $paradas = $linha_alvo['pontoDeParadas'] ?? [];
        
        $p_ini = null; $p_fim = null; $p_meio = [];
        
        foreach ($paradas as $p) {
            $plat = (float)($p['latitude']??0); $plng = (float)($p['longitude']??0);
            if ($plat == 0) continue;
            
            $d = ["lat"=>$plat, "lng"=>$plng, "passou"=>($p['passou']??false), "nome"=>$p['descricao']??'Ponto'];
            $tipo = strtolower($p['tipoPonto']['tipo']??'');

            if ($tipo == 'inicial') $p_ini = $d;
            elseif ($tipo == 'final') $p_fim = $d;
            else $p_meio[] = $d;
        }

        if ($p_ini) $pontos_mapa[] = $p_ini;
        foreach ($p_meio as $p) $pontos_mapa[] = $p;
        if ($p_fim) $pontos_mapa[] = $p_fim;

        if (empty($pontos_mapa)) responder_json(["erro" => "Rota sem pontos."], 400);

        // Define Destino
        $pAlvo = ($tipo_destino == 'Inicial') ? $pontos_mapa[0] : end($pontos_mapa);
        $lat2 = $pAlvo['lat']; $lon2 = $pAlvo['lng'];

        // 4. Monta Waypoints para TomTom
        $coords_tomtom = ["$lat1,$lon1"];
        $coords_visual = [[$lon1, $lat1]];

        if ($tipo_destino === 'Inicial') {
            $coords_tomtom[] = "$lat2,$lon2";
            $coords_visual[] = [$lon2, $lat2];
        } else {
            $encontrou = false;
            foreach ($pontos_mapa as $p) {
                if (!$p['passou']) {
                    $coords_tomtom[] = "{$p['lat']},{$p['lng']}";
                    $coords_visual[] = [$p['lng'], $p['lat']]; 
                    if ($p['lat'] == $lat2 && $p['lng'] == $lon2) $encontrou = true;
                }
            }
            if (!$encontrou) { 
                $coords_tomtom[] = "$lat2,$lon2"; 
                $coords_visual[] = [$lon2, $lat2];
            }
        }

        // 5. Calcula Rota
        $tomtom_data = calcular_rota_tomtom(implode(':', $coords_tomtom));
        $summary = $tomtom_data["routes"][0]["summary"] ?? ["travelTimeInSeconds" => 0, "lengthInMeters" => 0];
        
        $segundos = (int)$summary["travelTimeInSeconds"];
        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);

        responder_json([
            "tempo" => ($horas > 0 ? "{$horas}h " : "") . "{$minutos} min",
            "distancia" => sprintf("%.2f km", $summary["lengthInMeters"] / 1000),
            "lat" => $lat2, "lng" => $lon2,
            "duracaoSegundos" => $segundos,
            "waypoints_usados" => $coords_visual,
            "todos_pontos_visual" => $pontos_mapa,
            "paradas_restantes" => max(0, count($coords_visual) - 2)
        ]);

    } catch (Exception $e) {
        responder_json(["erro" => $e->getMessage()], 500);
    }
}
?>
