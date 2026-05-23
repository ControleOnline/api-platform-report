<?php

namespace ControleOnline\Tests\State;

use ApiPlatform\Metadata\GetCollection;
use ControleOnline\Entity\Order;
use ControleOnline\Service\CollectionSummaryService;
use ControleOnline\State\CollectionSummaryResult;
use ControleOnline\State\OrdersOperationalInsightsProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class OrdersOperationalInsightsProviderTest extends TestCase
{
    public function testFlattensTheOrderReportSummaryEnvelope(): void
    {
        $collectionSummaryService = $this->createMock(CollectionSummaryService::class);
        $collectionSummaryService->expects(self::once())
            ->method('buildSummary')
            ->willReturn([
                'report' => [
                    'operationalInsights' => [
                        'totals' => [
                            'orders' => 3,
                            'units' => 9,
                        ],
                    ],
                ],
            ]);

        $provider = new OrdersOperationalInsightsProvider($collectionSummaryService);

        $result = $provider->provide(
            new GetCollection(class: Order::class),
            [],
            ['filters' => ['provider' => '/people/1']]
        );

        self::assertInstanceOf(CollectionSummaryResult::class, $result);
        self::assertSame([
            'operationalInsights' => [
                'totals' => [
                    'orders' => 3,
                    'units' => 9,
                ],
            ],
        ], $result->getSummary());
    }
}
