<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório FullTrack - Cache Inteligente</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f6f9; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        h1 { color: #333; text-align: center; margin-bottom: 20px; }
        
        .progress-container { width: 100%; background-color: #e0e0e0; border-radius: 25px; margin: 20px 0; overflow: hidden; }
        .progress-bar { height: 25px; width: 0%; background-color: #28a745; text-align: center; line-height: 25px; color: white; transition: width 0.3s; }
        
        .log-window { background-color: #1e1e1e; color: #00ff00; font-family: 'Consolas', monospace; padding: 15px; height: 400px; overflow-y: scroll; border-radius: 5px; font-size: 13px; border: 1px solid #333; }
        .log-line { margin-bottom: 4px; border-bottom: 1px solid #333; padding-bottom: 2px; }
        
        /* Cores de Log */
        .log-batch { color: #ffeb3b; font-weight: bold; } 
        .log-retry { color: #ff9800; } 
        .log-error { color: #ff4444; }
        .log-cache { color: #00ffff; } /* Ciano para Cache */
        
        #download-area { display: none; text-align: center; margin-top: 20px; }
        .btn-download { background-color: #007bff; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-size: 18px; font-weight: bold; }
        .btn-download:hover { background-color: #0056b3; }
    </style>
</head>
<body>

<div class="container">
    <h1>Relatório FullTrack (Cache + Multi-Thread)</h1>
    
    <div class="progress-container">
        <div id="progressBar" class="progress-bar">0%</div>
    </div>
    
    <div id="statusText" style="text-align:center; margin-bottom: 10px; font-weight:bold;">Iniciando...</div>

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
        div.className = 'log-line ' + (type === 'batch' ? 'log-batch' : (type === 'retry' ? 'log-retry' : (type === 'cache' ? 'log-cache' : (type === 'error' ? 'log-error' : ''))));
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
// --- CONFIGURAÇÕES DO PHP ---
@apache_setenv('no-gzip', 1);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);
set_time_limit(0); 

// --- GERENCIAMENTO DE CACHE ---
$cacheFile = 'cache_enderecos.json';
$addressCache = [];

if (file_exists($cacheFile)) {
    $content = file_get_contents($cacheFile);
    $addressCache = json_decode($content, true) ?? [];
}

// Salva o cache ao final da execução (shutdown function) para garantir
register_shutdown_function(function() use (&$addressCache, $cacheFile) {
    file_put_contents($cacheFile, json_encode($addressCache));
});

function getCacheKey($lat, $lon) {
    // Arredonda para 5 casas decimais (~1.1m) para aumentar a chance de "Hit"
    // se o carro moveu milimetros.
    return round((float)$lat, 5) . '_' . round((float)$lon, 5);
}

// --- FUNÇÕES AUXILIARES ---
function sendUpdate($script) {
    echo "<script>$script</script>";
    echo str_pad('', 4096) . "\n";
    flush();
}
function sendLog($msg, $type = 'normal') {
    $cleanMsg = addslashes($msg);
    sendUpdate("addLog('$cleanMsg', '$type');");
}
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
    $end = implode("", $parts);
    $end = str_replace(", ,", ",", $end);
    return trim($end, " -,");
}

// --- LÓGICA DE NEGÓCIO ---

sendLog("🚀 Iniciando script com Sistema de Cache...", "normal");
sendLog("📂 Cache carregado: " . count($addressCache) . " endereços conhecidos.", "cache");

$yesterday = new DateTime('yesterday');
$dd = $yesterday->format('d');
$mm = $yesterday->format('m');
$yyyy = $yesterday->format('Y');
$dt_inicial = $yesterday->format('d/m/Y') . " 00:00:00";
$dt_final = $yesterday->format('d/m/Y') . " 23:59:59";

// 1. FullTrack Request
sendLog("📡 Consultando API FullTrack...", "normal");
$urlFullTrack = "https://api-fulltrack4.fulltrackapp.com/relatorio/DriverBehavior/gerar/";
$postData = [
    'search' => '', 'order' => 'asc', 'id_cliente' => '195577', 'id_ativo' => '', 
    'id_motorista' => '0', 'timezone' => 'America/Sao_Paulo',
    'dt_inicial' => $dt_inicial, 'dt_final' => $dt_final,
    'id_grupo' => '0', 'idioma' => 'pt-BR', 'id_indice' => '7259', 
    'id_usuario' => '250095', 'unidade_temperatura' => 'celsius', 
    'unidade_volume' => 'litro', 'unidade_comprimento' => 'quilometro', 
    'cerca' => '0', 'visualizar_por' => 'ativo'
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $urlFullTrack);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "authorization: Bearer a20cc5894d63a3eda08a1866c289b2c9b3ce2222",
    "content-type: application/x-www-form-urlencoded",
    "user-agent: PHP Script"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    sendLog("❌ Erro FullTrack: $httpCode", "error");
    exit;
}

$json = json_decode($response, true);
$allItems = [];

// 2. Flattening Data
if (!empty($json) && is_array($json)) {
    foreach ($json as $item) {
        if (!isset($item['sub_table'])) continue;
        $descricao_ativo = $item['descricao_ativo'] ?? '';
        $tag_ativo = $item['tag_ativo'] ?? '';
        $total_infracoes_geral = $item['total_infracoes'] ?? 0;
        $total_penalidade_geral = $item['total_penalidade'] ?? 0;

        foreach ($item['sub_table'] as $sub) {
            $motorista = $sub['descricao_motorista'] ?? '';
            if (!isset($sub['sub_table_infracao'])) continue;
            foreach ($sub['sub_table_infracao'] as $infType) {
                if ($infType['tipo_infracao'] === "Motor Ocioso" || $infType['tipo_infracao'] === "Banguela") continue;
                
                if (!isset($infType['infracoes'])) continue;
                foreach ($infType['infracoes'] as $detalhe) {
                    $lat = converterCoordenada($detalhe['endereco']['lat'] ?? null);
                    $lon = converterCoordenada($detalhe['endereco']['lon'] ?? null);

                    $allItems[] = [
                        'Codigo' => $descricao_ativo,
                        'Motorista' => $motorista,
                        'Num_Infracoes_Geral' => $total_infracoes_geral,
                        'Num_Penalidades_Geral' => $total_penalidade_geral,
                        'Descricao_Ativo' => $descricao_ativo,
                        'Placa' => $tag_ativo,
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
sendLog("📋 Linhas a processar: $totalRows");

// 3. Setup CSV
$filename = 'relatorio_' . $yyyy . '-' . $mm . '-' . $dd . '.csv';
$fp = fopen($filename, 'w');
fputs($fp, "\xEF\xBB\xBF");
$csvHeader = [
    "Código", "Motorista", "Número de infrações", "Número de penalidades",
    "Descrição do ativo", "Placa", "Número de infrações", "Número de penalidades",
    "Tipo da infração", "Infração", "Penalidades", "Data", 
    "Latitude", "Longitude", "Velocidade", "Penalidade", "Endereço"
];
fputcsv($fp, $csvHeader, ";");

// 4. Processing Loop
$tokens = [
    "pk.5dec6e778adac747992ee2564e7a57e1", "pk.74da1c80d1fdd103198bb2729dfc24b9",
    "pk.b499114f3bbfce0fae1e2894592036e6", "pk.3e95e7ff6651414b8b219ebca39f9309"
];
$tokenCount = count($tokens);
$chunks = array_chunk($allItems, $tokenCount);
$processedCount = 0;
$cacheHitCount = 0;

foreach ($chunks as $batch) {
    $mh = curl_multi_init();
    $curlHandles = [];
    $results = []; // Armazena resultados temporários (Cache ou Curl)

    // A. PREPARA O LOTE (Verifica Cache Primeiro)
    foreach ($batch as $key => $row) {
        $lat = $row['Latitude'];
        $lon = $row['Longitude'];
        $cacheKey = getCacheKey($lat, $lon);

        if ($lat == 0 || $lon == 0) {
            $results[$key] = ['type' => 'invalid', 'val' => "Coordenadas Inválidas"];
            continue;
        }

        // CHECAGEM DE CACHE
        if (isset($addressCache[$cacheKey])) {
            $results[$key] = ['type' => 'cache', 'val' => $addressCache[$cacheKey]];
            $cacheHitCount++;
        } else {
            // Se não tem no cache, prepara o Curl
            $tokenIndex = $key % $tokenCount; 
            $token = $tokens[$tokenIndex];
            $urlGeo = "https://us1.locationiq.com/v1/reverse?key={$token}&lat={$lat}&lon={$lon}&format=json";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urlGeo);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 4);
            curl_multi_add_handle($mh, $ch);
            
            // Guarda referência para processar depois
            $curlHandles[$key] = [
                'ch' => $ch, 
                'tokenIdx' => $tokenIndex, 
                'cacheKey' => $cacheKey
            ];
            $results[$key] = ['type' => 'pending'];
        }
    }

    // B. EXECUTA CURL (Apenas para os que não estavam no cache)
    if (!empty($curlHandles)) {
        $running = null;
        do { curl_multi_exec($mh, $running); } while ($running);
    }

    // C. PROCESSA RESULTADOS E RETENTATIVAS
    foreach ($batch as $key => $row) {
        $finalAddr = "Endereço não encontrado";
        
        // Se veio do cache
        if ($results[$key]['type'] === 'cache') {
            $finalAddr = $results[$key]['val'];
        }
        // Se era inválido
        elseif ($results[$key]['type'] === 'invalid') {
            $finalAddr = $results[$key]['val'];
        }
        // Se foi via API
        elseif (isset($curlHandles[$key])) {
            $ch = $curlHandles[$key]['ch'];
            $respGeo = curl_multi_getcontent($ch);
            $codeGeo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cacheKey = $curlHandles[$key]['cacheKey'];
            
            if ($codeGeo === 200) {
                $jsonGeo = json_decode($respGeo, true);
                $finalAddr = formatAddress($jsonGeo);
                // SALVA NO CACHE
                $addressCache[$cacheKey] = $finalAddr;
            } else {
                // LOGICA DE RETRY (Simplificada para manter concisão)
                sendLog("⚠️ Falha API (HTTP $codeGeo). Tentando recuperar...", "retry");
                $retrySuccess = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    $tIdx = ($curlHandles[$key]['tokenIdx'] + $attempt) % $tokenCount;
                    $tk = $tokens[$tIdx];
                    usleep(500000); // 0.5s wait
                    
                    $urlR = "https://us1.locationiq.com/v1/reverse?key={$tk}&lat={$row['Latitude']}&lon={$row['Longitude']}&format=json";
                    $chR = curl_init($urlR);
                    curl_setopt($chR, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chR, CURLOPT_TIMEOUT, 5);
                    $respR = curl_exec($chR);
                    if (curl_getinfo($chR, CURLINFO_HTTP_CODE) === 200) {
                        $finalAddr = formatAddress(json_decode($respR, true));
                        $addressCache[$cacheKey] = $finalAddr; // Salva no cache
                        $retrySuccess = true;
                        sendLog("✅ Recuperado!", "retry");
                        curl_close($chR);
                        break;
                    }
                    curl_close($chR);
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        // Escreve Linha no CSV
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
    
    if (!empty($curlHandles)) {
        curl_multi_close($mh);
    }

    // Atualização Visual
    $percent = ($processedCount / $totalRows) * 100;
    sendUpdate("updateProgress($percent, 'Processando... (Cache Hits: $cacheHitCount)');");
    
    if ($processedCount % 8 === 0) {
        // Log inteligente: Mostra se foi cache ou API
        $source = ($results[array_key_last($batch)]['type'] === 'cache') ? "[CACHE]" : "[API]";
        sendLog("$source Lote processado.", $source === "[CACHE]" ? "cache" : "batch");
    }
}

fclose($fp);

// Salva o cache atualizado no disco
file_put_contents($cacheFile, json_encode($addressCache));
sendLog("💾 Cache salvo no disco ($cacheFile)", "cache");

sendUpdate("finishProcess('$filename', '$filename');");
?>

</body>
</html>
