<?php



// ==============================================================================

// CONFIGURAÇÃO & CONSTANTES

// ==============================================================================

define('TOMTOM_KEYS', [

    "teste",

    "8iGI69ukjIFE8M5XwE2aVHJOcmRlhfwR",

    "vbdLg3miOthQgBBkTjZyAaj0TmBoyIGv",

    "WquMopwFZcPTeG4s6WkxkzhnMM3w1OGH",

    "ZQx0TqIl2gkDsgF3Yw4G4qwQG6jKp77N",

    "GK7A9HjGG0cOSN1UqADrkifoN0HExUzy"

]);



define('COOKIE_FILE', sys_get_temp_dir() . '/cookie_rastreamento_api.txt');



/**

 * Verifica autenticação rapidamente

 */

function verificar_auth() {

    if (empty($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {

        responder_json(["erro" => "Não autorizado"], 401);

    }

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

 * Realiza o aquecimento da sessão APENAS SE NECESSÁRIO.

 * Regra: Executa 1 vez a cada 20 minutos (1200 segundos) por usuário.

 */

function garantir_aquecimento() {

    global $URL_MAPA;

    

    $agora = time();

    $ultimo_aquecimento = $_SESSION['last_api_warmup'] ?? 0;

    

    // Se nunca aqueceu ou se o último aquecimento foi há mais de 20 minutos

    if (($agora - $ultimo_aquecimento) > 1200) {

        // Faz o request de "aquecimento" para gerar cookies/tokens

        fazer_requisicao_resiliente('GET', $URL_MAPA, null, []);

        

        // Atualiza o timestamp na sessão

        $_SESSION['last_api_warmup'] = $agora;

    }

}



/**

 * Tenta normalizar coordenadas de formatos variados da API

 */

function extrair_coordenadas($veiculo) {

    $lat = 0; $lon = 0;

    

    // Tentativa 1: Array [lat, lon]

    if (isset($veiculo['loc']) && is_array($veiculo['loc']) && count($veiculo['loc']) >= 2) {

        return [(float)$veiculo['loc'][0], (float)$veiculo['loc'][1]];

    }

    

    // Tentativa 2: String "lat,lon"

    if (isset($veiculo['loc']) && is_string($veiculo['loc']) && strpos($veiculo['loc'], ",") !== false) {

        $p = explode(",", str_replace(["[", "]"], "", $veiculo['loc']));

        return [(float)$p[0], (float)$p[1]];

    }



    // Tentativa 3: Campos separados

    $lat = (float)($veiculo['latitude'] ?? 0);

    $lon = (float)($veiculo['longitude'] ?? 0);



    return ($lat != 0 && $lon != 0) ? [$lat, $lon] : null;

}



/**

 * Busca posição do veículo

 */

function get_veiculo_posicao($placa_clean) {

    global $URL_API_RASTREAMENTO;

    

    // Garante que a sessão está "quente" (só executa se necessário)

    garantir_aquecimento();

    

    // Payload padrão

    $payload = ["placa_ou_identificacao" => $placa_clean, "index_view_ft" => "7259"];

    

    // Chamada

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

 * Rotação de chaves TomTom e cálculo de rota

 */

function calcular_rota_tomtom($locations_string) {

    $ultimo_erro = "";

    

    foreach (TOMTOM_KEYS as $key) {

        if ($key === "teste") continue; 



        $url = "https://api.tomtom.com/routing/1/calculateRoute/{$locations_string}/json?key={$key}&traffic=true&travelMode=bus";

        

        $ch = curl_init($url);

        curl_setopt_array($ch, [

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_TIMEOUT => 15,

            CURLOPT_HTTPHEADER => ["Content-Type: application/json"]

        ]);

        

        $body = curl_exec($ch);

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);



        if ($code == 200) {

            $resultado = json_decode($body, true);

            if ($resultado) {

                return $resultado;

            }

        }

        $ultimo_erro = "Status $code";

    }

    

    throw new Exception("Falha TomTom (Todas as chaves esgotadas). Último erro: $ultimo_erro");

}



// ==============================================================================

// HANDLERS (Controladores das Rotas)

// ==============================================================================



/**

 * /buscar_rastreamento/<placa>

 */

function handle_buscar_rastreamento($placa) {

    verificar_auth();

    global $URL_API_RASTREAMENTO;



    $placa_clean = limpar_placa($placa);



    // 1. Aquecimento Inteligente (Só roda na primeira vez ou após expirar)

    garantir_aquecimento();



    // 2. Configuração do Request

    $payload = [

        "placa_ou_identificacao" => $placa_clean, 

        "index_view_ft" => "7259" 

    ];



    $headers = []; 



    // 3. Execução

    list($body, $code, $erro) = fazer_requisicao_resiliente('POST', $URL_API_RASTREAMENTO, $payload, $headers);



    if ($erro) {

        responder_json(["erro" => $erro], 500);

    }



    // 4. Validação

    $json = json_decode($body);

    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {

        // Tenta limpar buffer se houver erro soft, ou força re-aquecimento na próxima

        $_SESSION['last_api_warmup'] = 0; // Força re-aquecimento na próxima tentativa se falhou

        

        responder_json([

            "erro" => "API recusou o formato", 

            "resposta_servidor" => strip_tags(substr($body, 0, 300)),

            "status" => $code

        ], 502);

    }



    http_response_code($code);

    header('Content-Type: application/json; charset=utf-8');

    echo $body;

    exit;

}



/**

 * /previsao/<placa>

 */

function handle_calcular_rota($placa, $tipo_destino = "Final") {

    verificar_auth();

    global $URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN;



    try {

        $placa_clean = limpar_placa($placa);



        // 1. Obter Veículo (Já inclui o aquecimento inteligente dentro da função)

        $veiculo = get_veiculo_posicao($placa_clean);

        $coords_atual = extrair_coordenadas($veiculo);

        

        if (!$coords_atual) {

            responder_json(["erro" => "Coordenadas inválidas recebidas da API."], 422);

        }

        list($lat1, $lon1) = $coords_atual;



        // 2. Obter Dados da Linha (Cache de Arquivo)

        $cache_file = sys_get_temp_dir() . '/dashboard_main_data.json';

        $data_dash = null;



        if (file_exists($cache_file)) {

            $data_dash = json_decode(file_get_contents($cache_file), true);

        }



        if (!$data_dash) {

            list($resp, $code, $err) = simple_get($URL_DASHBOARD_MAIN, $HEADERS_DASHBOARD_MAIN, 30);

            if ($err || $code >= 400) responder_json(["erro" => "Falha API Dashboard"], 500);

            $data_dash = json_decode($resp, true);

        }



        // Busca Linha Específica

        $todas_linhas = array_merge(

            $data_dash['linhasAndamento'] ?? [],

            $data_dash['linhasCarroDesligado'] ?? [],

            $data_dash['linhasComecaramSemPrimeiroPonto'] ?? []

        );



       $linha_alvo = null;
$id_linha_alvo = $_GET['linhaId'] ?? null; // Recebe o ID vindo do JS

// LÓGICA NOVA: Busca Exata pelo ID
if (!empty($id_linha_alvo)) {
    foreach ($todas_linhas as $l) {
        // Verifica se o ID bate (converte para string para garantir)
        if (strval($l['id'] ?? '') === strval($id_linha_alvo)) {
            $linha_alvo = $l;
            break; // Achou exatamente a linha que o usuário está vendo na tela
        }
    }
} 

// FALLBACK: Se não achou pelo ID (ou não foi enviado), tenta pela placa (lógica antiga)
if (!$linha_alvo) {
    foreach ($todas_linhas as $l) {
        if (($l['veiculo']['veiculo'] ?? '') == $placa) {
            $linha_alvo = $l; 
            // Se quiser manter a lógica de "mais recente" aqui como backup, pode manter
            // mas o ideal é que o ID sempre funcione.
            break; 
        }
    }
}

if (!$linha_alvo) responder_json(["erro" => "Linha não encontrada (ID: $id_linha_alvo / Placa: $placa)."], 404);



        // 3. Processar Pontos e Waypoints

        $pontos_mapa = [];

        $tomtom_string_coords = ["$lat1,$lon1"]; 

        $coords_visual = [[$lon1, $lat1]];

        

        $paradas = $linha_alvo['pontoDeParadas'] ?? [];

        

        $ponto_inicial = null;

        $paradas_intermediarias = [];

        $ponto_final = null;



        foreach ($paradas as $p) {

            $plat = (float)($p['latitude'] ?? 0);

            $plng = (float)($p['longitude'] ?? 0);

            if ($plat == 0) continue;



            $tipo = strtolower($p['tipoPonto']['tipo'] ?? '');

            $dados_ponto = [

                "lat" => $plat, "lng" => $plng, 

                "passou" => ($p['passou'] ?? false), 

                "nome" => $p['descricao'] ?? 'Ponto'

            ];



            if ($tipo == 'inicial') $ponto_inicial = $dados_ponto;

            elseif ($tipo == 'final') $ponto_final = $dados_ponto;

            else $paradas_intermediarias[] = $dados_ponto;

        }



        if ($ponto_inicial) $pontos_mapa[] = $ponto_inicial;

        foreach ($paradas_intermediarias as $p) $pontos_mapa[] = $p;

        if ($ponto_final) $pontos_mapa[] = $ponto_final;



        $lat2 = null; $lon2 = null;

        if (!empty($pontos_mapa)) {

            if ($tipo_destino == 'Inicial') {

                $pAlvo = $pontos_mapa[0];

            } else {

                $pAlvo = end($pontos_mapa);

            }

            $lat2 = $pAlvo['lat'];

            $lon2 = $pAlvo['lng'];

        }



        if (!$lat2) responder_json(["erro" => "Destino não determinado."], 400);



        // Lógica de Waypoints

        if ($tipo_destino === 'Inicial') {

            $tomtom_string_coords[] = "$lat2,$lon2";

            $coords_visual[] = [$lon2, $lat2];

        } else {

            $encontrou_destino = false;

            foreach ($pontos_mapa as $p) {

                if (!$p['passou']) {

                    $tomtom_string_coords[] = "{$p['lat']},{$p['lng']}";

                    $coords_visual[] = [$p['lng'], $p['lat']];

                    

                    if ($p['lat'] == $lat2 && $p['lng'] == $lon2) {

                        $encontrou_destino = true;

                    }

                }

            }

            if (!$encontrou_destino) {

                $tomtom_string_coords[] = "$lat2,$lon2";

                $coords_visual[] = [$lon2, $lat2];

            }

        }



        // 4. Calcular Rota TomTom

        $tomtom_data = calcular_rota_tomtom(implode(':', $tomtom_string_coords));



        // 5. Processar Resultados

        $summary = $tomtom_data["routes"][0]["summary"] ?? ["travelTimeInSeconds" => 0, "lengthInMeters" => 0];

        $segundos = (int)$summary["travelTimeInSeconds"];

        $metros = (int)$summary["lengthInMeters"];



        // Cache de Previsão

        if ($tipo_destino == 'Final' && $segundos > 0) {

            $f_cache = sys_get_temp_dir() . '/tomtom_predictions.json';

            $c_data = file_exists($f_cache) ? json_decode(file_get_contents($f_cache), true) : [];

            if (!is_array($c_data)) $c_data = [];

            

            $c_data[$placa] = ['arrival_ts' => time() + $segundos, 'updated_at' => time()];

            file_put_contents($f_cache, json_encode($c_data));

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
