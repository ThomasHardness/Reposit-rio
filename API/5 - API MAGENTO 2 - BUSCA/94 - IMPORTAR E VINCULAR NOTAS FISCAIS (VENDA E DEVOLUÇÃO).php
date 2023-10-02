<?php

namespace hardness;
global $g;

require_once('bibliotecas/classes/VEN003.php');
$VEN003 = new VEN003();

if (isset($r_arrInfo)) 
{
    // Processo manual (botão)
    $numPedidos     = 1;
    $T005_Id        = $r_arrInfo['T005_Id'];
    $T006_Id        = $r_arrInfo['T006_Id'];
    $arr_xml        = $r_arrInfo['arr_xml'];
    $chNFe          = $r_arrInfo['chNFe'];
    $refNFe         = $r_arrInfo['refNFe'];
    $marketplace    = $r_marketplace;
    $tipoProcesso   = $r_tipoProcesso;
    $transportadora = $r_transportadora;
    $arquivoLog     = $r_arquivoLog;    
}
else 
{    
    // Processo automático (crontab)
    $g['empresaAtual'] = 4; // Evita problema do CNPJ vazio ou de outra empresa
    
    $marketplace    = $parametros['marketplace'];
    $tipoProcesso   = $parametros['tipoProcesso'];
    $transportadora = $parametros['transportadora'];
    $arquivoLog     = $parametros['arquivoLog'];

    if ($tipoProcesso == 'venda')
    {
        $LJ1 = "LEFT JOIN P001 AS P001_Venda ON P001_Venda.P001_T005_Id = T005_Id AND P001_Venda.P001_Natureza_Operacao = 'Venda de mercadorias'";
        $WH1 = "AND P001_Venda.P001_T005_Id IS NULL";

        $LJ2 = "LEFT JOIN P001 AS P001_Retor ON P001_Retor.P001_T005_Id = T005_Id AND P001_Retor.P001_Natureza_Operacao = 'Retorno Simbolico de Deposito Temporario'";     
        $WH2 = "AND P001_Retor.P001_T005_Id IS NULL";
    }
    else
    {
        // Devolução
        $LJ1 = "LEFT JOIN P001 AS P001_Devol ON P001_Devol.P001_T005_Id = T005_Id AND P001_Devol.P001_Natureza_Operacao = 'Retorno de mercadoria nao entregue'";
        $WH1 = "AND P001_Devol.P001_T005_Id IS NULL";

        $LJ2 = "LEFT JOIN P001 AS P001_Remes ON P001_Remes.P001_T005_Id = T005_Id AND P001_Remes.P001_Natureza_Operacao = 'Outras Saidas - Remessa Simbolica para Deposito Temporario'";     
        $WH2 = "AND P001_Remes.P001_T005_Id IS NULL";
    }

    $T005 = "SELECT                 
                T005_Id,
                T006_Id,             
                JSON_UNQUOTE(T005A_JSON->'$.{$tipoProcesso}_arr_xml') AS 'arr_xml',
                JSON_UNQUOTE(T005A_JSON->'$.{$tipoProcesso}_chNFe') AS 'chNFe',
                JSON_UNQUOTE(T005A_JSON->'$.{$tipoProcesso}_refNFe') AS 'refNFe'
            FROM 
                T005
                LEFT JOIN T006 ON T006_T005_Id = T005_Id 
                LEFT JOIN D022 ON D022_Id = T005_D022_Id 
                LEFT JOIN T005A ON T005A_T005_Id = T005_Id 
                {$LJ1}
                {$LJ2}
            WHERE 
                D022_Nome_Empresa = '{$transportadora}'
                {$WH1}
                {$WH2}
                AND T005A_JSON->'$.{$tipoProcesso}_arr_xml' IS NOT NULL
                AND T005A_JSON->'$.{$tipoProcesso}_chNFe' IS NOT NULL
                AND T005A_JSON->'$.{$tipoProcesso}_refNFe' IS NOT NULL
            GROUP BY 
                T005_Id";
    $mT005 = mysql_query($T005);    
    $numPedidos = mysql_num_rows($mT005);
}

$caminhoFulfillment = $g['pathDados'] . "tmp/FULFILLMENT/{$marketplace}/NOTAS/";
if (!is_dir($caminhoFulfillment)) 
{
    mkdir($caminhoFulfillment, 0777, true);
}

if ($numPedidos > 0) // Caso exista ao menos um pedido para ser avaliado
{
    for ($i = 1; $i <= $numPedidos; $i++)
    {
        if (!isset($r_arrInfo)) // Somente se for automático, atualizar os dados conforme as linhas passam
        {
            $linha   = mysql_fetch_assoc($mT005);
            $T005_Id = $linha['T005_Id'];
            $T006_Id = $linha['T006_Id'];
            $arr_xml = $linha['arr_xml'];
            $chNFe   = $linha['chNFe'];
            $refNFe  = $linha['refNFe'];
        }

        $agora = date('Y-m-d H:i:s'); // Usado para salvar o valor no banco

        $log[] = date('d/m/y H:i:s');
        $log[] = '';
        $log[] = "Pedido: {$T005_Id}";
        $log[] = "Chave nota atual: {$chNFe}";

        $novaNFRef = false;
        if ($tipoProcesso == 'devolucao')
        {
            // $refNFe tem que ser trocado para a nota de devolução apontar para a remessa simbólica, e não a de venda
            $P001 = "SELECT 
                        P001_Chave_Acesso_NFe,
                        P001_Natureza_Operacao 
                    FROM 
                        P001 
                    WHERE 
                        P001_Chave_Acesso_NFe_Referenciada = '{$chNFe}'";
            $mP001 = mysql_query($P001);
            if (mysql_num_rows($mP001) > 0)
            {
                $novaNFRef = true;
                $linha2 = mysql_fetch_assoc($mP001);
                $refNFe   = $linha2['P001_Chave_Acesso_NFe'];
                $natOpRef = $linha2['P001_Natureza_Operacao'];
                $log[] = 'A nota de referencia da devolucao foi alterada para uma nota de remessa simbolica!';
            }
            else
            {                               
                $log[] = 'A remessa simbolica para essa nota de devolucao ainda nao foi encontrada! A nota de referencia permanece a de venda.';
            }
        }       
        $log[] = "Chave nota referenciada: {$refNFe}";

        $arqvTempAtual = $caminhoFulfillment . "tempXML_{$chNFe}.xml";
        $arqvTempRef   = $caminhoFulfillment . "tempXML_{$refNFe}.xml";

        if (file_exists($arqvTempAtual) AND file_exists($arqvTempRef))
        {
            if (($tipoProcesso == 'devolucao' AND $novaNFRef) OR $tipoProcesso == 'venda') // Devoluções precisam trocar a nota de referência, vendas não
            {
                $name = basename($arqvTempRef);
                $size = filesize($arqvTempRef);
                $arr_xml_ref = array(   'name' => $name,
                                        'type' => 'text/xml',
                                        'tmp_name' => $arqvTempRef,
                                        'size' => $size);

                // Transforma uma string em um array associativo
                $pares = explode(', ', str_replace("'", '', $arr_xml));
                $arr_xml = array();
                for ($j = 0; $j < count($pares); $j++)
                {
                    $par = explode(" => ", $pares[$j]);
                    $arr_xml[$par[0]] = $par[1];
                }

                $xmls[] = $arr_xml_ref;
                $xmls[] = $arr_xml;
    
                if ($tipoProcesso == 'devolucao')
                {
                    // Inverte a ordem para importar corretamente no processo de devolução
                    $xmls = array_reverse($xmls); 
                }                         
    
                foreach ($xmls as $xml)
                {
                    $chaveAtual = str_replace(array('tempXML_', '.xml'), '', $xml['name']);
                    $dados = array('transportadora' => $transportadora);
                    $retorno = $VEN003->prepararImportarXML($xml, false, false, $dados); 
                    if ($retorno[0])
                    {                                          
                        $importadas[$chaveAtual] = $xml['tmp_name']; 
                        $D001_Id = $retorno[7]['D001_Id'];                                             
                    }
                    else
                    {                                                   
                        $problemas[$chaveAtual] = $retorno[2];
                    }
                }

                mysql_query("SET AUTOCOMMIT=1");
    
                if (isset($importadas))
                {
                    $log[] = '';
                    foreach ($importadas as $key => $value)
                    {
                        $log[] = "[$key] - Importada com sucesso!";
                        if (!isset($problemas))
                        {                                                          
                            unlink($value);                        
                        }
                        $chaves[] = "'" . $key . "'";
                    }
                    $log[] = '';
                    $strChaves = implode(', ', $chaves);
    
                    $P001 = "UPDATE P001 SET P001_T005_Id = {$T005_Id}, P001_Status = 'Importada e vinculada', P001_Data_Hora_Vinculo = '{$agora}', P001_Data_Hora_Ultima_Tentativa_Vinculo = '{$agora}' WHERE P001_Chave_Acesso_NFe IN ({$strChaves})";
                    $log[] = $P001;
                    mysql_query($P001);
                    
                    if ($erroSQL = mysql_error())
                    {
                        $log[] = "Erro no vinculo da P001: {$erroSQL}";
                    }
                    else
                    {
                        $log[] = "Notas vinculadas com sucesso na P001!";                
                    }
                    $log[] = '';                            
    
                    // Todos os pedidos do fulfillment haverão apenas 1 produto, portanto apenas um T006_Id
                    $T007 = "UPDATE T007 LEFT JOIN T008 ON T008_T007_Id = T007_Id SET T007_T005_Id = {$T005_Id}, T008_T006_Id = {$T006_Id} WHERE T007_Chave_Acesso_Nfe IN ({$strChaves})";
                    $log[] = $T007;
                    mysql_query($T007);
                    
                    if ($erroSQL = mysql_error())
                    {
                        $log[] = "Erro no vinculo da T007 e T008: {$erroSQL}";
                    }
                    else
                    {
                        $log[] = "Notas vinculadas com sucesso na T007 e T008!";                    
                        if ($tipoProcesso == 'venda')
                        {
                            // Alterar status do pedido para coletado
                            $T005 = "UPDATE T005 SET T005_Flag_Status = 5, T005_Nome_Status = T005_Status_Pedido(T005_Flag_Status, 1) WHERE T005_Id = '{$T005_Id}'";
                            $log[] = '';
                            $log[] = $T005;
                            mysql_query($T005);
                            
                            if ($erroSQL = mysql_error())
                            {
                                $log[] = "Erro de troca de status na T005: {$erroSQL}";
                            }
                            else
                            {
                                $log[] = "Status do pedido alterado para COLETADO com sucesso!";
                                $log[] = ''; 
                                $padraoT178 = "INSERT INTO T178 (T178_C007_Id, T178_T005_Id, T178_Data, T178_Descricao) VALUES ";
                                $T178 = $padraoT178 . "(NULL, '{$T005_Id}', '{$agora}', 'Nota de venda vinculada ao pedido! Status alterado para COLETADO.')";
                                $log[] = $T178; 
                                mysql_query($T178);
                                if ($erroSQL = mysql_error())
                                {
                                    $log[] = "Erro ao inserir histórico na T178: {$erroSQL}";
                                }
                                else
                                {
                                    $log[] = "Historico inserido na T178!";   
                                }                                               
                            }
                        }                                                                   
                    }
                    require_once('bibliotecas/classes/CAD002.php');
                    $CAD002 = new CAD002();
                    foreach ($D001_Id as $id)
                    {
                        $CAD002->D001_reprocessa_historico($id, 0, 0, true);   
                    }                
                }
                $log[] = '';
                
                if (isset($problemas))
                {
                    unset($chaves);
                    foreach ($problemas as $key => $value)
                    {
                        $log[] = "[{$key}] - {$value}";
                        $chaves[] = "'" . $key . "'";
                    }                  
                    $strChaves = implode(', ', $chaves);
                    $P001 = "UPDATE P001 SET P001_Status = 'Problema na importação', P001_Data_Hora_Ultima_Tentativa_Vinculo = '{$agora}' WHERE P001_Chave_Acesso_NFe IN ({$strChaves})";
                    $log[] = $P001;
                    $log[] = '';    
                    mysql_query($P001);           
                }               
            }
            else
            {           
                $P001 = "UPDATE P001 SET P001_Data_Hora_Ultima_Tentativa_Vinculo = '{$agora}' WHERE P001_Chave_Acesso_NFe = '{$chNFe}'";
                $log[] = '';
                $log[] = $P001;
                $log[] = '';
                mysql_query($P001); 
            }       
        }
        else
        {
            $log[] = '';
            $log[] = "Ambos ou algum dos arquivos XML nao existem!";
            $log[] = $arqvTempAtual;
            $log[] = $arqvTempRef;
            $P001 = "UPDATE P001 SET P001_Data_Hora_Ultima_Tentativa_Vinculo = '{$agora}' WHERE P001_Chave_Acesso_NFe = '{$chNFe}'";
            $log[] = '';
            $log[] = $P001;
            $log[] = '';
            mysql_query($P001);
        }
        $log[] = '---------------------------------------------------------------';
        $log[] = '';                                                
        unset($importadas);
        unset($problemas);
        unset($xmls);
        unset($chaves);
    }
}
else
{
    $log[] = 'Nao ha notas para serem importadas e vinculadas!';
    $log[] = '';
}

$caminhoFulfillment = $g['pathDados'] . "tmp/FULFILLMENT/{$marketplace}/LOGS/";
if (!is_dir($caminhoFulfillment)) 
{
    mkdir($caminhoFulfillment, 0777, true);
}

$strLog = implode("\n", $log);
$fp = fopen($arquivoLog, 'a');
fwrite($fp, $strLog);
fclose($fp);