<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DecisionHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DecisionHistoryRepository::class)]
#[ORM\Table(name: 'decision_history')]
#[ORM\Index(columns: ['decision_id', 'changed_at'], name: 'decision_history_lookup_idx')]
#[ORM\Index(columns: ['changed_at'], name: 'decision_history_changed_at_idx')]
class DecisionHistory
{
    public const FIELD_CREATED = '_created';

    private const FIELD_LABELS = [
        self::FIELD_CREATED => 'Decision created',
        'decidedAt' => 'Decision date',
        'product' => 'Product',
        'department' => 'Department',
        'clientsType' => 'Clients type',
        'changeDescription' => 'Change',
        'comment' => 'Comment',
        'submittedBy' => 'Submitted by',
        'approvedBy' => 'Approved by',
        'asIsMetrics' => 'As-is metrics',
        'toBeMetrics' => 'To-be metrics',
        'followUpDate' => 'Follow-up date',
        'followUpOwner' => 'Follow-up owner',
        'actualResult' => 'Actual result',
        'followUpStatus' => 'Follow-up status',
    ];

    public static function labelFor(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }

    public function isCreation(): bool
    {
        return $this->fieldName === self::FIELD_CREATED;
    }

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Decision::class, inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Decision $decision;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $changedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    #[ORM\Column(length: 64)]
    private string $fieldName;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $oldValue = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $newValue = null;

    public function __construct(Decision $decision, string $fieldName, ?string $oldValue, ?string $newValue, ?User $changedBy)
    {
        $this->id = Uuid::v7();
        $this->decision = $decision;
        $this->fieldName = $fieldName;
        $this->oldValue = $oldValue;
        $this->newValue = $newValue;
        $this->changedBy = $changedBy;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDecision(): Decision
    {
        return $this->decision;
    }

    public function setDecision(Decision $decision): void
    {
        $this->decision = $decision;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getOldValue(): ?string
    {
        return $this->oldValue;
    }

    public function getNewValue(): ?string
    {
        return $this->newValue;
    }
}
