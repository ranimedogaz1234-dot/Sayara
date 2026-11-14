<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/user')]
#[IsGranted('ROLE_ADMIN')]
final class UserController extends AbstractController
{
    #[Route(name: 'app_user_index', methods: ['GET'])]
    public function index(UserRepository $userRepository): Response
    {
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Password is required for new users
            if (!$user->getPassword()) {
                $this->addFlash('error', 'Le mot de passe est requis.');
                return $this->render('user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            // Check username uniqueness
            $existing = $entityManager->getRepository(User::class)
                ->findOneBy(['username' => $user->getUsername()]);
            if ($existing) {
                $this->addFlash('error', 'Ce nom d\'utilisateur est déjà utilisé.');
                return $this->render('user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            // Check email uniqueness
            $existingEmail = $entityManager->getRepository(User::class)
                ->findOneBy(['email' => $user->getEmail()]);
            if ($existingEmail) {
                $this->addFlash('error', 'Cet email est déjà utilisé.');
                return $this->render('user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            // Hash the password
            $hashedPassword = $passwordHasher->hashPassword($user, $user->getPassword());
            $user->setPassword($hashedPassword);

            // Set default role for new clients
            $user->setRole('ROLE_USER');

            $entityManager->persist($user);
            $entityManager->flush();

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(User $user): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        // Store the current password before form processing
        $currentPassword = $user->getPassword();

        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check username uniqueness (skip if same user)
            $existing = $entityManager->getRepository(User::class)
                ->findOneBy(['username' => $user->getUsername()]);
            if ($existing && $existing->getId() !== $user->getId()) {
                $this->addFlash('error', 'Ce nom d\'utilisateur est déjà utilisé.');
                return $this->render('user/edit.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            // Check email uniqueness (skip if same user)
            $existingEmail = $entityManager->getRepository(User::class)
                ->findOneBy(['email' => $user->getEmail()]);
            if ($existingEmail && $existingEmail->getId() !== $user->getId()) {
                $this->addFlash('error', 'Cet email est déjà utilisé.');
                return $this->render('user/edit.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            // Get the submitted password (could be null or empty string)
            $newPassword = $user->getPassword();

            // If password is empty/null, restore the old one
            if (!$newPassword) {
                $user->setPassword($currentPassword);
            } else {
                // Hash the new password
                $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
                $user->setPassword($hashedPassword);
            }

            $entityManager->flush();
            $this->addFlash('success', 'Utilisateur modifié avec succès!');

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->getPayload()->getString('_token'))) {
            if ($user === $this->getUser()) {
                $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            } else {
                try {
                    $entityManager->remove($user);
                    $entityManager->flush();
                    $this->addFlash('success', 'Utilisateur supprimé avec succès!');
                } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException) {
                    $this->addFlash('error', 'Impossible de supprimer cet utilisateur : il possède des réservations.');
                }
            }
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
