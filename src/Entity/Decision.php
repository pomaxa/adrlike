<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Department;
use App\Enum\FollowUpStatus;
use App\Repository\DecisionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: DecisionRepository::class)]
#[ORM\Table(name: 'decisions')]
#[ORM\Index(columns: ['decided_at'], name: 'decisions_decided_at_idx')]
#[ORM\Index(columns: ['follow_up_date'], name: 'decisions_follow_up_date_idx')]
#[ORM\Index(columns: ['follow_up_status'], name: 'decisions_follow_up_status_idx')]
#[ORM\HasLifecycleCallbacks]
class Decision
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private \DateTimeImmutable $decidedAt;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: Department::class)]
    private Department $department;

    #[ORM\Column(length: 64)]
    private string $clientsType = 'All';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $changeDescription;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $submittedBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $approvedBy = null;

    /**
     * @var array{ar?: ?float, badrate?: ?float, avgTicket?: ?float, raw?: ?string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $asIsMetrics = null;

    /**
     * @var array{ar?: ?float, badrate?: ?float, avgTicket?: ?float, raw?: ?string}|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $toBeMetrics = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $followUpDate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $followUpOwner = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actualResult = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: FollowUpStatus::class)]
    private FollowUpStatus $followUpStatus = FollowUpStatus::NotRequired;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $followUpCompletedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $importHash = null;

    /**
     * @var Collection<int, DecisionHistory>
     */
    #[ORM\OneToMany(targetEntity: DecisionHistory::class, mappedBy: 'decision', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['changedAt' => 'DESC'])]
    private Collection $history;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->decidedAt = $now;
        $this->department = Department::Risk;
        $this->changeDescription = '';
        $this->history = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDecidedAt(): \DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(\DateTimeImmutable $decidedAt): void
    {
        $this->decidedAt = $decidedAt;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): void
    {
        $this->product = $product;
    }

    public function getDepartment(): Department
    {
        return $this->department;
    }

    public function setDepartment(Department $department): void
    {
        $this->department = $department;
    }

    public function getClientsType(): string
    {
        return $this->clientsType;
    }

    public function setClientsType(string $clientsType): void
    {
        $this->clientsType = $clientsType;
    }

    public function getChangeDescription(): string
    {
        return $this->changeDescription;
    }

    public function setChangeDescription(string $changeDescription): void
    {
        $this->changeDescription = $changeDescription;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $this->comment = $comment;
    }

    public function getSubmittedBy(): User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(User $submittedBy): void
    {
        $this->submittedBy = $submittedBy;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): void
    {
        $this->approvedBy = $approvedBy;
    }

    public function getAsIsMetrics(): ?array
    {
        return $this->asIsMetrics;
    }

    public function setAsIsMetrics(?array $asIsMetrics): void
    {
        $this->asIsMetrics = $asIsMetrics;
    }

    public function getToBeMetrics(): ?array
    {
        return $this->toBeMetrics;
    }

    public function setToBeMetrics(?array $toBeMetrics): void
    {
        $this->toBeMetrics = $toBeMetrics;
    }

    public function getFollowUpDate(): ?\DateTimeImmutable
    {
        return $this->followUpDate;
    }

    public function setFollowUpDate(?\DateTimeImmutable $followUpDate): void
    {
        $this->followUpDate = $followUpDate;
    }

    public function getFollowUpOwner(): ?User
    {
        return $this->followUpOwner;
    }

    public function setFollowUpOwner(?User $followUpOwner): void
    {
        $this->followUpOwner = $followUpOwner;
    }

    public function getActualResult(): ?string
    {
        return $this->actualResult;
    }

    public function setActualResult(?string $actualResult): void
    {
        $this->actualResult = $actualResult;
    }

    public function getFollowUpStatus(): FollowUpStatus
    {
        return $this->followUpStatus;
    }

    public function setFollowUpStatus(FollowUpStatus $followUpStatus): void
    {
        $this->followUpStatus = $followUpStatus;
    }

    public function getFollowUpCompletedAt(): ?\DateTimeImmutable
    {
        return $this->followUpCompletedAt;
    }

    public function setFollowUpCompletedAt(?\DateTimeImmutable $followUpCompletedAt): void
    {
        $this->followUpCompletedAt = $followUpCompletedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getImportHash(): ?string
    {
        return $this->importHash;
    }

    public function setImportHash(?string $importHash): void
    {
        $this->importHash = $importHash;
    }

    /**
     * @return Collection<int, DecisionHistory>
     */
    public function getHistory(): Collection
    {
        return $this->history;
    }

    public function addHistory(DecisionHistory $entry): void
    {
        if (!$this->history->contains($entry)) {
            $this->history->add($entry);
            $entry->setDecision($this);
        }
    }

    public function markFollowUpDone(\DateTimeImmutable $at, ?string $actualResult): void
    {
        $this->followUpStatus = FollowUpStatus::Done;
        $this->followUpCompletedAt = $at;
        if ($actualResult !== null) {
            $this->actualResult = $actualResult;
        }
    }

    public function recomputeFollowUpStatus(\DateTimeImmutable $today): void
    {
        if ($this->followUpStatus === FollowUpStatus::Done) {
            return;
        }

        if ($this->followUpDate === null) {
            $this->followUpStatus = FollowUpStatus::NotRequired;

            return;
        }

        $this->followUpStatus = $this->followUpDate < $today
            ? FollowUpStatus::Overdue
            : FollowUpStatus::Pending;
    }
}
