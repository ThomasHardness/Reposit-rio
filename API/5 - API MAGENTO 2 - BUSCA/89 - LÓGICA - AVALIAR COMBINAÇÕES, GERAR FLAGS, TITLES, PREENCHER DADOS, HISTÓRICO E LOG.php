<?php

namespace hardness;
global $g;

require_once('bibliotecas/classes/API001.php');
$API001 = new API001();

$padraoT178 = "INSERT INTO T178 (T178_C007_Id, T178_T005_Id, T178_Data, T178_Descricao) VALUES ";

// Retorna as combinações de status corretas salvas
$combinacoesStatusCorretas = $API001->executaProcesso(87);

$arrayStatus = $parametros['arrayStatus'];
$valores = $parametros['valores'];
$flagErro = $parametros['flagErro'];
$flagAuditoriaOriginal = $parametros['flagAuditoriaOriginal'];
$statusAtualizar = $parametros['statusAtualizar'];
$id_anymarket = $parametros['id_anymarket'];
$statusOriginais = $parametros['statusOriginais'];
$dataEntrega = $parametros['dataEntrega'];
$T005_Id = $parametros['T005_Id'];
$T006_Id = $parametros['T006_Id'];
$invoice = $parametros['invoice'];
$metadata = $parametros['metadata'];
$pedidoSite = $parametros['pedidoSite'];

if ($metadata['logistic_type'] == 'fulfillment')
{
    // No futuro, deixar de diferentes transportadoras apenas mudem o marketplace, mas usem o mesmo código (se for igual para todas)
    if ($valores['TRANSPORTADORA'] == 'FULL MELI')
    { 
        $marketplace = 'MERCADO-LIVRE';

        $arrLog[] = date('d/m/y H:i:s');
        $arrLog[] = '';
        $arrLog[] = "T005_Id: {$T005_Id}";
        $arrLog[] = '';
        if (!empty($invoice['invoiceLink']))
        {
            $links['venda'] = $invoice['invoiceLink'];            
        }
        if (!empty($metadata['nfe_xml_sale_return']))
        {
            $links['devolucao'] = $metadata['nfe_xml_sale_return'];
        }

        $caminhoFulfillment = $g['pathDados'] . "tmp/FULFILLMENT/{$marketplace}/NOTAS/";
        if (!is_dir($caminhoFulfillment)) 
        {
            mkdir($caminhoFulfillment, 0777, true);
        }

        foreach ($links as $tipo => $link)
        {
            $arqvTemp = tempnam($caminhoFulfillment, 'tempXML_');
            file_put_contents($arqvTemp, file_get_contents($link));

            $dom = new \DomDocument();
            @$dom->load($arqvTemp);

            $nNF    = $dom->getElementsByTagName("nNF")->item(0)->nodeValue;    // Número
            $chNFe  = $dom->getElementsByTagName("chNFe")->item(0)->nodeValue;  // Chave de Acesso
            $serie  = $dom->getElementsByTagName("serie")->item(0)->nodeValue;  // Série
            $cfop   = $dom->getElementsByTagName("CFOP")->item(0)->nodeValue;   // CFOP
            $natOp  = $dom->getElementsByTagName("natOp")->item(0)->nodeValue;  // Natureza da operação
            $NFref  = $dom->getElementsByTagName("NFref");                      // Notas referenciadas

            $i = 0;
            $chavesAcessoRef = array();
            $numerosNotasRef = array();
            foreach ($NFref as $NF)
            {
                $chavesAcessoRef[] = $NF->nodeValue; // Número da nota referenciada
                $numerosNotasRef[] = intval(substr($chavesAcessoRef[$i], 25, 9)); // Número da nota referenciada
                $i++;
            }
            $chavesAcessoRefStr = implode(' | ', $chavesAcessoRef);
            $numerosNotasRefStr = implode(' | ', $numerosNotasRef);

            $camposNF = array($nNF, $chNFe, $serie, $cfop, $natOp);
            if (count(array_filter($camposNF)) === count($camposNF)) // Verifica se todos os itens estão preenchidos
            {
                $arrLog[] = 'INFORMACOES DA NOTA FISCAL';
                $arrLog[] = '';
                $arrLog[] = "Chave de acesso da NF: {$chNFe}";
                $arrLog[] = "Numero NF: {$nNF}";
                $arrLog[] = "Serie: {$serie}";
                $arrLog[] = "CFOP: {$cfop}";
                $arrLog[] = "Natureza da operacao: {$natOp}";
                $arrLog[] = "Chaves de acesso referenciadas: {$chavesAcessoRefStr}";
                $arrLog[] = "Numero das notas referenciadas: {$numerosNotasRefStr}";
                $arrLog[] = '';

                if ($cfop == '6106' OR $cfop == '5106' OR $cfop == '2202' OR $cfop = '1202')
                {
                    $P001 = "SELECT P001_Id FROM P001 WHERE P001_Chave_Acesso_NFe = '{$chNFe}'";
                    $mP001 = mysql_query($P001);                    
                    if (mysql_num_rows($mP001) == 0)
                    {
                        $arqvTempNovo = $caminhoFulfillment . "tempXML_{$chNFe}.xml";
                        rename($arqvTemp, $arqvTempNovo);

                        /* Garante permissões suficientes para que o usuário do crontab (ubuntu) que está no mesmo 
                        grupo do www-data (usuário do sistema e API), proprietário do arquivo, possa lê-lo */
                        chmod($arqvTempNovo, 0777); 

                        $infoProprietario = posix_getpwuid(fileowner($arqvTempNovo));
                        $infoGrupo = posix_getgrgid(filegroup($arqvTempNovo));

                        $arrLog[] = '';
                        $arrLog[] = 'INFORMACOES DO ARQUIVO XML';
                        $arrLog[] = '';
                        $arrLog[] = 'Proprietario: ' . $infoProprietario['name'];
                        $arrLog[] = 'Permissoes: ' . fileperms($arqvTempNovo);
                        $arrLog[] = 'Grupo: ' . $infoGrupo['name'];
                        $arrLog[] = '';

                        $agora = date('Y-m-d H:i:s');        
                        $arrLog[] = "Inserindo registro na P001!";
                        $P001 = "INSERT INTO P001 
                                    (P001_Data_Hora_Entrada,
                                    P001_Chave_Acesso_NFe,  
                                    P001_Numero_Nota_Fiscal, 
                                    P001_Serie,
                                    P001_CFOP, 
                                    P001_Natureza_Operacao, 
                                    P001_URL_Download,
                                    P001_Chave_Acesso_NFe_Referenciada,
                                    P001_Numero_Nota_Fiscal_Referenciada,
                                    P001_Marketplace) 
                                VALUES 
                                    ('{$agora}', 
                                    '{$chNFe}',
                                    '{$nNF}',
                                    '{$serie}',
                                    '{$cfop}', 
                                    '{$natOp}',   
                                    '{$link}',
                                    '{$chavesAcessoRefStr}',
                                    '{$numerosNotasRefStr}',
                                    '{$marketplace}')";     
                        mysql_query($P001);
                        $P001_Id = $g['mysqlLastId'];

                        $name = basename($arqvTempNovo);
                        $size = filesize($arqvTempNovo);                    
                        $arrayXML = "'name' => $name, 'type' => 'text/xml', 'tmp_name' => '$arqvTempNovo', 'size' => $size";
                        $T005A = "UPDATE T005A SET T005A_JSON = JSON_SET(T005A_JSON, '$.{$tipo}_arr_xml', \"{$arrayXML}\", '$.{$tipo}_refNFe', '{$chavesAcessoRef[0]}', '$.{$tipo}_chNFe', '{$chNFe}') WHERE T005A_T005_Id = '{$T005_Id}'";
                        $arrLog[] = '';
                        $arrLog[] = $T005A;
                        $arrLog[] = '';
                        mysql_query($T005A);                                                                        
                    }
                    else
                    {
                        $arrLog[] = "Registro na P001 ja inserido.";
                        $P001_Id = mysql_fetch_assoc($mP001)['P001_Id'];
                    }
                    $arrLog[] = "Id na tabela P001: {$P001_Id}";
                    $arrLog[] = '';
                }
                else
                {
                    // CFOP não está na lista
                }                    
            }
            else
            {
                $arrLog[] = "Algum dado da NF não esta preenchido.";
                $arrLog[] = '';                
            }
            unlink($arqvTemp);          
        }
        $arrLog[] = '------------------------------------------------------';
        $arrLog[] = '';
        $arrLog[] = '';
    }
}

foreach ($combinacoesStatusCorretas as $key1 => $value1)
{
    $status1 = $arrayStatus[explode(' > ', $key1)[0]];
    $status2 = $arrayStatus[explode(' > ', $key1)[1]];
    
    foreach ($value1 as $key2 => $value2)
    {
        $campo = $key2;
        if (!array_key_exists(0, $value2))  
        {
            foreach ($value2 as $key3 => $value3)
            {
                $condicao = $key3;
                foreach ($value3 as $value4)
                {
                    $avaliarStatus = false;
                    if ($condicao == 'CONTER -1' AND strpos($valores[$campo], '-1') !== false)
                    {
                        $strEntao = strtoupper($campo[0]) . " contém traço um (-1)";
                        $avaliarStatus = true;
                    }
                    elseif ($condicao == 'PREENCHIDO' AND $valores[$campo] != 'VAZIO')
                    {
                        $strEntao = strtoupper($campo[0]) . " está preenchido";
                        $avaliarStatus = true;
                    }
                    elseif ($condicao == $valores[$campo])
                    {
                        $strEntao = strtoupper($campo[0]) . " é igual a " . $condicao;
                        $avaliarStatus = true;
                    }

                    if ($avaliarStatus)
                    {
                        if ($status1 == $value4[0] OR $status2 == $value4[1])
                        {
                            $resultadoCombinacao = 'Não';
                            if (($status1 == $value4[0] OR $value4[0] == 'QUALQUER STATUS') AND ($status2 == $value4[1] OR $value4[1] == 'QUALQUER STATUS'))
                            {
                                $resultadoCombinacao = 'Sim';
                                $flagErro[$key1]['resultado'] = 'N';
                            }
                            if (!strpos($strCombinacoes, $strEntao))
                            {
                                $strCombinacoes .= "\n{$strEntao}, então:\n";
                            }
                            $strCombinacoes .= "  S¹ = {$value4[0]} e S² = {$value4[1]} ? {$resultadoCombinacao}\n";
                        }
                    }
                }
            }
        }
        else
        {
            if ($status1 == $value2[0] OR $status2 == $value2[1])
            {
                $resultadoCombinacao = 'Não';
                if (($status1 == $value2[0] OR $value2[0] == 'QUALQUER STATUS') AND ($status2 == $value2[1] OR $value2[1] == 'QUALQUER STATUS'))
                {
                    $resultadoCombinacao = 'Sim';
                    $flagErro[$key1]['resultado'] = 'N';
                }
                $strCombinacoes .= "S¹ = {$value2[0]} e S² = {$value2[1]} ? {$resultadoCombinacao}\n";
            }
        }
    }
    $s1 = explode(' > ', $flagErro[$key1]['extenso'])[0];
    $s2 = explode(' > ', $flagErro[$key1]['extenso'])[1];
    $statusAtuaisStr = strtoupper($flagErro[$key1]['extenso']);
    if (empty($strCombinacoes))
    {
        $strCombinacoes = "Nenhuma combinação encontrada.\n";
    }

    $flagErro[$key1]['title'] = "Flag erro atual: {$statusAtuaisStr}\nStatus atuais: {$status1} > {$status2}\nTransportadora: {$valores['TRANSPORTADORA']}\nCanal de vendas: {$valores['CANAL DE VENDAS']}\nId RMA: {$valores['RMA']}\nId Magento: {$valores['MAGENTO ID']}\n\nSendo:\n\nS¹ = Status {$s1}\nS² = Status {$s2}\nT = Transportadora\nC = Canal de vendas\nR = Id RMA\nM = Id Magento\n\nAvaliando combinações aceitas:\n\n{$strCombinacoes}\nResultado: {$flagErro[$key1]['resultado']}";
    $strCombinacoes = '';
}

if ($pedidoSite) // Ele não vai existir na AnyMarket, somente no Magento
{
    log($T005_Id . ' - Pedido site!');
    unset($flagErro['MAG > ANY']);
    unset($flagErro['ANY > MKP']);
}

foreach ($flagErro as $key => $value)
{
    if ($flagErro['ERRO GERAL']['resultado'] == 'N')
    {
        if ($value['resultado'] == 'S')
        {
            $flagErro['ERRO GERAL']['resultado'] = 'S';                    
        }
    }
}

$flagAuditoria = 'N';
if ($flagErro['ERRO GERAL']['resultado'] == 'N' OR $flagAuditoriaOriginal == 'S')
{
    $flagAuditoria = 'S';
}

$dataHoraUltimaAtualizacaoStatus = date('Y-m-d H:i:s');

if (!empty($statusAtualizar))
{  
    if ($flagAuditoriaOriginal == 'S')
    {
        if ($flagErro['ERRO GERAL']['resultado'] == 'S')
        {
            $flagAuditoria = 'N';
        }
    }

    unset($valoresT178); 
    foreach ($statusAtualizar as $key => $value)
    {
        $valoresT178 .= "('{$g['usuarioAtual']}', '{$T005_Id}', '{$dataHoraUltimaAtualizacaoStatus}', '[{$key}] Status atualizado para: {$value}'), ";
    }
    $T178 = $padraoT178 . trim($valoresT178, ', ');
    $querys .= "{$T178}\n";
    mysql_query($T178);  
    if ($erroSQL = mysql_error())
    {
        $log .= "\n! ERRO SQL (INSERT INTO T178) !\n";
        $log .= "Erro: {$erroSQL}\n\n";
    } 
}

// Salva alterações na flag de auditoria
if ($flagAuditoriaOriginal != $flagAuditoria)
{
    $log .= "\nFlag auditoria alterada!\n";
    $historicoFlagAuditoria = "('{$g['usuarioAtual']}', '{$T005_Id}', '{$dataHoraUltimaAtualizacaoStatus}', '[SISTEMA] A flag de auditoria foi alterada para {$flagAuditoria}')";
    $T178 = $padraoT178 . $historicoFlagAuditoria;
    $querys .= "INSERT NA T178\n";
    mysql_query($T178); 
}

$T005A = "  UPDATE 
                T005A
                LEFT JOIN T005 ON T005_Id = T005A_T005_Id
            SET 
                T005A_JSON = JSON_SET(T005A_JSON, 
                '$.id_anymarket', '{$id_anymarket}', 
                '$.status', '{$arrayStatus['ANY']}', 
                '$.marketPlaceStatus', '{$arrayStatus['MKP']}',                         
                '$.status_magento', '{$arrayStatus['MAG']}', 
                '$.dataHoraUltimaAtualizacaoStatus', '{$dataHoraUltimaAtualizacaoStatus}', 
                '$.erro_hard_mag', '{$flagErro['HARD > MAG']['resultado']}',                             
                '$.erro_int_mag', '{$flagErro['INT > MAG']['resultado']}', 
                '$.erro_mag_any', '{$flagErro['MAG > ANY']['resultado']}', 
                '$.erro_any_mkp', '{$flagErro['ANY > MKP']['resultado']}', 
                '$.erro_geral', '{$flagErro['ERRO GERAL']['resultado']}',
                '$.title_erro_hard_mag', '{$flagErro['HARD > MAG']['title']}', 
                '$.title_erro_int_mag', '{$flagErro['INT > MAG']['title']}', 
                '$.title_erro_mag_any', '{$flagErro['MAG > ANY']['title']}', 
                '$.title_erro_any_mkp', '{$flagErro['ANY > MKP']['title']}', 
                '$.original_status', '{$statusOriginais['ANY']}',
                '$.original_marketPlaceStatus', '{$statusOriginais['MKP']}',
                '$.original_status_magento', '{$statusOriginais['MAG']}' 
                ),
                T005A_Flag_Auditoria = '{$flagAuditoria}',
                T005_Data_Entrega = '{$dataEntrega}'
            WHERE 
                T005_Id = {$T005_Id}";
$querys .= "UPDATE NA T005A\n";
mysql_query($T005A);
if ($erroSQL = mysql_error())
{
    $log .= "\n! ERRO SQL (UPDATE T005A) !\n";
    $log .= "Erro: {$erroSQL}\n\n";
} 

$dados['querys'] = $querys;
$dados['log'] = $log;

if (isset($arrLog))
{
    $caminhoFulfillment = $g['pathDados'] . "tmp/FULFILLMENT/{$marketplace}/LOGS/";
    if (!is_dir($caminhoFulfillment)) 
    {
        mkdir($caminhoFulfillment, 0777, true);
    }
    $arquivoLog = $caminhoFulfillment . "LOG_AUTO-E-BOTAO_ATUALIZACAO-DE-STATUS.txt";
    $strLog = implode("\n", $arrLog);
    $fp = fopen($arquivoLog, 'a');
    fwrite($fp, $strLog);
    fclose($fp);
}

return $dados;