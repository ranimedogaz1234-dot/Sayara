<?php

namespace App\Form;

use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\Voiture;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReservationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateDebut', null, [
                'label' => 'Date de début',
                'widget' => 'single_text',
            ])
            ->add('dateFin', null, [
                'label' => 'Date de fin',
                'widget' => 'single_text',
            ])
            ->add('voiture', EntityType::class, [
                'class' => Voiture::class,
                'choice_label' => function(Voiture $voiture) {
                    return $voiture->getMarque() . ' ' . $voiture->getModele() . ' ($' . number_format((float) $voiture->getPrixParJour(), 0, '.', ',') . '/day)';
                },
                'label' => 'Voiture à louer',
                'placeholder' => '-- Choisir une voiture disponible --',
                'query_builder' => function(EntityRepository $er) use ($options): QueryBuilder {
                    $qb = $er->createQueryBuilder('v')
                        ->where('v.disponible = :dispo')
                        ->setParameter('dispo', true);
                    // When editing, keep the reservation's current car selectable
                    if ($options['current_voiture'] !== null) {
                        $qb->orWhere('v = :current')->setParameter('current', $options['current_voiture']);
                    }
                    return $qb
                        ->orderBy('v.marque', 'ASC');
                },
            ])
        ;

        if ($options['is_admin']) {
            $builder->add('client', EntityType::class, [
                'class' => User::class,
                'choice_label' => function(User $user) {
                    return $user->getNom() . ' (' . $user->getEmail() . ')';
                },
                'label' => 'Client',
                'placeholder' => '-- Choisir un client --',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reservation::class,
            'is_admin' => false,
            'current_voiture' => null,
        ]);
    }
}
