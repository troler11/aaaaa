<?php

// ==============================================================================
// CONFIGURAÇÃO & CONSTANTES
// ==============================================================================

define('TOMTOM_KEYS', [
    // "teste", 
    "8iGI69ukjIFE8M5XwE2aVHJOcmRlhfwR",
    "vbdLg3miOthQgBBkTjZyAaj0TmBoyIGv",
    "WquMopwFZcPTeG4s6WkxkzhnMM3w1OGH",
    "ZQx0TqIl2gkDsgF3Yw4G4qwQG6jKp77N",
    "GK7A9HjGG0cOSN1UqADrkifoN0HExUzy",
    "y7k9fPzIQr27uNWDYCZfsS6zX73G25z8",
    "4VAflHY7FO3OJ57qGa2mxdnNPIHnkwqt",
    "0sZFxbwSeEKUti72AnYAZapyMCVSQYLe",
    "k1d8gtGfmXzyD03nTj8Ea2C8fTqGuC8K"
]);

define('COOKIE_FILE', sys_get_temp_dir() . '/cookie_rastreamento_api.txt');

// --- CONFIGURAÇÃO API LINHA (OFICIAL) ---
define('URL_API_DETALHE_LINHA', 'https://abmbus.com.br:8181/api/linha/');
define('TOKEN_ABMBUS', 'eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJtaW1vQGFibXByb3RlZ2UuY29tLmJyIiwiZXhwIjoxODY0MDk5MTA1fQ.NtMXhjWVGmd9DGYEXdW9AOTgAweiRMHC7j5XXAObFDAof80dUggpj7yv98Aqe5jXDajqFnc3sDrqcBTK0gy1lQ');
define('URL_API_MONGO', 'https://abmbus.com.br:8181/api/rota/temporealmongo/');

define('HEADERS_DETALHE_LINHA', [
    "Accept: application/json, text/plain, */*",
    "Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7",
    "Authorization: " . TOKEN_ABMBUS,
    "Connection: keep-alive",
    "Origin: https://abmbus.com.br",
    "Referer: https://abmbus.com.br/",
    "Sec-Fetch-Dest: empty",
    "Sec-Fetch-Mode: cors",
    "Sec-Fetch-Site: same-site",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36",
    "sec-ch-ua: \"Chromium\";v=\"142\", \"Google Chrome\";v=\"142\", \"Not_A Brand\";v=\"99\"",
    "sec-ch-ua-mobile: ?0",
    "sec-ch-ua-platform: \"Windows\""
]);

/**
 * Verifica autenticação e LIBERA O TRAVAMENTO DA SESSÃO
 */
function verificar_auth_e_liberar() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        responder_json(["erro" => "Não autorizado"], 401);
    }
    
    session_write_close();
}

/**
 * Limpa a placa para padrão API
 */
function limpar_placa($placa) {
    return preg_replace("/[^A-Za-z0-9]/", '', strtoupper($placa));
}

// ==============================================================================
// HELPERS
// ==============================================================================

function garantir_aquecimento() {
    global $URL_MAPA;
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $agora = time();
    $ultimo_aquecimento = $_SESSION['last_api_warmup'] ?? 0;
    
    if (($agora - $ultimo_aquecimento) > 1200) {
        $_SESSION['last_api_warmup'] = $agora;
        session_write_close(); 
        fazer_requisicao_resiliente('GET', $URL_MAPA, null, []);
    } else {
        session_write_close(); 
    }
}

function extrair_coordenadas($veiculo) {
    $lat = 0; $lon = 0;
    if (isset($veiculo['loc']) && is_array($veiculo['loc']) && count($veiculo['loc']) >= 2) {
        return [(float)$veiculo['loc'][0], (float)$veiculo['loc'][1]];
    }
    if (isset($veiculo['loc']) && is_string($veiculo['loc']) && strpos($veiculo['loc'], ",") !== false) {
        $p = explode(",", str_replace(["[", "]"], "", $veiculo['loc']));
        return [(float)$p[0], (float)$p[1]];
    }
    $lat = (float)($veiculo['latitude'] ?? 0);
    $lon = (float)($veiculo['longitude'] ?? 0);
    return ($lat != 0 && $lon != 0) ? [$lat, $lon] : null;
}

function get_rastro_executado_mongo($idVeiculo, $idLinha) {
    if (empty($idVeiculo) || empty($idLinha)) {
        return ['coords' => [], 'debug' => "IDs ausentes: V:$idVeiculo L:$idLinha"];
    }

    $url = URL_API_MONGO . $idVeiculo . "?idLinha=" . $idLinha;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => HEADERS_DETALHE_LINHA,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4
    ]);

    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);

    $coords_executadas = [];
    $msg_debug = "HTTP " . $info['http_code'];

    if ($info['http_code'] == 200 && $body) {
        $data = json_decode($body, true);
        
        // --- CORREÇÃO AQUI ---
        // A API retorna [ { "logRotaDiarias": ... } ]
        // Precisamos pegar o primeiro elemento do array ($data[0])
        $objeto_raiz = (is_array($data) && isset($data[0])) ? $data[0] : $data;

        if (isset($objeto_raiz['logRotaDiarias']) && is_array($objeto_raiz['logRotaDiarias'])) {
            $count = 0;
            foreach ($objeto_raiz['logRotaDiarias'] as $p) {
                // As coordenadas vêm como string "-23.32...", converte para float
                $lat = (float)($p['latitude'] ?? 0);
                $lng = (float)($p['longitude'] ?? 0);
                
                if ($lat != 0 && $lng != 0) {
                    // Leaflet no JS espera [lat, lng], mas aqui mandamos [lng, lat] (GeoJSON)
                    // e o JS inverte. Vamos manter o padrão GeoJSON aqui: [longitude, latitude]
                    $coords_executadas[] = [$lng, $lat];
                    $count++;
                }
            }
            $msg_debug = "Sucesso. Pontos recuperados: $count";
        } else {
            $msg_debug = "Campo 'logRotaDiarias' não encontrado na estrutura. " . substr($body, 0, 100);
        }
    } else {
        $msg_debug .= ". Curl Err: $err. URL: $url";
    }

    return ['coords' => $coords_executadas, 'debug' => $msg_debug];
}

function get_veiculo_posicao($placa_clean) {
    global $URL_API_RASTREAMENTO;
    garantir_aquecimento(); 
    
    $payload = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
    list($resp_body, $http_code, $erro) = fazer_requisicao_resiliente('POST', $URL_API_RASTREAMENTO, $payload, []);

    if ($erro || $http_code >= 400) {
        throw new Exception($erro ?? "Erro ao consultar API de Rastreamento ($http_code)");
    }

    $dados = json_decode($resp_body, true);
    if (empty($dados) || !isset($dados[0])) {
        throw new Exception("Veículo não localizado na API.");
    }
    return $dados[0];
}

function calcular_rota_tomtom($locations_string) {
    $ultimo_erro = "";
    $keys = TOMTOM_KEYS;
    shuffle($keys); 

    foreach ($keys as $key) {
        if ($key === "teste") continue; 

        $url = "https://api.tomtom.com/routing/1/calculateRoute/{$locations_string}/json?key={$key}&traffic=true&travelMode=bus";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, 
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"]
        ]);
        
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 200) {
            $resultado = json_decode($body, true);
            if ($resultado) return $resultado;
        }
        $ultimo_erro = "Status $code";
    }
    throw new Exception("Falha TomTom. Último erro: $ultimo_erro");
}

function calcular_distancia_haversine($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; 
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
          cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
          sin($dLon/2) * sin($dLon/2);
          
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

/**
 * ATUALIZADO: Busca os detalhes da linha com DEBUG
 * Retorna array: ['coords' => [], 'debug' => 'msg']
 */
/**
 * ATUALIZADO COM CACHE: Busca os detalhes da linha (Rastro Programado)
 * Cache de 24 horas para evitar chamadas repetitivas na API de detalhes
 */
function get_rastro_programado_com_debug($idLinha) {
    if (empty($idLinha)) {
        return ['coords' => null, 'debug' => "ID da linha vazio ou nulo."];
    }

    // --- LÓGICA DE CACHE ---
    // Cria um nome de arquivo único para cada linha na pasta temporária
    $arquivo_cache = sys_get_temp_dir() . '/rastro_programado_' . $idLinha . '.json';
    // Tempo de validade do cache: 86400 segundos = 24 horas
    $tempo_cache = 86400; 

    // 1. Verifica se o arquivo existe e se ainda é válido
    if (file_exists($arquivo_cache) && (time() - filemtime($arquivo_cache) < $tempo_cache)) {
        $conteudo_cache = file_get_contents($arquivo_cache);
        $dados_cache = json_decode($conteudo_cache, true);
        
        if ($dados_cache && isset($dados_cache['coords'])) {
            // Retorna o cache e adiciona um aviso no debug
            $dados_cache['debug'] = ($dados_cache['debug'] ?? '') . " [CACHE]";
            return $dados_cache;
        }
    }

    // --- LÓGICA ORIGINAL DE REQUISIÇÃO (Se não tiver cache) ---
    $url = URL_API_DETALHE_LINHA . $idLinha;
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => HEADERS_DETALHE_LINHA,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ]);

    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($http_code != 200) {
        return ['coords' => null, 'debug' => "HTTP Erro: $http_code. Curl Err: $curl_error. URL: $url"];
    }

    if (!$body) {
        return ['coords' => null, 'debug' => "Corpo da resposta vazio."];
    }

    $data = json_decode($body, true);
    if (!$data) {
        return ['coords' => null, 'debug' => "JSON inválido na resposta da API."];
    }

    if (!isset($data['desenhoRota']) || empty($data['desenhoRota'])) {
        return ['coords' => null, 'debug' => "Campo 'desenhoRota' não encontrado ou vazio."];
    }

    $rastro_bruto = $data['desenhoRota'];
    $coords_tratadas = [];

    // Tenta parsear string (Pipe, Ponto e Vírgula ou Espaço)
    if (is_string($rastro_bruto)) {
        $rastro_norm = str_replace([';', ' '], '|', $rastro_bruto);
        $pontos = array_filter(explode('|', $rastro_norm));
        
        foreach ($pontos as $p) {
            $parts = explode(',', $p);
            if (count($parts) >= 2) {
                // GeoJSON padrão: [LONGITUDE, LATITUDE]
                $coords_tratadas[] = [(float)$parts[1], (float)$parts[0]]; 
            }
        }
    } elseif (is_array($rastro_bruto)) {
        foreach ($rastro_bruto as $p) {
            $lat = $p['latitude'] ?? $p['lat'] ?? 0;
            $lng = $p['longitude'] ?? $p['lng'] ?? $p['lon'] ?? 0;
            if ($lat != 0 && $lng != 0) {
                $coords_tratadas[] = [(float)$lng, (float)$lat];
            }
        }
    }

    if (empty($coords_tratadas)) {
        return ['coords' => null, 'debug' => "Falha no parse das coordenadas."];
    }

    // Monta o resultado final
    $resultado_final = [
        'coords' => $coords_tratadas, 
        'debug' => "Sucesso. " . count($coords_tratadas) . " pontos. (API)"
    ];

    // --- SALVAR NO CACHE ---
    // Só salva se tiver coordenadas válidas
    if (count($coords_tratadas) > 0) {
        file_put_contents($arquivo_cache, json_encode($resultado_final));
    }

    return $resultado_final;
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

    $json = json_decode($body);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['last_api_warmup'] = 0; 
        session_write_close();
        responder_json(["erro" => "API recusou o formato", "status" => $code], 502);
    }

    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo $body;
    exit;
}

function handle_calcular_rota($placa, $tipo_destino = "Final") {
    verificar_auth_e_liberar(); 
    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;

    try {
        $placa_clean = limpar_placa($placa);

        // 1. Obter Veículo (Posição Atual)
        $veiculo = get_veiculo_posicao($placa_clean);
        $coords_atual = extrair_coordenadas($veiculo);
        
        if (!$coords_atual) responder_json(["erro" => "Coordenadas inválidas recebidas da API."], 422);
        list($lat1, $lon1) = $coords_atual;

        // 2. Obter Dados da Linha (Cache) para pegar IDs
        $cache_file = sys_get_temp_dir() . '/dashboard_main_data.json';
        $data_dash = null;

        if (file_exists($cache_file)) {
            $content = @file_get_contents($cache_file);
            if ($content) $data_dash = json_decode($content, true);
        }

        if (!$data_dash) {
             // Tenta buscar se não tiver cache
             if (function_exists('simple_get')) {
                list($resp, $code, $err) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, 30);
                if (!$err) $data_dash = json_decode($resp, true);
             }
             if (!$data_dash) responder_json(["erro" => "Cache vazio e falha ao buscar dados"], 500);
        }

        $todas_linhas = array_merge(
            $data_dash['linhasAndamento'] ?? [],
            $data_dash['linhasCarroDesligado'] ?? [],
            $data_dash['linhasComecaramSemPrimeiroPonto'] ?? []
        );

        // --- BUSCA DA LINHA ---
        $linha_alvo = null;
        $id_linha_param = $_GET['idLinha'] ?? null; 

        if (!empty($id_linha_param)) {
            foreach ($todas_linhas as $l) {
                $id_atual = $l['idLinha'] ?? $l['id'] ?? '';
                if (strval($id_atual) === strval($id_linha_param)) {
                    $linha_alvo = $l;
                    break;
                }
            }
        } 
        
        if (!$linha_alvo) {
            foreach ($todas_linhas as $l) {
                $placa_linha = limpar_placa($l['veiculo']['veiculo'] ?? '');
                if ($placa_linha == $placa_clean) {
                    $linha_alvo = $l; 
                    break; 
                }
            }
        }

        if (!$linha_alvo) responder_json(["erro" => "Linha não encontrada."], 404);

        $id_linha_oficial = $linha_alvo['idLinha'] ?? $linha_alvo['id'] ?? null;
        
        // --- NOVO: OBTER ID DO VEÍCULO PARA O MONGO ---
        // O id do veículo geralmente vem dentro do objeto 'veiculo' na linha
        $id_veiculo_mongo = $linha_alvo['veiculo']['id'] ?? null;
        
        // BUSCAS EXTERNAS (EM PARALELO SE POSSÍVEL, AQUI SEQUENCIAL RÁPIDO)
        
        // A. Rastro Oficial (Programado - Verde)
        $rastro_programado_res = get_rastro_programado_com_debug($id_linha_oficial);
        $rastro_programado = $rastro_programado_res['coords'];

        // B. Rastro Executado (Real - Preto) - NOVO
        $rastro_executado = [];
        if ($id_veiculo_mongo && $id_linha_oficial) {
            $rastro_executado = get_rastro_executado_mongo($id_veiculo_mongo, $id_linha_oficial);
        }

        // 3. Processar Pontos e Ordenar (Lógica TomTom e Visualização)
        $pontos_mapa = [];
        $tomtom_string_coords = [sprintf("%.5f,%.5f", $lat1, $lon1)]; 
        $coords_visual = [[$lon1, $lat1]];
        
        $paradas = $linha_alvo['pontoDeParadas'] ?? [];
        $ponto_inicial = null; $paradas_intermediarias = []; $ponto_final = null;

        foreach ($paradas as $p) {
            $plat = (float)($p['latitude'] ?? 0); $plng = (float)($p['longitude'] ?? 0);
            if ($plat == 0) continue;
            $tipo = strtolower($p['tipoPonto']['tipo'] ?? '');
            $dados_ponto = ["lat" => $plat, "lng" => $plng, "passou" => ($p['passou'] ?? false), "nome" => $p['descricao'] ?? 'Ponto'];

            if ($tipo == 'inicial') $ponto_inicial = $dados_ponto;
            elseif ($tipo == 'final') $ponto_final = $dados_ponto;
            else $paradas_intermediarias[] = $dados_ponto;
        }

        if ($ponto_inicial) $pontos_mapa[] = $ponto_inicial;
        foreach ($paradas_intermediarias as $p) $pontos_mapa[] = $p;
        if ($ponto_final) $pontos_mapa[] = $ponto_final;

        $lat2 = null; $lon2 = null;
        if (!empty($pontos_mapa)) {
            $pAlvo = ($tipo_destino == 'Inicial') ? $pontos_mapa[0] : end($pontos_mapa);
            $lat2 = $pAlvo['lat']; $lon2 = $pAlvo['lng'];
        }

        if (!$lat2) responder_json(["erro" => "Destino não determinado."], 400);

        // Lógica Anti-Bumerangue (Corte de waypoints já visitados)
        if ($tipo_destino === 'Inicial') {
            $tomtom_string_coords[] = sprintf("%.5f,%.5f", $lat2, $lon2);
            $coords_visual[] = [$lon2, $lat2];
        } else {
            $idx_corte = 0;
            $menor_dist = 99999999999;
            foreach ($pontos_mapa as $idx => $p) {
                if ($idx === 0) continue; 
                if (!$p['passou']) {
                    $d = calcular_distancia_haversine($lat1, $lon1, $p['lat'], $p['lng']);
                    if ($d < $menor_dist) { $menor_dist = $d; $idx_corte = $idx; }
                }
            }
            $encontrou_destino = false;
            $contador_waypoints = 0;
            foreach ($pontos_mapa as $index => $p) {
                if ($index === 0) continue; 
                if ($index < $idx_corte) continue;
                if (!$p['passou']) {
                    $coords_visual[] = [$p['lng'], $p['lat']];
                    if ($contador_waypoints < 100) {
                        $tomtom_string_coords[] = sprintf("%.5f,%.5f", $p['lat'], $p['lng']);
                        $contador_waypoints++;
                    }
                    if ($p['lat'] == $lat2 && $p['lng'] == $lon2) $encontrou_destino = true;
                }
            }
            if (!$encontrou_destino) {
                $tomtom_string_coords[] = sprintf("%.5f,%.5f", $lat2, $lon2);
                $coords_visual[] = [$lon2, $lat2];
            }
        }

        // 4. Calcular Rota TomTom
        $tomtom_data = calcular_rota_tomtom(implode(':', $tomtom_string_coords));
        $summary = $tomtom_data["routes"][0]["summary"] ?? ["travelTimeInSeconds" => 0, "lengthInMeters" => 0];
        $segundos = (int)$summary["travelTimeInSeconds"];
        $metros = (int)$summary["lengthInMeters"];

        // Cache Preditivo
        if ($tipo_destino == 'Final' && $segundos > 0) {
            $f_cache = sys_get_temp_dir() . '/tomtom_predictions.json';
            $fp = fopen($f_cache, 'c+');
            if (flock($fp, LOCK_EX)) {
                $c_data = json_decode(stream_get_contents($fp), true) ?? [];
                $c_data[$placa] = ['arrival_ts' => time() + $segundos, 'updated_at' => time()];
                ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($c_data)); fflush($fp); flock($fp, LOCK_UN);
            }
            fclose($fp);
        }

        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);
        $tempo_txt = $horas > 0 ? "{$horas}h {$minutos}min" : "{$minutos} min";

        responder_json([
            "tempo" => $tempo_txt,
            "distancia" => sprintf("%.2f km", $metros / 1000),
            "lat" => $lat2,
            "lng" => $lon2,
            "duracaoSegundos" => $segundos,
            
            // --- DADOS DO MAPA ---
            "rastro_oficial" => $rastro_programado,  // Linha Verde (Programada)
            "rastro_real" => $rastro_executado,      // Linha Preta (Executada/Mongo) - NOVO CAMPO
            "waypoints_usados" => $coords_visual,    // Linha Azul (TomTom/Futura)
            
            "todos_pontos_visual" => $pontos_mapa,
            "paradas_restantes" => max(0, count($coords_visual) - 2), 
            "pontos_ignorados_inicio" => isset($idx_corte) ? $idx_corte : 0
        ]);

    } catch (Exception $e) {
        responder_json(["erro" => $e->getMessage()], 500);
    }
}
?>
