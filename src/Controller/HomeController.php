<?php

namespace App\Controller;

use App\Entity\Voiture;
use App\Repository\VoitureRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(VoitureRepository $voitureRepository): Response
    {
        return $this->render('home/index.html.twig', [
            // The hero carousel features up to 5 real, currently available
            // vehicles - never a fixed, possibly-stale list.
            'featuredVoitures' => $voitureRepository->findBy(['disponible' => true], ['id' => 'DESC'], 5),
            'brands' => $voitureRepository->findDistinctBrands(),
        ]);
    }

    #[Route('/voitures', name: 'app_catalog')]
    public function catalog(Request $request, VoitureRepository $voitureRepository): Response
    {
        $brand = $request->query->get('brand') ?: null;
        $q = trim((string) $request->query->get('q', '')) ?: null;
        $sort = $request->query->get('sort', 'price_asc');
        if (!in_array($sort, ['price_asc', 'price_desc', 'name'], true)) {
            $sort = 'price_asc';
        }

        // The search bar's pick-up/return dates are not tied to a filter
        // the database can actually express (Voiture has no per-date
        // availability, only a single disponible flag - see
        // VehicleAvailabilityTest's documentation of that gap). They are
        // only validated here, so an invalid range surfaces as a clear
        // message instead of being silently ignored, and the valid dates
        // are carried through to pre-fill the reservation form once a
        // vehicle is chosen.
        $pickupDate = $request->query->get('pickupDate') ?: null;
        $returnDate = $request->query->get('returnDate') ?: null;
        if ($pickupDate && $returnDate) {
            try {
                $debut = new \DateTime($pickupDate);
                $fin = new \DateTime($returnDate);
                if ($fin <= $debut) {
                    $this->addFlash('error', 'The return date must be after the pick-up date.');
                    $pickupDate = $returnDate = null;
                }
            } catch (\Exception) {
                $this->addFlash('error', 'Please enter valid pick-up and return dates.');
                $pickupDate = $returnDate = null;
            }
        }

        return $this->render('home/catalog.html.twig', [
            'voitures' => $voitureRepository->searchAvailable($brand, $q, $sort),
            'brands' => $voitureRepository->findDistinctBrands(),
            'currentBrand' => $brand,
            'currentQuery' => $q,
            'currentSort' => $sort,
            'pickupDate' => $pickupDate,
            'returnDate' => $returnDate,
        ]);
    }

    /**
     * Public vehicle detail page. Any vehicle may be viewed this way (not
     * only the available ones listed on /voitures), so a shared link never
     * silently 404s just because the car has since become unavailable -
     * the page states its availability clearly instead.
     */
    #[Route('/voitures/{id}', name: 'app_catalog_show')]
    public function show(Voiture $voiture, Request $request): Response
    {
        return $this->render('home/show.html.twig', [
            'voiture' => $voiture,
            'pickupDate' => $request->query->get('pickupDate'),
            'returnDate' => $request->query->get('returnDate'),
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('home/about.html.twig');
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact(): Response
    {
        return $this->render('home/contact.html.twig');
    }
}
