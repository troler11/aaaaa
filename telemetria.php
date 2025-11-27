<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório FullTrack - Integrado</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #333; text-align: center; margin-bottom: 20px; }
        
        .progress-container { width: 100%; background-color: #e0e0e0; border-radius: 25px; margin: 20px 0; overflow: hidden; }
        .progress-bar { height: 25px; width: 0%; background-color: #28a745; text-align: center; line-height: 25px; color: white; transition: width 0.3s; }
        
        .log-window { background-color: #1e1e1e; color: #00ff00; font-family: 'Consolas', monospace; padding: 15px; height: 450px; overflow-y: scroll; border-radius: 5px; font-size: 13px; border: 1px solid #333; }
        .log-line { margin-bottom: 4px; border-bottom: 1px solid #333; padding-bottom: 2px; }
        
        /* Cores de Log */
        .log-auth { color: #ff00ff; font-weight: bold; } /* Magenta para Login */
        .log-batch { color: #ffeb3b; font-weight: bold; } 
        .log-retry { color: #ff9800; } 
        .log-error { color: #ff4444; }
        .log-cache { color: #00ffff; } 
        
        #download-area { display: none; text-align: center; margin-top: 20px; }
        .btn-download { background-color: #007bff; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold; }
        .btn-download:hover { background-color: #0056b3; }
    </style>
</head>
<body>

<div class="container">
    <h1>Relatório Integrado (Login + Geocodificação)</h1>
    
    <div class="progress-container">
        <div id="progressBar" class="progress-bar">0%</div>
    </div>
    
    <div id="statusText" style="text-align:center; margin-bottom: 10px; font-weight:bold;">Aguardando início...</div>

    <div class="log-window" id="logWindow"></div>

    <div id="download-area">
        <a id="downloadLink" href="#" class="btn-download" download>📥 Baixar Planilha CSV</a>
    </div>
</div>

<script>
    function updateProgress(percent, text) {
        const bar = document.getElementById('progressBar');
        bar.style.width = percent + '%';
        bar.innerText = Math.floor(percent) + '%';
        if(text) document.getElementById('statusText').innerText = text;
    }

    function addLog(message, type = 'normal') {
        const logWindow = document.getElementById('logWindow');
        const div = document.createElement('div');
        div.className = 'log-line ' + (type === 'auth' ? 'log-auth' : (type === 'batch' ? 'log-batch' : (type === 'retry' ? 'log-retry' : (type === 'cache' ? 'log-cache' : (type === 'error' ? 'log-error' : '')))));
        div.innerText = `[${new Date().toLocaleTimeString()}] ${message}`;
        logWindow.appendChild(div);
        logWindow.scrollTop = logWindow.scrollHeight;
    }

    function finishProcess(fileUrl, fileName) {
        document.getElementById('downloadLink').href = fileUrl;
        document.getElementById('downloadLink').setAttribute('download', fileName);
        document.getElementById('download-area').style.display = 'block';
        document.getElementById('statusText').innerText = "Processamento Concluído!";
        document.getElementById('statusText').style.color = "green";
    }
</script>

<?php
// --- 0. CONFIGURAÇÕES GERAIS ---
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);
set_time_limit(0); 

// Credenciais FullTrack / ABM
$ABM_USER = "lucas";
$ABM_PASS = "Lukinha2009";

$URL_BASE  = "https://abmtecnologia.abmprotege.net";
$URL_LOGIN = $URL_BASE . "/emp/abmtecnologia";
$URL_MAPA  = $URL_BASE . "/mapaGeral"; 

// Arquivos Locais
$COOKIE_FILE = sys_get_temp_dir() . '/abm_session_cookie.txt';
$CACHE_FILE  = 'cache_enderecos.json';

// Headers Comuns
$HEADERS_COMMON = [
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36",
    "Accept: application/json, text/javascript, */*; q=0.01",
    "Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7"
];

// Tokens LocationIQ (Rotação)
$LOCATION_TOKENS = [
    "pk.5dec6e778adac747992ee2564e7a57e1", "pk.74da1c80d1fdd103198bb2729dfc24b9",
    "pk.b499114f3bbfce0fae1e2894592036e6", "pk.3e95e7ff6651414b8b219ebca39f9309"
];

// --- 1. FUNÇÕES VISUAIS (JS) ---
function sendUpdate($script) {
    echo "<script>$script</script>";
    echo str_pad('', 4096) . "\n";
    flush();
}
function sendLog($msg, $type = 'normal') {
    $cleanMsg = addslashes($msg);
    sendUpdate("addLog('$cleanMsg', '$type');");
}

// --- 2. FUNÇÕES DE REDE E AUTENTICAÇÃO ---

function curl_with_session($metodo, $url, $cookieFile, $headers, $data = null, $timeout = 15) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    
    // Cookie Jar/File: Onde salvar e onde ler a sessão
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($metodo == 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data !== null) {
            $payload = is_array($data) ? http_build_query($data) : $data;
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $url_final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $err = curl_error($ch);
    curl_close($ch);

    return [$resp, $code, $url_final, $err];
}

function realizar_login() {
    global $URL_LOGIN, $ABM_USER, $ABM_PASS, $HEADERS_COMMON, $COOKIE_FILE, $URL_MAPA;
    
    if (file_exists($COOKIE_FILE)) unlink($COOKIE_FILE); // Limpa sessão antiga
    
    sendLog("🔑 Realizando Login na ABM...", "auth");
    
    $payload = ["login" => $ABM_USER, "senha" => $ABM_PASS, "password" => $ABM_PASS];
    $headers = array_merge($HEADERS_COMMON, ["Content-Type: application/x-www-form-urlencoded"]);
    
    list($resp, $code, $url_final, $err) = curl_with_session('POST', $URL_LOGIN, $COOKIE_FILE, $headers, $payload);
    
    if ($err || (strpos($url_final, "erro") !== false)) {
        sendLog("❌ Falha no Login: " . ($err ?: "Credenciais inválidas"), "error");
        return false;
    }
    
    // Aquece sessão
    curl_with_session('GET', $URL_MAPA, $COOKIE_FILE, $HEADERS_COMMON);
    sendLog("✅ Login efetuado com sucesso!", "auth");
    return true;
}

function request_resiliente($metodo, $url, $data = null, $extraHeaders = []) {
    global $COOKIE_FILE, $HEADERS_COMMON;
    
    // Garante sessão
    if (!file_exists($COOKIE_FILE)) {
        if (!realizar_login()) exit;
    }

    $headers = array_merge($HEADERS_COMMON, $extraHeaders);
    
    // Tentativa 1
    list($resp, $code, $url_final, $err) = curl_with_session($metodo, $url, $COOKIE_FILE, $headers, $data);
    
    // Verifica se sessão caiu (401 ou redirect para login)
    if ($code == 401 || $code == 403 || strpos($url_final, "emp/abmtecnologia") !== false) {
        sendLog("⚠️ Sessão expirada. Renovando...", "auth");
        if (realizar_login()) {
            // Tentativa 2
            list($resp, $code, $url_final, $err) = curl_with_session($metodo, $url, $COOKIE_FILE, $headers, $data);
        }
    }
    
    return [$resp, $code];
}

// --- 3. FUNÇÕES UTILITÁRIAS ---
function converterCoordenada($valor) {
    if (empty($valor) || is_array($valor)) return 0.0;
    $str = str_replace(',', '.', (string)$valor);
    $str = preg_replace('/[^0-9\.-]/', '', $str);
    return floatval($str);
}
function formatAddress($json) {
    $add = $json['address'] ?? [];
    $parts = [];
    if (!empty($add['road'])) $parts[] = $add['road'];
    if (!empty($add['house_number'])) $parts[] = $add['house_number'];
    if (!empty($add['suburb'])) $parts[] = " - " . $add['suburb'];
    if (!empty($add['city'])) $parts[] = ", " . $add['city'];
    if (empty($parts)) return "Endereço não encontrado (Sem dados)";
    return trim(str_replace(", ,", ",", implode("", $parts)), " -,");
}
function getCacheKey($lat, $lon) { return round((float)$lat, 5) . '_' . round((float)$lon, 5); }

// ==============================================================================
// 4. FLUXO PRINCIPAL
// ==============================================================================

sendLog("🚀 Iniciando Script Integrado...", "normal");

// A. Carregar Cache
$addressCache = [];
if (file_exists($CACHE_FILE)) {
    $addressCache = json_decode(file_get_contents($CACHE_FILE), true) ?? [];
}
// Shutdown function para salvar cache mesmo se der erro
register_shutdown_function(function() use (&$addressCache, $CACHE_FILE) {
    file_put_contents($CACHE_FILE, json_encode($addressCache));
});
sendLog("📂 Cache carregado: " . count($addressCache) . " endereços.", "cache");

// B. Definir Datas
$yesterday = new DateTime('yesterday');
$dd = $yesterday->format('d');
$mm = $yesterday->format('m');
$yyyy = $yesterday->format('Y');
$dt_inicial = $yesterday->format('d/m/Y') . " 00:00:00";
$dt_final = $yesterday->format('d/m/Y') . " 23:59:59";

// C. Requisitar Relatório (Usando Login Automático)
sendLog("📡 Buscando dados da FullTrack...", "normal");
$urlFullTrack = "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/";
$postData = [
    'search' => '', 'order' => 'asc', 'id_cliente' => '195577', 'id_ativo' => '', 
    'id_motorista' => '0', 'timezone' => 'America/Sao_Paulo',
    'dt_inicial' => $dt_inicial, 'dt_final' => $dt_final,
    'id_grupo' => '0', 'idioma' => 'pt-BR', 'id_indice' => '7259', 
    'id_usuario' => '250095', 'unidade_temperatura' => 'celsius', 
    'unidade_volume' => 'litro', 'unidade_comprimento' => 'quilometro', 
    'cerca' => '0', 'visualizar_por' => 'motorista'
];

// Adiciona Bearer Token junto com Session Cookie para garantir acesso
$extraHeaders = [
    "Authorization: Bearer a20cc5894d63a3eda08a1866c289b2c9b3ce2222", 
    "Origin: https://abmtecnologia.abmprotege.net"
];

// CHAMADA DA API RESILIENTE
list($response, $httpCode) = request_resiliente('POST', $urlFullTrack, $postData, $extraHeaders);

if ($httpCode !== 200 || !$response) {
    sendLog("❌ Erro Fatal API FullTrack: $httpCode", "error");
    exit;
}

$json = json_decode($response, true);
$allItems = [];

// D. Planificar Dados (Flatten)
if (!empty($json) && is_array($json)) {
    foreach ($json as $item) {
        if (!isset($item['sub_table'])) continue;
        foreach ($item['sub_table'] as $sub) {
            if (!isset($sub['sub_table_infracao'])) continue;
            foreach ($sub['sub_table_infracao'] as $infType) {
                if ($infType['tipo_infracao'] === "Motor Ocioso" || $infType['tipo_infracao'] === "Banguela") continue;
                if (!isset($infType['infracoes'])) continue;
                foreach ($infType['infracoes'] as $detalhe) {
                    $lat = converterCoordenada($detalhe['endereco']['lat'] ?? null);
                    $lon = converterCoordenada($detalhe['endereco']['lon'] ?? null);
                    $allItems[] = [
                        'Codigo' => $item['descricao_ativo'] ?? '',
                        'Motorista' => $sub['descricao_motorista'] ?? '',
                        'Num_Infracoes_Geral' => $item['total_infracoes'] ?? 0,
                        'Num_Penalidades_Geral' => $item['total_penalidade'] ?? 0,
                        'Descricao_Ativo' => $item['descricao_ativo'] ?? '',
                        'Placa' => $item['tag_ativo'] ?? '',
                        'Num_Infracoes_Tipo' => $infType['total_infracoes'] ?? 0,
                        'Num_Penalidades_Tipo' => $infType['total_penalidade'] ?? 0,
                        'Tipo_Infracao' => $infType['tipo_infracao'],
                        'Infracao_ID' => $detalhe['id_infracao'] ?? '',
                        'Penalidade_Detalhe' => $detalhe['penalidade'] ?? '',
                        'Data' => $detalhe['data'] ?? '',
                        'Latitude' => $lat,
                        'Longitude' => $lon,
                        'Velocidade' => $detalhe['velocidade'] ?? '',
                        'Penalidade_Final' => $detalhe['penalidade'] ?? '',
                        'Endereco' => 'Buscando...'
                    ];
                }
            }
        }
    }
}

$totalRows = count($allItems);
sendLog("📋 Dados Processados: $totalRows infrações encontradas.", "normal");

// E. Preparar CSV
$filename = 'relatorio_' . $yyyy . '-' . $mm . '-' . $dd . '.csv';
$fp = fopen($filename, 'w');
fputs($fp, "\xEF\xBB\xBF");
fputcsv($fp, [
    "Código", "Motorista", "Número de infrações", "Número de penalidades",
    "Descrição do ativo", "Placa", "Número de infrações", "Número de penalidades",
    "Tipo da infração", "Infração", "Penalidades", "Data", 
    "Latitude", "Longitude", "Velocidade", "Penalidade", "Endereço"
], ";");

// F. Processamento (Cache + Multi-Curl)
$chunks = array_chunk($allItems, count($LOCATION_TOKENS));
$processedCount = 0;
$cacheHitCount = 0;

foreach ($chunks as $batch) {
    $mh = curl_multi_init();
    $curlHandles = [];
    $results = []; 

    // Verifica Cache
    foreach ($batch as $key => $row) {
        $lat = $row['Latitude'];
        $lon = $row['Longitude'];
        $cacheKey = getCacheKey($lat, $lon);

        if ($lat == 0 || $lon == 0) {
            $results[$key] = ['type' => 'invalid', 'val' => "N/A"];
            continue;
        }

        if (isset($addressCache[$cacheKey])) {
            $results[$key] = ['type' => 'cache', 'val' => $addressCache[$cacheKey]];
            $cacheHitCount++;
        } else {
            // Setup Curl
            $tIdx = $key % count($LOCATION_TOKENS); 
            $token = $LOCATION_TOKENS[$tIdx];
            $urlGeo = "https://us1.locationiq.com/v1/reverse?key={$token}&lat={$lat}&lon={$lon}&format=json";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlGeo);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_multi_add_handle($mh, $ch);
            
            $curlHandles[$key] = ['ch' => $ch, 'tokenIdx' => $tIdx, 'cacheKey' => $cacheKey];
            $results[$key] = ['type' => 'pending'];
        }
    }

    // Executa API
    if (!empty($curlHandles)) {
        $running = null;
        do { curl_multi_exec($mh, $running); } while ($running);
    }

    // Processa Resultados
    foreach ($batch as $key => $row) {
        $finalAddr = "Endereço não encontrado";
        
        if ($results[$key]['type'] === 'cache' || $results[$key]['type'] === 'invalid') {
            $finalAddr = $results[$key]['val'];
        }
        elseif (isset($curlHandles[$key])) {
            $ch = $curlHandles[$key]['ch'];
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cacheKey = $curlHandles[$key]['cacheKey'];
            
            if ($code === 200) {
                $finalAddr = formatAddress(json_decode(curl_multi_getcontent($ch), true));
                $addressCache[$cacheKey] = $finalAddr;
            } else {
                // Retry Simples
                $retryToken = $LOCATION_TOKENS[($curlHandles[$key]['tokenIdx'] + 1) % count($LOCATION_TOKENS)];
                $urlR = "https://us1.locationiq.com/v1/reverse?key={$retryToken}&lat={$row['Latitude']}&lon={$row['Longitude']}&format=json";
                $respR = file_get_contents($urlR, false, stream_context_create(['http'=>['timeout'=>5]]));
                if ($respR) {
                    $finalAddr = formatAddress(json_decode($respR, true));
                    $addressCache[$cacheKey] = $finalAddr;
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        // CSV
        fputcsv($fp, [
            $row['Codigo'], $row['Motorista'], $row['Num_Infracoes_Geral'], $row['Num_Penalidades_Geral'],
            $row['Descricao_Ativo'], $row['Placa'], $row['Num_Infracoes_Tipo'], $row['Num_Penalidades_Tipo'],
            $row['Tipo_Infracao'], $row['Infracao_ID'], $row['Penalidade_Detalhe'], $row['Data'],
            str_replace('.', ',', $row['Latitude']), 
            str_replace('.', ',', $row['Longitude']),
            $row['Velocidade'], $row['Penalidade_Final'],
            $finalAddr
        ], ";");
        $processedCount++;
    }
    
    if (!empty($curlHandles)) curl_multi_close($mh);

    // Update Visual
    $pct = ($processedCount / $totalRows) * 100;
    sendUpdate("updateProgress($pct, 'Processando... (Cache: $cacheHitCount)');");
    if ($processedCount % 8 == 0) sendLog("Lote processado.", "batch");
}

fclose($fp);
// Salva Cache (Força final)
file_put_contents($CACHE_FILE, json_encode($addressCache));

sendLog("💾 Cache salvo. Sucesso!", "cache");
sendUpdate("finishProcess('$filename', '$filename');");
?>
</body>
</html>
