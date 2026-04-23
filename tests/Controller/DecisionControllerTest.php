<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DecisionControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'tester@example.com']);
        if (!$user) {
            $user = new User('tester@example.com', 'Tester');
            $user->setRoles(['ROLE_ADMIN', 'ROLE_SUBMITTER', 'ROLE_APPROVER']);
            $user->setPassword($hasher->hashPassword($user, 'test'));
            $em->persist($user);
            $em->flush();
        }

        $this->client->loginUser($user);
    }

    public function testIndexRenders(): void
    {
        $this->client->request('GET', '/decisions');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Decisions');
    }

    public function testCreateDecisionRoundTrip(): void
    {
        $crawler = $this->client->request('GET', '/decisions/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Save')->form();
        $fieldKey = static function (string $name) use ($form): string {
            foreach ($form->all() as $field) {
                if ($field->getName() === $name) {
                    return $name;
                }
            }
            $prefix = 'decision';
            return $prefix . '[' . str_replace('.', '][', $name) . ']';
        };

        $form[$fieldKey('decision[decidedAt]')] = '2026-04-23';
        $form[$fieldKey('decision[product]')] = 'Leasing';
        $form[$fieldKey('decision[department]')] = 'Risk';
        $form[$fieldKey('decision[changeDescription]')] = 'Test cut-off change';

        $submitter = $form->getValues()['decision[submittedBy]'] ?? null;
        if ($submitter === null || $submitter === '') {
            $options = $form['decision[submittedBy]']->availableOptionValues();
            $form['decision[submittedBy]']->select($options[0]);
        }

        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Test cut-off change');
    }
}
