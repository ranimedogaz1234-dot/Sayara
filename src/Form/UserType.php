<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Nom d\'utilisateur',
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom complet',
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
            ])
            ->add('password', PasswordType::class, [
                'label' => 'Mot de passe',
                'required' => false,
                'empty_data' => '',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            // The password field is optional on this form (used for both
            // registration and profile editing). Its Length constraint lives
            // in the 'password_change' group on the entity, so it only runs
            // when a new password was actually submitted; leaving the field
            // blank - the documented way to keep the current password - no
            // longer fails validation. SecurityController::signup() already
            // overrides this option to also activate 'registration', which
            // keeps Length enforced there regardless of this closure.
            'validation_groups' => static function (FormInterface $form): array {
                $groups = ['Default'];

                if ('' !== (string) $form->get('password')->getData()) {
                    $groups[] = 'password_change';
                }

                return $groups;
            },
        ]);
    }
}
