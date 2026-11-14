<?php

namespace App\Controller;

use App\Entity\Voiture;
use App\Form\VoitureType;
use App\Repository\VoitureRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/voiture')]
#[IsGranted('ROLE_ADMIN')]
final class VoitureController extends AbstractController
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    #[Route(name: 'app_voiture_index', methods: ['GET'])]
    public function index(VoitureRepository $voitureRepository): Response
    {
        return $this->render('voiture/index.html.twig', [
            'voitures' => $voitureRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_voiture_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $voiture = new Voiture();
        $form = $this->createForm(VoitureType::class, $voiture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newFilename = $this->storeImage($form->get('image')->getData(), $slugger);
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('voiture/new.html.twig', ['voiture' => $voiture, 'form' => $form]);
            }
            if ($newFilename) {
                $voiture->setImage($newFilename);
            }

            $entityManager->persist($voiture);
            $entityManager->flush();

            return $this->redirectToRoute('app_voiture_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('voiture/new.html.twig', [
            'voiture' => $voiture,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_voiture_show', methods: ['GET'])]
    public function show(Voiture $voiture): Response
    {
        return $this->render('voiture/show.html.twig', [
            'voiture' => $voiture,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_voiture_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Voiture $voiture, EntityManagerInterface $entityManager, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(VoitureType::class, $voiture);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newFilename = $this->storeImage($form->get('image')->getData(), $slugger);
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());

                return $this->render('voiture/edit.html.twig', ['voiture' => $voiture, 'form' => $form]);
            }
            if ($newFilename) {
                $voiture->setImage($newFilename);
            }

            $entityManager->flush();

            return $this->redirectToRoute('app_voiture_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('voiture/edit.html.twig', [
            'voiture' => $voiture,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_voiture_delete', methods: ['POST'])]
    public function delete(Request $request, Voiture $voiture, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$voiture->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $entityManager->remove($voiture);
                $entityManager->flush();
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'Impossible de supprimer ce véhicule : il est lié à des réservations.');
            }
        }

        return $this->redirectToRoute('app_voiture_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Moves an uploaded image to the uploads directory.
     * The stored extension is derived from the file's real content (MIME sniffing),
     * never from the client-supplied file name.
     */
    private function storeImage(?UploadedFile $imageFile, SluggerInterface $slugger): ?string
    {
        if (!$imageFile) {
            return null;
        }

        $extension = strtolower((string) $imageFile->guessExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException('Format d\'image non autorisé. Utilisez JPG, PNG, WebP ou GIF.');
        }

        $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
        $newFilename = $slugger->slug($originalFilename).'-'.bin2hex(random_bytes(6)).'.'.$extension;

        try {
            $imageFile->move($this->getParameter('voitures_directory'), $newFilename);
        } catch (\Exception) {
            // Do not leak filesystem paths / internal messages to the browser
            throw new \RuntimeException('Erreur lors de l\'upload de l\'image.');
        }

        return $newFilename;
    }
}
