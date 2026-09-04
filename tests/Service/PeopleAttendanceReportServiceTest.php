<?php

namespace ControleOnline\Tests\Service;

use ControleOnline\Entity\EmployeeProfile;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleAccessEvent;
use ControleOnline\Entity\PeopleAbsence;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\PeopleSchedule;
use ControleOnline\Repository\EmployeeProfileRepository;
use ControleOnline\Repository\PeopleAbsenceRepository;
use ControleOnline\Repository\PeopleAccessEventRepository;
use ControleOnline\Service\FileService;
use ControleOnline\Service\PeopleAttendanceReportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PeopleAttendanceReportServiceTest extends TestCase
{
    public function testBuildRowsFromDataHighlightsLateWorkAndAbsenceStates(): void
    {
        $service = new PeopleAttendanceReportService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FileService::class),
            $this->createMock(PeopleAccessEventRepository::class),
            $this->createMock(EmployeeProfileRepository::class),
            $this->createMock(PeopleAbsenceRepository::class),
        );

        $company = $this->createPeople(10, 'Clinica');
        $employee = $this->createPeople(11, 'Ana Souza');
        $profile = new EmployeeProfile();
        $profile->setPeopleLink((new PeopleLink())->setCompany($company)->setPeople($employee)->setEnabled(true));
        $profile->setDepartment('Estetica');
        $profile->setJobTitle('Recepcionista');

        $schedule = new PeopleSchedule();
        $schedule->setCompany($company);
        $schedule->setPeople($employee);
        $schedule->setContext(PeopleSchedule::MODE_RECURRING === 'recurring' ? 'employment' : 'employment');
        $schedule->setMode(PeopleSchedule::MODE_RECURRING);
        $schedule->setWeekday(1);
        $schedule->setStartTime('08:00');
        $schedule->setEndTime('17:00');

        $lateEntry = new PeopleAccessEvent();
        $lateEntry->setCompany($company);
        $lateEntry->setPeople($employee);
        $lateEntry->setContext('employment');
        $lateEntry->setDirection(PeopleAccessEvent::DIRECTION_ENTRY);
        $lateEntry->setEventAt('2026-07-13 08:15:00');

        $lateExit = new PeopleAccessEvent();
        $lateExit->setCompany($company);
        $lateExit->setPeople($employee);
        $lateExit->setContext('employment');
        $lateExit->setDirection(PeopleAccessEvent::DIRECTION_EXIT);
        $lateExit->setEventAt('2026-07-13 17:25:00');

        $absence = new PeopleAbsence();
        $absence->setCompany($company);
        $absence->setPeople($employee);
        $absence->setContext('employment');
        $absence->setAbsenceDate('2026-07-14');
        $absence->setReason('Consulta');

        $result = $service->buildRowsFromData(
            [$profile],
            [$schedule],
            [$lateEntry, $lateExit],
            [$absence],
            [
                'company' => $company,
                'people' => null,
                'context' => 'employment',
                'periodStart' => new \DateTimeImmutable('2026-07-13'),
                'periodEnd' => new \DateTimeImmutable('2026-07-14'),
                'department' => '',
                'status' => '',
                'search' => '',
            ],
        );

        self::assertCount(2, $result['rows']);

        $statuses = array_column($result['rows'], 'status');
        self::assertContains('late_overtime', $statuses);
        self::assertContains('absent_justified', $statuses);

        $lateRow = $result['rows'][array_search('late_overtime', $statuses, true)];
        $absenceRow = $result['rows'][array_search('absent_justified', $statuses, true)];

        self::assertSame('Atraso e extra', $lateRow['statusLabel']);
        self::assertSame('Falta justificada', $absenceRow['absenceLabel']);
        self::assertSame(1, $result['summary']['late']);
        self::assertSame(1, $result['summary']['absences']);
        self::assertSame(1, $result['summary']['overtime']);
        self::assertSame(1, $result['summary']['justifiedAbsences']);
    }

    private function createPeople(int $id, string $name): People
    {
        $people = new People();

        $reflection = new \ReflectionClass($people);
        foreach (['id' => $id, 'name' => $name, 'alias' => $name] as $property => $value) {
            $refProperty = $reflection->getProperty($property);
            $refProperty->setAccessible(true);
            $refProperty->setValue($people, $value);
        }

        return $people;
    }
}
