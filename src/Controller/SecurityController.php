<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($user = $this->getUser()) {
            if (in_array('ROLE_ADMIN', $user->getRoles())) {
                return $this->redirectToRoute('app_dashboard');
            }
            return $this->redirectToRoute('app_home');
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): Response
    {
        // This will be handled by the logout key in security.yaml
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/signup', name: 'app_signup', methods: ['GET', 'POST'])]
    public function signup(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        Security $security
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(UserType::class, $user, [
            'validation_groups' => ['Default', 'registration'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Check username uniqueness
            $existingUsername = $entityManager->getRepository(User::class)
                ->findOneBy(['username' => $user->getUsername()]);
            if ($existingUsername) {
                $this->addFlash('error', 'Ce nom d\'utilisateur est déjà utilisé.');
                return $this->render('security/signup.html.twig', [
                    'form' => $form,
                ]);
            }

            // Check email uniqueness
            $existingEmail = $entityManager->getRepository(User::class)
                ->findOneBy(['email' => $user->getEmail()]);
            if ($existingEmail) {
                $this->addFlash('error', 'Cet email est déjà utilisé.');
                return $this->render('security/signup.html.twig', [
                    'form' => $form,
                ]);
            }

            $hashedPassword = $passwordHasher->hashPassword($user, $user->getPassword());
            $user->setPassword($hashedPassword);
            $user->setRole('ROLE_USER');

            $entityManager->persist($user);
            $entityManager->flush();

            $this->addFlash('success', 'Inscription réussie! Veuillez vous connecter.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/signup.html.twig', [
            'form' => $form,
        ]);
    }
}
