<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Decision;
use App\Enum\FollowUpStatus;
use App\Form\DecisionType;
use App\Repository\DecisionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_SUBMITTER')]
#[Route('/decisions')]
final class DecisionController extends AbstractController
{
    #[Route('', name: 'app_decision_index', methods: ['GET'])]
    public function index(Request $request, DecisionRepository $repository): Response
    {
        $filters = [
            'product' => $request->query->get('product'),
            'department' => $request->query->get('department'),
            'status' => $request->query->get('status'),
            'q' => $request->query->get('q'),
        ];

        $decisions = $repository->queryByFilters($filters)->getQuery()->getResult();

        $today = new \DateTimeImmutable('today');
        foreach ($decisions as $d) {
            $d->recomputeFollowUpStatus($today);
        }

        return $this->render('decision/index.html.twig', [
            'decisions' => $decisions,
            'filters' => $filters,
        ]);
    }

    #[Route('/new', name: 'app_decision_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $decision = new Decision();
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            $decision->setSubmittedBy($user);
        }

        $form = $this->createForm(DecisionType::class, $decision);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $decision->recomputeFollowUpStatus(new \DateTimeImmutable('today'));
            $em->persist($decision);
            $em->flush();
            $this->addFlash('success', 'Decision recorded.');

            return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
        }

        return $this->render('decision/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_decision_show', methods: ['GET'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function show(Decision $decision): Response
    {
        $decision->recomputeFollowUpStatus(new \DateTimeImmutable('today'));

        return $this->render('decision/show.html.twig', [
            'decision' => $decision,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_decision_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function edit(Decision $decision, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(DecisionType::class, $decision);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $decision->recomputeFollowUpStatus(new \DateTimeImmutable('today'));
            $em->flush();
            $this->addFlash('success', 'Decision updated.');

            return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
        }

        return $this->render('decision/edit.html.twig', [
            'form' => $form->createView(),
            'decision' => $decision,
        ]);
    }

    #[Route('/{id}/complete-followup', name: 'app_decision_complete_followup', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function completeFollowUp(Decision $decision, Request $request, EntityManagerInterface $em): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('complete_followup_' . $decision->getId(), $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
        }

        $decision->markFollowUpDone(new \DateTimeImmutable(), $request->request->get('actualResult') ?: null);
        $em->flush();
        $this->addFlash('success', 'Follow-up marked as done.');

        return $this->redirectToRoute('app_decision_show', ['id' => $decision->getId()]);
    }
}
