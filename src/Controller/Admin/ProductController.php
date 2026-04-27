<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Form\ProductType;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/products')]
final class ProductController extends AbstractController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_admin_product_index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];
        foreach ($this->products->findAllOrderedByName() as $p) {
            $rows[] = ['product' => $p, 'count' => $this->products->countDecisions($p)];
        }

        return $this->render('admin/product/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/new', name: 'app_admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $product = new Product('');
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($product);
            $this->em->flush();
            $this->addFlash('success', sprintf('Product "%s" created.', $product->getName()));

            return $this->redirectToRoute('app_admin_product_index');
        }

        $status = $form->isSubmitted() ? 422 : 200;

        return $this->render('admin/product/new.html.twig', ['form' => $form->createView()], new Response(null, $status));
    }

    #[Route('/{id}/edit', name: 'app_admin_product_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function edit(string $id, Request $request): Response
    {
        $product = $this->products->find($id) ?? throw $this->createNotFoundException();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', sprintf('Product "%s" saved.', $product->getName()));

            return $this->redirectToRoute('app_admin_product_index');
        }

        $status = $form->isSubmitted() ? 422 : 200;

        return $this->render('admin/product/edit.html.twig', [
            'product' => $product,
            'form' => $form->createView(),
            'decisionCount' => $this->products->countDecisions($product),
        ], new Response(null, $status));
    }

    #[Route('/{id}/delete', name: 'app_admin_product_delete', methods: ['POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function delete(string $id, Request $request): Response
    {
        $product = $this->products->find($id) ?? throw $this->createNotFoundException();

        if (!$this->isCsrfTokenValid('delete_product_' . $product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_product_index');
        }

        $count = $this->products->countDecisions($product);
        if ($count > 0) {
            $this->addFlash('error', sprintf('Cannot delete "%s" — it is referenced by %d decision(s).', $product->getName(), $count));

            return $this->redirectToRoute('app_admin_product_index');
        }

        $this->em->remove($product);
        $this->em->flush();
        $this->addFlash('success', sprintf('Product "%s" deleted.', $product->getName()));

        return $this->redirectToRoute('app_admin_product_index');
    }
}
