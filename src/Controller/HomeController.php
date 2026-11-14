<?php

namespace App\Controller;

use App\Repository\VoitureRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(VoitureRepository $voitureRepository): Response
    {
        return $this->render('home/index.html.twig', [
            'voitures' => $voitureRepository->findAll(),
        ]);
    }

    #[Route('/voitures', name: 'app_catalog')]
    public function catalog(VoitureRepository $voitureRepository): Response
    {
        return $this->render('home/catalog.html.twig', [
            'voitures' => $voitureRepository->findBy(['disponible' => true]),
        ]);
    }
}
