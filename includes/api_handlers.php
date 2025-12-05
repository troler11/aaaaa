<?php
// ==============================================================================
// OTIMIZAÇÃO 0: GZIP & HEADERS
// ==============================================================================
if (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
    ob_start("ob_gzhandler"); 
} else { 
    ob_start(); 
}

// ==============================================================================
// CONFIGURAÇÃO
// ==============================================================================

define('TOMTOM_KEYS', [
    "8iGI69ukjIFE8M5XwE2aVHJOcmRlhfwR", "vbdLg3miOthQgBBkTjZyAaj0TmBoyIGv",
    "WquMopwFZcPTeG4s6WkxkzhnMM3w1OGH", "ZQx0TqIl2gkDsgF3Yw4G4qwQG6jKp77N",
    "GK7A9HjGG0cOSN1UqADrkifoN0HExUzy", "y7k9fPzIQr27uNWDYCZfsS6zX73G25z8",
    "4VAflHY7FO3OJ57qGa2mxdnNPIHnkwqt", "0sZFxbwSeEKUti72AnYAZapyMCVSQYLe",
    "k1d8gtGfmXzyD03nTj8Ea2C8fTqGuC8K"
]);

define('URL_API_DETALHE_LINHA', 'https://abmbus.com.br:8181/api/linha/');
define('URL_API_MONGO', 'https://abmbus.com.br:8181/api/rota/temporealmongo/');
define('TOKEN_ABMBUS', 'eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJtaW1vQGFibXByb3RlZ2UuY29tLmJyIiwiZXhwIjoxODY0MDk5MTA1fQ.NtMXhjWVGmd9DGYEXdW9AOTgAweiRMHC7j5XXAObFDAof80dUggpj7yv98Aqe5jXDajqFnc3sDrqcBTK0gy1lQ');

define('HEADERS_DETALHE_LINHA', [
    "Accept: application/json",
    "Authorization: " . TOKEN_ABMBUS,
    "Connection: keep-alive",
    "User-Agent: MimoBusBot/1.0"
]);

// ==============================================================================
// CACHE HÍBRIDO (RAM > DISCO)
// ==============================================================================

function cache_get($key) {
    // 1. Tenta RAM (Mais rápido - APCu)
    if (function_exists('apcu_fetch')) {
        $dados = apcu_fetch($key);
        if ($dados !== false) return $dados;
    }
    
    // 2. Tenta Disco (Fallback)
    $file = sys_get_temp_dir() . '/' . md5($key) . '.json';
    if (file_exists($file) && (time() - filemtime($file) < 86400)) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}

function cache_set($key, $data, $ttl = 86400) {
    if (function_exists('apcu_store')) apcu_store($key, $data, $ttl);
    
    $file = sys_get_temp_dir() . '/' . md5($key) . '.json';
    // OTIMIZAÇÃO: JSON Compacto
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// ==============================================================================
// MATEMÁTICA ALTA PERFORMANCE
// ==============================================================================

function calcular_distancia_rapida($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === $lat2 && $lon1 === $lon2) return 0;
    $degLen = 111195;
    $x = $lat1 - $lat2;
    $y = ($lon1 - $lon2) * cos(deg2rad(($lat1 + $lat2) / 2));
    return $degLen * sqrt($x*$x + $y*$y);
}

function distancia_perpendicular_sq($ponto, $linha_inicio, $linha_fim) {
    $x = $ponto[0]; $y = $ponto[1];
    $x1 = $linha_inicio[0]; $y1 = $linha_inicio[1];
    $x2 = $linha_fim[0]; $y2 = $linha_fim[1];
    $C = $x2 - $x1; $D = $y2 - $y1;
    $dot = ($x - $x1) * $C + ($y - $y1) * $D;
    $len_sq = $C * $C + $D * $D;
    $param = ($len_sq != 0) ? $dot / $len_sq : -1;
    if ($param < 0) { $xx = $x1; $yy = $y1; }
    elseif ($param > 1) { $xx = $x2; $yy = $y2; }
    else { $xx = $x1 + $param * $C; $yy = $y1 + $param * $D; }
    $dx = $x - $xx; $dy = $y - $yy;
    return $dx * $dx + $dy * $dy;
}

function simplificar_rota($pointList, $epsilon = 0.0001) {
    if (count($pointList) < 3) return $pointList;
    $dmax_sq = 0; $index = 0; $end = count($pointList) - 1;
    $epsilon_sq = $epsilon * $epsilon;
    for ($i = 1; $i < $end; $i++) {
        $d_sq = distancia_perpendicular_sq($pointList[$i], $pointList[0], $pointList[$end]);
        if ($d_sq > $dmax_sq) { $index = $i; $dmax_sq = $d_sq; }
    }
    if ($dmax_sq > $epsilon_sq) {
        $res1 = simplificar_rota(array_slice($pointList, 0, $index + 1), $epsilon);
        $res2 = simplificar_rota(array_slice($pointList, $index), $epsilon);
        return array_merge(array_slice($res1, 0, -1), $res2);
    }
    return [$pointList[0], $pointList[$end]];
}

function arredondar_coords(array $coords): array {
    $novo = [];
    foreach ($coords as $p) {
        $novo[] = [(float)number_format($p[0], 5, '.', ''), (float)number_format($p[1], 5, '.', '')];
    }
    return $novo;
}

// ==============================================================================
// HELPERS
// ==============================================================================

function verificar_auth_e_liberar() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        responder_json(["erro" => "Não autorizado"], 401);
    }
    session_write_close();
}

function limpar_placa($placa) {
    return preg_replace("/[^A-Z0-9]/", '', strtoupper($placa));
}

function garantir_aquecimento() {
    global $URL_MAPA;
    if (session_status() === PHP_SESSION_NONE) session_start();
    $agora = time();
    $ultimo = $_SESSION['last_api_warmup'] ?? 0;
    if (($agora - $ultimo) > 1200) {
        $_SESSION['last_api_warmup'] = $agora;
        session_write_close(); 
        if(function_exists('fazer_requisicao_resiliente')) fazer_requisicao_resiliente('GET', $URL_MAPA ?? '', null, []);
    } else {
        session_write_close(); 
    }
}

function extrair_coordenadas($veiculo) {
    if (isset($veiculo['loc'])) {
        if (is_array($veiculo['loc'])) return [(float)$veiculo['loc'][0], (float)$veiculo['loc'][1]];
        if (is_string($veiculo['loc'])) {
            $p = explode(',', trim($veiculo['loc'], "[]"));
            if(count($p) >= 2) return [(float)$p[0], (float)$p[1]];
        }
    }
    $lat = (float)($veiculo['latitude'] ?? 0);
    $lon = (float)($veiculo['longitude'] ?? 0);
    return ($lat != 0) ? [$lat, $lon] : null;
}

// ==============================================================================
// MULTI-CURL COM HTTP/2 (SEM CIRCUIT BREAKER)
// ==============================================================================

function buscar_dados_paralelos($idLinha, $idVeiculoMongo) {
    $mh = curl_multi_init();
    $handles = [];
    $resultados = ['programado' => [], 'executado' => []];

    // 1. Rota Programada
    $cachedProg = cache_get("rastro_prog_$idLinha");
    if ($cachedProg) {
        $resultados['programado'] = $cachedProg;
    } else {
        $ch1 = curl_init(URL_API_DETALHE_LINHA . $idLinha);
        curl_setopt_array($ch1, [
            CURLOPT_RETURNTRANSFER => 1, 
            CURLOPT_HTTPHEADER => HEADERS_DETALHE_LINHA, 
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0 // Força HTTP/2
        ]);
        curl_multi_add_handle($mh, $ch1);
        $handles['programado'] = $ch1;
    }

    // 2. Rota Executada
    if ($idVeiculoMongo) {
        $ch2 = curl_init(URL_API_MONGO . $idVeiculoMongo . "?idLinha=" . $idLinha);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => 1, 
            CURLOPT_HTTPHEADER => HEADERS_DETALHE_LINHA, 
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
        ]);
        curl_multi_add_handle($mh, $ch2);
        $handles['executado'] = $ch2;
    }

    // Executa em paralelo
    if (!empty($handles)) {
        $running = null;
        do { curl_multi_exec($mh, $running); } while ($running > 0);

        if (isset($handles['programado'])) {
            $code = curl_getinfo($handles['programado'], CURLINFO_HTTP_CODE);
            if ($code == 200) {
                $data = json_decode(curl_multi_getcontent($handles['programado']), true);
                $coords = parsear_rota_generica($data['desenhoRota'] ?? null);
                $resultados['programado'] = $coords;
                if(!empty($coords)) cache_set("rastro_prog_$idLinha", $coords);
            }
            curl_multi_remove_handle($mh, $handles['programado']);
        }

        if (isset($handles['executado'])) {
            $code = curl_getinfo($handles['executado'], CURLINFO_HTTP_CODE);
            if ($code == 200) {
                $data = json_decode(curl_multi_getcontent($handles['executado']), true);
                $root = (is_array($data) && isset($data[0])) ? $data[0] : $data;
                $coords = [];
                if (!empty($root['logRotaDiarias'])) {
                    foreach ($root['logRotaDiarias'] as $p) {
                        if (isset($p['longitude'], $p['latitude'])) $coords[] = [(float)$p['longitude'], (float)$p['latitude']];
                    }
                }
                $resultados['executado'] = $coords;
            }
            curl_multi_remove_handle($mh, $handles['executado']);
        }
    }
    curl_multi_close($mh);
    return $resultados;
}

function parsear_rota_generica($rota) {
    $coords = [];
    if (is_array($rota)) {
        foreach ($rota as $p) {
            $lat = $p['latitude'] ?? $p['lat'] ?? 0;
            $lng = $p['longitude'] ?? $p['lng'] ?? 0;
            if ($lat != 0) $coords[] = [(float)$lng, (float)$lat];
        }
    } elseif (is_string($rota)) {
        // Regex rápido
        if (preg_match_all('/(-?\d+\.\d+)[,\s]+(-?\d+\.\d+)/', $rota, $m, PREG_SET_ORDER)) {
            foreach ($m as $v) $coords[] = [(float)$v[2], (float)$v[1]];
        }
    }
    return $coords;
}

// ==============================================================================
// STICKY API KEY (TOMTOM)
// ==============================================================================

function calcular_rota_tomtom($locations_string) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $keys = TOMTOM_KEYS;
    $stickyKey = $_SESSION['tomtom_valid_key'] ?? null;
    
    // Se temos uma chave que funcionou antes, testamos ela primeiro
    if ($stickyKey) {
        $keys = array_diff($keys, [$stickyKey]);
        array_unshift($keys, $stickyKey);
    } else {
        shuffle($keys);
    }
    session_write_close();

    foreach ($keys as $key) {
        $url = "https://api.tomtom.com/routing/1/calculateRoute/{$locations_string}/json?key={$key}&traffic=true&travelMode=bus";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1, 
            CURLOPT_TIMEOUT => 3, 
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 200) {
            $res = json_decode($body, true);
            if ($res) {
                // Salva chave vencedora
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['tomtom_valid_key'] = $key;
                session_write_close();
                return $res;
            }
        }
    }
    throw new Exception("Falha TomTom (Todas as chaves falharam)");
}

function get_veiculo_posicao($placa_clean) {
    global $URL_API_RASTREAMENTO; 
    garantir_aquecimento(); 
    $payload = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
    list($body, $code, $err) = fazer_requisicao_resiliente('POST', $URL_API_RASTREAMENTO, $payload, []);
    if ($err || $code >= 400) throw new Exception("Erro API Rastreamento: $code");
    $dados = json_decode($body, true);
    if (empty($dados[0])) throw new Exception("Veículo não localizado.");
    return $dados[0];
}

// ==============================================================================
// HANDLERS
// ==============================================================================

function handle_buscar_rastreamento($placa) {
    verificar_auth_e_liberar();
    global $URL_API_RASTREAMENTO;
    $placa_clean = limpar_placa($placa);
    garantir_aquecimento();
    $payload = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
    list($body, $code, $erro) = fazer_requisicao_resiliente('POST', $URL_API_RASTREAMENTO, $payload, []);
    
    if ($erro) responder_json(["erro" => $erro], 500);
    
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(json_decode($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function handle_calcular_rota($placa, $tipo_destino = "Final") {
    verificar_auth_e_liberar(); 
    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;

    try {
        $placa_clean = limpar_placa($placa);

        // 1. Posição Atual
        $veiculo = get_veiculo_posicao($placa_clean);
        $coords_atual = extrair_coordenadas($veiculo);
        if (!$coords_atual) responder_json(["erro" => "Coordenadas inválidas."], 422);
        list($lat1, $lon1) = $coords_atual;

        // 2. Busca Dados da Linha (Cache)
        $data_dash = cache_get('main_dashboard_data');
        if (!$data_dash && function_exists('simple_get')) {
            list($resp, $c, $e) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, 10);
            if (!$e) {
                $data_dash = json_decode($resp, true);
                if($data_dash) cache_set('main_dashboard_data', $data_dash, 60);
            }
        }
        if (!$data_dash) responder_json(["erro" => "Dados indisponíveis."], 500);

        $todas = array_merge($data_dash['linhasAndamento']??[], $data_dash['linhasCarroDesligado']??[], $data_dash['linhasComecaramSemPrimeiroPonto']??[]);
        $linha_alvo = null;
        $id_busca = $_GET['idLinha'] ?? null;

        foreach ($todas as $l) {
            if ($id_busca && ($l['idLinha'] ?? $l['id']) == $id_busca) { $linha_alvo = $l; break; }
            if (!$id_busca && limpar_placa($l['veiculo']['veiculo']??'') == $placa_clean) { $linha_alvo = $l; break; }
        }

        if (!$linha_alvo) responder_json(["erro" => "Linha não encontrada."], 404);

        $id_linha_oficial = $linha_alvo['idLinha'] ?? $linha_alvo['id'] ?? null;
        $id_veiculo_mongo = $linha_alvo['veiculo']['id'] ?? null;

        // 3. BUSCA PARALELA
        $rastros = buscar_dados_paralelos($id_linha_oficial, $id_veiculo_mongo);
        $rastro_programado = $rastros['programado'];
        $rastro_executado = $rastros['executado'];

        // 4. Waypoints
        $pontos_mapa = [];
        foreach (($linha_alvo['pontoDeParadas'] ?? []) as $p) {
            if (empty($p['latitude'])) continue;
            $pontos_mapa[] = [
                "lat" => (float)$p['latitude'], "lng" => (float)$p['longitude'],
                "passou" => $p['passou'] ?? false, "nome" => $p['descricao'] ?? 'Ponto'
            ];
        }

        $lat2 = null; $lon2 = null;
        if (!empty($pontos_mapa)) {
            $pAlvo = ($tipo_destino == 'Inicial') ? $pontos_mapa[0] : end($pontos_mapa);
            $lat2 = $pAlvo['lat']; $lon2 = $pAlvo['lng'];
        } else {
            responder_json(["erro" => "Sem paradas."], 400);
        }

        // 5. Filtragem Waypoints (Otimizada)
        $tomtom_string_coords = [sprintf("%.5f,%.5f", $lat1, $lon1)];
        $coords_visual = [[$lon1, $lat1]];

        if ($tipo_destino === 'Inicial') {
            $tomtom_string_coords[] = sprintf("%.5f,%.5f", $lat2, $lon2);
            $coords_visual[] = [$lon2, $lat2];
        } else {
            $primeiro_valido = false;
            $waypoints_filtrados = [];
            $encontrou_destino = false;

            foreach ($pontos_mapa as $i => $p) {
                if ($i === 0 || ($p['passou'] ?? false)) continue;
                // Distância Rápida para filtro local
                if (!$primeiro_valido) {
                    $d = calcular_distancia_rapida($lat1, $lon1, $p['lat'], $p['lng']);
                    $primeiro_valido = true;
                }
                if ($primeiro_valido) {
                    $waypoints_filtrados[] = $p;
                    if ($p['lat'] == $lat2) $encontrou_destino = true;
                }
            }

            $waypoints_envio = array_slice($waypoints_filtrados, 0, 15);
            foreach ($waypoints_envio as $p) {
                $tomtom_string_coords[] = sprintf("%.5f,%.5f", $p['lat'], $p['lng']);
                $coords_visual[] = [$p['lng'], $p['lat']];
            }
            if (!$encontrou_destino || empty($waypoints_envio)) {
                $tomtom_string_coords[] = sprintf("%.5f,%.5f", $lat2, $lon2);
                $coords_visual[] = [$lon2, $lat2];
            }
        }

        // 6. TomTom
        $tomtom_data = calcular_rota_tomtom(implode(':', $tomtom_string_coords));
        $summary = $tomtom_data["routes"][0]["summary"] ?? ["travelTimeInSeconds" => 0, "lengthInMeters" => 0];
        
        $segundos = (int)$summary["travelTimeInSeconds"];
        $metros = (int)$summary["lengthInMeters"];
        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);
        $tempo_txt = $horas > 0 ? "{$horas}h {$minutos}min" : "{$minutos} min";

        // 7. Simplificação Final
        $rastro_programado = arredondar_coords(simplificar_rota($rastro_programado, 0.0001));
        $rastro_executado = arredondar_coords(simplificar_rota($rastro_executado, 0.0001));
        $coords_visual = arredondar_coords($coords_visual);

        responder_json([
            "tempo" => $tempo_txt,
            "distancia" => sprintf("%.2f km", $metros / 1000),
            "lat" => $lat2, "lng" => $lon2,
            "duracaoSegundos" => $segundos,
            "rastro_oficial" => $rastro_programado,
            "rastro_real" => $rastro_executado,
            "waypoints_usados" => $coords_visual,
            "todos_pontos_visual" => $pontos_mapa
        ]);

    } catch (Exception $e) {
        responder_json(["erro" => $e->getMessage()], 500);
    }
}
?>
