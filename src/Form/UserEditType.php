<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\Department;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UserEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('fullName', TextType::class, ['label' => 'Full name'])
            ->add('department', EnumType::class, [
                'class'        => Department::class,
                'label'        => 'Department',
                'required'     => false,
                'placeholder'  => '— select —',
                'choice_label' => fn(Department $d) => $d->label(),
            ]);

        if (!$options['is_self']) {
            $builder->add('roles', ChoiceType::class, [
                'label' => 'Roles',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'Approver' => 'ROLE_APPROVER',
                    'Submitter' => 'ROLE_SUBMITTER',
                ],
                'data' => array_values(array_intersect(
                    $options['current_roles'],
                    ['ROLE_ADMIN', 'ROLE_APPROVER', 'ROLE_SUBMITTER']
                )),
                'constraints' => [new NotBlank()],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_self' => false,
            'current_roles' => [],
        ]);
        $resolver->setAllowedTypes('is_self', 'bool');
        $resolver->setAllowedTypes('current_roles', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'user_edit';
    }
}
