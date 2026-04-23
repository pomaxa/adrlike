<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Decision;
use App\Entity\DecisionHistory;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

#[AsDoctrineListener(event: Events::onFlush)]
final class DecisionAuditSubscriber
{
    /** @var list<string> */
    private const TRACKED_FIELDS = [
        'decidedAt',
        'product',
        'department',
        'clientsType',
        'changeDescription',
        'comment',
        'submittedBy',
        'approvedBy',
        'asIsMetrics',
        'toBeMetrics',
        'followUpDate',
        'followUpOwner',
        'actualResult',
        'followUpStatus',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(DecisionHistory::class);
        $actor = $this->security->getUser();
        $actor = $actor instanceof User ? $actor : null;

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Decision) {
                continue;
            }

            $changes = $uow->getEntityChangeSet($entity);
            foreach ($changes as $field => [$old, $new]) {
                if (!in_array($field, self::TRACKED_FIELDS, true)) {
                    continue;
                }

                $history = new DecisionHistory(
                    $entity,
                    $field,
                    self::stringify($old),
                    self::stringify($new),
                    $actor,
                );
                $em->persist($history);
                $uow->computeChangeSet($metadata, $history);
            }
        }
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof User) {
            return $value->getFullName();
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : $value::class;
        }

        return null;
    }
}
