<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        #[Autowire('%env(default::ADMIN_EMAIL)%')]
        private readonly ?string $adminEmail = null,
        #[Autowire('%env(default::ADMIN_PASSWORD)%')]
        private readonly ?string $adminPassword = null,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $email = $this->adminEmail ?: 'admin@example.com';
        $password = $this->adminPassword ?: 'admin';

        $admin = new User($email, 'Administrator');
        $admin->setRoles(['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER']);
        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $manager->persist($admin);

        $manager->flush();
    }
}
