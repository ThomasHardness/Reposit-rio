<?php

// Normalmente chamado por um Ajax, tem como função enviar um comentário para um pedido no Magento 2

//Organizar os comentários por favor
/*-------------------------------------------------------*/
/*                    PROCESSO 26                        */
/*-------------------------------------------------------*/

namespace hardness;
global $g, $url, $token, $confUsuario;

// Existe uma personalização da função gatilhoAposTransmitirNF no VEN012 que não preenche $r_{variavel}
if (empty($r_T005_Id))
{
    $r_T005_Id = $parametros['T005_Id'];
}

// Incluir a API da Intelipost, usado para criarPedido caso a tranportadora seja TOTAL EXPRESS
include ("bibliotecas/webservices/intelipost/apiIntelipost.php");
$Intelipost = new Intelipost();

//Executa processo 24(ATUALIZAR TOKEN)
$token = $API001->executaProcesso(24);

// Pega informações da nota fiscal
$T007 = mysql_query("SELECT
                    T005A_JSON->>'$.id_anymarket' AS idPedidoAnyMarket, 
                    T005_Canal_Vendas_Ecommerce,
                    T005_Pedido_Ecommerce,
                    T007_Chave_Acesso_Nfe AS CHAVE_ACESSO_NOTA,
                    T005_Id_Pedido_Externo,
                    T007_Id,
                    T007_D024_Id,
                    CONCAT((DATE_FORMAT(T007_Data_Emissao,'%d/%m/%Y')),' ',T007_Hora_Emissao) AS DATA_HORA_EMISSAO,
                    T007_Numero_Nota_Fiscal,
                    D022_Nome_Empresa
                    FROM T007                    
                    LEFT JOIN T005 ON T007_T005_Id = T005_Id
                    LEFT JOIN T005A ON T005A_T005_Id = T005_Id
                    LEFT JOIN D022 ON T007_D022_Id = D022_Id
                    WHERE T005_Id = '$r_T005_Id'");
$mT007 = mysql_fetch_assoc($T007);

// Caso a nota fiscal não estiver vazia
if(!empty($mT007))
{

    if(empty($mT007['CHAVE_ACESSO_NOTA']) || empty($mT007['DATA_HORA_EMISSAO']) || empty($mT007['T007_D024_Id']) || empty($mT007['T007_Id']))
    {
        log("Info notas" . var_export($mT007, true));
        $respostaAjax['msg'] = "<b>Falta informações de nota fiscal!</b>";

        echo json_encode($respostaAjax);
        return $respostaAjax;
    }

    if(empty($mT007['T005_Pedido_Ecommerce']))
    {
        $respostaAjax['msg'] = "<b>Pedido não tem o 'entity_id' definido!</b>";

        echo json_encode($respostaAjax);
        return $respostaAjax;
    }

    // Monta oque vai no comentário da nota fiscal
    $Query = md5($mT007['T007_D024_Id'].'|'.$mT007['T007_Id']);
    $mT007['LINK_DANFE'] = "{$confUsuario['urlRaiz']}hardness3/hardness/danfe/imprimir_danfe.php?Query={$Query}&vlrNF=S&T007_Id={$mT007['T007_Id']}";

    $comentario = "";
    $comentario .= "nfe:" . $mT007['CHAVE_ACESSO_NOTA'] . ";";
    $comentario .= "emissao:" . gCorrigeData($mT007['DATA_HORA_EMISSAO']) . ";";
    $comentario .= "LINK:" . $mT007['LINK_DANFE'] . ";";

    $jsonEnviarArray = array();
    $jsonEnviarArray['statusHistory'] = array(
      "comment" => $comentario,                                       // "comment" => comentário a ser inserido no magento 2(obrigatorio)
      // Mudar status dps para faturado
      "status" => "faturado",                                        // "status" => atualiza status do pedido(não é obrigatório mas é bom colocar pois se não pode desorganizar pedidos no magento 2)
      "is_visible_on_front" => 0,                                  // "is_visible_on_front" => ?????
      "is_customer_notified" => 0,                                  // "is_customer_notified" => se o cliente deve ser notificado do comentário adicionado no magento 2(não obrigatório mas bom colocar 0 caso for fazer testes)
      //"created_at" => "valorAqui",                                      // "created_at" => data a ser inserida no comentário do magento 2(não obrigatorio)
      //"entity_id" => "valorAqui",                                   // "entity_id" => caso você queira modificar um comentário você pode colocar o entity_id do comentário(não obrigatório)
      //"entity_name" => "valorAqui",                                 // "entity_name" => nome da entidade id(não obrigatório)
      //"parent_id" => "valorAqui",                                   // "parent_id" => id do parente que recebe o comentário nesse caso é o pedido(não obrigatório pq o parent id é inserido na url)
      //"extension_attributes" => array()                             // "extension_attributes" => ?
    );

    $jsonEnviar = json_encode($jsonEnviarArray);
    
    if (empty($url)) // Não está preenchido dependendo de onde o processo é chamado
    {
        $url = "https://guimepa.com.br/rest/V1/";
    }

    $urlDestino = $url . "orders/{$mT007['T005_Pedido_Ecommerce']}/comments";

    // INICIO - ENVIAR PARA API

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $urlDestino,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $jsonEnviar,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $response = json_decode($response, true);

    // FIM - ENVIAR PARA API

    log('<pre>' . print_r($response, true) . '</pre>');

    if(empty($response['message']))
    {   

        // Existe uma personalização da função gatilhoAposTransmitirNF no VEN012 que não preenche $r_{variavel}
        if (empty($r_T005_Id_Pedido_Externo))
        {
            $r_T005_Id_Pedido_Externo = $mT007['T005_Id_Pedido_Externo'];
        }

        $T005 = mysql_query("UPDATE T005
                            SET T005_Data_Hora_Envio_Ecommerce = CURRENT_TIMESTAMP
                            WHERE T005_Id = $r_T005_Id");
        $respostaAjax['msg'] = "A nota fiscal do pedido {$r_T005_Id_Pedido_Externo} foi enviada com sucesso! <a href=\"https://devm2.guimepa.com.br/mguadm/sales/order/view/order_id/{$mT007['T005_Pedido_Ecommerce']}/\" target=\"_blank\">Ver painel</a>";

        // Regra especificada em solitação ao card: https://trello.com/c/qVtJTXDp
        $transportadora = strtoupper(gLimpaAcentosPuro(trim($mT007['D022_Nome_Empresa'])));

        if ($transportadora == "TOTAL EXPRESS") 
        {
            $retorno = $Intelipost -> criarPedido($r_T005_Id);
            // Erro na API da Intelipost 
            if ($retorno[0] == "ERROR") 
            { 
                $respostaAjax['msg'] = "<b>{$r_T005_Id}:</b> " . $retorno[1];
                echo json_encode($respostaAjax);
                return $respostaAjax;
            }
        }

        // Regra especifica em solicitação do card: https://trello.com/c/Nqbi4ID5
        $canalVendas = strtoupper(gLimpaAcentosPuro(trim($mT007['T005_Canal_Vendas_Ecommerce'])));

        log('Canal de vendas -> ' . $canalVendas);
        log('Transportadora -> ' . $transportadora);

        $listaCanaisVenda_1 = array('MAGAZINE_LUIZA-GUIMEPA', 'MAGAZINE_LUIZA-DELTATOP', 'MAGAZINE_LUIZA-GMK8');
        $listaCanaisVenda_2 = array('AMAZON-GUIMEPA', 'AMAZON-DELTATOP', 'AMAZON-GMK8');
        $listaCanaisVenda_3 = array('MERCADO_LIVRE-GUIMEPA', 'MERCADO_LIVRE-GUIMEPA SC');
        $listaCanaisVenda_4 = array('OLIST_NEW_API-GUIMEPA');

        if (
            (in_array($canalVendas, $listaCanaisVenda_1)) OR 
            (in_array($canalVendas, $listaCanaisVenda_2) AND $transportadora == 'DELIVERY BY AMAZON DBA') OR 
            (in_array($canalVendas, $listaCanaisVenda_3) AND $transportadora == 'MERCADO ENVIOS') OR
            (in_array($canalVendas, $listaCanaisVenda_4))
            ) 
        {            // Seleciona dados da nota de venda vinculada ao pedido atual para encontrar o arquivo
            $T007_2 = "SELECT T007_Data_Emissao, T007_Numero_Protocolo_Nfe FROM T007 WHERE T007_Id = '{$mT007['T007_Id']}'";     
            log('Query T007_2 -> ' . $T007_2);
            $mT007_2 = mysql_query($T007_2);
            $linhaT007_2 = mysql_fetch_assoc($mT007_2);

            $anoMesNFe = substr($linhaT007_2['T007_Data_Emissao'], 0, 7);
            $dirNFe = $g['pathDadosAntigo'] . 'nfe';
            $dirArqv = "{$dirNFe}/xml/autorizado/{$anoMesNFe}/{$linhaT007_2['T007_Numero_Protocolo_Nfe']}.xml";
            log('Diretório do arquivo -> ' . $dirArqv);
            $strArqv = file_get_contents($dirArqv);
            log('Conteúdo do arquivo XML: ' . $strArqv);

            $tokenAnyMarket = "259029869L1E1668256201567C157494420156700O1.I";
            $idPedidoAnyMarket = $mT007['idPedidoAnyMarket'];
            log('Id AnyMarket -> ' . $idPedidoAnyMarket);

            // Envia o arquivo para a AnyMarket repassar para o marketplace
            log("http://api.anymarket.com.br/v2/orders/{$idPedidoAnyMarket}/nfe");

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => "http://api.anymarket.com.br/v2/orders/{$idPedidoAnyMarket}/nfe",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "PUT",
                CURLOPT_POSTFIELDS => "{$strArqv}",
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/xml",
                    "gumgaToken: {$tokenAnyMarket}"
                ],
            ]);
            $rawResponseAnyMarket = curl_exec($curl);    
            $responseAnyMarket = json_decode($rawResponseAnyMarket, true);
            curl_close($curl);

            // Verifica se foi retornado algum erro
            if (!empty($responseAnyMarket['message']))
            {
                log('Erro no envio da nota venda ao AnyMarket <pre>' . print_r($responseAnyMarket, true) . '</pre>');   
                $respostaAjax['msg'] = "<b>{$r_T005_Id}: Erro de API da AnyMarket! Verifique o log.</b>";                  
                echo json_encode($respostaAjax);
                return $respostaAjax;
            }
        }

        echo json_encode($respostaAjax);
        return $respostaAjax;
    }
    else
    {
        log("Erro API: " . var_export($response, true));

        $respostaAjax['msg'] = 'Houve uma falha no envio da nota fiscal<br> para o magento';

        echo json_encode($respostaAjax);
        return $respostaAjax;
    }
}