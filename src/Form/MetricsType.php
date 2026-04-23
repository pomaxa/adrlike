<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MetricsType extends AbstractType implements DataTransformerInterface
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ar', NumberType::class, [
                'required' => false,
                'label' => 'AR %',
                'scale' => 2,
                'html5' => true,
            ])
            ->add('badrate', NumberType::class, [
                'required' => false,
                'label' => 'Badrate %',
                'scale' => 2,
                'html5' => true,
            ])
            ->add('avgTicket', NumberType::class, [
                'required' => false,
                'label' => 'AVG ticket',
                'scale' => 2,
                'html5' => true,
            ])
            ->add('raw', TextareaType::class, [
                'required' => false,
                'label' => 'Free-form note',
                'attr' => ['rows' => 2],
            ])
            ->addViewTransformer($this);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => false,
            'compound' => true,
            'empty_data' => null,
            'required' => false,
        ]);
    }

    public function transform(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [];
        }

        return [
            'ar' => $value['ar'] ?? null,
            'badrate' => $value['badrate'] ?? null,
            'avgTicket' => $value['avgTicket'] ?? null,
            'raw' => $value['raw'] ?? null,
        ];
    }

    public function reverseTransform(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $filtered = array_filter(
            $value,
            static fn ($v) => $v !== null && $v !== '' && $v !== [],
        );

        return $filtered === [] ? null : $filtered;
    }
}
