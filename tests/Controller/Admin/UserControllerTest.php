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

    public function testCreateUserWithDepartmentSavesDepartment(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create user')->form([
            'user_create[email]'             => 'dept@example.com',
            'user_create[fullName]'          => 'Dept User',
            'user_create[password][first]'   => 'secretpass',
            'user_create[password][second]'  => 'secretpass',
            'user_create[department]'        => 'Risk',
        ]);
        $this->setRoleCheckboxes($form, 'ROLE_SUBMITTER');
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/users');

        $created = $this->em->getRepository(User::class)->findOneBy(['email' => 'dept@example.com']);
        self::assertNotNull($created);
        self::assertSame(\App\Enum\Department::Risk, $created->getDepartment());
    }

    public function testShowUserPage(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target User', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Target User');
        self::assertSelectorTextContains('body', 'target@example.com');
    }

    private function setEditRoleCheckboxes(\Symfony\Component\DomCrawler\Form $form, string ...$wantedRoles): void
    {
        $choiceOrder = ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER'];
        foreach ($choiceOrder as $i => $role) {
            $key = "user_edit[roles][$i]";
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

    public function testEditUserUpdatesFields(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target Old', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122() . '/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save')->form([
            'user_edit[email]' => 'target-new@example.com',
            'user_edit[fullName]' => 'Target New',
        ]);
        $this->setEditRoleCheckboxes($form, 'ROLE_APPROVER');
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        self::assertSame('target-new@example.com', $reloaded->getEmail());
        self::assertSame('Target New', $reloaded->getFullName());
        self::assertContains('ROLE_APPROVER', $reloaded->getRoles());
        self::assertNotContains('ROLE_SUBMITTER', $reloaded->getRoles());
    }

    public function testEditUserUpdatesDepartment(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122() . '/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form([
            'user_edit[email]'      => 'target@example.com',
            'user_edit[fullName]'   => 'Target',
            'user_edit[department]' => 'Manual',
        ]);
        $this->setEditRoleCheckboxes($form, 'ROLE_SUBMITTER');
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        self::assertSame(\App\Enum\Department::Manual, $reloaded->getDepartment());
    }

    public function testEditSelfHidesRolesField(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $admin->getId()->toRfc4122() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name^="user_edit[roles]"]'));
    }

    public function testEditSelfCannotTamperWithRoles(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);

        $this->client->request('POST', '/admin/users/' . $admin->getId()->toRfc4122() . '/edit', [
            'user_edit' => [
                'email' => 'adm@example.com',
                'fullName' => 'Adm',
                'roles' => ['ROLE_SUBMITTER'],
                '_token' => 'anything',
            ],
        ]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($admin->getId());
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles());
    }

    public function testResetPasswordChangesHash(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $target = $this->makeUser('target@example.com', 'Target', ['ROLE_SUBMITTER'], password: 'old-pw-123');
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $target->getId()->toRfc4122() . '/password');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Set new password')->form([
            'password_reset[password][first]' => 'brandnewpw',
            'password_reset[password][second]' => 'brandnewpw',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($target->getId());
        self::assertFalse($this->hasher->isPasswordValid($reloaded, 'old-pw-123'));
        self::assertTrue($this->hasher->isPasswordValid($reloaded, 'brandnewpw'));
    }

    public function testResetPasswordOnPlaceholderRedirectsToPromote(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $ph = $this->makeUser('ghost-a@imported.local', 'Ghost A', ['ROLE_SUBMITTER'], placeholder: true, password: null);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users/' . $ph->getId()->toRfc4122() . '/password');
        self::assertResponseRedirects('/admin/users/' . $ph->getId()->toRfc4122() . '/promote');
    }

    private function setPromoteRoleCheckboxes(\Symfony\Component\DomCrawler\Form $form, string ...$wantedRoles): void
    {
        $choiceOrder = ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER'];
        foreach ($choiceOrder as $i => $role) {
            $key = "promote_placeholder[roles][$i]";
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

    public function testPromotePlaceholderPreservesUuidAndAllowsLogin(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $ph = $this->makeUser('ghost-b@imported.local', 'Ghost B', ['ROLE_SUBMITTER'], placeholder: true, password: null);
        $originalId = $ph->getId()->toRfc4122();
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/users/' . $originalId . '/promote');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Promote user')->form([
            'promote_placeholder[email]' => 'ghost-b@real.example.com',
            'promote_placeholder[password][first]' => 'realpass1',
            'promote_placeholder[password][second]' => 'realpass1',
        ]);
        $this->setPromoteRoleCheckboxes($form, 'ROLE_SUBMITTER');
        $this->client->submit($form);
        self::assertResponseRedirects();

        $this->em->clear();
        $reloaded = $this->em->getRepository(User::class)->find($ph->getId());
        self::assertSame($originalId, $reloaded->getId()->toRfc4122());
        self::assertSame('ghost-b@real.example.com', $reloaded->getEmail());
        self::assertFalse($reloaded->isPlaceholder());
        self::assertTrue($this->hasher->isPasswordValid($reloaded, 'realpass1'));
    }

    public function testPromoteNonPlaceholderReturns404(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $real = $this->makeUser('real@example.com', 'Real', ['ROLE_SUBMITTER']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users/' . $real->getId()->toRfc4122() . '/promote');
        self::assertResponseStatusCodeSame(404);
    }

    public function testIndexShowsSsoStatusBanner(): void
    {
        $admin = $this->makeUser('adm@example.com', 'Adm', ['ROLE_ADMIN']);
        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'SSO');
    }
}
