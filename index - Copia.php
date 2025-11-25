<?php
// Silencia avisos de "deprecated" se houver, mas mantém erros.
error_reporting(E_ALL & ~E_DEPRECATED);

// --------------------------------------
// Configuração principal
// --------------------------------------
$URL_BASE = "https://abmtecnologia.abmprotege.net";
$URL_LOGIN = $URL_BASE . "/emp/abmtecnologia";
$URL_API_RASTREAMENTO = $URL_BASE . "/mapaGeral/filtrarRastreadosPorPlacaOuIdentificacao";
$URL_DASHBOARD_MAIN = "https://abmbus.com.br:8181/api/dashboard/mongo/95?naoVerificadas=false&agrupamentos=";

// RECOMENDAÇÃO: Use getenv('ABM_PASS') em produção
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";

// Headers Globais (Simulando navegador) - Formato para cURL
$HEADERS_COMMON = [
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Origin: " . $URL_BASE,
    "Referer: " . $URL_BASE . "/mapaGeral",
    "X-Requested-With: XMLHttpRequest",
    "Accept: application/json, text/javascript, */*; q=0.01"
];

// Header para a API do Dashboard (Token fixo)
$HEADERS_DASHBOARD_MAIN = [
    "Accept: application/json, text/plain, */*",
    "Authorization: eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJtaW1vQGFibXByb3RlZ2UuY29tLmJyIiwiZXhwIjoxODYwNzEwOTEyfQ.2yLysK8kK1jwmSCYJODCvWgppg8WtjuLxCwxyLnm2S0qAzSp12bFVmhwhVe8pDSWWCqYBCXuj0o2wQLNtHFpRw"
];

// Header para a API OpenRouteService (Chave do ambiente)
$ORS_KEY = getenv("ORS_KEY") ?: "eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6ImZjMDdhNWQ0MWUxZDQyYTQ5NzJjMzFmNzcwY2RjMmE3IiwiaCI6Im11cm11cjY0In0=";
$HEADERS_ORS = [
    "Authorization: " . $ORS_KEY
];


// ==============================================================================
// 🤖 FUNÇÕES AUXILIARES DE REQUISIÇÃO (cURL)
// ==============================================================================

/**
 * Faz uma requisição GET simples.
 */
function simple_get($url, $headers = [], $timeout = 10) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    // Ignorar verificação SSL (não recomendado, mas pode ser necessário)
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($http_code >= 400) {
        error_log("simple_get falhou para $url: HTTP $http_code - $response_body");
    }

    return [$response_body, $http_code, $error];
}

/**
 * Faz uma requisição POST ou GET usando um arquivo de cookie (sessão).
 */
function curl_with_session($metodo, $url, $cookieFile, $headers, $data = null, $timeout = 10) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    
    // Gerenciamento da Sessão (Cookies)
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile); // Salva cookies aqui
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile); // Envia cookies daqui

    if ($metodo == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data) {
            // Converte array para "form data" (x-www-form-urlencoded)
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); // URL final após redirects
    $error = curl_error($ch);
    curl_close($ch);

    return [$response_body, $http_code, $effective_url, $error];
}


// ==============================================================================
// 🤖 SISTEMA DE LOGIN INTELIGENTE (PHP)
// ==============================================================================

/**
 * Obtém o caminho para o arquivo de cookie da sessão.
 * Se a sessão não existir ou for forçada, faz login para criar um novo.
 */
function get_session_autenticada($force_login = false) {
    global $URL_LOGIN, $ABM_USER, $ABM_PASS, $HEADERS_COMMON;

    // Simula a 'global_session' do Flask usando um arquivo temporário.
    // Isso garante que UMA sessão de login seja compartilhada por TODAS as requisições.
    $COOKIE_FILE_PATH = sys_get_temp_dir() . '/abm_php_session.cookie';

    // Se já temos sessão e não estamos forçando login, retorna o caminho do arquivo
    if (file_exists($COOKIE_FILE_PATH) && !$force_login) {
        return $COOKIE_FILE_PATH;
    }

    // Se forçamos, deleta o cookie antigo
    if (file_exists($COOKIE_FILE_PATH)) {
        unlink($COOKIE_FILE_PATH);
    }
    
    error_log("🔄 [LOGIN] Iniciando autenticação PHP para usuário: $ABM_USER...");

    $payload = [
        "login" => $ABM_USER,
        "senha" => $ABM_PASS,
        "password" => $ABM_PASS // Garantia
    ];

    // Usa a função 'curl_with_session' para já salvar os cookies no arquivo
    list($response_body, $http_code, $effective_url, $error) = curl_with_session(
        'POST',
        $URL_LOGIN,
        $COOKIE_FILE_PATH, // Onde salvar os cookies
        $HEADERS_COMMON,
        $payload
    );

    if ($error) {
        error_log("❌ [LOGIN] Erro crítico de cURL ao tentar logar: $error");
        return null;
    }

    // 2. Verifica se o login funcionou (lógica idêntica ao Python)
    // Se a URL final ainda for a de login E conter a palavra "senha", falhou.
    if (strpos($effective_url, "emp/abmtecnologia") !== false && strpos(strtolower($response_body), "senha") !== false) {
        error_log("❌ [LOGIN] Falha: Credenciais parecem incorretas ou campos do form estão errados.");
        if (file_exists($COOKIE_FILE_PATH)) {
            unlink($COOKIE_FILE_PATH); // Limpa o cookie inválido
        }
        return null;
    }
    
    error_log("✅ [LOGIN] Sucesso! Sessão PHP renovada em $COOKIE_FILE_PATH");
    return $COOKIE_FILE_PATH;
}

/**
 * Tenta fazer a requisição. Se der erro de permissão, reloga e tenta de novo.
 */
function fazer_requisicao_resiliente($metodo, $url, $data = null) {
    global $HEADERS_COMMON;
    
    $sessionCookieFile = get_session_autenticada(false);
    if (!$sessionCookieFile) {
        return [null, null, "Falha no login inicial"];
    }

    // Tentativa 1
    list($response_body, $http_code, $effective_url, $error) = curl_with_session(
        $metodo, $url, $sessionCookieFile, $HEADERS_COMMON, $data
    );
    
    if ($error) {
        return [null, null, "Erro na Tentativa 1: $error"];
    }

    // Verifica se a sessão caiu (Redirecionou pro login ou deu 401/403)
    $sessao_expirada = ($http_code == 401 || $http_code == 403 || strpos($effective_url, "emp/abmtecnologia") !== false);

    if ($sessao_expirada) {
        error_log("⚠️ [RETRY] Erro (HTTP $http_code ou redirect para login). Tentando relogar e repetir...");
        
        // Força novo login
        $sessionCookieFile = get_session_autenticada(true);
        if (!$sessionCookieFile) {
            return [null, null, "Falha ao renovar login"];
        }

        // Tentativa 2 (Com sessão nova)
        list($response_body, $http_code, $effective_url, $error) = curl_with_session(
            $metodo, $url, $sessionCookieFile, $HEADERS_COMMON, $data
        );

        if ($error) {
            return [null, null, "Erro final após retry (cURL): $error"];
        }
        
        // Se falhar de novo, é um erro final
        $sessao_expirada_de_novo = ($http_code == 401 || $http_code == 403 || strpos($effective_url, "emp/abmtecnologia") !== false);
        if($sessao_expirada_de_novo) {
             return [null, null, "Erro final após retry (Sessão ainda inválida)"];
        }
    }
    
    // Sucesso (ou erro não relacionado à sessão)
    return [$response_body, $http_code, null];
}


// ==============================================================================
// 🎯 HANDLERS DE ROTA (Os "endpoints" da API)
// ==============================================================================

/**
 * Define o cabeçalho de resposta como JSON e encerra o script.
 */
function responder_json($data, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * /buscar_rastreamento/<placa>
 */
function handle_buscar_rastreamento($placa) {
    global $URL_API_RASTREAMENTO;
    
    if (empty($placa)) {
        responder_json(["erro" => "Placa inválida"], 400);
    }
    
    // Limpa a placa (remove traços e espaços)
    $placa_clean = preg_replace("/[^A-Za-z0-9]/", '', strtoupper($placa));
    
    $data = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
    
    // Chama nossa função inteligente
    list($response_body, $http_code, $erro) = fazer_requisicao_resiliente('POST', $URL_API_RASTREAMENTO, $data);

    if ($erro) {
        responder_json(["erro" => $erro], 500);
    }
    
    // A API de rastreamento retorna JSON. Vamos apenas repassá-lo.
    // Primeiro, verificamos se é um JSON válido.
    json_decode($response_body);
    if (json_last_error() == JSON_ERROR_NONE) {
        // Repassa o JSON original
        http_response_code($http_code);
        header('Content-Type: application/json; charset=utf-8');
        echo $response_body;
        exit;
    } else {
        responder_json([
            "erro" => "API retornou dados inválidos (não-JSON)", 
            "debug" => substr($response_body, 0, 100)
        ], 500);
    }
}

/**
 * /previsao/<placa> e /previsaoinicial/<placa>
 */
function handle_calcular_rota($placa, $tipo_destino = "Final") {
    global $URL_API_RASTREAMENTO, $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, $HEADERS_ORS;

    try {
        // 1. Buscar localização atual (Reusando a função resiliente)
        $placa_clean = preg_replace("/[^A-Za-z0-9]/", '', strtoupper($placa));
        $data_rastreio = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
        
        list($resp_body, $http_code, $erro) = fazer_requisicao_resiliente('POST', $URL_API_RASTREAMENTO, $data_rastreio);
        if ($erro) {
            responder_json(["erro" => $erro], 500);
        }
        
        $veiculos = json_decode($resp_body, true);
        if (empty($veiculos) || !is_array($veiculos)) {
            responder_json(["erro" => "Veículo não localizado no rastreador."]);
        }

        $veiculo = $veiculos[0];
        
        // Extrair Lat/Lon do veículo (Lógica idêntica ao Python)
        $lat1 = 0; $lon1 = 0;
        try {
            $loc_text = $veiculo['loc'] ?? null;
            if (is_array($loc_text) && count($loc_text) >= 2) {
                $lat1 = (float)$loc_text[0];
                $lon1 = (float)$loc_text[1];
            } elseif (is_string($loc_text) && strpos($loc_text, ",") !== false) {
                $p = explode(",", str_replace(["[", "]"], "", $loc_text));
                $lat1 = (float)$p[0];
                $lon1 = (float)$p[1];
            } else {
                $lat1 = (float)($veiculo['latitude'] ?? 0);
                $lon1 = (float)($veiculo['longitude'] ?? 0);
            }
        } catch (Exception $e) { /* Mantém 0,0 */ }

        if ($lat1 == 0) {
            responder_json(["erro" => "Coordenadas do veículo inválidas."]);
        }

        // 2. Buscar dados da rota (API Principal)
        list($resp_dash_body, $dash_code, $dash_err) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN);
        if ($dash_err || $dash_code >= 400) {
             responder_json(["erro" => "Falha ao buscar dados do Dashboard", "debug" => $dash_err], 500);
        }
        $data_dash = json_decode($resp_dash_body, true);
        
        $todas = array_merge(
            $data_dash['linhasAndamento'] ?? [],
            $data_dash['linhasCarroDesligado'] ?? [],
            $data_dash['linhasComecaramSemPrimeiroPonto'] ?? []
        );
        
        $linha = null;
        foreach ($todas as $l) {
            if (($l['veiculo']['veiculo'] ?? null) == $placa) {
                $linha = $l;
                break;
            }
        }
        
        if (!$linha) {
            responder_json(["erro" => "Linha não encontrada no dashboard."]);
        }

        // 3. Buscar Lat/Lon do destino (Inicial ou Final)
        $lat2 = null; $lon2 = null;
        foreach ($linha['pontoDeParadas'] ?? [] as $p) {
            if (($p['tipoPonto']['tipo'] ?? null) == $tipo_destino) {
                $lat2 = $p['latitude'] ?? null;
                $lon2 = $p['longitude'] ?? null;
                break;
            }
        }
        
        if (!$lat2) {
            responder_json(["erro" => "Ponto $tipo_destino não cadastrado na linha."]);
        }

        // 4. Calcular Rota (OpenRouteService)
        $url_ors = "https://api.openrouteservice.org/v2/directions/driving-car";
        $params = http_build_query(["start" => "$lon1,$lat1", "end" => "$lon2,$lat2"]);
        
        list($res_ors_body, $ors_code, $ors_err) = simple_get($url_ors . '?' . $params, $HEADERS_ORS);
        if ($ors_err || $ors_code >= 400) {
             responder_json(["erro" => "Falha ao buscar dados do OpenRouteService", "debug" => $ors_err], 500);
        }
        $dados_ors = json_decode($res_ors_body, true);

        if (!isset($dados_ors["features"])) {
            responder_json(["erro" => "Erro ao calcular rota externa.", "debug" => $dados_ors]);
        }

        $resumo = $dados_ors["features"][0]["properties"]["summary"] ?? [];
        $segundos = (int)($resumo["duration"] ?? 0);
        $metros = (int)($resumo["distance"] ?? 0);

        $horas = floor($segundos / 3600);
        $minutos = floor(($segundos % 3600) / 60);
        $km = $metros / 1000;
        
        $tempo_fmt = $horas > 0 ? "{$horas}h {$minutos}min" : "{$minutos} min";
        
        responder_json([
            "tempo" => $tempo_fmt,
            "distancia" => sprintf("%.2f km", $km)
        ]);

    } catch (Exception $e) {
        responder_json(["erro" => "Erro interno de cálculo: " . $e->getMessage()], 500);
    }
}


/**
 * / (Página principal)
 * Esta função apenas busca e processa os dados.
 * O HTML é renderizado fora dela.
 */
function handle_index_data() {
    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;
    
    list($response_body, $http_code, $error) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, 15);
    
    if ($error || $http_code >= 400) {
        echo "Erro ao buscar dados: $error (HTTP $http_code)";
        exit;
    }

    $data = json_decode($response_body, true);
    if (!$data) {
        echo "Erro ao decodificar JSON da API principal.";
        exit;
    }

    $linhas_andamento = $data["linhasAndamento"] ?? [];
    $linhas_desligado = $data["linhasCarroDesligado"] ?? [];
    $linhas_sem_ponto = $data["linhasComecaramSemPrimeiroPonto"] ?? [];
    
    $todas_linhas = [];
    $combinadas = array_merge($linhas_andamento, $linhas_desligado, $linhas_sem_ponto);

    foreach ($combinadas as $l) {
        // Define categoria
        if (in_array($l, $linhas_desligado, true)) {
            $l["categoria"] = "Carro desligado";
        } elseif (in_array($l, $linhas_sem_ponto, true)) {
            $l["categoria"] = "Começou sem ponto";
        } else {
            $l["categoria"] = "Em andamento";
        }

        // Horário Programado (Inicial)
        $horario_inicial = "N/D";
        foreach ($l["pontoDeParadas"] ?? [] as $p) {
            if (($p["tipoPonto"]["tipo"] ?? null) == "Inicial" && isset($p["horario"])) {
                $horario_inicial = $p["horario"];
                break;
            }
        }
        $l["horarioProgramado"] = $horario_inicial;

        // Horário Real (Inicial)
        $horario_real = null;
        foreach ($l["pontoDeParadas"] ?? [] as $p) {
            if (($p["tipoPonto"]["tipo"] ?? null) == "Inicial" && isset($p["dataPassouGmt3"])) {
                try {
                    // O Python usava `astimezone(timezone(timedelta(hours=-0)))` que é UTC.
                    $dt = new DateTime($p["dataPassouGmt3"]);
                    $dt->setTimezone(new DateTimeZone('UTC'));
                    $horario_real = $dt->format("H:i");
                } catch (Exception $e) {
                    $horario_real = "N/D";
                }
                break;
            }
        }
        $l["horarioReal"] = $horario_real ?: "N/D";

        // --- LÓGICA DE STATUS REFINADA ---
        $agora = new DateTime();
        $dt_prog = null;
        
        if ($l["horarioProgramado"] != "N/D") {
            try {
                list($h_prog, $m_prog) = array_map('intval', explode(':', $l["horarioProgramado"]));
                $dt_prog = (new DateTime())->setTime($h_prog, $m_prog, 0, 0);
            } catch (Exception $e) {
                $dt_prog = null;
            }
        }

        if ($l["horarioReal"] == "N/D") {
            if ($dt_prog) {
                if ($dt_prog > $agora) {
                    $l["status_tempo"] = "em_deslocamento";
                } else {
                    $l["status_tempo"] = "atrasado_sem_inicial";
                }
            } else {
                $l["status_tempo"] = "indefinido";
            }
        } else {
            if ($dt_prog) {
                try {
                    list($h_real, $m_real) = array_map('intval', explode(':', $l["horarioReal"]));
                    $dt_real = (new DateTime())->setTime($h_real, $m_real, 0, 0);
                    
                    // Compara timestamps para pegar a diferença em segundos
                    $diff_seconds = $dt_real->getTimestamp() - $dt_prog->getTimestamp();
                    $diferenca_minutos = $diff_seconds / 60;

                    if ($diferenca_minutos > 10) {
                        $l["status_tempo"] = "atrasado";
                    } else {
                        $l["status_tempo"] = "no_horario";
                    }
                } catch (Exception $e) {
                    $l["status_tempo"] = "no_horario"; // Fallback
                }
            } else {
                $l["status_tempo"] = "no_horario";
            }
        }

        // Horário Programado (Final)
        $horario_finalprogramado = "N/D";
        foreach ($l["pontoDeParadas"] ?? [] as $p) {
            if (($p["tipoPonto"]["tipo"] ?? null) == "Final" && isset($p["horario"])) {
                $horario_finalprogramado = $p["horario"];
                break;
            }
        }
        $l["horariofinalProgramado"] = $horario_finalprogramado;

        // Local Final
        $lat_final = "N/D"; $lon_final = "N/D";
        foreach ($l["pontoDeParadas"] ?? [] as $p) {
            if (($p["tipoPonto"]["tipo"] ?? null) == "Final") {
                $lat_final = $p["latitude"] ?? "N/D";
                $lon_final = $p["longitude"] ?? "N/D";
                break;
            }
        }
        $l["localfinal"] = "(Lat: $lat_final, Lon: $lon_final)";

        // Local Inicial
        $lat_inicial = "N/D"; $lon_inicial = "N/D";
        foreach ($l["pontoDeParadas"] ?? [] as $p) {
            if (($p["tipoPonto"]["tipo"] ?? null) == "Inicial") {
                $lat_inicial = $p["latitude"] ?? "N/D";
                $lon_inicial = $p["longitude"] ?? "N/D";
                break;
            }
        }
        $l["localinicial"] = "(Lat: $lat_inicial, Lon: $lon_inicial)";

        $todas_linhas[] = $l;
    }

    // --- Contagem para os Cards ---
    $qtd_atrasados = 0;
    $qtd_deslocamento = 0;
    $qtd_sem_inicio = 0;
    $qtd_desligados = 0;
    $qtd_pontual = 0;

    foreach ($todas_linhas as $l) {
        $status = $l['status_tempo'] ?? 'indefinido';
        $categoria = $l['categoria'] ?? 'indefinido';

        if ($status == "atrasado") {
            $qtd_atrasados++;
        }
        if ($categoria == "Em andamento" && $status == "em_deslocamento") {
            $qtd_deslocamento++;
        }
        if ($categoria == "Começou sem ponto") {
            $qtd_sem_inicio++;
        }
        if ($categoria == "Carro desligado") {
            $qtd_desligados++;
        }
        if ($categoria == "Em andamento" && $status == "no_horario") {
            $qtd_pontual++;
        }
    }
    
    $qtd_total = count($todas_linhas);

    // Retorna todas as variáveis para o template HTML
    return compact(
        'todas_linhas',
        'qtd_atrasados',
        'qtd_desligados',
        'qtd_deslocamento',
        'qtd_pontual',
        'qtd_total',
        'qtd_sem_inicio'
    );
}


// ==============================================================================
// 🚦 ROUTER PRINCIPAL (Simula o Flask)
// ==============================================================================

// Parseia a URL da requisição
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Remove a query string do path, se houver
$path = explode('?', $path)[0];

// Tenta encontrar uma rota que corresponda
if (preg_match('!^/buscar_rastreamento/([^/]+)$!', $path, $matches)) {
    handle_buscar_rastreamento($matches[1]);

} elseif (preg_match('!^/previsao/([^/]+)$!', $path, $matches)) {
    handle_calcular_rota($matches[1], "Final");

} elseif (preg_match('!^/previsaoinicial/([^/]+)$!', $path, $matches)) {
    handle_calcular_rota($matches[1], "Inicial");

} elseif ($path == '/') {
    // 1. Processa todos os dados
    $vars = handle_index_data();
    
    // 2. Extrai as variáveis ($todas_linhas, $qtd_atrasados, etc.) para o escopo local
    extract($vars);

    // 3. Renderiza o HTML (saindo do PHP)
    // O código abaixo é o seu HTML, com as tags {{}} e {%%} do Jinja
    // Adicionei htmlspecialchars() para segurança (evitar XSS).
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABM Bus - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .sidebar { background-color: #0b1f3a; color: white; min-height: 100vh; width: 250px; position: fixed; }
        .sidebar a { color: #d1d5db; display: block; padding: 12px 20px; text-decoration: none; }
        .sidebar a.active, .sidebar a:hover { background-color: #1b2e52; color: white; }
        .content { margin-left: 250px; padding: 25px; }
        .card-summary { border-radius: 12px; padding: 15px; color: white; text-align: center; font-weight: 600; }
        .card-blue { background-color: #1e3a8a; }
        .card-orange { background-color: #f59e0b; }
        .card-green { background-color: #10b981; }
        .card-red { background-color: #dc2626; }
        .table thead { background-color: #0b1f3a; color: white; }
        .search-bar { border-radius: 8px; border: 1px solid #ccc; padding-left: 35px; }
        .search-icon { position: absolute; left: 10px; top: 8px; color: #6b7280; }
        
        /* Estilos do final do seu código */
        th[onclick]:hover {
            background-color: #1b2e52;
            color: #ffc107;
            user-select: none;
        }
        .modal-content {
            transition: all 0.3s ease-in-out;
        }
        .modal-content:hover {
            transform: scale(1.01);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }
        #alertModalBody h6 {
            color: #007bff;
        }
        #alertModalBody p {
            color: #212529;
        }
    </style>
</head>
<body>

<div class="sidebar d-flex flex-column">
    <h4 class="text-center mt-4 mb-4">Viação Mimo</h4>
    <a href="#" class="active"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
    <a href="#"><i class="bi bi-map me-2"></i>Routes</a>
    <a href="#"><i class="bi bi-bus-front me-2"></i>Vehicles</a>
    <a href="#"><i class="bi bi-person-vcard me-2"></i>Drivers</a>
    <a href="#"><i class="bi bi-bar-chart me-2"></i>Reports</a>
    <a href="#"><i class="bi bi-gear me-2"></i>Settings</a>
</div>

<div class="content">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="position-relative w-50">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="searchInput" class="form-control search-bar" placeholder="Buscar rota, veículo ou motorista...">
        </div>
        <div>
            <i class="bi bi-bell me-3 fs-5"></i>
            <i class="bi bi-person-circle fs-5"></i>
        </div>
    </div>

    <div class="row g-3 mb-5">
        <div class="col-md-2">
            <div class="card-summary card-blue">
                <h5>Total linhas</h5>
                <h3><?php echo $qtd_total; ?></h3>
            </div>
        </div>
       <div class="col-md-2">
            <div class="card-summary card-red">
                <h5>Veículos Atrasados</h5> <h3><?php echo $qtd_atrasados; ?></h3> </div>
        </div>
        <div class="col-md-2">
            <div class="card-summary card-green">
                <h5>Pontual</h5>
                 <h3><?php echo $qtd_pontual; ?></h3>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card-summary bg-secondary">
                <h5>Carros Desligados</h5>
                <h3><?php echo $qtd_desligados; ?></h3>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card-summary bg-info">
                <h5>Em deslocamento</h5>
                <h3><?php echo $qtd_deslocamento; ?></h3>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card-summary card-orange">
                <h5>Sem Início</h5>
                <h3><?php echo $qtd_sem_inicio; ?></h3>
            </div>
        </div>
    </div>

 <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="mb-3">Dashboard</h5>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th style="cursor: pointer;" onclick="ordenarTabela(0)">
                                Empresa <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th style="cursor: pointer;" onclick="ordenarTabela(1)">
                                Rota/Linha <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th style="cursor: pointer;" onclick="ordenarTabela(2)">
                                Prefixo <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th style="cursor: pointer;" onclick="ordenarTabela(3)">
                                Programado <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th style="cursor: pointer;" onclick="ordenarTabela(4)">
                                Realizado <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th style="cursor: pointer;" onclick="ordenarTabela(5)">
                                Motorista <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th style="cursor: pointer;" onclick="ordenarTabela(6)">
                                Status <i class="bi bi-arrow-down-up" style="font-size: 0.8em; opacity: 0.5;"></i>
                            </th>
                            <th>Inicial</th>
                            <th>Final</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todas_linhas as $linha): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($linha['empresa']['nome'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($linha['descricaoLinha'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($linha['veiculo']['veiculo'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($linha['horarioProgramado'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($linha['horarioReal'] ?? 'N/D'); ?></td>
                                <td><?php echo htmlspecialchars($linha['nome'] ?? 'DESCONHECIDO'); ?></td>
                                <td>
                                    <?php if ($linha['categoria'] == 'Carro desligado'): ?>
                                        <span class="badge bg-secondary">Carro desligado</span>
                                    <?php elseif ($linha['categoria'] == 'Em andamento'): ?>
                                        
                                        <?php if ($linha['status_tempo'] == 'atrasado'): ?>
                                            <span class="badge bg-danger">Atrasado (>10min)</span>
                                        <?php elseif ($linha['status_tempo'] == 'atrasado_sem_inicial'): ?>
                                            <span class="badge bg-danger">Atrasado (Não iniciou)</span>
                                        <?php elseif ($linha['status_tempo'] == 'em_deslocamento'): ?>
                                            <span class="badge bg-info text-dark">Em deslocamento</span>
                                        <?php elseif ($linha['status_tempo'] == 'no_horario'): ?>
                                            <span class="badge bg-success">No horário</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Sem Previsão</span>
                                        <?php endif; ?>
                                        
                                    <?php elseif ($linha['categoria'] == 'Começou sem ponto'): ?>
                                        <span class="badge bg-warning text-dark">Sem Ponto Inicial</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark">Sem dados</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm"
                                        onclick="buscarRastreamentoinicial(
                                            '<?php echo htmlspecialchars($linha['veiculo']['veiculo'] ?? '', ENT_QUOTES); ?>', 
                                            '<?php echo htmlspecialchars($linha['localinicial'] ?? 'N/D', ENT_QUOTES); ?>', 
                                            this)">
                                        🔍 Calcular
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm"
                                        onclick="buscarRastreamento(
                                            '<?php echo htmlspecialchars($linha['veiculo']['veiculo'] ?? '', ENT_QUOTES); ?>', 
                                            '<?php echo htmlspecialchars($linha['localfinal'] ?? 'N/D', ENT_QUOTES); ?>', 
                                            this)">
                                        🔍 Calcular
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0 rounded-4" style="background-color: #ffffff;">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="alertModalLabel" style="color: #007bff; font-size: 1.5rem;">
          ⚠️ Linhas Atrasadas
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body pt-3" id="alertModalBody"></div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
          Fechar
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="popupResultado" tabindex="-1" aria-labelledby="popupResultadoLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content shadow-lg border-0 rounded-4" style="background-color: #ffffff;">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="popupResultadoLabel" style="color: #007bff; font-size: 1.5rem;">
          Resultado da Consulta
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="resultadoConteudo">
        </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">
          Fechar
        </button>
      </div>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // ---------------------------------------------------------
    // 1. LÓGICA DO ALERTA AUTOMÁTICO
    // Converte os dados do PHP para JSON para o JavaScript usar
    // ---------------------------------------------------------
    const linhas = <?php echo json_encode($todas_linhas); ?>;
    const agora = new Date();
    
    // A lógica de filtragem foi movida para o backend (status_tempo == 'atrasado_sem_inicial')
    // Esta lógica de JS original era um pouco diferente. Vou manter a sua lógica de JS original.
    const atrasadas_js = linhas.filter(l => {
        if (!l.horarioProgramado || l.horarioProgramado === "N/D") return false;
        
        // Esta lógica de JS é para 'atrasado_sem_inicial'
        if (l.horarioReal !== "N/D") return false; // Já passou, não é esse tipo de atraso
        
        try {
            const [h, m] = l.horarioProgramado.split(":").map(Number);
            const horaProg = new Date();
            horaProg.setHours(h, m, 0, 0);
            
            // Se o horário programado já passou e ainda não há horário real
            return horaProg < agora;
        } catch (e) {
            return false;
        }
    });

    if (atrasadas_js.length > 0) {
        const agrupadas = {};
        atrasadas_js.forEach(linha => {
            const empresaNome = linha.empresa?.nome || 'N/D';
            if (!agrupadas[empresaNome]) agrupadas[empresaNome] = [];
            agrupadas[empresaNome].push(linha);
        });

        let html = '';
        for (const empresa in agrupadas) {
            html += `<div class="p-3 mb-3 rounded-3" style="background-color: #f8f9fa;">
                         <h6 class="fw-bold text-primary mb-2">🏢 ${empresa}</h6>`;
            agrupadas[empresa].forEach(linha => {
                html += `<p class="mb-1">
                             <strong>Linha:</strong> ${linha.descricaoLinha || 'N/D'} <br>
                             <strong>Prefixo:</strong> ${linha.veiculo?.veiculo || 'N/D'} <br>
                             <strong>Horário Programado:</strong> ${linha.horarioProgramado}
                         </p>
                         <hr class="my-2">`;
            });
            html += `</div>`;
        }

        document.getElementById('alertModalBody').innerHTML = html;
        new bootstrap.Modal(document.getElementById('alertModal')).show();
    }

    // ---------------------------------------------------------
    // 2. LÓGICA DE BUSCA INSTANTÂNEA (Filtro)
    // ---------------------------------------------------------
    const inputBusca = document.getElementById('searchInput');
    if (inputBusca) {
        inputBusca.addEventListener('keyup', function() {
            const termo = this.value.toLowerCase();
            const linhasTabela = document.querySelectorAll('tbody tr');

            linhasTabela.forEach(linha => {
                const texto = linha.innerText.toLowerCase();
                if (texto.includes(termo)) {
                    linha.style.display = '';
                } else {
                    linha.style.display = 'none';
                }
            });
        });
    }
});

// ---------------------------------------------------------
// 3. FUNÇÃO DE ORDENAR COLUNAS
// ---------------------------------------------------------
let direcaoAtual = {}; // Estado da ordenação por coluna

function ordenarTabela(n) {
    const tabela = document.querySelector("table tbody");
    const linhas = Array.from(tabela.rows);
    
    // Alterna entre ascendente e descendente
    const asc = !direcaoAtual[n];
    direcaoAtual[n] = asc;

    // Atualiza ícones visuais nos cabeçalhos
    document.querySelectorAll("th i").forEach(i => i.className = "bi bi-arrow-down-up text-muted opacity-50");
    const thAtual = document.querySelectorAll("th")[n];
    const icone = thAtual.querySelector("i");
    if(icone) icone.className = asc ? "bi bi-sort-alpha-down text-white" : "bi bi-sort-alpha-down-alt text-white";

    linhas.sort((a, b) => {
        const valorA = a.cells[n].innerText.trim().toLowerCase();
        const valorB = b.cells[n].innerText.trim().toLowerCase();

        // Joga valores vazios ou N/D para o final sempre
        if (valorA === 'n/d' || valorA === '') return 1;
        if (valorB === 'n/d' || valorB === '') return -1;

        // Verifica se é horário (ex: 14:30) para ordenar numericamente
        const isHora = valorA.includes(':') && !isNaN(parseFloat(valorA.replace(':','')));
        
        if (isHora) {
            const numA = parseFloat(valorA.replace(':', '.'));
            const numB = parseFloat(valorB.replace(':', '.'));
            return asc ? numA - numB : numB - numA;
        }
        
        // Ordenação de texto padrão
        return asc ? valorA.localeCompare(valorB) : valorB.localeCompare(valorA);
    });

    // Re-renderiza as linhas ordenadas
    linhas.forEach(linha => tabela.appendChild(linha));
}

// ---------------------------------------------------------
// 4. FUNÇÕES DE FETCH (Pop-up)
// ---------------------------------------------------------

// O label do modal de popup está errado, vamos corrigir
document.addEventListener("DOMContentLoaded", () => {
    const modalPopup = document.getElementById('popupResultado');
    if (modalPopup) {
        const title = modalPopup.querySelector('.modal-title');
        if (title) {
            // Corrige o ID do label para não ser duplicado
            title.id = "popupResultadoLabel"; 
            title.innerHTML = "🔍 Previsão de Rota"; // Define um título melhor
        }
    }
});


async function buscarRastreamento(placa, localfinal, button) {
    if (!placa) return alert("Veículo não identificado!");
    const row = button.closest('tr');
    
    // Célula alvo é a 8 (Inicial) ou 9 (Final). Botão está na 9.
    const previsaoCell = button.closest('td'); // A própria célula
    previsaoCell.innerHTML = "Calculando...";

    document.getElementById("resultadoConteudo").innerHTML = "<p>Carregando...</p>";
    const modal = new bootstrap.Modal(document.getElementById("popupResultado"));
    modal.show();

    try {
        // As duas APIs são chamadas em paralelo
        const [respRastreio, respRota] = await Promise.all([
            fetch(`/buscar_rastreamento/${placa}`),
            fetch(`/previsao/${placa}`)
        ]);

        const data = await respRastreio.json();
        const rotaData = await respRota.json();

        // Atualiza a célula com o resultado principal
        if (rotaData.tempo) {
            previsaoCell.innerHTML = `${rotaData.tempo}`;
        } else {
            previsaoCell.innerHTML = `<span class="text-danger">Erro</span>`;
        }
        
        // Monta o HTML do Modal
        let html = "";
        if (rotaData.erro) {
            html += `<p class="text-danger"><b>Erro na Rota:</b> ${rotaData.erro}</p>`;
        }
        
        if (Array.isArray(data) && data.length > 0) {
            const veiculo = data[0];
            html += `<ul class="list-unstyled">
                        <li><b>Identificação:</b> ${veiculo.ras_veiculos?.ras_vei_tag_identificacao || veiculo.identificacao || 'N/D'}</li>
                        <li><b>Endereço Veículo:</b> ${veiculo.loc || veiculo.endereco || 'N/D'}</li>
                        <li><b>Endereço Ponto Final:</b> ${localfinal || 'N/D'}</li>
                        <hr>
                        <li><h5 class="text-primary"><b>Previsão até o ponto final:</b><br> ${rotaData.tempo || 'N/D'} — ${rotaData.distancia || 'N/D'}</h5></li>
                     </ul>`;
        } else if (data?.erro) {
            html += `<p class="text-danger"><b>Erro no Rastreio:</b> ${data.erro}</p>`;
        } else {
            html += `<p>Nenhum dado de rastreio encontrado.</p>`;
        }
        
        document.getElementById("resultadoConteudo").innerHTML = html;

    } catch (err) {
        previsaoCell.innerHTML = "Erro";
        document.getElementById("resultadoConteudo").innerHTML = "<p>Erro ao buscar dados.</p>";
        console.error(err);
    }
}

async function buscarRastreamentoinicial(placa, localinicial, button) {
    if (!placa) return alert("Veículo não identificado!");
    const row = button.closest('tr');
    
    const previsaoCell = button.closest('td'); // A própria célula
    previsaoCell.innerHTML = "Calculando...";

    document.getElementById("resultadoConteudo").innerHTML = "<p>Carregando...</p>";
    const modal = new bootstrap.Modal(document.getElementById("popupResultado"));
    modal.show();

    try {
        const [respRastreio, respRota] = await Promise.all([
            fetch(`/buscar_rastreamento/${placa}`),
            fetch(`/previsaoinicial/${placa}`)
        ]);

        const data = await respRastreio.json();
        const rotaData = await respRota.json();

        if (rotaData.tempo) {
            previsaoCell.innerHTML = `${rotaData.tempo}`;
        } else {
            previsaoCell.innerHTML = `<span class="text-danger">Erro</span>`;
        }

        let html = "";
        if (rotaData.erro) {
            html += `<p class="text-danger"><b>Erro na Rota:</b> ${rotaData.erro}</p>`;
        }

        if (Array.isArray(data) && data.length > 0) {
            const veiculo = data[0];
            html += `<ul class="list-unstyled">
                        <li><b>Identificação:</b> ${veiculo.ras_veiculos?.ras_vei_tag_identificacao || veiculo.identificacao || 'N/D'}</li>
                        <li><b>Endereço Veículo:</b> ${veiculo.loc || veiculo.endereco || 'N/D'}</li>
                        <li><b>Endereço Ponto Inicial:</b> ${localinicial || 'N/D'}</li>
                        <hr>
                        <li><h5 class="text-primary"><b>Previsão até o ponto inicial:</b><br> ${rotaData.tempo || 'N/D'} — ${rotaData.distancia || 'N/D'}</h5></li>
                     </ul>`;
        } else if (data?.erro) {
            html += `<p class="text-danger"><b>Erro no Rastreio:</b> ${data.erro}</p>`;
        } else {
            html += `<p>Nenhum dado de rastreio encontrado.</p>`;
        }
        
        document.getElementById("resultadoConteudo").innerHTML = html;

    } catch (err) {
        previsaoCell.innerHTML = "Erro";
        document.getElementById("resultadoConteudo").innerHTML = "<p>Erro ao buscar dados.</p>";
        console.error(err);
    }
}

</script>
</body>
</html>

<?php
    exit; // Termina o script após renderizar a página principal

} else {
    // Se nenhuma rota corresponder, retorna 404
    http_response_code(404);
    responder_json(["erro" => "Rota não encontrada: $path"], 404);
}
?>