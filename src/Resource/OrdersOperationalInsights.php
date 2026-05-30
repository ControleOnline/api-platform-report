<?php

/*
 * Contract imported from AGENTS.md
 * ## Escopo
 * - Modulo de reports e visoes consolidadas da API.
 * - Serve para recursos agregados e leitura resumida de dados operacionais.
 *
 * ## Quando usar
 * - Prompts sobre indicadores, cards de report, endpoints de resumo e consultas agregadas.
 *
 * ## Limites
 * - Evitar colocar regra de escrita principal aqui.
 * - Sempre que possivel, `report` deve consumir dados dos modulos de dominio em vez de virar dono deles.
 * - O endpoint `/report/orders/operational-insights` deve apenas orquestrar filtros e serializacao do summary; as queries de agregacao pertencem ao dominio `orders`, especialmente ao `OrderRepository`.
 * - O endpoint pode receber `insight=<chave>` e deve devolver apenas esse bloco operacional quando a TV pedir um card especifico; o provider deve achatar o envelope do summary de `report` antes de serializar.
 * - Cards operacionais da TV devem buscar este endpoint e consumir apenas metrica de operacao, sem campos financeiros.
 */


namespace ControleOnline\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ControleOnline\State\OrdersOperationalInsightsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/report/orders/operational-insights',
            provider: OrdersOperationalInsightsProvider::class,
            security: "is_granted('ROLE_HUMAN')",
            paginationEnabled: false,
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['report_orders_operational_insights:read']]
)]
class OrdersOperationalInsights
{
    #[ApiProperty(identifier: true)]
    #[Groups(['report_orders_operational_insights:read'])]
    private string $rowId = 'report';

    public function getRowId(): string
    {
        return $this->rowId;
    }
}
