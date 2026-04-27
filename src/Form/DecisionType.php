<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Decision;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\Department;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DecisionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('decidedAt', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Decision date',
            ])
            ->add('product', EntityType::class, [
                'class' => Product::class,
                'choice_label' => 'name',
                'placeholder' => '— select —',
            ])
            ->add('department', EnumType::class, [
                'class' => Department::class,
                'choice_label' => static fn (Department $d) => $d->label(),
            ])
            ->add('clientsType', TextType::class, [
                'label' => 'Clients type',
                'required' => false,
                'empty_data' => 'All',
            ])
            ->add('changeDescription', TextareaType::class, [
                'label' => 'Change',
                'attr' => ['rows' => 5],
            ])
            ->add('comment', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('submittedBy', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'fullName',
                'label' => 'Submitted by',
            ])
            ->add('approvedBy', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => '— not approved —',
                'label' => 'Approved by',
            ])
            ->add('asIsMetrics', MetricsType::class, [
                'label' => 'As-is metrics',
            ])
            ->add('toBeMetrics', MetricsType::class, [
                'label' => 'To-be metrics',
            ])
            ->add('followUpDate', DateType::class, [
                'widget' => 'single_text',
                'required' => false,
                'label' => 'Follow-up date',
            ])
            ->add('followUpOwner', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'fullName',
                'required' => false,
                'placeholder' => '— none —',
                'label' => 'Follow-up owner',
            ])
            ->add('actualResult', TextareaType::class, [
                'required' => false,
                'attr' => ['rows' => 3],
                'label' => 'Actual result',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Decision::class,
        ]);
    }
}
