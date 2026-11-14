<?php

namespace App\Controller;

use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
final class ProfileController extends AbstractController
{
    #[Route(name: 'app_profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('profile/index.html.twig', [
            'reservations' => $user->getReservations(),
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = $this->getUser();
        $currentPassword = $user->getPassword();

        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check username uniqueness (skip if same user)
            $existing = $entityManager->getRepository(\App\Entity\User::class)
                ->findOneBy(['username' => $user->getUsername()]);
            if ($existing && $existing->getId() !== $user->getId()) {
                $this->addFlash('error', 'Ce nom d\'utilisateur est déjà utilisé.');
                return $this->render('profile/edit.html.twig', [
                    'form' => $form,
                ]);
            }

            // Check email uniqueness (skip if same user)
            $existingEmail = $entityManager->getRepository(\App\Entity\User::class)
                ->findOneBy(['email' => $user->getEmail()]);
            if ($existingEmail && $existingEmail->getId() !== $user->getId()) {
                $this->addFlash('error', 'Cet email est déjà utilisé.');
                return $this->render('profile/edit.html.twig', [
                    'form' => $form,
                ]);
            }

            $newPassword = $user->getPassword();
            if (!$newPassword) {
                $user->setPassword($currentPassword);
            } else {
                $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
                $user->setPassword($hashedPassword);
            }

            $entityManager->flush();
            $this->addFlash('success', 'Profil mis à jour avec succès!');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
