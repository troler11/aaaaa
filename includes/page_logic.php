<?php

/**
 * / (Página principal)
 * Busca e processa os dados para o dashboard com CACHE e FILTRO DE EMPRESA.
 * @param array $allowed_companies Lista de empresas permitidas (vazio = todas/admin)
 */
function handle_index_data($allowed_companies = []) {
    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;
    
    date_default_timezone_set('America/Sao_Paulo'); 

    // 1. Busca Dados Principais (Cache 3 min)
    $cache_main = sys_get_temp_dir() . '/dashboard_main_data.json';
    $response_body = null;
    
    if (file_exists($cache_main) && (time() - filemtime($cache_main) < 30)) {
        $response_body = file_get_contents($cache_main);
    } else {
        list($response_body, $http_code, $error) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, 30);
        if (!$error && $http_code < 400) {
            file_put_contents($cache_main, $response_body);
        } elseif (file_exists($cache_main)) {
            $response_body = file_get_contents($cache_main); 
        } else {
            return [
                "erro" => "Erro API: $error", "todas_linhas" => [], 
                "qtd_total" => 0, "qtd_atrasados" => 0, "qtd_desligados" => 0,
                "qtd_deslocamento" => 0, "qtd_pontual" => 0, "qtd_sem_inicio" => 0
            ];
        }
    }

    $data = json_decode($response_body, true);
    if (!$data) return ["erro" => "Erro JSON API."];

    // --- LER CACHE DE PREVISÕES DA TOMTOM ---
    $cache_preds_file = sys_get_temp_dir() . '/tomtom_predictions.json';
    $previsoes_cache = [];
    if (file_exists($cache_preds_file)) {
        $previsoes_cache = json_decode(file_get_contents($cache_preds_file), true) ?? [];
    }

    $todas_linhas = [];
    
    // --- PREPARAÇÃO DO FILTRO (NORMALIZAÇÃO) ---
    // Cria uma lista de empresas permitidas em MAIÚSCULO e SEM ESPAÇOS EXTRAS
    $empresas_permitidas_norm = [];
    if (!empty($allowed_companies)) {
        foreach ($allowed_companies as $emp) {
            $empresas_permitidas_norm[] = strtoupper(trim($emp));
        }
    }
    
    // Passamos a lista normalizada para dentro da função via 'use'
    $processar_grupo = function($lista, $categoria) use (&$todas_linhas, $previsoes_cache, $empresas_permitidas_norm) {
        foreach ($lista as $l) {
            
            // --- 1. FILTRO DE PERMISSÃO INTELIGENTE ---
            // Se a lista de permitidos NÃO for vazia (ou seja, não é admin)
            if (!empty($empresas_permitidas_norm)) {
                $empresa_api_nome = $l['empresa']['nome'] ?? '';
                // Normaliza o nome que veio da API também
                $empresa_api_norm = strtoupper(trim($empresa_api_nome));
                
                // Verifica se ESTÁ na lista
                if (!in_array($empresa_api_norm, $empresas_permitidas_norm)) {
                    continue; // Se não estiver, pula este veículo
                }
            }
            // -----------------------------------------------

            // --- 2. FILTRO: REMOVER SE JÁ CHEGOU NO FINAL ---
            $viagem_finalizada = false;
            foreach ($l["pontoDeParadas"] ?? [] as $p) {
                if (($p["tipoPonto"]["tipo"] ?? null) == "Final") {
                    if (isset($p['passou']) && $p['passou'] == true) {
                        $viagem_finalizada = true;
                    }
                    break; 
                }
            }

            if ($viagem_finalizada) {
                continue; 
            }
            // --------------------------------------------------

            $l['categoria'] = $categoria;

            // 1. Horário Inicial Programado
            $horario_inicial = "N/D";
            foreach ($l["pontoDeParadas"] ?? [] as $p) {
                if (($p["tipoPonto"]["tipo"] ?? null) == "Inicial" && isset($p["horario"])) {
                    $horario_inicial = $p["horario"]; break;
                }
            }
            $l["horarioProgramado"] = $horario_inicial;
            
            // 2. Horário Inicial Real
            $horario_real = "N/D";
            foreach ($l["pontoDeParadas"] ?? [] as $p) {
                if (($p["tipoPonto"]["tipo"] ?? null) == "Inicial") {
                    if (
                        isset($p['passou']) && 
                        $p['passou'] && 
                        isset($p['horario']) && 
                        (isset($p['tempoDiferenca']) && $p['tempoDiferenca'] !== '') 
                    ) {
                        try {
                            $base_time = new DateTime($p['horario']); 
                            $val_diff = $p['tempoDiferenca'];
                            $interval = null;
                            
                            if (is_numeric($val_diff)) {
                                $minutos = (int)$val_diff;
                                $interval = new DateInterval('PT' . abs($minutos) . 'M');
                            } 
                            elseif (strpos($val_diff, ':') !== false) {
                                $diffParts = explode(':', $val_diff);
                                if (count($diffParts) >= 2) {
                                    $seconds = ((int)$diffParts[0] * 3600) + ((int)$diffParts[1] * 60) + ((int)($diffParts[2] ?? 0));
                                    $interval = new DateInterval('PT' . $seconds . 'S');
                                }
                            }

                            if ($interval) {
                                $atrasado = $p['atrasado'] ?? false;
                                if ($atrasado) $base_time->add($interval); 
                                else $base_time->sub($interval);
                                $horario_real = $base_time->format("H:i");
                            }
                        } catch (Exception $e) { $horario_real = "N/D"; }
                    } 
                    
                    if ($horario_real == "N/D" && isset($p["dataPassouGmt3"])) {
                        try {
                            $dt = new DateTime($p["dataPassouGmt3"], new DateTimeZone('America/Sao_Paulo'));
                            $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
                            $horario_real = $dt->format("H:i");
                        } catch (Exception $e) {} 
                    }
                    break;
                }
            }
            $l["horarioReal"] = $horario_real;

            // 3. Status de Tempo
            $dt_prog = null;
            if ($l["horarioProgramado"] != "N/D") {
                $parts = explode(':', str_replace(' ', '', $l["horarioProgramado"]));
                if(count($parts) >= 2) $dt_prog = (new DateTime())->setTime((int)$parts[0], (int)$parts[1]);
            }
            
            $l["status_tempo"] = "indefinido";

            if ($l["horarioReal"] != "N/D" && $dt_prog) {
                try {
                    $partsR = explode(':', str_replace(' ', '', $l["horarioReal"]));
                    if(count($partsR) >= 2) {
                        $dt_real = (new DateTime())->setTime((int)$partsR[0], (int)$partsR[1]);
                        $diff_minutos = ($dt_real->getTimestamp() - $dt_prog->getTimestamp()) / 60;
                        if ($diff_minutos > 10) $l["status_tempo"] = "atrasado";
                        else $l["status_tempo"] = "no_horario";
                    }
                } catch(Exception $e) { $l["status_tempo"] = "no_horario"; }
            } elseif ($l["horarioReal"] == "N/D" && $dt_prog) {
                $agora = new DateTime();
                $l["status_tempo"] = ($dt_prog > $agora) ? "aguardando" : "atrasado_sem_inicial";
            }

            // 4. Horário Final Programado
            $horario_final = "N/D";
            foreach ($l["pontoDeParadas"] ?? [] as $p) {
                if (($p["tipoPonto"]["tipo"] ?? null) == "Final" && isset($p["horario"])) {
                    $horario_final = $p["horario"]; break;
                }
            }
            $l["horariofinalProgramado"] = $horario_final;

            // 5. Localizações
            $l["localfinal"] = "N/D"; $l["localinicial"] = "N/D";
            foreach ($l["pontoDeParadas"] ?? [] as $p) {
                if (($p["tipoPonto"]["tipo"] ?? null) == "Final") $l["localfinal"] = ($p["latitude"]??0).",".($p["longitude"]??0);
                if (($p["tipoPonto"]["tipo"] ?? null) == "Inicial") $l["localinicial"] = ($p["latitude"]??0).",".($p["longitude"]??0);
            }

            // 6. Injetar Previsão do Cache
            $placa = $l['veiculo']['veiculo'] ?? '';
            $l['previsao_fim_ts'] = null; 
            if ($placa && isset($previsoes_cache[$placa])) {
                $cached = $previsoes_cache[$placa];
                if ((time() - $cached['updated_at']) < 300) {
                    $l['previsao_fim_ts'] = $cached['arrival_ts'];
                }
            }

            $todas_linhas[] = $l;
        }
    };

    $processar_grupo($data["linhasAndamento"] ?? [], "Em andamento");
    $processar_grupo($data["linhasCarroDesligado"] ?? [], "Carro desligado");
    $processar_grupo($data["linhasComecaramSemPrimeiroPonto"] ?? [], "Começou sem ponto");

    // --- LÓGICA DE CONTAGEM ---
    $qtd_atrasados = 0; 
    $qtd_deslocamento = 0; 
    $qtd_sem_inicio = 0; 
    $qtd_desligados = 0; 
    $qtd_pontual = 0;
    $qtd_total = count($todas_linhas);
    
    $hora_atual_str = date('H:i');

    foreach ($todas_linhas as $l) {
        $prog = $l['horarioProgramado'] ?? '23:59';
        $real = $l['horarioReal'] ?? 'N/D';
        $ja_saiu = ($real != 'N/D');
        
        if ($l['categoria'] == "Carro desligado") $qtd_desligados++;
        elseif (!$ja_saiu && $prog < $hora_atual_str) $qtd_sem_inicio++;
        elseif (!$ja_saiu && $prog >= $hora_atual_str) $qtd_deslocamento++;
        elseif ($ja_saiu && $l['status_tempo'] == "atrasado") $qtd_atrasados++;
        elseif ($ja_saiu) $qtd_pontual++;
        else $qtd_sem_inicio++;
    }
    
    return compact(
        'todas_linhas', 'qtd_atrasados', 'qtd_desligados', 'qtd_deslocamento', 
        'qtd_pontual', 'qtd_sem_inicio', 'qtd_total'
    );
}

// Função auxiliar simples para GET
if (!function_exists('simple_get')) {
    function simple_get($url, $headers = [], $timeout = 30) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return [$response, $http_code, $error];
    }
}
?>