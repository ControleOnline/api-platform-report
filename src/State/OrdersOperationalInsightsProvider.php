<?php

namespace ControleOnline\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ControleOnline\Entity\Order;
use ControleOnline\Service\CollectionSummaryService;
use Symfony\Component\HttpFoundation\RequestStack;

class OrdersOperationalInsightsProvider implements \ApiPlatform\State\ProviderInterface
{
    public function __construct(
        private CollectionSummaryService $collectionSummaryService,
        private ?RequestStack $requestStack = null
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $filters = $context['filters'] ?? $this->requestStack?->getCurrentRequest()?->query->all() ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $filters['report'] = '1';

        $summaryOperation = new GetCollection(
            class: Order::class,
            normalizationContext: ['groups' => ['order:read']],
        );

        $summary = $this->collectionSummaryService->buildSummary(
            $summaryOperation,
            $uriVariables,
            ['filters' => $filters]
        ) ?? [];

        return new CollectionSummaryResult([], $summary);
    }
}
