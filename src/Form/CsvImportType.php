<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\CsvImporter;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotNull;

final class CsvImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $encodings = [
            'Auto-detect (recommended)' => CsvImporter::ENCODING_AUTO,
        ];
        foreach (CsvImporter::CANDIDATE_ENCODINGS as $enc) {
            $encodings[$enc] = $enc;
        }

        $builder
            ->add('file', FileType::class, [
                'label' => 'CSV file',
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new NotNull(message: 'Please select a CSV file.'),
                    new File(
                        maxSize: '10M',
                        mimeTypes: ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'],
                        mimeTypesMessage: 'Please upload a CSV file.',
                    ),
                ],
                'attr' => ['accept' => '.csv,text/csv'],
            ])
            ->add('encoding', ChoiceType::class, [
                'label' => 'Source encoding',
                'mapped' => false,
                'choices' => $encodings,
                'data' => CsvImporter::ENCODING_AUTO,
                'help' => 'Leave on auto — the importer detects UTF-8, Windows-1251/1252, KOI8-R, CP866, ISO-8859 variants.',
            ])
            ->add('dryRun', CheckboxType::class, [
                'label' => 'Dry run (parse only, do not save)',
                'mapped' => false,
                'required' => false,
                'help' => 'Validate the file without writing anything to the database.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'csv_import',
        ]);
    }
}
