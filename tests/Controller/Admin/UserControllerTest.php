<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $em;
    protected UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        // FK-safe teardown: delete history and decisions before users
        $this->em->createQuery('DELETE FROM App\Entity\DecisionHistory h')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\Decision d')->execute();
        $this->em->createQuery('DELETE FROM App\Entity\User u')->execute();
    }

    protected function makeUser(string $email, string $name, array $roles = ['ROLE_SUBMITTER'], bool $placeholder = false, ?string $password = 'test'): User
    {
        $u = new User($email, $name);
        $u->setRoles($roles);
        if ($placeholder) {
            $u->setPlaceholder(true);
        }
        if ($password !== null) {
            $u->setPassword($this->hasher->hashPassword($u, $password));
        }
        $this->em->persist($u);
        $this->em->flush();
        return $u;
    }

    public function testIndexForbiddenForSubmitter(): void
    {
        $sub = $this->makeUser('sub@example.com', 'Sub', ['ROLE_SUBMITTER']);
        $this->client->loginUser($sub);
        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexForbiddenForApprover(): void
    {
        $app = $this->makeUser('app@example.com', 'App', ['ROLE_APPROVER']);
        $this->client->loginUser($app);
        $this->client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIndexAccessibleForAdmin(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    public function testIndexListsUsersAndAppliesSearchFilter(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->makeUser('zoe@example.com', 'Zoe Zebra', ['ROLE_SUBMITTER']);
        $this->makeUser('p@imported.local', 'Placeholder Pat', ['ROLE_SUBMITTER'], placeholder: true, password: null);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zoe Zebra');
        self::assertSelectorTextContains('body', 'Placeholder Pat');

        $this->client->request('GET', '/admin/users?q=zoe');
        self::assertSelectorTextContains('body', 'Zoe Zebra');
        self::assertSelectorTextNotContains('body', 'Placeholder Pat');

        $this->client->request('GET', '/admin/users?placeholder=yes');
        self::assertSelectorTextContains('body', 'Placeholder Pat');
        self::assertSelectorTextNotContains('body', 'Zoe Zebra');
    }
}
