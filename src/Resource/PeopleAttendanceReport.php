<?php

namespace ControleOnline\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ControleOnline\State\PeopleAttendanceReportsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/report/people/attendance',
            provider: PeopleAttendanceReportsProvider::class,
            security: "is_granted('ROLE_HUMAN')",
        ),
    ],
    formats: ['jsonld', 'json', 'html', 'jsonhal', 'csv' => ['text/csv']],
    normalizationContext: ['groups' => ['report_people_attendance:read']]
)]
class PeopleAttendanceReport
{
    #[ApiProperty(identifier: true)]
    #[Groups(['report_people_attendance:read'])]
    private string $rowId = 'report';

    public function getRowId(): string
    {
        return $this->rowId;
    }
}
