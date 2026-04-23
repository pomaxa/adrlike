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

    /**
     * With expanded+multiple ChoiceType, each checkbox is a separate input:
     *   user_create[roles][0] → ROLE_ADMIN
     *   user_create[roles][1] → ROLE_APPROVER
     *   user_create[roles][2] → ROLE_SUBMITTER
     * Ticking one requires addressing the per-index checkbox explicitly.
     */
    private function setRoleCheckboxes(\Symfony\Component\DomCrawler\Form $form, string ...$wantedRoles): void
    {
        $choiceOrder = ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER'];
        foreach ($choiceOrder as $i => $role) {
            $key = "user_create[roles][$i]";
            if (!$form->has($key)) {
                continue;
            }
            if (in_array($role, $wantedRoles, true)) {
                $form[$key]->tick();
            } else {
                $form[$key]->untick();
            }
        }
    }

    public function testCreateUserFlowPersistsAndAllowsLogin(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create user')->form([
            'user_create[email]' => 'new@example.com',
            'user_create[fullName]' => 'New User',
            'user_create[password][first]' => 'secretpass',
            'user_create[password][second]' => 'secretpass',
        ]);
        $this->setRoleCheckboxes($form, 'ROLE_APPROVER');
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users');

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertNotNull($created);
        self::assertSame('New User', $created->getFullName());
        self::assertContains('ROLE_APPROVER', $created->getRoles());
        self::assertNotContains('ROLE_SUBMITTER', $created->getRoles());
        self::assertFalse($created->isPlaceholder());
        self::assertTrue($this->hasher->isPasswordValid($created, 'secretpass'));
    }

    public function testCreateUserRejectsDuplicateEmail(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->makeUser('dup@example.com', 'Dup', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/new');
        $form = $crawler->selectButton('Create user')->form([
            'user_create[email]' => 'dup@example.com',
            'user_create[fullName]' => 'Second Dup',
            'user_create[password][first]' => 'secretpass',
            'user_create[password][second]' => 'secretpass',
        ]);
        $this->setRoleCheckboxes($form, 'ROLE_SUBMITTER');
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'already exists');
    }
}
