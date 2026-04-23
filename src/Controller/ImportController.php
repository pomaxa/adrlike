<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\CsvImportType;
use App\Service\CsvImporter;
use App\Service\CsvImportResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/decisions/import')]
final class ImportController extends AbstractController
{
    public function __construct(private readonly CsvImporter $importer)
    {
    }

    #[Route('', name: 'app_decision_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(CsvImportType::class);
        $form->handleRequest($request);

        $result = null;
        $meta = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('file')->getData();
            $encoding = (string) $form->get('encoding')->getData();
            $dryRun = (bool) $form->get('dryRun')->getData();

            $meta = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'dryRun' => $dryRun,
            ];

            $result = $this->importer->import(
                (string) file_get_contents($file->getPathname()),
                $encoding,
                $dryRun,
            );

            if ($result->isOk()) {
                $this->addFlash(
                    'success',
                    $dryRun
                        ? sprintf('Dry run: would import %d, skip %d duplicates, create %d users.', $result->created, $result->skipped, $result->newUsers)
                        : sprintf('Imported %d decisions, skipped %d duplicates, created %d users.', $result->created, $result->skipped, $result->newUsers)
                );
            } else {
                $this->addFlash('error', $result->fatalError);
            }
        }

        return $this->render('import/index.html.twig', [
            'form' => $form->createView(),
            'result' => $result,
            'meta' => $meta,
        ]);
    }
}
