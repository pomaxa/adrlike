<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final class PromotePlaceholderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Real email',
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'constraints' => [new NotBlank(), new Length(min: 8, minMessage: 'Password must be at least 8 characters.')],
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat password'],
                'invalid_message' => 'The passwords must match.',
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Roles',
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'Approver' => 'ROLE_APPROVER',
                    'Submitter' => 'ROLE_SUBMITTER',
                ],
                'data' => ['ROLE_SUBMITTER'],
                'constraints' => [new NotBlank()],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }

    public function getBlockPrefix(): string
    {
        return 'promote_placeholder';
    }
}
