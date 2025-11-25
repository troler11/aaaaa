<?php
// 1. INICIAR A SESSÃO OBRIGATORIAMENTE
// Isso deve ser a *primeira* coisa no arquivo para gerenciar o login da API.
session_start();

// ==============================================================================
// 🤖 CONFIGURAÇÃO E CONSTANTES GLOBAIS
// ==============================================================================

// URLs
define('URL_BASE', "https://abmtecnologia.abmprotege.net");
define('URL_LOGIN', URL_BASE . "/emp/abmtecnologia");
define('URL_API_RASTREAMENTO', URL_BASE . "/mapaGeral/filtrarRastreadosPorPlacaOuIdentificacao");
define('URL_DASHBOARD_MAIN', "https://abmbus.com.br:8181/api/dashboard/mongo/95?naoVerificadas=false&agrupamentos=");

// Credenciais (RECOMENDAÇÃO: Use getenv('ABM_USER') em produção)
define('ABM_USER', "lucas");
define('ABM_PASS', "Lukinha2009");

// Header Fixo para o Dashboard Principal
$HEADERS_DASHBOARD_MAIN = [
    "Accept: application/json, text/plain, */*",
    "Authorization: eyJhbGciOiJIUzUxMiJ9.eyJzdWIiOiJtaW1vQGFibXByb3RlZ2UuY29tLmJyIiwiZXhwIjoxODYwNzEwOTEyfQ.2yLysK8kK1jwmSCYJODCvWgppg8WtjuLxCwxyLnm2S0qAzSp12bFVmhwhVe8pDSWWCqYBCXuj0o2wQLNtHFpRw"
];

// Headers Globais para a API de Rastreamento (Simulando navegador)
$HEADERS_COMMON = [
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
    "Origin: " . URL_BASE,
    "Referer: " . URL_BASE . "/mapaGeral",
    "X-Requested-With: XMLHttpRequest",
    "Accept: application/json, text/javascript, */*; q=0.01"
];


// ==============================================================================
// 🤖 FUNÇÃO AUXILIAR DE CURL
// ==============================================================================

/**
 * Função genérica para fazer requisições cURL.
 * Retorna um array com 'body', 'http_code', 'final_url' e 'cookies'.
 */
function fazer_curl_request($url, $method = 'GET', $headers = [], $data = null, $cookies = null) {
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true); // Precisamos do header para pegar cookies
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Ignora verificação SSL (como no Python requests)
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // Seguir redirecionamentos
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data); // Para JSON, etc.
        }
    }
    
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    
    if (!empty($cookies)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
    }

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['body' => null, 'http_code' => 500, 'final_url' => $url, 'cookies' => null, 'error' => $error];
    }
    
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    $header_str = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    
    // Extrai cookies da resposta para o login
    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $header_str, $matches);
    $response_cookies = implode('; ', $matches[1]);
    
    curl_close($ch);
    
    return [
        'body' => $body,
        'http_code' => $http_code,
        'final_url' => $final_url,
        'cookies' => $response_cookies // Cookies definidos por *esta* resposta
    ];
}


// ==============================================================================
// 🤖 SISTEMA DE LOGIN INTELIGENTE (COM SESSÃO PHP)
// ==============================================================================

/**
 * Obtém os cookies de sessão da API, logando se necessário.
 * Armazena os cookies em $_SESSION['abm_cookies'].
 */
function get_session_autenticada($force_login = false) {
    global $HEADERS_COMMON;
    
    // Se já temos cookies na sessão PHP e não estamos forçando, retorna os cookies
    if (!empty($_SESSION['abm_cookies']) && !$force_login) {
        return $_SESSION['abm_cookies'];
    }

    error_log("🔄 [LOGIN] Iniciando autenticação para usuário: " . ABM_USER . "...");
    
    $payload = [
        "login" => ABM_USER,
        "senha" => ABM_PASS,
        "password" => ABM_PASS // Garantia
    ];
    
    $result = fazer_curl_request(URL_LOGIN, 'POST', $HEADERS_COMMON, $payload);

    if ($result['http_code'] >= 400) {
        error_log("❌ [LOGIN] Erro HTTP " . $result['http_code'] . " ao tentar logar.");
        return null;
    }

    // Verifica se fomos redirecionados de volta para a página de login
    if (str_contains($result['final_url'], 'emp/abmtecnologia') && str_contains(strtolower($result['body']), 'senha')) {
        error_log("❌ [LOGIN] Falha: Credenciais parecem incorretas.");
        return null;
    }

    if (empty($result['cookies'])) {
         error_log("❌ [LOGIN] Falha: Login pareceu funcionar, mas não retornou cookies.");
         return null;
    }
    
    error_log("✅ [LOGIN] Sucesso! Cookies de sessão renovados.");
    
    // Salva os cookies na sessão PHP
    $_SESSION['abm_cookies'] = $result['cookies'];
    return $_SESSION['abm_cookies'];
}

/**
 * Tenta fazer a requisição. Se der erro de permissão, reloga e tenta de novo.
 */
function fazer_requisicao_resiliente($method, $url, $data = null) {
    $cookies = get_session_autenticada(false); // Tenta pegar da sessão
    if (!$cookies) {
        return [null, "Falha no login inicial"];
    }

    global $HEADERS_COMMON;
    
    // Tentativa 1
    $resp = fazer_curl_request($url, $method, $HEADERS_COMMON, $data, $cookies);
    
    // Verifica se a sessão caiu (Redirecionou pro login ou deu 401/403)
    $sessao_expirada = in_array($resp['http_code'], [401, 403]) || str_contains($resp['final_url'], 'emp/abmtecnologia');

    if ($sessao_expirada) {
        error_log("⚠️ [RETRY] Sessão expirada. Tentando relogar e repetir...");
        
        // Força novo login
        $cookies = get_session_autenticada(true);
        if (!$cookies) {
            return [null, "Falha ao renovar login"];
        }

        // Tentativa 2
        $resp = fazer_curl_request($url, $method, $HEADERS_COMMON, $data, $cookies);
        
        // Se falhar de novo, desiste
        if (in_array($resp['http_code'], [401, 403]) || str_contains($resp['final_url'], 'emp/abmtecnologia')) {
             return [null, "Erro final após retry (Sessão ainda inválida)"];
        }
    }
    
    if($resp['http_code'] >= 400) {
        return [null, "Erro HTTP " . $resp['http_code'] . ": " . $resp['body']];
    }

    return [$resp, null];
}


// ==============================================================================
// 🤖 ROTAS DA API
// ==============================================================================

/**
 * Endpoint: /buscar_rastreamento/<placa>
 */
function buscar_rastreamento($placa) {
    header('Content-Type: application/json');
    
    if (empty($placa)) {
        http_response_code(400);
        echo json_encode(["erro" => "Placa inválida"]);
        return;
    }
    
    $placa_clean = strtoupper(str_replace(['-', ' '], '', $placa));
    $data = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
    
    list($resp, $erro) = fazer_requisicao_resiliente('POST', URL_API_RASTREAMENTO, $data);

    if ($erro) {
        http_response_code(500);
        echo json_encode(["erro" => $erro]);
        return;
    }
    
    // A API de rastreamento retorna JSON, então apenas repassamos o corpo
    echo $resp['body'];
}


/**
 * Endpoint: /previsao/<placa>
 */
function previsao_tempo($placa) {
    calcular_rota($placa, "Final");
}

/**
 * Endpoint: /previsaoinicial/<placa>
 */
function previsao_tempo_inicial($placa) {
    calcular_rota($placa, "Inicial");
}

/**
 * Função Auxiliar para não repetir código nas rotas de previsão
 */
function calcular_rota($placa, $tipo_destino = "Final") {
    header('Content-Type: application/json');
    global $HEADERS_DASHBOARD_MAIN;
    
    try {
        // 1. Buscar localização atual (Reusando a função resiliente)
        $placa_clean = strtoupper(str_replace(['-', ' '], '', $placa));
        $data_rastreio = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];
        
        list($resp, $erro) = fazer_requisicao_resiliente('POST', URL_API_RASTREAMENTO, $data_rastreio);
        if ($erro) {
            echo json_encode(["erro" => $erro]);
            return;
        }
        
        $veiculos = json_decode($resp['body'], true);
        if (empty($veiculos) || !is_array($veiculos)) {
            echo json_encode(["erro" => "Veículo não localizado no rastreador."]);
            return;
        }

        $veiculo = $veiculos[0];
        
        // Extrair Lat/Lon do veículo
        $lat1 = 0; $lon1 = 0;
        $loc_text = $veiculo['loc'] ?? '';
        
        if (is_array($loc_text) && count($loc_text) >= 2) {
            $lat1 = (float)$loc_text[0];
            $lon1 = (float)$loc_text[1];
        } elseif (is_string($loc_text) && str_contains($loc_text, ',')) {
            $p = explode(',', str_replace(['[', ']'], '', $loc_text));
            $lat1 = (float)($p[0] ?? 0);
            $lon1 = (float)($p[1] ?? 0);
        } else {
            $lat1 = (float)($veiculo['latitude'] ?? 0);
            $lon1 = (float)($veiculo['longitude'] ?? 0);
        }
        
        if ($lat1 == 0) {
            echo json_encode(["erro" => "Coordenadas do veículo inválidas."]);
            return;
        }

        // 2. Buscar dados da rota (API Principal - Dashboard)
        $resp_dash = fazer_curl_request(URL_DASHBOARD_MAIN, 'GET', $HEADERS_DASHBOARD_MAIN);
        $data_dash = json_decode($resp_dash['body'], true);
        
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
            echo json_encode(["erro" => "Linha não encontrada no dashboard."]);
            return;
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
            echo json_encode(["erro" => "Ponto $tipo_destino não cadastrado na linha."]);
            return;
        }

        // 4. Calcular Rota (OpenRouteService)
        // RECOMENDAÇÃO: Use getenv('ORS_KEY') em produção
        $api_key = getenv("ORS_KEY") ?: "eyJvcmciOiI1YjNjZTM1OTc4NTExMTAwMDFjZjYyNDgiLCJpZCI6ImZjMDdhNWQ0MWUxZDQyYTQ5NzJjMzFmNzcwY2RjMmE3IiwiaCI6Im11cm11cjY0In0=";
        $url_ors = "https://api.openrouteservice.org/v2/directions/driving-car?start={$lon1},{$lat1}&end={$lon2},{$lat2}";
        $headers_ors = ["Authorization: $api_key"];
        
        $res_ors = fazer_curl_request($url_ors, 'GET', $headers_ors);
        $dados_ors = json_decode($res_ors['body'], true);

        if (!isset($dados_ors["features"])) {
            echo json_encode(["erro" => "Erro ao calcular rota externa.", "debug" => $dados_ors]);
            return;
        }

        $resumo = $dados_ors["features"][0]["properties"]["summary"];
        $segundos = $resumo["duration"];
        $metros = $resumo["distance"];

        $horas = (int)($segundos / 3600);
        $minutos = (int)(fmod($segundos, 3600) / 60);
        $km = $metros / 1000;
        
        $tempo_fmt = $horas > 0 ? "{$horas}h {$minutos}min" : "{$minutos} min";
        
        echo json_encode([
            "tempo" => $tempo_fmt,
            "distancia" => number_format($km, 2, ',', '') . " km"
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["erro" => "Erro interno de cálculo: " . $e->getMessage()]);
    }
}


// ==============================================================================
// 🤖 PÁGINA PRINCIPAL (/)
// ==============================================================================

function index() {
    global $HEADERS_DASHBOARD_MAIN;
    
    // Tenta buscar os dados principais
    $response = fazer_curl_request(URL_DASHBOARD_MAIN, 'GET', $HEADERS_DASHBOARD_MAIN);
    
    if ($response['http_code'] >= 400 || empty($response['body'])) {
        echo "Erro ao buscar dados: " . ($response['error'] ?? $response['body']);
        return;
    }
    
    $data = json_decode($response['body'], true);
    if (!$data) {
        echo "Erro: API principal retornou dados inválidos.";
        return;
    }

    $linhas_andamento = $data["linhasAndamento"] ?? [];
    $linhas_desligado = $data["linhasCarroDesligado"] ?? [];
    $linhas_sem_ponto = $data["linhasComecaramSemPrimeiroPonto"] ?? [];
    
    // Processamento dos dados (Conversão da lógica Python)
    $todas_linhas = [];
    $agora = new DateTime(); // Fuso horário do servidor
    
    // **** FIX 1: SUBSTITUÍDO '...' PELO 'array_merge()' ****
    $lista_combinada = array_merge($linhas_andamento, $linhas_desligado, $linhas_sem_ponto);

    foreach ($lista_combinada as $l) {
        
        // Identifica categoria
        if (in_array($l, $linhas_desligado, true)) {
            $l["categoria"] = "Carro desligado";
        } elseif (in_array($l, $linhas_sem_ponto, true)) {
            $l["categoria"] = "Começou sem ponto";
        } else {
            $l["categoria"] = "Em andamento";
        }

        // Pega horário inicial programado
        $horario_inicial = "N/D";
        foreach ($l['pontoDeParadas'] ?? [] as $p) {
            if (($p['tipoPonto']['tipo'] ?? null) == "Inicial" && isset($p['horario'])) {
                $horario_inicial = $p['horario'];
                break;
            }
        }
        $l["horarioProgramado"] = $horario_inicial;

        // Pega horário inicial real
        $horario_real = "N/D";
        foreach ($l['pontoDeParadas'] ?? [] as $p) {
            if (($p['tipoPonto']['tipo'] ?? null) == "Inicial" && isset($p['dataPassouGmt3'])) {
                try {
                    $dt = new DateTime($p["dataPassouGmt3"]);
                    $horario_real = $dt->format("H:i");
                } catch (Exception $e) {
                    $horario_real = "N/D";
                }
                break;
            }
        }
        $l["horarioReal"] = $horario_real;

        // --- LÓGICA DE STATUS REFINADA ---
        $dt_prog = null;
        if ($l["horarioProgramado"] != "N/D") {
            try {
                list($h_prog, $m_prog) = explode(':', $l["horarioProgramado"]);
                $dt_prog = (clone $agora)->setTime((int)$h_prog, (int)$m_prog, 0);
            } catch (Exception $e) {
                $dt_prog = null;
            }
        }

        // CENÁRIO A: O ônibus AINDA NÃO passou no ponto inicial
        if ($l["horarioReal"] == "N/D") {
            if ($dt_prog) {
                if ($dt_prog > $agora) {
                    $l["status_tempo"] = "em_deslocamento"; // Programado é no futuro
                } else {
                    $l["status_tempo"] = "atrasado_sem_inicial"; // Programado já passou
                }
            } else {
                $l["status_tempo"] = "indefinido";
            }
        }
        // CENÁRIO B: O ônibus JÁ passou no ponto inicial
        else {
            if ($dt_prog) {
                try {
                    list($h_real, $m_real) = explode(':', $l["horarioReal"]);
                    $dt_real = (clone $agora)->setTime((int)$h_real, (int)$m_real, 0);
                    
                    $diferenca_segundos = $dt_real->getTimestamp() - $dt_prog->getTimestamp();
                    $diferenca_minutos = $diferenca_segundos / 60;
                    
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
        
        // Pega horário final programado
        $horario_final = "N/D";
        foreach ($l['pontoDeParadas'] ?? [] as $p) {
            if (($p['tipoPonto']['tipo'] ?? null) == "Final" && isset($p['horario'])) {
                $horario_final = $p['horario'];
                break;
            }
        }
        $l["horariofinalProgramado"] = $horario_final;

        // Pega local final
        $lat_final = "N/D"; $lon_final = "N/D";
        foreach ($l['pontoDeParadas'] ?? [] as $p) {
            if (($p['tipoPonto']['tipo'] ?? null) == "Final") {
                $lat_final = $p["latitude"] ?? "N/D";
                $lon_final = $p["longitude"] ?? "N/D";
                break;
            }
        }
        $l["localfinal"] = "(Lat: $lat_final, Lon: $lon_final)";

        // Pega local inicial
        $lat_inicial = "N/D"; $lon_inicial = "N/D";
        foreach ($l['pontoDeParadas'] ?? [] as $p) {
            if (($p['tipoPonto']['tipo'] ?? null) == "Inicial") {
                $lat_inicial = $p["latitude"] ?? "N/D";
                $lon_inicial = $p["longitude"] ?? "N/D";
                break;
            }
        }
        $l["localinicial"] = "(Lat: $lat_inicial, Lon: $lon_inicial)";

        $todas_linhas[] = $l;
    }

    // --- Contagem dos Cards ---
    $qtd_atrasados = 0;
    $qtd_deslocamento = 0;
    $qtd_sem_inicio = 0;
    $qtd_desligados = 0;
    $qtd_pontual = 0;

    foreach ($todas_linhas as $l) {
        $status_tempo = $l['status_tempo'] ?? null;
        $categoria = $l['categoria'] ?? null;

        // 1. Atrasados
        if ($status_tempo === 'atrasado' || $status_tempo === 'atrasado_sem_inicial') {
            $qtd_atrasados++;
        }
        
        // 2. Em Deslocamento
        if ($categoria === 'Em andamento' && $status_tempo === 'em_deslocamento') {
            $qtd_deslocamento++;
        }
        
        // 3. Sem Ponto Inicial
        if ($categoria === 'Começou sem ponto') {
            $qtd_sem_inicio++;
        }
        
        // 4. Desligados
        if ($categoria === 'Carro desligado') {
            $qtd_desligados++;
        }
        
        // 5. Pontuais
        if ($categoria === 'Em andamento' && $status_tempo === 'no_horario') {
            $qtd_pontual++;
        }
    }
    
    $qtd_total = count($todas_linhas);

    // ==========================================================================
    // 6. RENDERIZAÇÃO DO HTML
    // ==========================================================================
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ABM Bus - Dashboard (PHP)</title>
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
        th[onclick]:hover {
            background-color: #1b2e52;
            color: #ffc107;
            user-select: none;
        }
        .modal-content { transition: all 0.3s ease-in-out; }
        .modal-content:hover { transform: scale(1.01); box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1); }
        #alertModalBody h6 { color: #007bff; }
        #alertModalBody p { color: #212529; }
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
                <h3><?= $qtd_total ?></h3>
            </div>
        </div>
       <div class="col-md-2">
            <div class="card-summary card-red">
                <h5>Veículos Atrasados</h5> <h3><?= $qtd_atrasados ?></h3> </div>
        </div>
        <div class="col-md-2">
            <div class="card-summary card-green">
                <h5>Pontual</h5>
                 <h3><?= $qtd_pontual ?></h3>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card-summary bg-secondary">
                <h5>Carros Desligados</h5>
                <h3><?= $qtd_desligados ?></h3>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card-summary bg-info">
                <h5>Em deslocamento</h5>
                <h3><?= $qtd_deslocamento ?></h3>
            </div>
        </div>
        <div class="col-6 col-md">
            <div class="card-summary card-orange">
                <h5>Sem Início</h5>
                <h3><?= $qtd_sem_inicio ?></h3>
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
                                <td><?= htmlspecialchars($linha['empresa']['nome'] ?? 'N/D') ?></td>
                                <td><?= htmlspecialchars($linha['descricaoLinha'] ?? 'N/D') ?></td>
                                <td><?= htmlspecialchars($linha['veiculo']['veiculo'] ?? 'N/D') ?></td>
                                <td><?= htmlspecialchars($linha['horarioProgramado'] ?? 'N/D') ?></td>
                                <td><?= htmlspecialchars($linha['horarioReal'] ?? 'N/D') ?></td>
                                <td><?= htmlspecialchars($linha['nome'] ?? 'DESCONHECIDO') ?></td>
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
                                        onclick="buscarRastreamentoinicial('<?= htmlspecialchars($linha['veiculo']['veiculo'] ?? '') ?>', '<?= str_replace("'", '&#39;', $linha['localinicial'] ?? 'N/D') ?>', this)">
                                        🔍 Calcular
                                    </button>
                                </td>
                                <td>
                                    <button class="btn btn-primary btn-sm"
                                        onclick="buscarRastreamento('<?= htmlspecialchars($linha['veiculo']['veiculo'] ?? '') ?>', '<?= str_replace("'", '&#39;', $linha['localfinal'] ?? 'N/D') ?>', this)">
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
          Previsão de Rota
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
// **** FIX 3: FUNÇÕES MOVIDAS PARA FORA do DOMContentLoaded ****

document.addEventListener("DOMContentLoaded", function() {
    // ---------------------------------------------------------
    // 1. LÓGICA DO ALERTA AUTOMÁTICO
    // ---------------------------------------------------------
    const linhas = <?= json_encode($todas_linhas) ?>;
    
    const atrasadas = linhas.filter(l => {
        // A lógica mais precisa é usar o status que o PHP já calculou
        return l.status_tempo === 'atrasado_sem_inicial';
    });

    if (atrasadas.length > 0) {
        const agrupadas = {};
        atrasadas.forEach(linha => {
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
}); // <-- FIM DO DOMContentLoaded


// ---------------------------------------------------------
// 3. FUNÇÃO DE ORDENAR COLUNAS (Escopo Global)
// ---------------------------------------------------------
let direcaoAtual = {}; 

function ordenarTabela(n) {
    const tabela = document.querySelector("table tbody");
    const linhas = Array.from(tabela.rows);
    
    const asc = !direcaoAtual[n];
    direcaoAtual = {}; // Reseta outras colunas
    direcaoAtual[n] = asc;

    document.querySelectorAll("th i").forEach((i, idx) => {
        i.className = "bi bi-arrow-down-up" + (idx === n ? '' : ' text-muted opacity-50');
    });
    const thAtual = document.querySelectorAll("th")[n];
    const icone = thAtual.querySelector("i");
    if(icone) icone.className = asc ? "bi bi-sort-alpha-down text-white" : "bi bi-sort-alpha-down-alt text-white";

    linhas.sort((a, b) => {
        const valorA = a.cells[n].innerText.trim().toLowerCase();
        const valorB = b.cells[n].innerText.trim().toLowerCase();

        if (valorA === 'n/d' || valorA === '') return 1;
        if (valorB === 'n/d' || valorB === '') return -1;

        const isHora = valorA.includes(':') && !isNaN(parseFloat(valorA.replace(':','.')));
        
        if (isHora) {
            const numA = parseFloat(valorA.replace(':', '.'));
            const numB = parseFloat(valorB.replace(':', '.'));
            return asc ? numA - numB : numB - numA;
        }
        
        return asc ? valorA.localeCompare(valorB) : valorB.localeCompare(valorA);
    });

    linhas.forEach(linha => tabela.appendChild(linha));
}

// ---------------------------------------------------------
// 4. FUNÇÕES DE CÁLCULO DE ROTA (Escopo Global)
// ---------------------------------------------------------

async function buscarRastreamento(placa, localfinal, button) {
    if (!placa) return alert("Veículo não identificado!");
    
    button.disabled = true;
    button.innerHTML = "Calculando...";

    document.getElementById("resultadoConteudo").innerHTML = "<p>Carregando...</p>";
    const modal = new bootstrap.Modal(document.getElementById("popupResultado"));
    modal.show();
    
    document.getElementById("popupResultadoLabel").innerText = `Previsão Ponto Final (Placa: ${placa})`;

    try {
        // **** FIX 4: Removido "index.php/" ****
        const response = await fetch(`buscar_rastreamento/${placa}`);
        const data = await response.json();

        const rotaResp = await fetch(`previsao/${placa}`);
        const rotaData = await rotaResp.json();

        // **** BÔNUS FIX: Escreve resultado no botão ****
        if (rotaData.tempo) {
            button.innerHTML = `${rotaData.tempo}`;
        } else {
            button.innerHTML = "Erro";
            button.classList.remove('btn-primary');
            button.classList.add('btn-danger');
        }

        let html = "";
        if (rotaData.erro) {
            html = `<p class="text-danger"><b>Erro no cálculo da rota:</b> ${rotaData.erro}</p>`;
        } else {
             html += `<h4><span class="badge bg-success">${rotaData.tempo || 'N/D'} (${rotaData.distancia || 'N/D'})</span></h4>`;
        }

        if (Array.isArray(data) && data.length > 0) {
            const veiculo = data[0];
            html += `<ul class="list-group mt-3">
                       <li class="list-group-item"><b>Identificação:</b> ${veiculo.ras_veiculos?.ras_vei_tag_identificacao || veiculo.identificacao || 'N/D'}</li>
                       <li class="list-group-item"><b>Endereço Veículo:</b> ${veiculo.loc || veiculo.endereco || 'N/D'}</li>
                       <li class="list-group-item"><b>Endereço Ponto Final:</b> ${localfinal || 'N/D'}</li>
                     </ul>`;
        } else if (data?.erro) {
            html += `<p class="text-danger mt-3"><b>Erro ao buscar veículo:</b> ${data.erro}</p>`;
        } else {
            html += `<p class="mt-3">Nenhum dado de rastreamento encontrado para este veículo.</p>`;
        }
        
        document.getElementById("resultadoConteudo").innerHTML = html;

    } catch (err) {
        button.innerHTML = "Erro";
        button.classList.remove('btn-primary');
        button.classList.add('btn-danger');
        document.getElementById("resultadoConteudo").innerHTML = `<p class="text-danger">Erro fatal ao buscar dados: ${err.message}</p>`;
        console.error(err);
    }
    // Deixamos o botão desabilitado para mostrar o resultado
}

async function buscarRastreamentoinicial(placa, localinicial, button) {
    if (!placa) return alert("Veículo não identificado!");
    
    button.disabled = true;
    button.innerHTML = "Calculando...";

    document.getElementById("resultadoConteudo").innerHTML = "<p>Carregando...</p>";
    const modal = new bootstrap.Modal(document.getElementById("popupResultado"));
    modal.show();
    
    document.getElementById("popupResultadoLabel").innerText = `Previsão Ponto Inicial (Placa: ${placa})`;

    try {
        // **** FIX 4: Removido "index.php/" ****
        const response = await fetch(`buscar_rastreamento/${placa}`);
        const data = await response.json();

        const rotaResp = await fetch(`previsaoinicial/${placa}`);
        const rotaData = await rotaResp.json();

        // **** BÔNUS FIX: Escreve resultado no botão ****
        if (rotaData.tempo) {
            button.innerHTML = `${rotaData.tempo}`;
        } else {
            button.innerHTML = "Erro";
            button.classList.remove('btn-primary');
            button.classList.add('btn-danger');
        }
        
        let html = "";
        if (rotaData.erro) {
            html = `<p class="text-danger"><b>Erro no cálculo da rota:</b> ${rotaData.erro}</p>`;
        } else {
             html += `<h4><span class="badge bg-success">${rotaData.tempo || 'N/D'} (${rotaData.distancia || 'N/D'})</span></h4>`;
        }
        
        if (Array.isArray(data) && data.length > 0) {
            const veiculo = data[0];
            html += `<ul class="list-group mt-3">
                       <li class="list-group-item"><b>Identificação:</b> ${veiculo.ras_veiculos?.ras_vei_tag_identificacao || veiculo.identificacao || 'N/D'}</li>
                       <li class="list-group-item"><b>Endereço Veículo:</b> ${veiculo.loc || veiculo.endereco || 'N/D'}</li>
                       <li class="list-group-item"><b>Endereço Ponto Inicial:</b> ${localinicial || 'N/D'}</li>
                     </ul>`;
        } else if (data?.erro) {
            html += `<p class="text-danger mt-3"><b>Erro ao buscar veículo:</b> ${data.erro}</p>`;
        } else {
             html += `<p class="mt-3">Nenhum dado de rastreamento encontrado para este veículo.</p>`;
        }

        document.getElementById("resultadoConteudo").innerHTML = html;

    } catch (err) {
        button.innerHTML = "Erro";
        button.classList.remove('btn-primary');
        button.classList.add('btn-danger');
        document.getElementById("resultadoConteudo").innerHTML = `<p class="text-danger">Erro fatal ao buscar dados: ${err.message}</p>`;
        console.error(err);
    }
}
</script>

</body>
</html>

<?php
} // Fim da função index()


// ==============================================================================
// 🚀 ROTEADOR PRINCIPAL (MAIS ROBUSTO - PHP 7+)
// ==============================================================================

// Pega o caminho da URL (ex: "/projeto/previsao/ABC1234")
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);

// Pega o "script name" (ex: "/projeto/index.php")
$script_name = $_SERVER['SCRIPT_NAME']; // ex: /index.php ou /pasta/index.php

// Remove o 'index.php' do script name para ter o base_path (ex: "" ou "/pasta")
$base_path = str_replace('/index.php', '', $script_name);

// Remove o base_path do $path para ter a rota real (ex: "/previsao/ABC1234")
// Versão compatível com PHP 7 (str_starts_with é PHP 8+)
if ($base_path && strpos($path, $base_path) === 0) {
    $path = substr($path, strlen($base_path));
}

// Caso especial: se o $path for /index.php, ele pode ter virado uma string vazia
if ($path === '/index.php') {
     $path = '/';
}

// Se $path for vazio (acesso root), define como "/"
if (empty($path)) {
    $path = '/';
}

// Roteia para a função correta
if (preg_match('#^/buscar_rastreamento/([^/]+)$#', $path, $matches)) {
    buscar_rastreamento($matches[1]);
} elseif (preg_match('#^/previsao/([^/]+)$#', $path, $matches)) {
    previsao_tempo($matches[1]);
} elseif (preg_match('#^/previsaoinicial/([^/]+)$#', $path, $matches)) {
    previsao_tempo_inicial($matches[1]);
} elseif ($path === '/') { // Agora o root ('' ou '/') é tratado
    index(); // Renderiza a página principal
} else {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>O caminho solicitado ($path) não foi encontrado.</p>";
}
?>