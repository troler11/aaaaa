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
    "GK7A9HjGG0cOSN1UqADrkifoN0HExUzy"
]);

define('COOKIE_FILE', sys_get_temp_dir() . '/cookie_rastreamento_api.txt');

/**
 * Verifica autenticação e LIBERA O TRAVAMENTO DA SESSÃO (CRÍTICO PARA VELOCIDADE)
 */
function verificar_auth_e_liberar() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
        responder_json(["erro" => "Não autorizado"], 401);
    }
    
    // OTIMIZAÇÃO: Fecha a escrita da sessão para permitir requisições paralelas
    session_write_close();
}

/**
 * Limpa a placa para padrão API
 */
function limpar_placa($placa) {
    return preg_replace("/[^A-Za-z0-9]/", '', strtoupper($placa));
}

// ==============================================================================
// HELPERS (Funções Auxiliares)
// ==============================================================================

/**
 * Realiza o aquecimento da sessão de forma OTIMIZADA
 */
function garantir_aquecimento() {
    global $URL_MAPA;
    
    if (session_status() === PHP_SESSION_NONE) session_start();
    
    $agora = time();
    $ultimo_aquecimento = $_SESSION['last_api_warmup'] ?? 0;
    
    if (($agora - $ultimo_aquecimento) > 1200) {
        $_SESSION['last_api_warmup'] = $agora;
        session_write_close(); // Libera antes do request lento

        fazer_requisicao_resiliente('GET', $URL_MAPA, null, []);
    } else {
        session_write_close(); // Libera se não precisou aquecer
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

function get_veiculo_posicao($placa_clean) {
    global $URL_API_RASTREAMENTO;
    
    garantir_aquecimento(); // Agora usa a versão otimizada
    
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

/**
 * Rotação de chaves TomTom com SHUFFLE (Balanceamento)
 */
function calcular_rota_tomtom($locations_string) {
    $ultimo_erro = "";
    $keys = TOMTOM_KEYS;
    shuffle($keys); // Otimização: Embaralha para não estourar a primeira chave

    foreach ($keys as $key) {
        if ($key === "teste") continue; 

        $url = "https://api.tomtom.com/routing/1/calculateRoute/{$locations_string}/json?key={$key}&traffic=true&travelMode=bus";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, // Timeout menor para falhar rápido
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

// ==============================================================================
// HANDLERS
// ==============================================================================

function handle_buscar_rastreamento($placa) {
    verificar_auth_e_liberar(); // Otimização crítica
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
    verificar_auth_e_liberar(); // Otimização crítica
    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;

    try {
        $placa_clean = limpar_placa($placa);

        // 1. Obter Veículo
        $veiculo = get_veiculo_posicao($placa_clean);
        $coords_atual = extrair_coordenadas($veiculo);
        
        if (!$coords_atual) responder_json(["erro" => "Coordenadas inválidas recebidas da API."], 422);
        list($lat1, $lon1) = $coords_atual;

        // 2. Obter Dados da Linha (Leitura Segura)
        $cache_file = sys_get_temp_dir() . '/dashboard_main_data.json';
        $data_dash = null;

        if (file_exists($cache_file)) {
            $content = @file_get_contents($cache_file);
            if ($content) $data_dash = json_decode($content, true);
        }

        if (!$data_dash) {
            list($resp, $code, $err) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, 30);
            if ($err || $code >= 400) responder_json(["erro" => "Falha API Dashboard"], 500);
            $data_dash = json_decode($resp, true);
        }

        $todas_linhas = array_merge(
            $data_dash['linhasAndamento'] ?? [],
            $data_dash['linhasCarroDesligado'] ?? [],
            $data_dash['linhasComecaramSemPrimeiroPonto'] ?? []
        );

        // --- LÓGICA DE BUSCA DA LINHA COM ID ---
        $linha_alvo = null;
        $id_linha_alvo = $_GET['idLinha'] ?? null; 

        // Tenta pelo ID exato (Prioridade)
        if (!empty($id_linha_alvo)) {
            foreach ($todas_linhas as $l) {
                // Verifica idLinha OU id (para compatibilidade)
                $id_atual = $l['idLinha'] ?? $l['id'] ?? '';
                if (strval($id_atual) === strval($id_linha_alvo)) {
                    $linha_alvo = $l;
                    break;
                }
            }
        } 

        // Fallback pela placa
        if (!$linha_alvo) {
            foreach ($todas_linhas as $l) {
                $placa_linha = limpar_placa($l['veiculo']['veiculo'] ?? '');
                if ($placa_linha == $placa_clean) {
                    $linha_alvo = $l; 
                    break; 
                }
            }
        }

        if (!$linha_alvo) responder_json(["erro" => "Linha não encontrada (ID: $id_linha_alvo / Placa: $placa)."], 404);

        // 3. Processar Pontos
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

        // Lógica Waypoints Otimizada
        if ($tipo_destino === 'Inicial') {
            $tomtom_string_coords[] = sprintf("%.5f,%.5f", $lat2, $lon2);
            $coords_visual[] = [$lon2, $lat2];
        } else {
            $encontrou_destino = false;
            $contador_waypoints = 0;
            foreach ($pontos_mapa as $p) {
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

        // 4. Calcular Rota
        $tomtom_data = calcular_rota_tomtom(implode(':', $tomtom_string_coords));
        $summary = $tomtom_data["routes"][0]["summary"] ?? ["travelTimeInSeconds" => 0, "lengthInMeters" => 0];
        $segundos = (int)$summary["travelTimeInSeconds"];
        $metros = (int)$summary["lengthInMeters"];

        // Cache com LOCK_EX (Segurança contra corrupção)
        if ($tipo_destino == 'Final' && $segundos > 0) {
            $f_cache = sys_get_temp_dir() . '/tomtom_predictions.json';
            $fp = fopen($f_cache, 'c+');
            if (flock($fp, LOCK_EX)) {
                $conteudo = stream_get_contents($fp);
                $c_data = $conteudo ? json_decode($conteudo, true) : [];
                if (!is_array($c_data)) $c_data = [];
                
                $c_data[$placa] = ['arrival_ts' => time() + $segundos, 'updated_at' => time()];
                
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($c_data));
                fflush($fp);
                flock($fp, LOCK_UN);
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
            "waypoints_usados" => $coords_visual,
            "todos_pontos_visual" => $pontos_mapa,
            "paradas_restantes" => max(0, count($coords_visual) - 2)
        ]);

    } catch (Exception $e) {
        responder_json(["erro" => $e->getMessage()], 500);
    }
}
?>
