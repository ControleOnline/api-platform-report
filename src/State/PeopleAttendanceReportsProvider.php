<?php

namespace ControleOnline\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ControleOnline\Service\PeopleAttendanceReportService;
use Symfony\Component\HttpFoundation\RequestStack;

class PeopleAttendanceReportsProvider implements \ApiPlatform\State\ProviderInterface
{
    public function __construct(
        private PeopleAttendanceReportService $peopleAttendanceReportService,
        private ?RequestStack $requestStack = null,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $filters = $context['filters'] ?? $this->requestStack?->getCurrentRequest()?->query->all() ?? [];
        if (!is_array($filters)) {
            $filters = [];
        }

        $result = $this->peopleAttendanceReportService->build($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $itemsPerPage = max(1, (int) ($filters['itemsPerPage'] ?? 50));

        return new CollectionSummaryResult(
            new ArrayPaginator($result['rows'] ?? [], ($page - 1) * $itemsPerPage, $itemsPerPage),
            $result['summary'] ?? [],
        );
    }
}
