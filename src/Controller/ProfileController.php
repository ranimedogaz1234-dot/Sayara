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
            // UserType maps every field, the password included, straight onto
            // $user - the same object the security token keeps in the session.
            // Hold the submitted password here; the paths that bail out below
            // call refresh() to drop every in-memory change, so neither the
            // plaintext password nor a rejected username reaches the session.
            $submittedPassword = $user->getPassword();

            // Check username uniqueness (skip if same user)
            $existing = $entityManager->getRepository(\App\Entity\User::class)
                ->findOneBy(['username' => $user->getUsername()]);
            if ($existing && $existing->getId() !== $user->getId()) {
                $this->addFlash('error', 'Ce nom d\'utilisateur est déjà utilisé.');
                $entityManager->refresh($user);

                return $this->render('profile/edit.html.twig', [
                    'form' => $form,
                ]);
            }

            // Check email uniqueness (skip if same user)
            $existingEmail = $entityManager->getRepository(\App\Entity\User::class)
                ->findOneBy(['email' => $user->getEmail()]);
            if ($existingEmail && $existingEmail->getId() !== $user->getId()) {
                $this->addFlash('error', 'Cet email est déjà utilisé.');
                $entityManager->refresh($user);

                return $this->render('profile/edit.html.twig', [
                    'form' => $form,
                ]);
            }

            // An empty field means "keep the current password".
            if ($submittedPassword) {
                $user->setPassword($passwordHasher->hashPassword($user, $submittedPassword));
            } else {
                $user->setPassword($currentPassword);
            }

            $entityManager->flush();
            $this->addFlash('success', 'Profil mis à jour avec succès!');

            return $this->redirectToRoute('app_profile');
        }

        // A submitted-but-invalid form leaves the raw password and the rejected
        // values on the entity too, so discard them the same way.
        if ($form->isSubmitted()) {
            $entityManager->refresh($user);
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
