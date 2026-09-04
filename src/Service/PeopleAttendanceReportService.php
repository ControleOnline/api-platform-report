<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\EmployeeProfile;
use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleAccessEvent;
use ControleOnline\Entity\PeopleAbsence;
use ControleOnline\Entity\PeopleSchedule;
use ControleOnline\Repository\EmployeeProfileRepository;
use ControleOnline\Repository\PeopleAbsenceRepository;
use ControleOnline\Repository\PeopleAccessEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;

class PeopleAttendanceReportService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private FileService $fileService,
        private PeopleAccessEventRepository $peopleAccessEventRepository,
        private EmployeeProfileRepository $employeeProfileRepository,
        private PeopleAbsenceRepository $peopleAbsenceRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function build(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);

        $company = $normalized['company'];
        if (!$company instanceof People) {
            throw new Exception('company is required');
        }

        $people = $normalized['people'] instanceof People ? $normalized['people'] : null;
        $context = $normalized['context'];
        $periodStart = $normalized['periodStart'];
        $periodEnd = $normalized['periodEnd'];

        $profiles = $this->loadEmployeeProfiles($company, $people, $normalized['department']);
        $schedules = $this->loadSchedules($company, $people, $context);
        $events = $this->peopleAccessEventRepository->findTimesheetEvents(
            $company,
            $people,
            $this->toRangeStart($periodStart),
            $this->toRangeEnd($periodEnd),
            $context
        );
        $absences = $this->peopleAbsenceRepository->findAbsences(
            $company,
            $people,
            $periodStart,
            $periodEnd,
            $context
        );

        return $this->buildRowsFromData(
            $profiles,
            $schedules,
            $events,
            $absences,
            $normalized
        );
    }

    /**
     * @param array<int, EmployeeProfile> $profiles
     * @param array<int, PeopleSchedule> $schedules
     * @param array<int, PeopleAccessEvent> $events
     * @param array<int, PeopleAbsence> $absences
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function buildRowsFromData(
        array $profiles,
        array $schedules,
        array $events,
        array $absences,
        array $filters
    ): array {
        $periodStart = $filters['periodStart'];
        $periodEnd = $filters['periodEnd'];
        $departmentFilter = $this->normalizeText($filters['department'] ?? '');
        $statusFilter = $this->normalizeText($filters['status'] ?? '');
        $searchFilter = $this->normalizeText($filters['search'] ?? '');
        $peopleFilter = $filters['people'];

        $profilesByPeopleId = $this->indexProfiles($profiles);
        $schedulesByPeopleId = $this->indexSchedules($schedules);
        $eventsByPeopleId = $this->indexEvents($events);
        $absencesByPeopleId = $this->indexAbsences($absences);

        $peopleIds = $this->collectPeopleIds($profilesByPeopleId, $schedulesByPeopleId, $eventsByPeopleId, $absencesByPeopleId);
        if ($peopleFilter instanceof People) {
            $peopleIds = [(int) $peopleFilter->getId()];
        }

        sort($peopleIds);

        $rows = [];
        foreach ($peopleIds as $peopleId) {
            $profile = $profilesByPeopleId[$peopleId] ?? null;
            $department = $this->normalizeText($profile?->getDepartmentLabel());

            if ($departmentFilter !== '' && !$this->textContains($department, $departmentFilter)) {
                continue;
            }

            $personSchedules = $schedulesByPeopleId[$peopleId] ?? [];
            $personEvents = $eventsByPeopleId[$peopleId] ?? [];
            $personAbsences = $absencesByPeopleId[$peopleId] ?? [];

            $dateCursor = \DateTimeImmutable::createFromInterface($periodStart);
            $endCursor = \DateTimeImmutable::createFromInterface($periodEnd);

            while ($dateCursor <= $endCursor) {
                $dateKey = $dateCursor->format('Y-m-d');
                $schedule = $this->resolveScheduleForDate($personSchedules, $dateCursor);
                $dailyEvents = $personEvents[$dateKey] ?? [];
                $absence = $personAbsences[$dateKey] ?? null;

                if ($schedule === null && [] === $dailyEvents && !$absence instanceof PeopleAbsence) {
                    $dateCursor = $dateCursor->modify('+1 day');
                    continue;
                }

                $row = $this->buildRow(
                    $peopleId,
                    $profile,
                    $department,
                    $dateCursor,
                    $schedule,
                    $dailyEvents,
                    $absence,
                    $this->resolvePeopleForRow($profile, $dailyEvents, $schedule, $absence),
                    $this->resolveCompanyForRow($profile, $dailyEvents, $schedule, $absence)
                );

            if (
                $statusFilter !== '' &&
                !$this->textContains($this->normalizeText($row['status'] ?? ''), $statusFilter) &&
                !$this->textContains($this->normalizeText($row['statusLabel'] ?? ''), $statusFilter)
            ) {
                $dateCursor = $dateCursor->modify('+1 day');
                continue;
            }

                if ($searchFilter !== '' && !$this->rowMatchesSearch($row, $searchFilter)) {
                    $dateCursor = $dateCursor->modify('+1 day');
                    continue;
                }

                $rows[] = $row;
                $dateCursor = $dateCursor->modify('+1 day');
            }
        }

        usort($rows, static function (array $left, array $right): int {
            $leftDate = $left['date'] ?? '';
            $rightDate = $right['date'] ?? '';
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }

            $leftPeople = strtolower((string) ($left['peopleLabel'] ?? ''));
            $rightPeople = strtolower((string) ($right['peopleLabel'] ?? ''));
            if ($leftPeople !== $rightPeople) {
                return strcmp($leftPeople, $rightPeople);
            }

            return strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        $summary = [
            'rows' => count($rows),
            'late' => 0,
            'absences' => 0,
            'overtime' => 0,
            'justifiedAbsences' => 0,
            'workedMinutes' => 0,
            'expectedMinutes' => 0,
            'delayMinutes' => 0,
            'overtimeMinutes' => 0,
            'balanceMinutes' => 0,
        ];

        foreach ($rows as $row) {
            $summary['workedMinutes'] += (int) ($row['workedMinutes'] ?? 0);
            $summary['expectedMinutes'] += (int) ($row['expectedMinutes'] ?? 0);
            $summary['delayMinutes'] += (int) ($row['delayMinutes'] ?? 0);
            $summary['overtimeMinutes'] += (int) ($row['overtimeMinutes'] ?? 0);
            $summary['balanceMinutes'] += (int) ($row['balanceMinutes'] ?? 0);

            $status = $this->normalizeText($row['status'] ?? '');
            if (str_contains($status, 'late')) {
                $summary['late'] += 1;
            }
            if (str_contains($status, 'absent')) {
                $summary['absences'] += 1;
            }
            if (str_contains($status, 'overtime')) {
                $summary['overtime'] += 1;
            }
            if ($status === 'absent_justified') {
                $summary['justifiedAbsences'] += 1;
            }
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
        ];
    }

    /**
     * @param array<int, EmployeeProfile> $profiles
     * @return array<int, EmployeeProfile>
     */
    private function indexProfiles(array $profiles): array
    {
        $indexed = [];

        foreach ($profiles as $profile) {
            if (!$profile instanceof EmployeeProfile) {
                continue;
            }

            $peopleId = $profile->getPeopleLink()?->getPeople()?->getId();
            if (!$peopleId) {
                continue;
            }

            $indexed[(int) $peopleId] = $profile;
        }

        return $indexed;
    }

    /**
     * @param array<int, PeopleSchedule> $schedules
     * @return array<int, array<int, PeopleSchedule>>
     */
    private function indexSchedules(array $schedules): array
    {
        $indexed = [];

        foreach ($schedules as $schedule) {
            if (!$schedule instanceof PeopleSchedule) {
                continue;
            }

            $peopleId = $schedule->getPeople()?->getId();
            if (!$peopleId) {
                continue;
            }

            $indexed[(int) $peopleId][] = $schedule;
        }

        return $indexed;
    }

    /**
     * @param array<int, PeopleAccessEvent> $events
     * @return array<int, array<string, array<int, PeopleAccessEvent>>>
     */
    private function indexEvents(array $events): array
    {
        $indexed = [];

        foreach ($events as $event) {
            if (!$event instanceof PeopleAccessEvent || !$event->getEventAt() instanceof \DateTimeInterface) {
                continue;
            }

            $peopleId = $event->getPeople()?->getId();
            if (!$peopleId) {
                continue;
            }

            $indexed[(int) $peopleId][$event->getEventAt()->format('Y-m-d')][] = $event;
        }

        return $indexed;
    }

    /**
     * @param array<int, PeopleAbsence> $absences
     * @return array<int, array<string, PeopleAbsence>>
     */
    private function indexAbsences(array $absences): array
    {
        $indexed = [];

        foreach ($absences as $absence) {
            if (!$absence instanceof PeopleAbsence || !$absence->getAbsenceDate() instanceof \DateTimeInterface) {
                continue;
            }

            $peopleId = $absence->getPeople()?->getId();
            if (!$peopleId) {
                continue;
            }

            $indexed[(int) $peopleId][$absence->getAbsenceDate()->format('Y-m-d')] = $absence;
        }

        return $indexed;
    }

    /**
     * @param array<int, EmployeeProfile> $profilesByPeopleId
     * @param array<int, array<int, PeopleSchedule>> $schedulesByPeopleId
     * @param array<int, array<string, array<int, PeopleAccessEvent>>> $eventsByPeopleId
     * @param array<int, array<string, PeopleAbsence>> $absencesByPeopleId
     * @return array<int, int>
     */
    private function collectPeopleIds(
        array $profilesByPeopleId,
        array $schedulesByPeopleId,
        array $eventsByPeopleId,
        array $absencesByPeopleId
    ): array {
        $peopleIds = [];

        foreach ([
            array_keys($profilesByPeopleId),
            array_keys($schedulesByPeopleId),
            array_keys($eventsByPeopleId),
            array_keys($absencesByPeopleId),
        ] as $set) {
            foreach ($set as $peopleId) {
                $peopleIds[(int) $peopleId] = (int) $peopleId;
            }
        }

        return array_values($peopleIds);
    }

    /**
     * @param array<int, PeopleSchedule> $schedules
     */
    private function resolveScheduleForDate(array $schedules, \DateTimeImmutable $date): ?PeopleSchedule
    {
        $dateWeekday = (int) $date->format('N');
        $recurring = [];
        $appointments = [];

        foreach ($schedules as $schedule) {
            if (!$schedule instanceof PeopleSchedule) {
                continue;
            }

            if ($schedule->getMode() === PeopleSchedule::MODE_APPOINTMENT) {
                $startsAt = $schedule->getStartsAt();
                if ($startsAt instanceof \DateTimeInterface && $startsAt->format('Y-m-d') === $date->format('Y-m-d')) {
                    $appointments[] = $schedule;
                }
                continue;
            }

            if ((int) $schedule->getWeekday() === $dateWeekday) {
                $recurring[] = $schedule;
            }
        }

        if ($appointments !== []) {
            usort($appointments, static function (PeopleSchedule $left, PeopleSchedule $right): int {
                $leftStarts = $left->getStartsAt()?->format('H:i:s') ?? '';
                $rightStarts = $right->getStartsAt()?->format('H:i:s') ?? '';

                return strcmp($leftStarts, $rightStarts);
            });

            return $appointments[0];
        }

        if ($recurring === []) {
            return null;
        }

        usort($recurring, static function (PeopleSchedule $left, PeopleSchedule $right): int {
            $leftStart = $left->getStartTime()?->format('H:i:s') ?? '';
            $rightStart = $right->getStartTime()?->format('H:i:s') ?? '';

            return strcmp($leftStart, $rightStart);
        });

        return $recurring[0];
    }

    /**
     * @param array<int, PeopleAccessEvent> $events
     */
    private function summarizeEvents(array $events, ?\DateTimeImmutable $expectedStart, ?\DateTimeImmutable $expectedEnd): array
    {
        usort($events, static function (PeopleAccessEvent $left, PeopleAccessEvent $right): int {
            $leftAt = $left->getEventAt();
            $rightAt = $right->getEventAt();
            if (!$leftAt instanceof \DateTimeInterface || !$rightAt instanceof \DateTimeInterface) {
                return 0;
            }

            return $leftAt->getTimestamp() <=> $rightAt->getTimestamp();
        });

        $entries = [];
        $exits = [];
        $workedMinutes = 0;
        $openEntry = null;

        foreach ($events as $event) {
            $eventAt = $event->getEventAt();
            if (!$eventAt instanceof \DateTimeInterface) {
                continue;
            }

            if ($event->getDirection() === PeopleAccessEvent::DIRECTION_ENTRY) {
                $entries[] = $eventAt;
                if (!$openEntry instanceof \DateTimeInterface) {
                    $openEntry = $eventAt;
                }
                continue;
            }

            $exits[] = $eventAt;
            if ($openEntry instanceof \DateTimeInterface) {
                $workedMinutes += max(
                    0,
                    (int) floor(($eventAt->getTimestamp() - $openEntry->getTimestamp()) / 60)
                );
                $openEntry = null;
            }
        }

        $firstEntry = $entries[0] ?? null;
        $lastExit = $exits !== [] ? $exits[array_key_last($exits)] : null;

        $delayMinutes = 0;
        if ($expectedStart instanceof \DateTimeImmutable && $firstEntry instanceof \DateTimeInterface) {
            $delayMinutes = max(
                0,
                (int) floor(($firstEntry->getTimestamp() - $expectedStart->getTimestamp()) / 60)
            );
        }

        $overtimeMinutes = 0;
        if ($expectedEnd instanceof \DateTimeImmutable && $lastExit instanceof \DateTimeInterface) {
            $overtimeMinutes = max(
                0,
                (int) floor(($lastExit->getTimestamp() - $expectedEnd->getTimestamp()) / 60)
            );
        }

        return [
            'entryTimes' => array_map(
                static fn (\DateTimeInterface $dateTime): string => $dateTime->format('H:i'),
                $entries
            ),
            'exitTimes' => array_map(
                static fn (\DateTimeInterface $dateTime): string => $dateTime->format('H:i'),
                $exits
            ),
            'workedMinutes' => $workedMinutes,
            'delayMinutes' => $delayMinutes,
            'overtimeMinutes' => $overtimeMinutes,
            'firstEntry' => $firstEntry,
            'lastExit' => $lastExit,
        ];
    }

    /**
     * @param array<int, PeopleAccessEvent> $events
     */
    private function buildRow(
        int $peopleId,
        ?EmployeeProfile $profile,
        string $department,
        \DateTimeImmutable $date,
        ?PeopleSchedule $schedule,
        array $events,
        ?PeopleAbsence $absence,
        ?People $peopleEntity,
        ?People $companyEntity
    ): array {
        $scheduleWindow = $this->buildScheduleWindow($schedule, $date);
        $summary = $this->summarizeEvents($events, $scheduleWindow['start'], $scheduleWindow['end']);
        $expectedMinutes = $scheduleWindow['expectedMinutes'];
        $workedMinutes = $summary['workedMinutes'];
        $delayMinutes = $summary['delayMinutes'];
        $overtimeMinutes = $summary['overtimeMinutes'];
        $balanceMinutes = $workedMinutes - $expectedMinutes;
        $hasEvents = $events !== [];
        $hasSchedule = $schedule instanceof PeopleSchedule;
        $hasAbsence = $absence instanceof PeopleAbsence;
        $justified = $hasAbsence && $absence->getHasJustification();

        $status = 'present';
        if ($hasAbsence && !$hasEvents) {
            $status = $justified ? 'absent_justified' : 'absent';
        } elseif (!$hasEvents && $hasSchedule) {
            $status = 'absent';
        } elseif (!$hasSchedule && $hasEvents) {
            $status = 'unplanned';
        } elseif ($delayMinutes > 0 && $overtimeMinutes > 0) {
            $status = 'late_overtime';
        } elseif ($delayMinutes > 0) {
            $status = 'late';
        } elseif ($overtimeMinutes > 0) {
            $status = 'overtime';
        }

        $tone = match ($status) {
            'absent', 'absent_justified' => 'danger',
            'late', 'late_overtime' => 'warning',
            'overtime', 'unplanned' => 'info',
            default => 'success',
        };

        $peopleLabel = $this->resolvePeopleLabel($peopleEntity);
        $jobTitle = trim((string) ($profile?->getJobTitleLabel() ?? ''));
        $jobFunction = trim((string) ($profile?->getJobFunctionLabel() ?? ''));
        $absenceLabel = $hasAbsence
            ? ($justified ? 'Falta justificada' : 'Falta')
            : ($status === 'late' || $status === 'late_overtime' || $status === 'overtime' ? '-' : '-');
        $justificationLabel = $hasAbsence ? $absence->getJustificationLabel() : '-';

        return [
            'id' => sprintf('%d-%s', $peopleId, $date->format('Ymd')),
            'context' => $schedule?->getContext() ?: ($hasAbsence ? $absence->getContext() : PeopleAbsence::CONTEXT_EMPLOYMENT),
            'contextLabel' => $this->formatLabel($schedule?->getContext() ?: ($hasAbsence ? $absence->getContext() : PeopleAbsence::CONTEXT_EMPLOYMENT)),
            'companyId' => $companyEntity?->getId(),
            'companyLabel' => $this->resolvePeopleLabel($companyEntity),
            'peopleId' => $peopleId,
            'peopleLabel' => $peopleLabel,
            'department' => $department !== '' ? $department : '-',
            'jobTitle' => $jobTitle !== '' ? $jobTitle : '-',
            'jobFunction' => $jobFunction !== '' ? $jobFunction : '-',
            'date' => $date->format('Y-m-d'),
            'dateLabel' => $date->format('d/m/Y'),
            'weekdayLabel' => $date->format('D'),
            'scheduleLabel' => $scheduleWindow['label'],
            'entryTimesLabel' => $summary['entryTimes'] !== [] ? implode(', ', $summary['entryTimes']) : '-',
            'exitTimesLabel' => $summary['exitTimes'] !== [] ? implode(', ', $summary['exitTimes']) : '-',
            'workedMinutes' => $workedMinutes,
            'workedHoursLabel' => $this->formatDurationMinutes($workedMinutes),
            'expectedMinutes' => $expectedMinutes,
            'expectedHoursLabel' => $this->formatDurationMinutes($expectedMinutes),
            'delayMinutes' => $delayMinutes,
            'delayLabel' => $scheduleWindow['hasSchedule'] ? $this->formatSignedDurationMinutes($delayMinutes) : '-',
            'overtimeMinutes' => $overtimeMinutes,
            'overtimeLabel' => $scheduleWindow['hasSchedule'] ? $this->formatSignedDurationMinutes($overtimeMinutes) : '-',
            'balanceMinutes' => $balanceMinutes,
            'status' => $status,
            'statusLabel' => $this->formatStatusLabel($status),
            'tone' => $tone,
            'absenceLabel' => $absenceLabel,
            'justificationLabel' => $justificationLabel,
            'justificationFileLabel' => $hasAbsence ? $absence->getJustificationFileLabel() : '-',
            'absenceId' => $hasAbsence ? $absence->getId() : null,
        ];
    }

    /**
     * @return array{hasSchedule: bool, label: string, start: ?\DateTimeImmutable, end: ?\DateTimeImmutable, expectedMinutes: int}
     */
    private function buildScheduleWindow(?PeopleSchedule $schedule, \DateTimeImmutable $date): array
    {
        if (!$schedule instanceof PeopleSchedule) {
            return [
                'hasSchedule' => false,
                'label' => '-',
                'start' => null,
                'end' => null,
                'expectedMinutes' => 0,
            ];
        }

        if ($schedule->getMode() === PeopleSchedule::MODE_APPOINTMENT) {
            $start = $schedule->getStartsAt() instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($schedule->getStartsAt())
                : null;
            $end = $schedule->getEndsAt() instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($schedule->getEndsAt())
                : null;
            $label = $schedule->getWindowLabel() ?: '-';
            $expectedMinutes = $start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable
                ? max(0, (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60))
                : 0;

            return [
                'hasSchedule' => true,
                'label' => $label,
                'start' => $start,
                'end' => $end,
                'expectedMinutes' => $expectedMinutes,
            ];
        }

        $startTime = $schedule->getStartTime();
        $endTime = $schedule->getEndTime();
        $start = $startTime instanceof \DateTimeInterface
            ? new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $startTime->format('H:i:s'))
            : null;
        $end = $endTime instanceof \DateTimeInterface
            ? new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $endTime->format('H:i:s'))
            : null;
        $expectedMinutes = $start instanceof \DateTimeImmutable && $end instanceof \DateTimeImmutable
            ? max(0, (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60))
            : 0;

        return [
            'hasSchedule' => true,
            'label' => $schedule->getWindowLabel() ?: '-',
            'start' => $start,
            'end' => $end,
            'expectedMinutes' => $expectedMinutes,
        ];
    }

    /**
     * @return array<int, EmployeeProfile>
     */
    private function loadEmployeeProfiles(People $company, ?People $people, string $department): array
    {
        $queryBuilder = $this->employeeProfileRepository->createQueryBuilder('profile');
        $queryBuilder
            ->innerJoin('profile.peopleLink', 'peopleLink')
            ->innerJoin('peopleLink.people', 'people')
            ->innerJoin('peopleLink.company', 'company')
            ->leftJoin('profile.department', 'department')
            ->where('peopleLink.company = :company')
            ->andWhere('peopleLink.linkType = :linkType')
            ->setParameter('company', $company)
            ->setParameter('linkType', 'employee');

        if ($people instanceof People) {
            $queryBuilder
                ->andWhere('people = :people')
                ->setParameter('people', $people);
        }

        if ($department !== '') {
            $queryBuilder
                ->andWhere('LOWER(COALESCE(department.name, \'\')) LIKE :department')
                ->setParameter('department', '%' . strtolower($department) . '%');
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @return array<int, PeopleSchedule>
     */
    private function loadSchedules(People $company, ?People $people, string $context): array
    {
        $queryBuilder = $this->manager->createQueryBuilder();
        $queryBuilder
            ->select('schedule')
            ->from(PeopleSchedule::class, 'schedule')
            ->innerJoin('schedule.company', 'company')
            ->innerJoin('schedule.people', 'schedulePeople')
            ->where('schedule.context = :context')
            ->andWhere('schedule.company = :company')
            ->andWhere('schedule.active = true')
            ->setParameter('context', $context)
            ->setParameter('company', $company)
            ->orderBy('schedulePeople.id', 'ASC')
            ->addOrderBy('schedule.mode', 'ASC')
            ->addOrderBy('schedule.weekday', 'ASC')
            ->addOrderBy('schedule.startTime', 'ASC')
            ->addOrderBy('schedule.startsAt', 'ASC');

        if ($people instanceof People) {
            $queryBuilder
                ->andWhere('schedule.people = :people')
                ->setParameter('people', $people);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{company: People|null, people: People|null, context: string, periodStart: \DateTimeImmutable, periodEnd: \DateTimeImmutable, department: string, status: string, search: string}
     */
    private function normalizeFilters(array $filters): array
    {
        $context = $this->normalizeText($filters['context'] ?? PeopleAbsence::CONTEXT_EMPLOYMENT);
        $context = $context !== '' ? $context : PeopleAbsence::CONTEXT_EMPLOYMENT;

        $companyReference = $filters['company'] ?? $filters['companyId'] ?? null;
        $company = $this->resolvePeopleReference($companyReference);

        $peopleReference = $filters['people'] ?? $filters['peopleId'] ?? null;
        $people = $peopleReference !== null ? $this->resolvePeopleReference($peopleReference) : null;

        $periodStart = $this->normalizeDateValue(
            $filters['periodStart']
                ?? $filters['period_start']
                ?? $filters['after']
                ?? $filters['startDate']
                ?? $filters['start_date']
                ?? null
        ) ?? new \DateTimeImmutable('first day of this month');
        $periodEnd = $this->normalizeDateValue(
            $filters['periodEnd']
                ?? $filters['period_end']
                ?? $filters['before']
                ?? $filters['endDate']
                ?? $filters['end_date']
                ?? null
        ) ?? new \DateTimeImmutable('today');

        $periodStart = $periodStart->setTime(0, 0, 0);
        $periodEnd = $periodEnd->setTime(23, 59, 59);

        if ($periodStart > $periodEnd) {
            throw new Exception('periodStart must be before periodEnd');
        }

        return [
            'company' => $company,
            'people' => $people,
            'context' => $context,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'department' => $this->normalizeText($filters['department'] ?? ''),
            'status' => $this->normalizeText($filters['status'] ?? $filters['statusLabel'] ?? ''),
            'search' => $this->normalizeText($filters['search'] ?? ''),
        ];
    }

    private function resolvePeopleReference(mixed $reference): ?People
    {
        $people = $this->fileService->resolvePeopleReference($reference);

        return $people instanceof People ? $people : null;
    }

    private function normalizeDateValue(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($normalized);
        } catch (Exception) {
            return null;
        }
    }

    private function toRangeStart(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        return $dateTime->setTime(0, 0, 0);
    }

    private function toRangeEnd(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        return $dateTime->setTime(23, 59, 59);
    }

    private function normalizeText(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function formatLabel(string $value): string
    {
        $normalized = trim(str_replace(['_', '-'], ' ', strtolower($value)));
        if ($normalized === '') {
            return '';
        }

        return ucfirst($normalized);
    }

    private function resolvePeopleLabel(?People $people): string
    {
        if (!$people instanceof People) {
            return '';
        }

        $alias = trim((string) $people->getAlias());
        $name = trim((string) $people->getName());

        if ($alias !== '' && $name !== '' && $alias !== $name) {
            return sprintf('%s - %s', $alias, $name);
        }

        return $alias !== '' ? $alias : $name;
    }

    /**
     * @param array<int, PeopleAccessEvent> $events
     */
    private function resolvePeopleForRow(
        ?EmployeeProfile $profile,
        array $events,
        ?PeopleSchedule $schedule,
        ?PeopleAbsence $absence
    ): ?People {
        $people = $profile?->getPeopleLink()?->getPeople();
        if ($people instanceof People) {
            return $people;
        }

        foreach ($events as $event) {
            if ($event instanceof PeopleAccessEvent && $event->getPeople() instanceof People) {
                return $event->getPeople();
            }
        }

        if ($schedule?->getPeople() instanceof People) {
            return $schedule->getPeople();
        }

        if ($absence?->getPeople() instanceof People) {
            return $absence->getPeople();
        }

        return null;
    }

    /**
     * @param array<int, PeopleAccessEvent> $events
     */
    private function resolveCompanyForRow(
        ?EmployeeProfile $profile,
        array $events,
        ?PeopleSchedule $schedule,
        ?PeopleAbsence $absence
    ): ?People {
        $company = $profile?->getPeopleLink()?->getCompany();
        if ($company instanceof People) {
            return $company;
        }

        foreach ($events as $event) {
            if ($event instanceof PeopleAccessEvent && $event->getCompany() instanceof People) {
                return $event->getCompany();
            }
        }

        if ($schedule?->getCompany() instanceof People) {
            return $schedule->getCompany();
        }

        if ($absence?->getCompany() instanceof People) {
            return $absence->getCompany();
        }

        return null;
    }

    private function formatDurationMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%02d:%02d', $hours, $remainingMinutes);
    }

    private function formatSignedDurationMinutes(int $minutes): string
    {
        $sign = $minutes > 0 ? '+' : '';
        return $sign . $this->formatDurationMinutes(abs($minutes));
    }

    private function formatStatusLabel(string $status): string
    {
        return match ($status) {
            'late' => 'Atraso',
            'late_overtime' => 'Atraso e extra',
            'overtime' => 'Hora extra',
            'absent' => 'Falta',
            'absent_justified' => 'Falta justificada',
            'unplanned' => 'Sem escala',
            default => 'Em dia',
        };
    }

    private function rowMatchesSearch(array $row, string $search): bool
    {
        foreach ([
            'peopleLabel',
            'department',
            'jobTitle',
            'jobFunction',
            'scheduleLabel',
            'entryTimesLabel',
            'exitTimesLabel',
            'absenceLabel',
            'justificationLabel',
            'statusLabel',
        ] as $field) {
            if ($this->textContains($this->normalizeText($row[$field] ?? ''), $search)) {
                return true;
            }
        }

        return false;
    }

    private function textContains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return $haystack !== '' && str_contains($haystack, $needle);
    }
}
