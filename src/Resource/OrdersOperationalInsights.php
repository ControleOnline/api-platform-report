<?php

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
